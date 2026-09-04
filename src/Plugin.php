<?php

namespace GithubSync;

use GithubSync\Admin\Dashboard;
use GithubSync\API\RestController;
use GithubSync\Database\Migrations;
use GithubSync\Database\Models\Log;
use GithubSync\Deployment\Rollback;
use GithubSync\GitHub\TokenProvider;
use GithubSync\Support\Settings;
use GithubSync\Support\Workspace;

/**
 * Wires the plugin together.
 */
final class Plugin {

    /**
     * Daily housekeeping hook.
     */
    private const CLEANUP_HOOK = 'github_sync_daily_cleanup';

    private static ?Plugin $instance = null;

    /**
     * Single instance.
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {}

    private function __clone() {}

    /**
     * Register everything the plugin needs at runtime.
     */
    public function init(): void {
        $this->load_textdomain();

        (new RestController())->init();

        if (is_admin()) {
            // Covers the case where the plugin files were updated in place and
            // the activation hook never ran again.
            Migrations::maybe_upgrade();
            self::add_capabilities();
            self::schedule_cleanup();

            (new Dashboard())->init();
        }

        add_action(self::CLEANUP_HOOK, [$this, 'run_cleanup']);
    }

    /**
     * Translations.
     */
    private function load_textdomain(): void {
        load_plugin_textdomain(
            'github-sync',
            false,
            dirname(plugin_basename(GITHUB_SYNC_FILE)) . '/languages/'
        );
    }

    /**
     * Create tables, grant capabilities and schedule housekeeping.
     */
    public static function activate(): void {
        Migrations::run();
        self::add_capabilities();
        Workspace::protect(Workspace::base_dir());
        self::schedule_cleanup();
    }

    /**
     * Make sure housekeeping is booked in.
     *
     * Called on activation and again from the admin, because a plugin updated
     * in place never runs its activation hook.
     */
    private static function schedule_cleanup(): void {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    /**
     * Stop housekeeping and drop cached credentials.
     */
    public static function deactivate(): void {
        $timestamp = wp_next_scheduled(self::CLEANUP_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CLEANUP_HOOK);
        }

        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        TokenProvider::flush_cache();
    }

    /**
     * Trim old logs, backups and abandoned run folders.
     */
    public function run_cleanup(): void {
        Log::prune((int) Settings::get('log_retention_days', 30));
        Rollback::prune();
        Workspace::cleanup_old_runs();
    }

    /**
     * Let administrators manage syncing.
     */
    private static function add_capabilities(): void {
        $role = get_role('administrator');

        if ($role && !$role->has_cap(Dashboard::CAPABILITY)) {
            $role->add_cap(Dashboard::CAPABILITY);
        }
    }
}
