<?php

namespace GithubSync\Deployment;

use GithubSync\Support\Workspace;
use WP_Error;
use ZipArchive;

/**
 * Archives the files a pull is about to overwrite or delete, and puts them back
 * when applying fails part way through.
 */
class Rollback {

    private string $dest_path;
    private string $archive_path;

    /**
     * @param string $dest_path    Validated absolute destination folder.
     * @param string $archive_path Absolute path of the zip archive to build or restore.
     */
    public function __construct(string $dest_path, string $archive_path) {
        $this->dest_path = untrailingslashit(wp_normalize_path($dest_path));
        $this->archive_path = wp_normalize_path($archive_path);
    }

    /**
     * Whether zip support is available at all.
     */
    public static function is_supported(): bool {
        return class_exists('ZipArchive');
    }

    /**
     * Build the archive path for a mapping.
     */
    public static function new_archive_path(int $mapping_id): string {
        return Workspace::backup_dir() . '/backup-' . $mapping_id . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false) . '.zip';
    }

    /**
     * Add another batch of files to the archive.
     *
     * The archive is opened and closed per batch so a long backup can be spread
     * over several requests.
     *
     * @param string[] $relative_paths
     * @return int|WP_Error Number of files added.
     */
    public function add_batch(array $relative_paths) {
        if (!self::is_supported()) {
            return new WP_Error('missing_ziparchive', __('The ZipArchive PHP extension is missing, so no backup can be made.', 'github-sync'));
        }

        $existing = [];

        foreach ($relative_paths as $relative_path) {
            $file = $this->dest_path . '/' . ltrim($relative_path, '/');

            if (is_file($file) && is_readable($file)) {
                $existing[$relative_path] = $file;
            }
        }

        if (!$existing) {
            return 0;
        }

        $zip = new ZipArchive();
        $flags = file_exists($this->archive_path) ? 0 : ZipArchive::CREATE;

        if ($zip->open($this->archive_path, $flags) !== true) {
            return new WP_Error('zip_open_failed', __('The backup archive could not be opened for writing.', 'github-sync'));
        }

        foreach ($existing as $relative_path => $file) {
            $zip->addFile($file, ltrim($relative_path, '/'));
        }

        if (!$zip->close()) {
            return new WP_Error('zip_write_failed', __('The backup archive could not be written.', 'github-sync'));
        }

        return count($existing);
    }

    /**
     * Restore every file in the archive over the destination folder.
     */
    public function restore(): bool {
        if (!self::is_supported() || !file_exists($this->archive_path)) {
            return false;
        }

        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (empty($GLOBALS['wp_filesystem'])) {
            WP_Filesystem();
        }

        $result = unzip_file($this->archive_path, $this->dest_path);

        return !is_wp_error($result);
    }

    /**
     * Absolute path of the archive.
     */
    public function path(): string {
        return $this->archive_path;
    }

    /**
     * True once at least one file has been archived.
     */
    public function exists(): bool {
        return file_exists($this->archive_path);
    }

    /**
     * Delete archives older than the retention window.
     */
    public static function prune(int $keep_days = 14): void {
        $dir = Workspace::backup_dir();

        foreach (glob($dir . '/backup-*.zip') ?: [] as $file) {
            $modified = @filemtime($file);

            if ($modified && (time() - $modified) > $keep_days * DAY_IN_SECONDS) {
                @unlink($file);
            }
        }
    }
}
