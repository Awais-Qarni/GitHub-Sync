<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Tables and options are kept unless the site owner ticked "Remove all data on
 * uninstall" in Settings, so deleting and reinstalling does not lose mappings.
 *
 * @package GithubSync
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$github_sync_settings = get_option('github_sync_settings', []);
$github_sync_remove_all = is_array($github_sync_settings) && !empty($github_sync_settings['remove_data_on_uninstall']);

// Scheduled housekeeping and cached credentials always go.
wp_clear_scheduled_hook('github_sync_daily_cleanup');

global $wpdb;

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_github\_sync\_%'
        OR option_name LIKE '\_transient\_timeout\_github\_sync\_%'"
);

if (!$github_sync_remove_all) {
    return;
}

foreach (['file_state', 'logs', 'runs', 'mappings', 'deployments', 'jobs'] as $github_sync_table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}github_sync_{$github_sync_table}");
}

delete_option('github_sync_settings');
delete_option('github_sync_db_version');

$role = get_role('administrator');

if ($role) {
    $role->remove_cap('github_sync_manage');
}

// Backups and scratch folders inside uploads.
$github_sync_uploads = wp_upload_dir();

if (empty($github_sync_uploads['error'])) {
    $github_sync_dir = trailingslashit($github_sync_uploads['basedir']) . 'github-sync';

    if (is_dir($github_sync_dir)) {
        $github_sync_items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($github_sync_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($github_sync_items as $github_sync_item) {
            if ($github_sync_item->isDir()) {
                @rmdir($github_sync_item->getPathname());
            } else {
                @unlink($github_sync_item->getPathname());
            }
        }

        @rmdir($github_sync_dir);
    }
}
