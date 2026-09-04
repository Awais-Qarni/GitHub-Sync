<?php

namespace GithubSync\Sync;

use GithubSync\Database\Models\Log;
use GithubSync\Database\Models\Mapping;
use GithubSync\Database\Models\Run;
use GithubSync\Support\Settings;
use GithubSync\Support\Workspace;
use WP_Error;

/**
 * Starts manual syncs and drives them forward one step at a time.
 *
 * There is no background scheduler: the admin screen calls step() until the run
 * reports that it has finished, which keeps every sync visible and cancellable.
 */
class SyncManager {

    private const LOCK_PREFIX = 'github_sync_lock_';

    private const LOCK_TTL = 600;

    /**
     * Longest commit message accepted from the form.
     */
    private const MAX_COMMIT_MESSAGE = 1000;

    /**
     * Begin a pull or a push.
     *
     * @return Run|WP_Error
     */
    public static function start(Mapping $mapping, string $type, string $commit_message = '') {
        if (!$mapping->enabled) {
            return new WP_Error('mapping_disabled', __('This mapping is paused. Enable it before syncing.', 'github-sync'));
        }

        if ($type === Run::TYPE_PULL && !$mapping->allows_pull()) {
            return new WP_Error('direction_not_allowed', __('This mapping is set to push only.', 'github-sync'));
        }

        if ($type === Run::TYPE_PUSH && !$mapping->allows_push()) {
            return new WP_Error('direction_not_allowed', __('This mapping is set to pull only.', 'github-sync'));
        }

        $blocking = self::blocking_run($mapping->id);

        if ($blocking instanceof WP_Error) {
            return $blocking;
        }

        $message = null;

        if ($type === Run::TYPE_PUSH) {
            $message = self::clean_commit_message($commit_message);

            if ($message === '') {
                return new WP_Error('empty_commit_message', __('Enter a commit message before pushing.', 'github-sync'));
            }
        }

        $phase = $type === Run::TYPE_PUSH ? PushRunner::FIRST_PHASE : PullRunner::FIRST_PHASE;
        $run = Run::start($mapping->id, $type, $phase, $message);

        if ($run->id <= 0) {
            return new WP_Error('run_not_created', __('The sync could not be started. Check the database tables are installed.', 'github-sync'));
        }

        self::lock($mapping->id, $run->id);

        return $run;
    }

    /**
     * Do the next slice of work for a run.
     *
     * @return Run|WP_Error
     */
    public static function step(Run $run) {
        if (!$run->is_running()) {
            return $run;
        }

        $mapping = Mapping::find($run->mapping_id);

        if (!$mapping) {
            $run->finish(Run::STATUS_FAILED, __('The mapping for this sync no longer exists.', 'github-sync'));
            self::unlock($run->mapping_id);

            return $run;
        }

        $holder = self::lock_holder($mapping->id);

        if ($holder > 0 && $holder !== $run->id) {
            return new WP_Error('sync_locked', __('Another sync is already running for this mapping.', 'github-sync'));
        }

        self::lock($mapping->id, $run->id);
        self::prepare_request_limits();

        $runner = $run->type === Run::TYPE_PUSH ? new PushRunner($mapping, $run) : new PullRunner($mapping, $run);

        try {
            $runner->step();
        } catch (\Throwable $exception) {
            $run->finish(Run::STATUS_FAILED, $exception->getMessage());
            Log::error(
                __('The sync stopped because of an unexpected error.', 'github-sync'),
                ['error' => $exception->getMessage()],
                $mapping->id,
                $run->id
            );
        }

        // The dashboard can cancel while this step is still working. Saving now
        // would put the run back to "running", so the cancellation is honoured
        // instead of being overwritten.
        $current = Run::find($run->id);

        if ($current && !$current->is_running() && $run->is_running()) {
            self::unlock($mapping->id);
            Workspace::remove_run($run->id);

            return $current;
        }

        $run->touch();
        $run->save();

        if (!$run->is_running()) {
            self::unlock($mapping->id);
            Workspace::remove_run($run->id);
        }

        return $run;
    }

    /**
     * Stop a run that is in progress.
     */
    public static function cancel(Run $run): Run {
        if ($run->is_running()) {
            $run->phase = 'done';
            $run->finish(Run::STATUS_CANCELLED, __('Cancelled from the dashboard.', 'github-sync'));
            Log::warning(__('Sync cancelled.', 'github-sync'), [], $run->mapping_id, $run->id);
        }

        self::unlock($run->mapping_id);
        Workspace::remove_run($run->id);

        return $run;
    }

    /**
     * Check whether another run stands in the way, releasing abandoned ones.
     *
     * @return true|WP_Error
     */
    private static function blocking_run(int $mapping_id) {
        $active = Run::active_for_mapping($mapping_id);

        if (!$active) {
            self::unlock($mapping_id);

            return true;
        }

        if ($active->is_stale()) {
            $active->finish(
                Run::STATUS_FAILED,
                __('The sync stopped responding, most likely because the browser tab was closed.', 'github-sync')
            );
            Log::warning(
                __('An unfinished sync was cleared so a new one could start.', 'github-sync'),
                [],
                $mapping_id,
                $active->id
            );
            self::unlock($mapping_id);
            Workspace::remove_run($active->id);

            return true;
        }

        return new WP_Error(
            'sync_in_progress',
            __('A sync is already running for this mapping. Wait for it to finish or cancel it first.', 'github-sync'),
            ['run_id' => $active->id]
        );
    }

    /**
     * Trim and shorten a commit message, falling back to the configured default.
     */
    public static function clean_commit_message(string $message): string {
        $message = trim(wp_strip_all_tags($message));

        if ($message === '') {
            $message = trim((string) Settings::get('default_commit_message', ''));
        }

        $message = str_replace(["\r\n", "\r"], "\n", $message);

        if (function_exists('mb_substr')) {
            return trim(mb_substr($message, 0, self::MAX_COMMIT_MESSAGE));
        }

        return trim(substr($message, 0, self::MAX_COMMIT_MESSAGE));
    }

    /**
     * Give the step room to finish without the server cutting it off.
     */
    private static function prepare_request_limits(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(Settings::step_seconds() + 60);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    private static function lock_key(int $mapping_id): string {
        return self::LOCK_PREFIX . $mapping_id;
    }

    private static function lock(int $mapping_id, int $run_id): void {
        set_transient(self::lock_key($mapping_id), $run_id, self::LOCK_TTL);
    }

    private static function unlock(int $mapping_id): void {
        delete_transient(self::lock_key($mapping_id));
    }

    private static function lock_holder(int $mapping_id): int {
        return (int) get_transient(self::lock_key($mapping_id));
    }
}
