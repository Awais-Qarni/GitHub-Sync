<?php

namespace GithubSync\Database\Models;

use GithubSync\Database\Migrations;
use GithubSync\Support\Dates;

/**
 * A configured link between a GitHub repository folder and a WordPress folder.
 */
class Mapping {

    public const DIRECTION_BOTH = 'both';
    public const DIRECTION_PULL = 'pull';
    public const DIRECTION_PUSH = 'push';

    public int $id = 0;
    public string $public_id = '';
    public bool $enabled = true;
    public string $repo_owner = '';
    public string $repo_name = '';
    public string $branch = '';
    public string $source_path = '';
    public string $dest_path = '';
    public string $dest_type = 'custom';
    public string $sync_direction = self::DIRECTION_BOTH;
    public bool $delete_policy = false;
    public array $exclusions = [];
    public ?string $last_commit_sha = null;
    public ?string $last_synced_at = null;
    public string $created_at = '';
    public string $updated_at = '';

    /**
     * Table name.
     */
    public static function table_name(): string {
        return Migrations::mappings_table();
    }

    /**
     * Find one mapping by primary key.
     */
    public static function find(int $id): ?self {
        global $wpdb;

        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        return $row ? self::hydrate($row) : null;
    }

    /**
     * All mappings, newest first.
     *
     * @return self[]
     */
    public static function all(): array {
        global $wpdb;

        $table = self::table_name();
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");

        return array_map([self::class, 'hydrate'], $rows ?: []);
    }

    /**
     * True when another mapping already writes to the same destination.
     */
    public static function destination_taken(string $dest_type, string $dest_path, int $ignore_id = 0): bool {
        global $wpdb;

        $table = self::table_name();

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE dest_type = %s AND dest_path = %s AND id != %d LIMIT 1",
                $dest_type,
                $dest_path,
                $ignore_id
            )
        );
    }

    /**
     * Build an object from a database row.
     */
    private static function hydrate(object $row): self {
        $mapping = new self();
        $mapping->id = (int) $row->id;
        $mapping->public_id = (string) $row->public_id;
        $mapping->enabled = (bool) $row->enabled;
        $mapping->repo_owner = (string) $row->repo_owner;
        $mapping->repo_name = (string) $row->repo_name;
        $mapping->branch = (string) $row->branch;
        $mapping->source_path = (string) $row->source_path;
        $mapping->dest_path = (string) $row->dest_path;
        $mapping->dest_type = (string) $row->dest_type;
        $mapping->sync_direction = (string) $row->sync_direction;
        $mapping->delete_policy = (bool) $row->delete_policy;
        $mapping->exclusions = self::decode_exclusions($row->exclusions ?? '');
        $mapping->last_commit_sha = $row->last_commit_sha;
        $mapping->last_synced_at = $row->last_synced_at;
        $mapping->created_at = (string) $row->created_at;
        $mapping->updated_at = (string) $row->updated_at;

        return $mapping;
    }

    /**
     * @return string[]
     */
    private static function decode_exclusions(?string $raw): array {
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * Insert or update the record.
     */
    public function save(): bool {
        global $wpdb;

        $table = self::table_name();

        if ($this->public_id === '') {
            $this->public_id = wp_generate_password(32, false);
        }

        $data = [
            'public_id'       => $this->public_id,
            'enabled'         => $this->enabled ? 1 : 0,
            'repo_owner'      => $this->repo_owner,
            'repo_name'       => $this->repo_name,
            'branch'          => $this->branch,
            'source_path'     => $this->source_path,
            'dest_path'       => $this->dest_path,
            'dest_type'       => $this->dest_type,
            'sync_direction'  => $this->sync_direction,
            'delete_policy'   => $this->delete_policy ? 1 : 0,
            'exclusions'      => wp_json_encode(array_values($this->exclusions)),
            'last_commit_sha' => $this->last_commit_sha,
            'last_synced_at'  => $this->last_synced_at,
        ];

        $format = ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'];

        if ($this->id > 0) {
            return $wpdb->update($table, $data, ['id' => $this->id], $format, ['%d']) !== false;
        }

        $inserted = $wpdb->insert($table, $data, $format);

        if ($inserted) {
            $this->id = (int) $wpdb->insert_id;
        }

        return $inserted !== false;
    }

    /**
     * Delete this mapping and everything that belongs to it.
     */
    public function delete(): bool {
        global $wpdb;

        if ($this->id <= 0) {
            return false;
        }

        $wpdb->delete(Migrations::file_state_table(), ['mapping_id' => $this->id], ['%d']);
        $wpdb->delete(Migrations::logs_table(), ['mapping_id' => $this->id], ['%d']);
        $wpdb->delete(Migrations::runs_table(), ['mapping_id' => $this->id], ['%d']);

        return (bool) $wpdb->delete(self::table_name(), ['id' => $this->id], ['%d']);
    }

    /**
     * Repository in "owner/name" form.
     */
    public function repo_full_name(): string {
        return $this->repo_owner . '/' . $this->repo_name;
    }

    /**
     * Whether this mapping may pull from GitHub.
     */
    public function allows_pull(): bool {
        return $this->sync_direction !== self::DIRECTION_PUSH;
    }

    /**
     * Whether this mapping may push to GitHub.
     */
    public function allows_push(): bool {
        return $this->sync_direction !== self::DIRECTION_PULL;
    }

    /**
     * Shape used by the admin REST responses.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'id'              => $this->id,
            'enabled'         => $this->enabled,
            'repo_owner'      => $this->repo_owner,
            'repo_name'       => $this->repo_name,
            'repo_full_name'  => $this->repo_full_name(),
            'branch'          => $this->branch,
            'source_path'     => $this->source_path,
            'dest_path'       => $this->dest_path,
            'dest_type'       => $this->dest_type,
            'sync_direction'  => $this->sync_direction,
            'delete_policy'   => $this->delete_policy,
            'exclusions'      => array_values($this->exclusions),
            'last_commit_sha' => $this->last_commit_sha,
            'last_synced_at'  => Dates::format($this->last_synced_at),
            'allows_pull'     => $this->allows_pull(),
            'allows_push'     => $this->allows_push(),
        ];
    }
}
