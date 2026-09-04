<?php

namespace GithubSync\Database\Models;

use GithubSync\Database\Migrations;
use GithubSync\Support\Dates;

/**
 * Activity log. Every manual sync writes here, and the admin Logs screen reads it.
 */
class Log {

    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public int $id = 0;
    public ?int $mapping_id = null;
    public ?int $run_id = null;
    public string $level = self::LEVEL_INFO;
    public string $message = '';
    public array $context = [];
    public string $created_at = '';

    /**
     * Table name.
     */
    public static function table_name(): string {
        return Migrations::logs_table();
    }

    /**
     * Write one entry.
     *
     * @param array<string, mixed> $context
     */
    public static function write(string $level, string $message, array $context = [], ?int $mapping_id = null, ?int $run_id = null): void {
        global $wpdb;

        $level = in_array($level, [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR], true) ? $level : self::LEVEL_INFO;

        $wpdb->insert(
            self::table_name(),
            [
                'mapping_id' => $mapping_id ?: null,
                'run_id'     => $run_id ?: null,
                'level'      => $level,
                'message'    => $message,
                'context'    => $context ? wp_json_encode($context) : null,
                // Stored in GMT so display can apply the site's timezone once.
                'created_at' => Dates::now(),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Convenience wrappers.
     *
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = [], ?int $mapping_id = null, ?int $run_id = null): void {
        self::write(self::LEVEL_INFO, $message, $context, $mapping_id, $run_id);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = [], ?int $mapping_id = null, ?int $run_id = null): void {
        self::write(self::LEVEL_WARNING, $message, $context, $mapping_id, $run_id);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = [], ?int $mapping_id = null, ?int $run_id = null): void {
        self::write(self::LEVEL_ERROR, $message, $context, $mapping_id, $run_id);
    }

    /**
     * Paginated query for the Logs screen.
     *
     * @param array{level?: string, mapping_id?: int, run_id?: int, search?: string, page?: int, per_page?: int} $args
     * @return array{items: self[], total: int, pages: int, page: int, per_page: int}
     */
    public static function query(array $args = []): array {
        global $wpdb;

        $table = self::table_name();
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(5, min(200, (int) ($args['per_page'] ?? 25)));

        $where = ['1=1'];
        $params = [];

        if (!empty($args['level'])) {
            $where[] = 'level = %s';
            $params[] = $args['level'];
        }

        if (!empty($args['mapping_id'])) {
            $where[] = 'mapping_id = %d';
            $params[] = (int) $args['mapping_id'];
        }

        if (!empty($args['run_id'])) {
            $where[] = 'run_id = %d';
            $params[] = (int) $args['run_id'];
        }

        if (!empty($args['search'])) {
            $where[] = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $args['search']) . '%';
        }

        // Both bounds are GMT, matching how created_at is stored.
        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = (string) $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = (string) $args['date_to'];
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $offset = ($page - 1) * $per_page;
        $list_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, [$per_page, $offset])));

        return [
            'items'    => array_map([self::class, 'hydrate'], $rows ?: []),
            'total'    => $total,
            'pages'    => (int) ceil($total / $per_page),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Build an object from a database row.
     */
    private static function hydrate(object $row): self {
        $log = new self();
        $log->id = (int) $row->id;
        $log->mapping_id = $row->mapping_id === null ? null : (int) $row->mapping_id;
        $log->run_id = $row->run_id === null ? null : (int) $row->run_id;
        $log->level = (string) $row->level;
        $log->message = (string) $row->message;
        $log->context = $row->context ? (json_decode($row->context, true) ?: []) : [];
        $log->created_at = (string) $row->created_at;

        return $log;
    }

    /**
     * Delete every entry, or only those for one mapping.
     */
    public static function clear(int $mapping_id = 0): int {
        global $wpdb;

        $table = self::table_name();

        if ($mapping_id > 0) {
            return (int) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE mapping_id = %d", $mapping_id));
        }

        return (int) $wpdb->query("DELETE FROM $table");
    }

    /**
     * Delete entries older than the retention window.
     */
    public static function prune(int $days): int {
        global $wpdb;

        if ($days <= 0) {
            return 0;
        }

        $table = self::table_name();

        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days)
        );
    }

    /**
     * Shape used by the admin REST responses.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'id'         => $this->id,
            'mapping_id' => $this->mapping_id,
            'run_id'     => $this->run_id,
            'level'      => $this->level,
            'message'    => $this->message,
            'context'    => $this->context,
            'created_at' => $this->created_at,
        ];
    }
}
