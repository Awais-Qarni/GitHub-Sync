<?php

namespace GithubSync\Database\Models;

use GithubSync\Database\Migrations;
use GithubSync\Support\Dates;

/**
 * One manual sync run: a pull or a push, processed in steps.
 */
class Run {

    public const TYPE_PULL = 'pull';
    public const TYPE_PUSH = 'push';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * A run whose last step is older than this is treated as abandoned,
     * for example when the admin closed the browser tab mid sync.
     */
    public const STALE_AFTER_SECONDS = 300;

    public int $id = 0;
    public int $mapping_id = 0;
    public string $type = self::TYPE_PULL;
    public string $status = self::STATUS_RUNNING;
    public string $phase = '';
    public ?string $commit_sha = null;
    public ?string $prev_commit_sha = null;
    public ?string $commit_message = null;
    public int $total_items = 0;
    public int $processed_items = 0;
    public ?string $backup_path = null;
    public array $state = [];
    public ?string $error = null;
    public int $started_by = 0;
    public string $created_at = '';
    public string $updated_at = '';
    public ?string $completed_at = null;

    /**
     * Table name.
     */
    public static function table_name(): string {
        return Migrations::runs_table();
    }

    /**
     * Start a new run record.
     */
    public static function start(int $mapping_id, string $type, string $phase, ?string $commit_message = null): self {
        $run = new self();
        $run->mapping_id = $mapping_id;
        $run->type = $type === self::TYPE_PUSH ? self::TYPE_PUSH : self::TYPE_PULL;
        $run->status = self::STATUS_RUNNING;
        $run->phase = $phase;
        $run->commit_message = $commit_message;
        $run->started_by = get_current_user_id();
        $run->touch();
        $run->save();

        return $run;
    }

    /**
     * Find one run by primary key.
     */
    public static function find(int $id): ?self {
        global $wpdb;

        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        return $row ? self::hydrate($row) : null;
    }

    /**
     * The run currently in progress for a mapping, if any.
     */
    public static function active_for_mapping(int $mapping_id): ?self {
        global $wpdb;

        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE mapping_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
                $mapping_id,
                self::STATUS_RUNNING
            )
        );

        return $row ? self::hydrate($row) : null;
    }

    /**
     * Recent runs, newest first.
     *
     * @return self[]
     */
    public static function recent(int $mapping_id = 0, int $limit = 10): array {
        global $wpdb;

        $table = self::table_name();
        $limit = max(1, min(100, $limit));

        if ($mapping_id > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table WHERE mapping_id = %d ORDER BY id DESC LIMIT %d", $mapping_id, $limit)
            );
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit));
        }

        return array_map([self::class, 'hydrate'], $rows ?: []);
    }

    /**
     * Build an object from a database row.
     */
    private static function hydrate(object $row): self {
        $run = new self();
        $run->id = (int) $row->id;
        $run->mapping_id = (int) $row->mapping_id;
        $run->type = (string) $row->type;
        $run->status = (string) $row->status;
        $run->phase = (string) $row->phase;
        $run->commit_sha = $row->commit_sha;
        $run->prev_commit_sha = $row->prev_commit_sha;
        $run->commit_message = $row->commit_message;
        $run->total_items = (int) $row->total_items;
        $run->processed_items = (int) $row->processed_items;
        $run->backup_path = $row->backup_path;
        $run->state = $row->state ? (json_decode($row->state, true) ?: []) : [];
        $run->error = $row->error;
        $run->started_by = (int) $row->started_by;
        $run->created_at = (string) $row->created_at;
        $run->updated_at = (string) $row->updated_at;
        $run->completed_at = $row->completed_at;

        return $run;
    }

    /**
     * Insert or update the record.
     */
    public function save(): bool {
        global $wpdb;

        $table = self::table_name();

        $data = [
            'mapping_id'      => $this->mapping_id,
            'type'            => $this->type,
            'status'          => $this->status,
            'phase'           => $this->phase,
            'commit_sha'      => $this->commit_sha,
            'prev_commit_sha' => $this->prev_commit_sha,
            'commit_message'  => $this->commit_message,
            'total_items'     => $this->total_items,
            'processed_items' => $this->processed_items,
            'backup_path'     => $this->backup_path,
            'state'           => wp_json_encode($this->state),
            'error'           => $this->error,
            'started_by'      => $this->started_by,
            'completed_at'    => $this->completed_at,
            // Timestamps are stored in GMT so display can apply the site's timezone once.
            'updated_at'      => Dates::now(),
        ];

        $format = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s'];

        if ($this->id > 0) {
            return $wpdb->update($table, $data, ['id' => $this->id], $format, ['%d']) !== false;
        }

        $data['created_at'] = Dates::now();
        $format[] = '%s';

        $inserted = $wpdb->insert($table, $data, $format);

        if ($inserted) {
            $this->id = (int) $wpdb->insert_id;
        }

        return $inserted !== false;
    }

    /**
     * Read one key out of the run's saved state.
     *
     * @return mixed
     */
    public function get(string $key, $fallback = null) {
        return $this->state[$key] ?? $fallback;
    }

    /**
     * Write keys into the run's saved state.
     *
     * @param array<string, mixed> $values
     */
    public function set(array $values): void {
        $this->state = array_merge($this->state, $values);
    }

    /**
     * Mark the run finished.
     */
    public function finish(string $status, ?string $error = null): void {
        $this->status = $status;
        $this->error = $error;
        $this->completed_at = Dates::now();
        $this->save();
    }

    /**
     * True when the run is still in progress.
     */
    public function is_running(): bool {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * True when a running record has not been stepped for a while.
     *
     * The heartbeat is a Unix timestamp kept in the run state, so this does not
     * depend on how the database server reports time.
     */
    public function is_stale(): bool {
        if (!$this->is_running()) {
            return false;
        }

        $heartbeat = (int) $this->get('last_step_at', 0);

        if ($heartbeat <= 0) {
            return false;
        }

        return (time() - $heartbeat) > self::STALE_AFTER_SECONDS;
    }

    /**
     * Record that a step just ran.
     */
    public function touch(): void {
        $this->set(['last_step_at' => time()]);
    }

    /**
     * Rough completion percentage for the progress bar.
     */
    public function percent(): int {
        if ($this->status === self::STATUS_COMPLETED) {
            return 100;
        }

        if ($this->total_items <= 0) {
            return 0;
        }

        return (int) min(99, floor(($this->processed_items / $this->total_items) * 100));
    }

    /**
     * Shape used by the admin REST responses.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'id'              => $this->id,
            'mapping_id'      => $this->mapping_id,
            'type'            => $this->type,
            'status'          => $this->status,
            'phase'           => $this->phase,
            'phase_label'     => self::phase_label($this->type, $this->phase),
            'commit_sha'      => $this->commit_sha,
            'commit_message'  => $this->commit_message,
            'total_items'     => $this->total_items,
            'processed_items' => $this->processed_items,
            'percent'         => $this->percent(),
            'error'           => $this->error,
            'summary'         => $this->get('summary', []),
            'created_at'      => Dates::format($this->created_at),
            'completed_at'    => Dates::format($this->completed_at),
            'is_running'      => $this->is_running(),
        ];
    }

    /**
     * Human readable name for the current phase.
     */
    public static function phase_label(string $type, string $phase): string {
        $shared = [
            'prepare'  => __('Comparing with GitHub', 'github-sync'),
            'finalize' => __('Finishing up', 'github-sync'),
            'done'     => __('Done', 'github-sync'),
        ];

        $pull = [
            'stage'  => __('Downloading files', 'github-sync'),
            'backup' => __('Backing up current files', 'github-sync'),
            'apply'  => __('Writing files', 'github-sync'),
            'remove' => __('Removing deleted files', 'github-sync'),
        ];

        $push = [
            'scan'   => __('Listing local files', 'github-sync'),
            'hash'   => __('Checking for local changes', 'github-sync'),
            'build'  => __('Uploading changed files', 'github-sync'),
            'purge'  => __('Recording deleted files', 'github-sync'),
            'commit' => __('Creating the commit', 'github-sync'),
        ];

        $labels = array_merge($shared, $type === self::TYPE_PUSH ? $push : $pull);

        return $labels[$phase] ?? ucfirst($phase);
    }
}
