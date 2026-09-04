<?php

namespace GithubSync\Security;

use WP_Error;

/**
 * Keeps every file operation inside a folder the site owner actually chose.
 */
class PathValidator {

    /**
     * Stream wrappers have no business in a file path.
     */
    private const BLOCKED_PROTOCOLS = [
        'file://', 'php://', 'phar://', 'data://', 'zlib://', 'glob://', 'ssh2://', 'rar://', 'ogg://', 'expect://', 'http://', 'https://',
    ];

    /**
     * Resolve a mapping destination to an absolute path inside an allowed root.
     *
     * @param string $path Relative folder name, or an absolute path inside the allowed root.
     * @param string $type One of theme, plugin or custom.
     * @return string|WP_Error
     */
    public static function validate_destination(string $path, string $type) {
        $path = trim($path);

        if ($path === '') {
            return new WP_Error('empty_path', __('Choose a destination folder.', 'github-sync'));
        }

        if (strpos($path, "\0") !== false) {
            return new WP_Error('null_bytes', __('That path contains characters that are not allowed.', 'github-sync'));
        }

        foreach (self::BLOCKED_PROTOCOLS as $protocol) {
            if (stripos($path, $protocol) !== false) {
                return new WP_Error('blocked_protocol', __('That path contains a protocol wrapper, which is not allowed.', 'github-sync'));
            }
        }

        $path = wp_normalize_path($path);

        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return new WP_Error('path_traversal', __('That path tries to move outside the allowed folder.', 'github-sync'));
        }

        $allowed_base = self::allowed_base($type);

        if (is_wp_error($allowed_base)) {
            return $allowed_base;
        }

        if (!self::is_absolute($path)) {
            $path = $allowed_base . '/' . ltrim($path, '/');
        }

        $path = untrailingslashit(wp_normalize_path($path));

        $resolved = realpath($path);

        if ($resolved !== false) {
            $path = untrailingslashit(wp_normalize_path($resolved));
        }

        if (!self::is_inside($path, $allowed_base)) {
            return new WP_Error(
                'path_escape',
                sprintf(
                    /* translators: %s: allowed parent folder. */
                    __('The destination must be inside %s.', 'github-sync'),
                    $allowed_base
                )
            );
        }

        if ($path === $allowed_base) {
            return new WP_Error(
                'path_is_root',
                __('Choose a folder inside the WordPress directory rather than the directory itself.', 'github-sync')
            );
        }

        $protected = self::protected_path($path);

        if ($protected !== null) {
            return new WP_Error('path_protected', $protected);
        }

        return $path;
    }

    /**
     * Normalise a repository subfolder: no leading slash, no traversal.
     */
    public static function validate_source_path(string $path): string {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || $path === '/') {
            return '';
        }

        if (strpos($path, "\0") !== false) {
            return '';
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * Whether a path from a repository listing is safe to write below a folder.
     */
    public static function is_safe_relative_path(string $path): bool {
        if ($path === '' || strpos($path, "\0") !== false) {
            return false;
        }

        $normalised = str_replace('\\', '/', $path);

        if (self::is_absolute($normalised)) {
            return false;
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $normalised)) {
            return false;
        }

        foreach (self::BLOCKED_PROTOCOLS as $protocol) {
            if (stripos($normalised, $protocol) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * The folder a destination type must live inside.
     *
     * @return string|WP_Error
     */
    private static function allowed_base(string $type) {
        switch ($type) {
            case 'theme':
                $base = get_theme_root();
                break;
            case 'plugin':
                $base = WP_PLUGIN_DIR;
                break;
            case 'custom':
                $base = WP_CONTENT_DIR;
                break;
            default:
                return new WP_Error('invalid_type', __('Choose a valid destination type.', 'github-sync'));
        }

        $real = realpath($base);

        return untrailingslashit(wp_normalize_path($real ?: $base));
    }

    /**
     * Folders the plugin refuses to write into, with the reason why.
     */
    private static function protected_path(string $path): ?string {
        $blocked = [];

        if (defined('GITHUB_SYNC_PLUGIN_DIR')) {
            $blocked[untrailingslashit(wp_normalize_path(GITHUB_SYNC_PLUGIN_DIR))] = __('A mapping cannot write into the GitHub Sync plugin folder itself.', 'github-sync');
        }

        $uploads = wp_upload_dir();

        if (empty($uploads['error'])) {
            $blocked[untrailingslashit(wp_normalize_path($uploads['basedir'])) . '/github-sync'] = __('A mapping cannot write into the folder GitHub Sync uses for backups.', 'github-sync');
        }

        foreach ($blocked as $blocked_path => $reason) {
            if ($path === $blocked_path || self::is_inside($path, $blocked_path)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * True when a path sits inside a parent folder.
     */
    private static function is_inside(string $path, string $parent): bool {
        $path = untrailingslashit(wp_normalize_path($path));
        $parent = untrailingslashit(wp_normalize_path($parent));

        if ($path === $parent) {
            return true;
        }

        return strpos($path, $parent . '/') === 0;
    }

    /**
     * Absolute on both Unix style and Windows style paths.
     */
    private static function is_absolute(string $path): bool {
        return (bool) preg_match('#^/|^[a-zA-Z]:/#', str_replace('\\', '/', $path));
    }
}
