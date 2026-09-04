<?php
/**
 * Plugin Name:       GitHub Sync
 * Plugin URI:        https://github.com/Awais-Qarni/GitHub-Sync
 * Description:       Manual two-way syncing between a GitHub repository folder and a WordPress folder. Pull and push on demand, with backups, logs and your own commit messages.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Muhammad Awais
 * Author URI:        mailto:reachoutawais@gmail.com
 * License:           GPL-2.0-or-later
 * Text Domain:       github-sync
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('GITHUB_SYNC_VERSION', '2.0.0');
define('GITHUB_SYNC_FILE', __FILE__);
define('GITHUB_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GITHUB_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader for the GithubSync namespace.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'GithubSync\\';
    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $file = GITHUB_SYNC_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

/**
 * Boot the plugin once WordPress has loaded.
 */
function github_sync_init(): void {
    if (!class_exists('\\GithubSync\\Plugin')) {
        return;
    }

    \GithubSync\Plugin::get_instance()->init();
}

add_action('plugins_loaded', 'github_sync_init');

register_activation_hook(__FILE__, static function (): void {
    if (class_exists('\\GithubSync\\Plugin')) {
        \GithubSync\Plugin::activate();
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists('\\GithubSync\\Plugin')) {
        \GithubSync\Plugin::deactivate();
    }
});
