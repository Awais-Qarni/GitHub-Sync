<?php

namespace GithubSync\Sync;

use GithubSync\Database\Models\FileState;
use GithubSync\Database\Models\Log;
use GithubSync\GitHub\CommitApi;
use GithubSync\GitHub\TreeApi;
use GithubSync\Security\PathValidator;
use GithubSync\Support\Dates;
use GithubSync\Support\Settings;
use WP_Error;

/**
 * Sends WordPress side changes back to GitHub as a single commit.
 *
 * Files are compared by content hash first, so only what really changed is
 * uploaded. Tree entries are written to GitHub as the run progresses, and the
 * branch only moves once, at the end.
 */
class PushRunner extends AbstractRunner {

    public const FIRST_PHASE = 'prepare';

    /**
     * Files at or below this size travel inside the tree request itself, which
     * saves one API call per file.
     */
    private const INLINE_MAX_BYTES = 262144;

    /**
     * Rough payload ceiling for a single tree request.
     */
    private const TREE_FLUSH_BYTES = 3145728;

    /**
     * Advance the run.
     */
    public function step(): void {
        switch ($this->run->phase) {
            case 'prepare':
                $this->prepare();
                break;
            case 'scan':
                $this->scan();
                break;
            case 'hash':
                $this->hash();
                break;
            case 'build':
                $this->build();
                break;
            case 'purge':
                $this->purge();
                break;
            case 'commit':
                $this->commit();
                break;
            case 'finalize':
                $this->finalize();
                break;
            default:
                $this->fail(__('This sync reached an unknown state and was stopped.', 'github-sync'));
        }
    }

    /**
     * Check the folder and find the commit this push will build on.
     */
    private function prepare(): void {
        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            $this->fail($dest_path);
            return;
        }

        if (!is_dir($dest_path)) {
            $this->fail(
                sprintf(
                    /* translators: %s: folder path. */
                    __('The folder %s does not exist, so there is nothing to push.', 'github-sync'),
                    $dest_path
                )
            );
            return;
        }

        $client = $this->github_client();

        if (is_wp_error($client)) {
            $this->fail($client);
            return;
        }

        $commit_api = new CommitApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);
        $head = $commit_api->get_branch_head($this->mapping->branch);

        if (is_wp_error($head)) {
            $this->fail($head);
            return;
        }

        $commit = $commit_api->get_commit($head);

        if (is_wp_error($commit)) {
            $this->fail($commit);
            return;
        }

        if (empty($commit['tree']['sha'])) {
            $this->fail(__('GitHub did not report the file tree for the latest commit.', 'github-sync'));
            return;
        }

        $this->run->prev_commit_sha = $head;
        $this->run->set([
            'base_commit'  => $head,
            'base_tree'    => (string) $commit['tree']['sha'],
            'current_tree' => (string) $commit['tree']['sha'],
            'prefix'       => $this->source_prefix(),
        ]);

        $this->enter_phase('scan');
    }

    /**
     * List the local files, and the tracked files that disappeared.
     */
    private function scan(): void {
        $detector = $this->detector();

        if (is_wp_error($detector)) {
            $this->fail($detector);
            return;
        }

        $scan = $detector->list_files();
        $files = $scan['files'];

        $stored = $this->workspace->write_list('local', $files);

        if (is_wp_error($stored)) {
            $this->fail($stored);
            return;
        }

        if ($scan['skipped']) {
            $this->log(
                Log::LEVEL_WARNING,
                sprintf(
                    /* translators: %d: number of files. */
                    _n(
                        '%d file was skipped because it is larger than the size limit in Settings.',
                        '%d files were skipped because they are larger than the size limit in Settings.',
                        count($scan['skipped']),
                        'github-sync'
                    ),
                    count($scan['skipped'])
                ),
                ['files' => array_slice($scan['skipped'], 0, 50)]
            );
            $this->add_summary(['skipped' => count($scan['skipped'])]);
        }

        $deletes = [];

        if ($this->mapping->delete_policy) {
            $deletes = $detector->find_deleted($this->mapping->id);
            $stored = $this->workspace->write_list('deletes', $deletes);

            if (is_wp_error($stored)) {
                $this->fail($stored);
                return;
            }
        }

        $this->run->total_items = count($files) + count($deletes);
        $this->run->set([
            'local_count'  => count($files),
            'delete_count' => count($deletes),
            'upload_count' => 0,
        ]);

        $this->enter_phase('hash');
    }

    /**
     * Hash local files and keep only the ones that differ from the last sync.
     */
    private function hash(): void {
        $detector = $this->detector();

        if (is_wp_error($detector)) {
            $this->fail($detector);
            return;
        }

        $cursor = $this->cursor();
        $total = (int) $this->run->get('local_count', 0);
        $upload_count = (int) $this->run->get('upload_count', 0);

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('local', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            $changed = $detector->changed_in_batch($this->mapping->id, $batch);

            if ($changed) {
                $this->workspace->append_list('upload', $changed);
                $upload_count += count($changed);
            }

            $cursor += count($batch);
            $this->add_progress(count($batch));
            $this->set_cursor($cursor);
            $this->run->set(['upload_count' => $upload_count]);
        }

        if ($cursor < $total) {
            return;
        }

        $this->workspace->seal_list('upload');

        $delete_count = (int) $this->run->get('delete_count', 0);
        $this->run->total_items = $total + $upload_count + $delete_count;

        if ($upload_count === 0 && $delete_count === 0) {
            $this->run->set(['nothing_to_push' => true]);
            $this->enter_phase('commit');
            return;
        }

        $this->log(
            Log::LEVEL_INFO,
            sprintf(
                /* translators: 1: number of changed files, 2: number of deleted files. */
                __('Push started: %1$d changed files, %2$d deleted files.', 'github-sync'),
                $upload_count,
                $delete_count
            )
        );

        $this->enter_phase('build');
    }

    /**
     * Upload the changed files and fold them into a new tree.
     */
    private function build(): void {
        $client = $this->github_client();

        if (is_wp_error($client)) {
            $this->fail($client);
            return;
        }

        $detector = $this->detector();

        if (is_wp_error($detector)) {
            $this->fail($detector);
            return;
        }

        $tree_api = new TreeApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);
        $commit_api = new CommitApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);

        $prefix = (string) $this->run->get('prefix', '');
        $cursor = $this->cursor();
        $total = (int) $this->run->get('upload_count', 0);

        while ($cursor < $total) {
            $batch = $this->workspace->read_list('upload', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            $entries = [];
            $pending_bytes = 0;

            foreach ($batch as $item) {
                $relative_path = (string) $item['path'];
                $absolute = $detector->absolute_path($relative_path);
                $cursor++;
                $this->add_progress(1);

                if (!is_readable($absolute)) {
                    // Deleted or made unreadable since the scan; nothing to send.
                    continue;
                }

                $content = file_get_contents($absolute);

                if ($content === false) {
                    $this->fail(
                        sprintf(
                            /* translators: %s: file path. */
                            __('The file %s could not be read.', 'github-sync'),
                            $relative_path
                        )
                    );
                    return;
                }

                $entry = [
                    'path' => $prefix . $relative_path,
                    'mode' => $this->file_mode($absolute),
                    'type' => 'blob',
                ];

                if (strlen($content) <= self::INLINE_MAX_BYTES && $this->is_utf8_text($content)) {
                    $entry['content'] = $content;
                    $pending_bytes += strlen($content);
                } else {
                    $blob = $tree_api->create_blob($content);

                    if (is_wp_error($blob)) {
                        $this->fail($blob);
                        return;
                    }

                    if (empty($blob['sha'])) {
                        $this->fail(__('GitHub did not return an identifier for an uploaded file.', 'github-sync'));
                        return;
                    }

                    $entry['sha'] = (string) $blob['sha'];
                }

                unset($content);
                $entries[] = $entry;

                // Flush when the request would get too large, and whenever this
                // step has used its time, so uploads resume from here.
                if ($pending_bytes >= self::TREE_FLUSH_BYTES || $this->out_of_time()) {
                    $flushed = $this->flush_tree($commit_api, $entries);

                    if (is_wp_error($flushed)) {
                        $this->fail($flushed);
                        return;
                    }

                    $entries = [];
                    $pending_bytes = 0;
                    $this->set_cursor($cursor);

                    if ($this->out_of_time()) {
                        return;
                    }
                }
            }

            $flushed = $this->flush_tree($commit_api, $entries);

            if (is_wp_error($flushed)) {
                $this->fail($flushed);
                return;
            }

            $this->set_cursor($cursor);

            if ($this->out_of_time()) {
                return;
            }
        }

        $this->enter_phase('purge');
    }

    /**
     * Record deletions in the tree.
     */
    private function purge(): void {
        $delete_total = (int) $this->run->get('delete_count', 0);

        if ($delete_total === 0) {
            $this->enter_phase('commit');
            return;
        }

        $client = $this->github_client();

        if (is_wp_error($client)) {
            $this->fail($client);
            return;
        }

        $commit_api = new CommitApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);
        $prefix = (string) $this->run->get('prefix', '');
        $cursor = $this->cursor();

        while ($cursor < $delete_total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('deletes', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            $entries = [];

            foreach ($batch as $relative_path) {
                $entries[] = [
                    'path' => $prefix . (string) $relative_path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha'  => null,
                ];
            }

            $flushed = $this->flush_tree($commit_api, $entries);

            if (is_wp_error($flushed)) {
                $this->fail($flushed);
                return;
            }

            $cursor += count($batch);
            $this->add_progress(count($batch));
            $this->set_cursor($cursor);
        }

        if ($cursor >= $delete_total) {
            $this->enter_phase('commit');
        }
    }

    /**
     * Create the commit and move the branch.
     */
    private function commit(): void {
        if ($this->run->get('nothing_to_push')) {
            $this->mapping->last_synced_at = Dates::now();
            $this->mapping->save();
            $this->complete(__('Nothing to push. WordPress already matches the repository.', 'github-sync'));
            return;
        }

        $base_commit = (string) $this->run->get('base_commit', '');
        $tree_sha = (string) $this->run->get('current_tree', '');

        if ($tree_sha === '' || $tree_sha === (string) $this->run->get('base_tree', '')) {
            // Every file turned out to be identical to what GitHub already has,
            // which happens the first time a folder that is already in sync is
            // pushed. Record the hashes so the next push is instant.
            $this->run->commit_sha = $base_commit;
            $this->run->set(['unchanged_tree' => true]);
            $this->enter_phase('finalize');
            return;
        }

        $client = $this->github_client();

        if (is_wp_error($client)) {
            $this->fail($client);
            return;
        }

        $commit_api = new CommitApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);

        $commit = $commit_api->create_commit(
            (string) $this->run->commit_message,
            $tree_sha,
            [$base_commit],
            $this->commit_author()
        );

        if (is_wp_error($commit)) {
            $this->fail($commit);
            return;
        }

        $new_sha = (string) ($commit['sha'] ?? '');

        if ($new_sha === '') {
            $this->fail(__('GitHub did not return the new commit.', 'github-sync'));
            return;
        }

        $updated = $commit_api->update_branch($this->mapping->branch, $new_sha);

        if (is_wp_error($updated)) {
            $this->fail($updated);
            return;
        }

        $this->run->commit_sha = $new_sha;
        $this->enter_phase('finalize');
    }

    /**
     * Record the new hashes now that GitHub has accepted the commit.
     */
    private function finalize(): void {
        $cursor = (int) $this->run->get('state_cursor', 0);
        $total = (int) $this->run->get('upload_count', 0);
        $added = 0;
        $updated = 0;

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('upload', $cursor, 200);

            if (!$batch) {
                break;
            }

            $hashes = [];

            foreach ($batch as $item) {
                $hashes[(string) $item['path']] = (string) $item['hash'];

                if (($item['operation'] ?? '') === 'add') {
                    $added++;
                } else {
                    $updated++;
                }
            }

            FileState::put_many($this->mapping->id, $hashes, FileState::ORIGIN_WORDPRESS);

            $cursor += count($batch);
            $this->run->set(['state_cursor' => $cursor]);
        }

        $this->add_summary(['added' => $added, 'updated' => $updated]);

        if ($cursor < $total) {
            return;
        }

        $delete_total = (int) $this->run->get('delete_count', 0);
        $delete_cursor = (int) $this->run->get('state_delete_cursor', 0);

        while ($delete_cursor < $delete_total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('deletes', $delete_cursor, 200);

            if (!$batch) {
                break;
            }

            FileState::delete_many($this->mapping->id, $batch);
            $delete_cursor += count($batch);
            $this->run->set(['state_delete_cursor' => $delete_cursor]);
            $this->add_summary(['deleted' => count($batch)]);
        }

        if ($delete_cursor < $delete_total) {
            return;
        }

        $this->mapping->last_commit_sha = (string) $this->run->commit_sha;
        $this->mapping->last_synced_at = Dates::now();
        $this->mapping->save();

        if ($this->run->get('unchanged_tree')) {
            $this->complete(__('Nothing to push. Every file already matches the repository, and tracking is now up to date.', 'github-sync'));
            return;
        }

        $summary = (array) $this->run->get('summary', []);

        $this->complete(
            sprintf(
                /* translators: 1: files added, 2: files updated, 3: files deleted, 4: short commit hash. */
                __('Push finished: %1$d added, %2$d updated, %3$d deleted, in commit %4$s.', 'github-sync'),
                (int) ($summary['added'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['deleted'] ?? 0),
                substr((string) $this->run->commit_sha, 0, 7)
            )
        );
    }

    /**
     * Send pending tree entries to GitHub, chained onto the tree built so far.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return true|WP_Error
     */
    private function flush_tree(CommitApi $commit_api, array $entries) {
        if (!$entries) {
            return true;
        }

        $base = (string) $this->run->get('current_tree', '');
        $tree = $commit_api->create_tree($entries, $base);

        if (is_wp_error($tree)) {
            return $tree;
        }

        if (empty($tree['sha'])) {
            return new WP_Error('tree_missing_sha', __('GitHub did not return the updated file tree.', 'github-sync'));
        }

        $this->run->set(['current_tree' => (string) $tree['sha']]);

        return true;
    }

    /**
     * A change detector bound to the validated destination folder.
     *
     * @return LocalChangeDetector|WP_Error
     */
    private function detector() {
        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            return $dest_path;
        }

        return new LocalChangeDetector($dest_path, $this->filter, $this->max_file_bytes);
    }

    /**
     * Repository folder that local paths sit inside, with a trailing slash.
     */
    private function source_prefix(): string {
        $source = PathValidator::validate_source_path($this->mapping->source_path);

        return $source === '' ? '' : $source . '/';
    }

    /**
     * Git file mode. Executable bits only exist on systems that report them.
     */
    private function file_mode(string $absolute_path): string {
        if (DIRECTORY_SEPARATOR === '/' && is_executable($absolute_path)) {
            return '100755';
        }

        return '100644';
    }

    /**
     * Inline tree content has to be valid UTF-8 text.
     */
    private function is_utf8_text(string $content): bool {
        if (strpos($content, "\0") !== false) {
            return false;
        }

        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($content, 'UTF-8');
        }

        return (bool) preg_match('//u', $content);
    }

    /**
     * Commit author from Settings, when one is configured.
     *
     * @return array{name: string, email: string}|null
     */
    private function commit_author(): ?array {
        $name = trim((string) Settings::get('commit_author_name', ''));
        $email = trim((string) Settings::get('commit_author_email', ''));

        if ($name === '' || $email === '' || !is_email($email)) {
            return null;
        }

        return ['name' => $name, 'email' => $email];
    }
}
