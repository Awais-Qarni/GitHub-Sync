<?php

namespace GithubSync\Sync;

use GithubSync\Database\Models\FileState;
use GithubSync\Support\ExclusionFilter;
use GithubSync\Support\FileHasher;

/**
 * Looks at the WordPress side of a mapping: which files exist, which of them
 * changed since the last sync, and which tracked files are gone.
 */
class LocalChangeDetector {

    private string $dest_path;
    private ExclusionFilter $filter;
    private int $max_file_bytes;

    /**
     * @param string $dest_path Validated absolute destination folder.
     */
    public function __construct(string $dest_path, ExclusionFilter $filter, int $max_file_bytes) {
        $this->dest_path = untrailingslashit(wp_normalize_path($dest_path));
        $this->filter = $filter;
        $this->max_file_bytes = $max_file_bytes;
    }

    /**
     * List every syncable file below the destination folder.
     *
     * Excluded folders are never descended into, so a stray node_modules does
     * not cost anything.
     *
     * @return array{files: array<int, array{path: string, size: int}>, skipped: array<int, string>}
     */
    public function list_files(): array {
        $files = [];
        $skipped = [];

        if (!is_dir($this->dest_path)) {
            return ['files' => $files, 'skipped' => $skipped];
        }

        $prefix_length = strlen($this->dest_path) + 1;

        // Symlinks are deliberately not followed: a link loop would make the
        // scan run forever, and a link out of the mapping would push files that
        // live somewhere else entirely.
        $directory_iterator = new \RecursiveDirectoryIterator(
            $this->dest_path,
            \FilesystemIterator::SKIP_DOTS
        );

        $filter = $this->filter;

        $pruned = new \RecursiveCallbackFilterIterator(
            $directory_iterator,
            static function ($current) use ($filter, $prefix_length): bool {
                $relative = substr(wp_normalize_path($current->getPathname()), $prefix_length);

                if ($relative === false || $relative === '') {
                    return true;
                }

                if ($current->isDir()) {
                    return !$filter->is_excluded_directory($relative);
                }

                return !$filter->is_excluded($relative);
            }
        );

        foreach (new \RecursiveIteratorIterator($pruned) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relative = substr(wp_normalize_path($item->getPathname()), $prefix_length);

            if ($relative === false || $relative === '') {
                continue;
            }

            $size = (int) $item->getSize();

            if ($this->max_file_bytes > 0 && $size > $this->max_file_bytes) {
                $skipped[] = $relative;
                continue;
            }

            $files[] = ['path' => $relative, 'size' => $size];
        }

        usort($files, static function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        return ['files' => $files, 'skipped' => $skipped];
    }

    /**
     * Compare a batch of local files with their recorded hashes.
     *
     * @param array<int, array{path: string, size: int}> $batch
     * @return array<int, array{path: string, size: int, hash: string, operation: string}>
     */
    public function changed_in_batch(int $mapping_id, array $batch): array {
        if (!$batch) {
            return [];
        }

        $paths = array_column($batch, 'path');
        $tracked = FileState::get_hashes($mapping_id, $paths);
        $changed = [];

        foreach ($batch as $file) {
            $path = (string) $file['path'];
            $hash = FileHasher::hash_file($this->dest_path . '/' . $path);

            if ($hash === '') {
                continue;
            }

            if (isset($tracked[$path])) {
                if ($tracked[$path] === $hash) {
                    continue;
                }

                $changed[] = [
                    'path'      => $path,
                    'size'      => (int) $file['size'],
                    'hash'      => $hash,
                    'operation' => 'update',
                ];
                continue;
            }

            $changed[] = [
                'path'      => $path,
                'size'      => (int) $file['size'],
                'hash'      => $hash,
                'operation' => 'add',
            ];
        }

        return $changed;
    }

    /**
     * Tracked files that are no longer on disk.
     *
     * @return string[]
     */
    public function find_deleted(int $mapping_id): array {
        $deleted = [];

        foreach (FileState::get_map($mapping_id) as $path => $hash) {
            if ($this->filter->is_excluded($path)) {
                continue;
            }

            if (!is_file($this->dest_path . '/' . $path)) {
                $deleted[] = $path;
            }
        }

        return $deleted;
    }

    /**
     * Absolute path of a tracked file.
     */
    public function absolute_path(string $relative_path): string {
        return $this->dest_path . '/' . ltrim($relative_path, '/');
    }
}
