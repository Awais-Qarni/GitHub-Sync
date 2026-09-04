<?php

namespace GithubSync\Database;

/**
 * Creates and upgrades the plugin's database tables.
 */
class Migrations {

    public const DB_VERSION = '2.0.0';

    public const VERSION_OPTION = 'github_sync_db_version';

    /**
     * Run migrations when the stored schema version is behind.
     */
    public static function maybe_upgrade(): void {
        if (get_option(self::VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        self::run();
    }

    /**
     * Create or update every table, then apply data fixes.
     */
    public static function run(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::get_schema($wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }

        self::upgrade_from_v1();

        update_option(self::VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Mappings table name.
     */
    public static function mappings_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'github_sync_mappings';
    }

    /**
     * Sync runs table name.
     */
    public static function runs_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'github_sync_runs';
    }

    /**
     * Logs table name.
     */
    public static function logs_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'github_sync_logs';
    }

    /**
     * File tracking table name.
     */
    public static function file_state_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'github_sync_file_state';
    }

    /**
     * Every table this plugin owns.
     *
     * @return string[]
     */
    private static function get_schema(string $charset_collate): array {
        $tables = [];

        $mappings = self::mappings_table();
        $tables[] = "CREATE TABLE $mappings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id varchar(64) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            repo_owner varchar(255) NOT NULL,
            repo_name varchar(255) NOT NULL,
            branch varchar(255) NOT NULL,
            source_path text NOT NULL,
            dest_path text NOT NULL,
            dest_type varchar(50) NOT NULL,
            sync_direction varchar(20) NOT NULL DEFAULT 'both',
            delete_policy tinyint(1) NOT NULL DEFAULT 0,
            exclusions longtext,
            last_commit_sha varchar(40) DEFAULT NULL,
            last_synced_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id)
        ) $charset_collate;";

        $runs = self::runs_table();
        $tables[] = "CREATE TABLE $runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mapping_id bigint(20) unsigned NOT NULL,
            type varchar(10) NOT NULL DEFAULT 'pull',
            status varchar(20) NOT NULL DEFAULT 'running',
            phase varchar(30) NOT NULL DEFAULT '',
            commit_sha varchar(40) DEFAULT NULL,
            prev_commit_sha varchar(40) DEFAULT NULL,
            commit_message text,
            total_items int(10) unsigned NOT NULL DEFAULT 0,
            processed_items int(10) unsigned NOT NULL DEFAULT 0,
            backup_path text,
            state longtext,
            error text,
            started_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY mapping_id (mapping_id),
            KEY status (status)
        ) $charset_collate;";

        $logs = self::logs_table();
        $tables[] = "CREATE TABLE $logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mapping_id bigint(20) unsigned DEFAULT NULL,
            run_id bigint(20) unsigned DEFAULT NULL,
            level varchar(20) NOT NULL,
            message longtext NOT NULL,
            context longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY mapping_id (mapping_id),
            KEY run_id (run_id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";

        $file_state = self::file_state_table();
        $tables[] = "CREATE TABLE $file_state (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mapping_id bigint(20) unsigned NOT NULL,
            path_hash char(40) NOT NULL DEFAULT '',
            file_path text NOT NULL,
            hash varchar(64) NOT NULL,
            origin varchar(20) NOT NULL DEFAULT 'github',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY mapping_path (mapping_id, path_hash)
        ) $charset_collate;";

        return $tables;
    }

    /**
     * Bring a 1.x install up to date: fill in path hashes, drop duplicate
     * tracking rows, and remove the columns and tables that webhook driven
     * automatic deployment used to need.
     */
    private static function upgrade_from_v1(): void {
        global $wpdb;

        $file_state = self::file_state_table();

        if (self::table_exists($file_state)) {
            // 1.x inserted a new row per sync because the table had no unique key.
            $wpdb->query(
                "DELETE older FROM $file_state AS older
                 INNER JOIN $file_state AS newer
                 ON older.mapping_id = newer.mapping_id
                 AND older.file_path = newer.file_path
                 AND older.id < newer.id"
            );

            $wpdb->query("UPDATE $file_state SET path_hash = SHA1(file_path) WHERE path_hash = '' OR path_hash IS NULL");

            if (!self::index_exists($file_state, 'mapping_path')) {
                $wpdb->query("ALTER TABLE $file_state ADD UNIQUE KEY mapping_path (mapping_id, path_hash)");
            }

            if (self::index_exists($file_state, 'mapping_id')) {
                $wpdb->query("ALTER TABLE $file_state DROP INDEX mapping_id");
            }
        }

        $mappings = self::mappings_table();

        if (self::column_exists($mappings, 'auto_deploy')) {
            $wpdb->query("ALTER TABLE $mappings DROP COLUMN auto_deploy");
        }

        if (self::table_exists($mappings)) {
            $wpdb->query("UPDATE $mappings SET sync_direction = 'both' WHERE sync_direction NOT IN ('both', 'pull', 'push')");
        }

        // 1.x deployment history belonged to the webhook pipeline and has no
        // counterpart in the manual runs table.
        foreach (['github_sync_deployments', 'github_sync_jobs'] as $legacy) {
            $table = $wpdb->prefix . $legacy;

            if (self::table_exists($table)) {
                $wpdb->query("DROP TABLE IF EXISTS $table");
            }
        }

        self::drop_legacy_settings();
    }

    /**
     * Remove settings that only the webhook listener used.
     */
    private static function drop_legacy_settings(): void {
        $settings = get_option('github_sync_settings', []);

        if (!is_array($settings) || !array_key_exists('webhook_secret', $settings)) {
            return;
        }

        unset($settings['webhook_secret']);
        update_option('github_sync_settings', $settings, false);
    }

    private static function table_exists(string $table): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    private static function column_exists(string $table, string $column): bool {
        global $wpdb;

        if (!self::table_exists($table)) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $column));
    }

    private static function index_exists(string $table, string $index): bool {
        global $wpdb;

        if (!self::table_exists($table)) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM $table WHERE Key_name = %s", $index));
    }

    /**
     * Drop every table this plugin owns. Used by uninstall.
     */
    public static function drop_all(): void {
        global $wpdb;

        $tables = [
            self::file_state_table(),
            self::logs_table(),
            self::runs_table(),
            self::mappings_table(),
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
}
