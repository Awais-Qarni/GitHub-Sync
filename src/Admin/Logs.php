<?php

namespace GithubSync\Admin;

use GithubSync\Database\Models\Log;
use GithubSync\Database\Models\Mapping;

/**
 * The Logs screen: what every manual sync did, and what went wrong.
 */
class Logs {

    private const NONCE_ACTION = 'github_sync_clear_logs';
    private const NONCE_FIELD = 'github_sync_logs_nonce';

    /**
     * Render the screen.
     */
    public function render(): void {
        if (!current_user_can(Dashboard::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage GitHub Sync.', 'github-sync'));
        }

        $this->maybe_clear();

        $filters = [
            'level'      => $this->requested_level(),
            'mapping_id' => isset($_GET['mapping_id']) ? absint($_GET['mapping_id']) : 0,
            'run_id'     => isset($_GET['run_id']) ? absint($_GET['run_id']) : 0,
            'search'     => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'page'       => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
            'per_page'   => 30,
        ];

        $results = Log::query($filters);
        $mappings = Mapping::all();
        $mapping_names = [];

        foreach ($mappings as $mapping) {
            $mapping_names[$mapping->id] = $mapping->repo_full_name();
        }

        $clear_url = wp_nonce_url(
            add_query_arg(['page' => Dashboard::PAGE_LOGS, 'github_sync_clear' => 1], admin_url('admin.php')),
            self::NONCE_ACTION,
            self::NONCE_FIELD
        );

        require GITHUB_SYNC_PLUGIN_DIR . 'templates/admin/logs.php';
    }

    /**
     * Handle the "clear log" link.
     */
    private function maybe_clear(): void {
        if (empty($_GET['github_sync_clear']) || empty($_GET[self::NONCE_FIELD])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_GET[self::NONCE_FIELD]));

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            add_settings_error('github_sync_logs', 'nonce', __('That link expired. Please try again.', 'github-sync'), 'error');

            return;
        }

        $deleted = Log::clear();

        add_settings_error(
            'github_sync_logs',
            'cleared',
            sprintf(
                /* translators: %d: number of deleted log entries. */
                _n('%d log entry deleted.', '%d log entries deleted.', $deleted, 'github-sync'),
                $deleted
            ),
            'updated'
        );
    }

    /**
     * Turn a stored log context into labelled rows the template can print.
     *
     * Raw JSON is unreadable in a table, and a half shown blob is worse than
     * nothing, so every value is flattened to text here.
     *
     * @param array<string, mixed> $context
     * @return array<int, array{label: string, value: string, url: string}>
     */
    public static function context_rows(array $context): array {
        $labels = [
            'direction'         => __('Direction', 'github-sync'),
            'repository'        => __('Repository', 'github-sync'),
            'repository_folder' => __('Folder in repository', 'github-sync'),
            'branch'            => __('Branch', 'github-sync'),
            'destination'       => __('Destination', 'github-sync'),
            'commit'            => __('Commit', 'github-sync'),
            'commit_sha'        => __('Commit', 'github-sync'),
            'commit_url'        => __('View on GitHub', 'github-sync'),
            'commit_message'    => __('Commit message', 'github-sync'),
            'added'             => __('Files added', 'github-sync'),
            'updated'           => __('Files updated', 'github-sync'),
            'deleted'           => __('Files deleted', 'github-sync'),
            'unchanged'         => __('Files already in sync', 'github-sync'),
            'skipped'           => __('Files skipped', 'github-sync'),
            'files_changed'     => __('Files changed', 'github-sync'),
            'files'             => __('Files', 'github-sync'),
            'paths'             => __('Paths', 'github-sync'),
            'phase'             => __('Step reached', 'github-sync'),
            'error'             => __('Error', 'github-sync'),
            'backup'            => __('Backup archive', 'github-sync'),
        ];

        $rows = [];

        foreach ($context as $key => $value) {
            $label = $labels[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
            $url = '';

            if (is_array($value)) {
                $shown = array_slice($value, 0, 25);
                $text = implode("\n", array_map('strval', $shown));

                if (count($value) > count($shown)) {
                    $text .= "\n" . sprintf(
                        /* translators: %d: number of further entries. */
                        __('and %d more', 'github-sync'),
                        count($value) - count($shown)
                    );
                }
            } elseif (is_bool($value)) {
                $text = $value ? __('Yes', 'github-sync') : __('No', 'github-sync');
            } else {
                $text = (string) $value;

                if (preg_match('#^https?://#', $text)) {
                    $url = esc_url_raw($text);
                }
            }

            if (trim($text) === '') {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $text, 'url' => $url];
        }

        return $rows;
    }

    /**
     * The level filter, restricted to the levels the plugin writes.
     */
    private function requested_level(): string {
        $level = isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '';
        $allowed = [Log::LEVEL_INFO, Log::LEVEL_WARNING, Log::LEVEL_ERROR];

        return in_array($level, $allowed, true) ? $level : '';
    }
}
