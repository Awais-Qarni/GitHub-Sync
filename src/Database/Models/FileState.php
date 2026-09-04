<?php

namespace GithubSync\Database\Models;

use GithubSync\Database\Migrations;
use GithubSync\Support\Dates;

/**
 * Tracks the Git blob hash of every synced file, which is how the plugin knows
 * what actually changed on either side.
 *
 * Rows are keyed by (mapping_id, SHA1(file_path)) so lookups stay indexed even
 * with tens of thousands of files and long paths.
 */
class FileState {

    public const ORIGIN_GITHUB = 'github';
    public const ORIGIN_WORDPRESS = 'wordpress';

    /**
     * Rows written per INSERT statement.
     */
    private const WRITE_CHUNK = 200;

    /**
     * Table name.
     */
    public static function table_name(): string {
        return Migrations::file_state_table();
    }

    /**
     * Every tracked path for a mapping as path => hash.
     *
     * @return array<string, string>
     */
    public static function get_map(int $mapping_id): array {
        global $wpdb;

        $table = self::table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT file_path, hash FROM $table WHERE mapping_id = %d", $mapping_id)
        );

        $map = [];

        foreach ($rows ?: [] as $row) {
            $map[$row->file_path] = $row->hash;
        }

        return $map;
    }

    /**
     * Hashes for a specific set of paths as path => hash.
     *
     * @param string[] $paths
     * @return array<string, string>
     */
    public static function get_hashes(int $mapping_id, array $paths): array {
        global $wpdb;

        if (!$paths) {
            return [];
        }

        $table = self::table_name();
        $hashes = array_map([self::class, 'path_hash'], $paths);
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

        $sql = "SELECT file_path, hash FROM $table WHERE mapping_id = %d AND path_hash IN ($placeholders)";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$mapping_id], $hashes)));

        $map = [];

        foreach ($rows ?: [] as $row) {
            $map[$row->file_path] = $row->hash;
        }

        return $map;
    }

    /**
     * How many files are tracked for a mapping.
     */
    public static function count(int $mapping_id): int {
        global $wpdb;

        $table = self::table_name();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE mapping_id = %d", $mapping_id));
    }

    /**
     * Insert or update many rows at once.
     *
     * @param array<string, string> $paths_to_hashes Relative path => Git blob hash.
     */
    public static function put_many(int $mapping_id, array $paths_to_hashes, string $origin = self::ORIGIN_GITHUB): void {
        global $wpdb;

        if (!$paths_to_hashes) {
            return;
        }

        $table = self::table_name();
        $now = Dates::now();

        foreach (array_chunk($paths_to_hashes, self::WRITE_CHUNK, true) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $path => $hash) {
                $values[] = '(%d, %s, %s, %s, %s, %s)';
                $params[] = $mapping_id;
                $params[] = self::path_hash((string) $path);
                $params[] = (string) $path;
                $params[] = (string) $hash;
                $params[] = $origin;
                $params[] = $now;
            }

            $sql = "INSERT INTO $table (mapping_id, path_hash, file_path, hash, origin, updated_at) VALUES "
                . implode(', ', $values)
                . ' ON DUPLICATE KEY UPDATE hash = VALUES(hash), origin = VALUES(origin), updated_at = VALUES(updated_at)';

            $wpdb->query($wpdb->prepare($sql, $params));
        }
    }

    /**
     * Remove tracking rows for the given paths.
     *
     * @param string[] $paths
     */
    public static function delete_many(int $mapping_id, array $paths): void {
        global $wpdb;

        if (!$paths) {
            return;
        }

        $table = self::table_name();

        foreach (array_chunk($paths, self::WRITE_CHUNK) as $chunk) {
            $hashes = array_map([self::class, 'path_hash'], $chunk);
            $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
            $sql = "DELETE FROM $table WHERE mapping_id = %d AND path_hash IN ($placeholders)";

            $wpdb->query($wpdb->prepare($sql, array_merge([$mapping_id], $hashes)));
        }
    }

    /**
     * Forget everything tracked for a mapping.
     */
    public static function clear(int $mapping_id): void {
        global $wpdb;

        $wpdb->delete(self::table_name(), ['mapping_id' => $mapping_id], ['%d']);
    }

    /**
     * The indexed lookup key for a path.
     */
    public static function path_hash(string $path): string {
        return sha1($path);
    }
}
