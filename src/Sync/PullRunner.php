<?php

namespace GithubSync\Sync;

use GithubSync\Database\Models\FileState;
use GithubSync\Database\Models\Log;
use GithubSync\Deployment\ManifestBuilder;
use GithubSync\Deployment\Rollback;
use GithubSync\GitHub\CommitApi;
use GithubSync\GitHub\TreeApi;
use GithubSync\Security\PathValidator;
use GithubSync\Support\Dates;
use GithubSync\Support\FileHasher;
use WP_Error;

/**
 * Brings GitHub changes into WordPress, one bounded step at a time.
 *
 * Order of work: plan, drop files that already match, download to a staging
 * folder, back up what is about to change, then write. Nothing in the live
 * folder is touched until every file has been downloaded and verified.
 */
class PullRunner extends AbstractRunner {

    public const FIRST_PHASE = 'prepare';

    /**
     * Advance the run.
     */
    public function step(): void {
        switch ($this->run->phase) {
            case 'prepare':
                $this->prepare();
                break;
            case 'compare':
                $this->compare();
                break;
            case 'stage':
                $this->stage();
                break;
            case 'backup':
                $this->backup();
                break;
            case 'apply':
                $this->apply();
                break;
            case 'remove':
                $this->remove();
                break;
            case 'finalize':
                $this->finalize();
                break;
            default:
                $this->fail(__('This sync reached an unknown state and was stopped.', 'github-sync'));
        }
    }

    /**
     * Ask GitHub what the branch contains and compare it with the tracking data.
     */
    private function prepare(): void {
        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            $this->fail($dest_path);
            return;
        }

        $created = $this->ensure_directory($dest_path);

        if (is_wp_error($created)) {
            $this->fail($created);
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

        $tree_api = new TreeApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);
        $remote_files = $tree_api->list_files($head, $this->mapping->source_path);

        if (is_wp_error($remote_files)) {
            $this->fail($remote_files);
            return;
        }

        $remote_files = $this->drop_unsafe_paths($remote_files);

        $builder = new ManifestBuilder($this->mapping, $dest_path, $this->max_file_bytes);
        $manifest = $builder->build($remote_files);

        if ($manifest['skipped']) {
            $this->log(
                Log::LEVEL_WARNING,
                sprintf(
                    /* translators: %d: number of files. */
                    _n(
                        '%d file was skipped because it is larger than the size limit in Settings.',
                        '%d files were skipped because they are larger than the size limit in Settings.',
                        count($manifest['skipped']),
                        'github-sync'
                    ),
                    count($manifest['skipped'])
                ),
                ['files' => array_slice($manifest['skipped'], 0, 50)]
            );
        }

        $this->run->commit_sha = $head;
        $this->run->prev_commit_sha = $this->mapping->last_commit_sha;
        $this->add_summary([
            'unchanged' => (int) $manifest['unchanged'],
            'skipped'   => count($manifest['skipped']),
        ]);

        $candidates = $manifest['changes'];
        $deletes = $manifest['deletes'];

        if (!$candidates && !$deletes) {
            $this->save_mapping_state($head);
            $this->run->total_items = 0;
            $this->complete(__('Already up to date. No files needed changing.', 'github-sync'));
            return;
        }

        $stored = $this->workspace->write_list('candidates', $candidates);

        if (is_wp_error($stored)) {
            $this->fail($stored);
            return;
        }

        $stored = $this->workspace->write_list('deletes', $deletes);

        if (is_wp_error($stored)) {
            $this->fail($stored);
            return;
        }

        $this->run->total_items = count($candidates) + count($deletes);
        $this->run->set([
            'candidate_count' => count($candidates),
            'delete_count'    => count($deletes),
            'change_count'    => 0,
            'backup_count'    => 0,
        ]);

        $this->log(
            Log::LEVEL_INFO,
            sprintf(
                /* translators: 1: number of candidate files, 2: number of files to delete, 3: short commit hash. */
                __('Pull started from commit %3$s: %1$d files to check, %2$d to delete.', 'github-sync'),
                count($candidates),
                count($deletes),
                substr($head, 0, 7)
            )
        );

        $this->enter_phase('compare');
    }

    /**
     * Drop the files whose contents already match GitHub.
     *
     * This is what makes a first sync into an existing folder cheap: matching
     * files are recorded as tracked instead of being downloaded again.
     */
    private function compare(): void {
        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            $this->fail($dest_path);
            return;
        }

        $cursor = $this->cursor();
        $total = (int) $this->run->get('candidate_count', 0);
        $change_count = (int) $this->run->get('change_count', 0);
        $backup_count = (int) $this->run->get('backup_count', 0);
        $adopted = 0;

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('candidates', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            $adopt = [];
            $changes = [];
            $backups = [];

            foreach ($batch as $item) {
                $target = $dest_path . '/' . $item['path'];

                if ($this->matches_remote($target, (string) $item['sha'], (int) $item['size'])) {
                    $adopt[(string) $item['path']] = (string) $item['sha'];
                    continue;
                }

                $changes[] = $item;

                if (is_file($target)) {
                    $backups[] = (string) $item['path'];
                }
            }

            if ($adopt) {
                FileState::put_many($this->mapping->id, $adopt, FileState::ORIGIN_GITHUB);
                $adopted += count($adopt);
            }

            $this->workspace->append_list('changes', $changes);
            $this->workspace->append_list('backup', $backups);

            $change_count += count($changes);
            $backup_count += count($backups);

            $cursor += count($batch);
            $this->add_progress(count($batch));
            $this->set_cursor($cursor);
            $this->run->set(['change_count' => $change_count, 'backup_count' => $backup_count]);
        }

        if ($adopted > 0) {
            $this->add_summary(['unchanged' => $adopted]);
        }

        if ($cursor < $total) {
            return;
        }

        $delete_count = (int) $this->run->get('delete_count', 0);
        $backup_count += $this->queue_deletes_for_backup($delete_count);

        $this->workspace->seal_list('changes');
        $this->workspace->seal_list('backup');

        if ($change_count === 0 && $delete_count === 0) {
            $this->save_mapping_state((string) $this->run->commit_sha);
            $this->complete(__('Already up to date. Every file already matched the repository.', 'github-sync'));
            return;
        }

        if ($backup_count > 0 && Rollback::is_supported()) {
            $this->run->backup_path = Rollback::new_archive_path($this->mapping->id);
        } elseif ($backup_count > 0) {
            $backup_count = 0;
            $this->log(
                Log::LEVEL_WARNING,
                __('No backup was made because the ZipArchive PHP extension is not available on this server.', 'github-sync')
            );
        }

        $this->run->total_items = (int) $this->run->get('candidate_count', 0)
            + ($change_count * 2)
            + $backup_count
            + $delete_count;

        $this->run->set(['change_count' => $change_count, 'backup_count' => $backup_count]);

        $this->log(
            Log::LEVEL_INFO,
            sprintf(
                /* translators: 1: number of files to write, 2: number of files to delete. */
                __('Ready to apply %1$d changed files and %2$d deletions.', 'github-sync'),
                $change_count,
                $delete_count
            )
        );

        $this->enter_phase('stage');
    }

    /**
     * Download the changed files into the staging folder.
     */
    private function stage(): void {
        $client = $this->github_client();

        if (is_wp_error($client)) {
            $this->fail($client);
            return;
        }

        $tree_api = new TreeApi($client, $this->mapping->repo_owner, $this->mapping->repo_name);
        $staging_dir = $this->workspace->staging_dir();
        $cursor = $this->cursor();
        $total = (int) $this->run->get('change_count', 0);

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('changes', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            foreach ($batch as $item) {
                $target = $staging_dir . '/' . $item['path'];
                $created = $this->ensure_directory(dirname($target));

                if (is_wp_error($created)) {
                    $this->fail($created);
                    return;
                }

                $downloaded = $tree_api->download_blob((string) $item['sha'], $target);

                if (is_wp_error($downloaded)) {
                    $this->fail($downloaded);
                    return;
                }

                if (FileHasher::hash_file($target) !== (string) $item['sha']) {
                    $this->fail(
                        sprintf(
                            /* translators: %s: file path. */
                            __('The download of %s did not match what GitHub reported, so nothing was written.', 'github-sync'),
                            $item['path']
                        )
                    );
                    return;
                }

                $cursor++;
                $this->add_progress(1);

                // Downloads are the slowest part of a pull, so the budget is
                // checked per file rather than per batch.
                if ($this->out_of_time()) {
                    break;
                }
            }

            $this->set_cursor($cursor);
        }

        if ($cursor >= $total) {
            $this->enter_phase($this->run->backup_path ? 'backup' : 'apply');
        }
    }

    /**
     * Archive the files that are about to be overwritten or removed.
     */
    private function backup(): void {
        if (!$this->run->backup_path) {
            $this->enter_phase('apply');
            return;
        }

        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            $this->fail($dest_path);
            return;
        }

        $rollback = new Rollback($dest_path, $this->run->backup_path);
        $cursor = $this->cursor();
        $total = (int) $this->run->get('backup_count', 0);

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('backup', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            $added = $rollback->add_batch($batch);

            if (is_wp_error($added)) {
                $this->fail($added);
                return;
            }

            $cursor += count($batch);
            $this->add_progress(count($batch));
            $this->set_cursor($cursor);
        }

        if ($cursor >= $total) {
            $this->enter_phase('apply');
        }
    }

    /**
     * Copy staged files into the live folder.
     */
    private function apply(): void {
        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            $this->fail($dest_path);
            return;
        }

        $staging_dir = $this->workspace->staging_dir();
        $cursor = $this->cursor();
        $total = (int) $this->run->get('change_count', 0);

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('changes', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            $written = [];
            $added = 0;
            $updated = 0;

            foreach ($batch as $item) {
                $source = $staging_dir . '/' . $item['path'];
                $target = $dest_path . '/' . $item['path'];

                $created = $this->ensure_directory(dirname($target));

                if (is_wp_error($created)) {
                    $this->rollback_and_fail($dest_path, $created);
                    return;
                }

                $existed = is_file($target);

                if (!$this->install_file($source, $target)) {
                    $this->rollback_and_fail(
                        $dest_path,
                        sprintf(
                            /* translators: %s: file path. */
                            __('The file %s could not be written. Check the folder permissions and try again.', 'github-sync'),
                            $item['path']
                        )
                    );
                    return;
                }

                $written[(string) $item['path']] = (string) $item['sha'];

                if ($existed) {
                    $updated++;
                } else {
                    $added++;
                }
            }

            FileState::put_many($this->mapping->id, $written, FileState::ORIGIN_GITHUB);

            $cursor += count($batch);
            $this->add_progress(count($batch));
            $this->add_summary(['added' => $added, 'updated' => $updated]);
            $this->set_cursor($cursor);
        }

        if ($cursor >= $total) {
            $this->enter_phase('remove');
        }
    }

    /**
     * Delete files that are gone from the repository.
     */
    private function remove(): void {
        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path)) {
            $this->fail($dest_path);
            return;
        }

        $cursor = $this->cursor();
        $total = (int) $this->run->get('delete_count', 0);

        while ($cursor < $total && !$this->out_of_time()) {
            $batch = $this->workspace->read_list('deletes', $cursor, $this->batch_size);

            if (!$batch) {
                break;
            }

            foreach ($batch as $relative_path) {
                $file = $dest_path . '/' . $relative_path;

                if (is_file($file)) {
                    @unlink($file);
                }

                $this->remove_empty_parents(dirname($file), $dest_path);
            }

            FileState::delete_many($this->mapping->id, $batch);

            $cursor += count($batch);
            $this->add_progress(count($batch));
            $this->add_summary(['deleted' => count($batch)]);
            $this->set_cursor($cursor);
        }

        if ($cursor >= $total) {
            $this->enter_phase('finalize');
        }
    }

    /**
     * Record the new commit and tidy up.
     */
    private function finalize(): void {
        $this->save_mapping_state((string) $this->run->commit_sha);

        Rollback::prune();

        $summary = (array) $this->run->get('summary', []);

        $this->complete(
            sprintf(
                /* translators: 1: files added, 2: files updated, 3: files deleted. */
                __('Pull finished: %1$d added, %2$d updated, %3$d deleted.', 'github-sync'),
                (int) ($summary['added'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['deleted'] ?? 0)
            )
        );
    }

    /**
     * Add the files that are about to be deleted to the backup list.
     */
    private function queue_deletes_for_backup(int $delete_count): int {
        $queued = 0;
        $cursor = 0;

        while ($cursor < $delete_count) {
            $batch = $this->workspace->read_list('deletes', $cursor, 200);

            if (!$batch) {
                break;
            }

            $this->workspace->append_list('backup', $batch);
            $queued += count($batch);
            $cursor += count($batch);
        }

        return $queued;
    }

    /**
     * True when the file on disk is byte for byte what GitHub has.
     */
    private function matches_remote(string $absolute_path, string $remote_sha, int $remote_size): bool {
        if (!is_file($absolute_path)) {
            return false;
        }

        if (filesize($absolute_path) !== $remote_size) {
            return false;
        }

        return FileHasher::hash_file($absolute_path) === $remote_sha;
    }

    /**
     * Put the live folder back the way it was, then stop the run.
     *
     * @param string|WP_Error $error
     */
    private function rollback_and_fail(string $dest_path, $error): void {
        if ($this->run->backup_path) {
            $rollback = new Rollback($dest_path, $this->run->backup_path);

            if ($rollback->exists()) {
                $restored = $rollback->restore();
                $this->log(
                    $restored ? Log::LEVEL_WARNING : Log::LEVEL_ERROR,
                    $restored
                        ? __('Writing files failed, so the previous files were restored from the backup.', 'github-sync')
                        : __('Writing files failed and the backup could not be restored automatically.', 'github-sync'),
                    ['backup' => $this->run->backup_path]
                );
            }
        }

        $this->fail($error);
    }

    /**
     * Move a staged file into place, falling back to a copy across filesystems.
     */
    private function install_file(string $source, string $target): bool {
        if (!is_file($source)) {
            return false;
        }

        if (is_file($target) && !is_writable($target)) {
            return false;
        }

        if (!@rename($source, $target) && !@copy($source, $target)) {
            return false;
        }

        @chmod($target, $this->file_permissions());

        return true;
    }

    /**
     * Remove folders left empty by deletions, without ever touching the mapping root.
     */
    private function remove_empty_parents(string $dir, string $stop_at): void {
        $dir = wp_normalize_path($dir);
        $stop_at = untrailingslashit(wp_normalize_path($stop_at));

        while ($dir !== $stop_at && strpos($dir, $stop_at . '/') === 0) {
            $entries = @scandir($dir);

            if ($entries === false || count(array_diff($entries, ['.', '..'])) > 0) {
                return;
            }

            @rmdir($dir);
            $dir = dirname($dir);
        }
    }

    /**
     * Store the commit the folder now matches.
     */
    private function save_mapping_state(string $commit_sha): void {
        $this->mapping->last_commit_sha = $commit_sha;
        $this->mapping->last_synced_at = Dates::now();
        $this->mapping->save();
    }

    /**
     * Refuse repository paths that would escape the destination folder.
     *
     * @param array<int, array{path: string, sha: string, size: int}> $files
     * @return array<int, array{path: string, sha: string, size: int}>
     */
    private function drop_unsafe_paths(array $files): array {
        $safe = [];
        $rejected = [];

        foreach ($files as $file) {
            if (PathValidator::is_safe_relative_path((string) $file['path'])) {
                $safe[] = $file;
                continue;
            }

            $rejected[] = (string) $file['path'];
        }

        if ($rejected) {
            $this->log(
                Log::LEVEL_WARNING,
                __('Some repository paths were ignored because they are not safe to write inside WordPress.', 'github-sync'),
                ['paths' => array_slice($rejected, 0, 50)]
            );
        }

        return $safe;
    }
}
