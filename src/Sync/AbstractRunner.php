<?php

namespace GithubSync\Sync;

use GithubSync\Database\Models\Log;
use GithubSync\Database\Models\Mapping;
use GithubSync\Database\Models\Run;
use GithubSync\GitHub\Client;
use GithubSync\GitHub\Connection;
use GithubSync\Security\PathValidator;
use GithubSync\Support\ExclusionFilter;
use GithubSync\Support\Settings;
use GithubSync\Support\Workspace;
use WP_Error;

/**
 * Shared machinery for the pull and push runners.
 *
 * A run is a small state machine. Every call to step() does a bounded amount of
 * work, saves its position, and returns, so the browser can drive a sync of any
 * size without ever hitting the PHP time limit.
 */
abstract class AbstractRunner {

    protected Mapping $mapping;
    protected Run $run;
    protected Workspace $workspace;
    protected ExclusionFilter $filter;
    protected int $batch_size;
    protected int $max_file_bytes;

    /**
     * Wall clock moment this step must stop working.
     */
    protected float $deadline;

    public function __construct(Mapping $mapping, Run $run) {
        $this->mapping = $mapping;
        $this->run = $run;
        $this->workspace = Workspace::for_run($run->id);
        $this->filter = ExclusionFilter::for_rules($mapping->exclusions);
        $this->batch_size = Settings::batch_size();
        $this->max_file_bytes = Settings::max_file_bytes();
        $this->deadline = microtime(true) + Settings::step_seconds();
    }

    /**
     * Advance the run by one step.
     */
    abstract public function step(): void;

    /**
     * True once this step has used its time budget.
     */
    protected function out_of_time(): bool {
        return microtime(true) >= $this->deadline;
    }

    /**
     * The destination folder, validated once per run.
     *
     * @return string|WP_Error
     */
    protected function destination_path() {
        $cached = (string) $this->run->get('dest_path', '');

        if ($cached !== '') {
            return $cached;
        }

        $validated = PathValidator::validate_destination($this->mapping->dest_path, $this->mapping->dest_type);

        if (is_wp_error($validated)) {
            return $validated;
        }

        $this->run->set(['dest_path' => $validated]);

        return $validated;
    }

    /**
     * An authenticated GitHub client for this run.
     *
     * @return Client|WP_Error
     */
    protected function github_client() {
        return Connection::client();
    }

    /**
     * Move the run to its next phase and reset the cursor.
     */
    protected function enter_phase(string $phase): void {
        $this->run->phase = $phase;
        $this->run->set(['cursor' => 0]);
    }

    /**
     * Current position inside the phase.
     */
    protected function cursor(): int {
        return (int) $this->run->get('cursor', 0);
    }

    /**
     * Save the position reached inside the phase.
     */
    protected function set_cursor(int $cursor): void {
        $this->run->set(['cursor' => $cursor]);
    }

    /**
     * Count work that has been done, for the progress bar.
     */
    protected function add_progress(int $items): void {
        $this->run->processed_items += max(0, $items);
    }

    /**
     * Merge counters into the run summary shown when it finishes.
     *
     * @param array<string, int> $counts
     */
    protected function add_summary(array $counts): void {
        $summary = (array) $this->run->get('summary', []);

        foreach ($counts as $key => $value) {
            $summary[$key] = (int) ($summary[$key] ?? 0) + (int) $value;
        }

        $this->run->set(['summary' => $summary]);
    }

    /**
     * Stop the run with an error.
     */
    protected function fail($error): void {
        $message = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
        $phase = $this->run->phase;

        $this->run->finish(Run::STATUS_FAILED, $message);
        $this->log(Log::LEVEL_ERROR, $message, array_merge($this->run_context(), [
            'phase' => Run::phase_label($this->run->type, $phase),
        ]));
        $this->cleanup();
    }

    /**
     * Finish the run successfully.
     */
    protected function complete(string $message): void {
        $this->run->phase = 'done';
        $this->run->processed_items = $this->run->total_items;
        $this->run->finish(Run::STATUS_COMPLETED);

        $summary = (array) $this->run->get('summary', []);
        $counts = [];

        foreach (['added', 'updated', 'deleted', 'unchanged', 'skipped'] as $key) {
            if (!empty($summary[$key])) {
                $counts[$key] = (int) $summary[$key];
            }
        }

        $this->log(Log::LEVEL_INFO, $message, array_merge($this->run_context(), $counts));
        $this->cleanup();
    }

    /**
     * Details worth recording with every finished run, so the log entry stands
     * on its own without needing the run row next to it.
     *
     * @return array<string, mixed>
     */
    protected function run_context(): array {
        $context = [
            'direction'   => $this->run->type,
            'repository'  => $this->mapping->repo_full_name(),
            'branch'      => $this->mapping->branch,
            'destination' => $this->mapping->dest_type . ': ' . $this->mapping->dest_path,
        ];

        if ($this->mapping->source_path !== '') {
            $context['repository_folder'] = $this->mapping->source_path;
        }

        if (!empty($this->run->commit_message)) {
            $context['commit_message'] = $this->run->commit_message;
        }

        if (!empty($this->run->commit_sha)) {
            $context['commit'] = substr((string) $this->run->commit_sha, 0, 10);
            $context['commit_url'] = sprintf(
                'https://github.com/%s/%s/commit/%s',
                rawurlencode($this->mapping->repo_owner),
                rawurlencode($this->mapping->repo_name),
                rawurlencode((string) $this->run->commit_sha)
            );
        }

        return $context;
    }

    /**
     * Remove the scratch folder for this run.
     */
    protected function cleanup(): void {
        $this->workspace->cleanup();
    }

    /**
     * Write a log entry tied to this run.
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $level, string $message, array $context = []): void {
        Log::write($level, $message, $context, $this->mapping->id, $this->run->id);
    }

    /**
     * Ensure a folder exists, reporting a readable error when it cannot.
     *
     * @return true|WP_Error
     */
    protected function ensure_directory(string $dir) {
        if (is_dir($dir)) {
            return true;
        }

        if (!wp_mkdir_p($dir)) {
            return new WP_Error(
                'mkdir_failed',
                sprintf(
                    /* translators: %s: folder path. */
                    __('The folder %s could not be created. Check the file permissions on your WordPress installation.', 'github-sync'),
                    $dir
                )
            );
        }

        return true;
    }

    /**
     * Permissions WordPress would use for a new file.
     */
    protected function file_permissions(): int {
        return defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
    }
}
