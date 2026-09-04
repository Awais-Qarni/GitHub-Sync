<?php

namespace GithubSync\Support;

use WP_Error;

/**
 * Private scratch space for a sync run.
 *
 * Long file lists are stored in numbered chunks so a single step only reads the
 * slice it is about to work on, which keeps memory flat no matter how many files
 * the repository holds.
 */
class Workspace {

    /**
     * Entries per list chunk file.
     */
    private const CHUNK_SIZE = 250;

    private string $root;

    public function __construct(string $root) {
        $this->root = untrailingslashit(wp_normalize_path($root));
    }

    /**
     * Workspace for a run, created on demand.
     */
    public static function for_run(int $run_id): self {
        $workspace = new self(self::base_dir() . '/work/run-' . $run_id);
        $workspace->ensure();

        return $workspace;
    }

    /**
     * The plugin's private folder inside uploads.
     */
    public static function base_dir(): string {
        $uploads = wp_upload_dir();

        return wp_normalize_path(untrailingslashit($uploads['basedir']) . '/github-sync');
    }

    /**
     * Folder holding rollback archives.
     */
    public static function backup_dir(): string {
        $dir = self::base_dir() . '/backups';
        wp_mkdir_p($dir);
        self::protect(self::base_dir());

        return $dir;
    }

    /**
     * Absolute path of this workspace.
     */
    public function path(string $relative = ''): string {
        return $relative === '' ? $this->root : $this->root . '/' . ltrim($relative, '/');
    }

    /**
     * Where downloaded files are staged before they are applied.
     */
    public function staging_dir(): string {
        $dir = $this->path('staged');
        wp_mkdir_p($dir);

        return $dir;
    }

    /**
     * Create the folder and block direct web access to it.
     */
    public function ensure(): bool {
        if (!wp_mkdir_p($this->root)) {
            return false;
        }

        self::protect(self::base_dir());

        return true;
    }

    /**
     * Drop deny rules into the plugin's uploads folder once.
     */
    public static function protect(string $dir): void {
        wp_mkdir_p($dir);

        $htaccess = $dir . '/.htaccess';

        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n");
        }

        $index = $dir . '/index.php';

        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Store a list of items as numbered chunks.
     *
     * @param array<int, mixed> $items
     * @return int|WP_Error Number of items stored.
     */
    public function write_list(string $name, array $items) {
        $this->ensure();
        $this->delete_list($name);

        $total = count($items);
        $index = 0;

        foreach (array_chunk($items, self::CHUNK_SIZE) as $chunk) {
            $written = @file_put_contents($this->chunk_path($name, $index), $this->encode($chunk));

            if ($written === false) {
                return new WP_Error(
                    'workspace_write_failed',
                    __('The plugin could not write to the uploads folder. Check the folder permissions.', 'github-sync')
                );
            }

            $index++;
        }

        return $total;
    }

    /**
     * Read a slice of a stored list.
     *
     * @return array<int, mixed>
     */
    public function read_list(string $name, int $offset, int $limit): array {
        if ($limit <= 0) {
            return [];
        }

        $items = [];
        $chunk_index = intdiv($offset, self::CHUNK_SIZE);
        $position_in_chunk = $offset % self::CHUNK_SIZE;

        while (count($items) < $limit) {
            $file = $this->chunk_path($name, $chunk_index);

            if (!is_readable($file)) {
                break;
            }

            $chunk = json_decode((string) file_get_contents($file), true);

            if (!is_array($chunk) || !$chunk) {
                break;
            }

            $slice = array_slice($chunk, $position_in_chunk, $limit - count($items));
            $items = array_merge($items, $slice);

            if (count($chunk) < self::CHUNK_SIZE) {
                break;
            }

            $chunk_index++;
            $position_in_chunk = 0;
        }

        return $items;
    }

    /**
     * Add items to a list that is being built up across several steps.
     *
     * Whatever does not fill a whole chunk is held in a pending file, so the
     * finished list still has evenly sized chunks and can be read by offset.
     *
     * @param array<int, mixed> $items
     */
    public function append_list(string $name, array $items): void {
        if (!$items) {
            return;
        }

        $this->ensure();

        $buffer = array_merge($this->read_pending($name), array_values($items));
        $index = $this->chunk_count($name);

        while (count($buffer) >= self::CHUNK_SIZE) {
            $chunk = array_splice($buffer, 0, self::CHUNK_SIZE);
            @file_put_contents($this->chunk_path($name, $index), $this->encode($chunk));
            $index++;
        }

        $this->write_pending($name, $buffer);
    }

    /**
     * Finish a list built with append_list, writing out whatever is left over.
     */
    public function seal_list(string $name): void {
        $pending = $this->read_pending($name);

        if ($pending) {
            @file_put_contents($this->chunk_path($name, $this->chunk_count($name)), $this->encode($pending));
        }

        @unlink($this->pending_path($name));
    }

    /**
     * How many chunks a list currently has.
     */
    public function chunk_count(string $name): int {
        return count(glob($this->root . '/' . $name . '-*.json') ?: []);
    }

    /**
     * @return array<int, mixed>
     */
    private function read_pending(string $name): array {
        $file = $this->pending_path($name);

        if (!is_readable($file)) {
            return [];
        }

        $items = json_decode((string) file_get_contents($file), true);

        return is_array($items) ? $items : [];
    }

    /**
     * @param array<int, mixed> $items
     */
    private function write_pending(string $name, array $items): void {
        if (!$items) {
            @unlink($this->pending_path($name));

            return;
        }

        @file_put_contents($this->pending_path($name), $this->encode($items));
    }

    private function pending_path(string $name): string {
        return $this->root . '/' . $name . '.pending';
    }

    /**
     * Remove every chunk of a list.
     */
    public function delete_list(string $name): void {
        foreach (glob($this->root . '/' . $name . '-*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Delete the whole workspace.
     */
    public function cleanup(): void {
        self::delete_directory($this->root);
    }

    /**
     * Delete a run folder without creating it first.
     */
    public static function remove_run(int $run_id): void {
        self::delete_directory(self::base_dir() . '/work/run-' . $run_id);
    }

    /**
     * Remove stale run folders left behind by abandoned syncs.
     */
    public static function cleanup_old_runs(int $older_than_seconds = DAY_IN_SECONDS): void {
        $work_dir = self::base_dir() . '/work';

        if (!is_dir($work_dir)) {
            return;
        }

        foreach (glob($work_dir . '/run-*') ?: [] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $modified = @filemtime($dir);

            if ($modified && (time() - $modified) > $older_than_seconds) {
                self::delete_directory($dir);
            }
        }
    }

    /**
     * Recursively delete a directory.
     */
    public static function delete_directory(string $dir): void {
        $dir = wp_normalize_path($dir);

        if ($dir === '' || !is_dir($dir) || strpos($dir, self::base_dir()) !== 0) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function chunk_path(string $name, int $index): string {
        return $this->root . '/' . $name . '-' . $index . '.json';
    }

    /**
     * Encode a list for storage.
     *
     * A file name that is not valid UTF-8 would make the encoder return false
     * and silently empty the chunk, so those bytes are substituted instead.
     *
     * @param array<int, mixed> $items
     */
    private function encode(array $items): string {
        $json = wp_json_encode(array_values($items));

        if ($json === false) {
            $json = json_encode(array_values($items), JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $json === false ? '[]' : $json;
    }
}
