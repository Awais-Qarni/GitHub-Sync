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

        $this->warn_about_repository_layout($remote_files);

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
            $this->verify_destination();
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
            $this->verify_destination();
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

                if ($this->already_installed($source, $target, (string) $item['sha'])) {
                    // The batch was interrupted after this file was written but
                    // before the cursor moved, so there is nothing left to do.
                    $written[(string) $item['path']] = (string) $item['sha'];
                    $updated++;
                    continue;
                }

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

        $this->verify_destination();

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
     * True when a staged file is gone because it has already been moved into
     * place, which is what a step killed mid batch leaves behind.
     */
    private function already_installed(string $source, string $target, string $sha): bool {
        if (is_file($source) || !is_file($target)) {
            return false;
        }

        return FileHasher::hash_file($target) === $sha;
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
     * Record a warning that the person who pressed Pull needs to read.
     *
     * A run that finishes without writing the folder WordPress expects is still
     * reported as completed, so the reason is kept on the run as well as in the
     * log and shown next to the result on the dashboard.
     *
     * @param array<string, mixed> $context
     */
    private function warn_run(string $message, array $context = []): void {
        $this->log(Log::LEVEL_WARNING, $message, $context);

        if ((string) $this->run->get('notice', '') === '') {
            $this->run->set(['notice' => $message]);
        }
    }

    /**
     * Warn when the branch does not look like the thing this mapping installs,
     * before a single file is written.
     *
     * WordPress only looks one folder deep for themes and plugins. A repository
     * that keeps its theme in a subfolder therefore syncs perfectly, reports
     * success, and still never appears under Appearance, because what landed in
     * the themes folder is a folder of folders. Saying so here is a great deal
     * cheaper than leaving the site owner to work it out from an empty screen.
     *
     * @param array<int, array{path: string, sha: string, size: int}> $remote_files
     */
    private function warn_about_repository_layout(array $remote_files): void {
        $type = $this->mapping->dest_type;

        if (($type !== 'theme' && $type !== 'plugin') || !$remote_files) {
            return;
        }

        $root = [];
        $nested = [];

        foreach ($remote_files as $file) {
            $parts = explode('/', (string) $file['path']);

            if (count($parts) === 1) {
                $root[] = $parts[0];
                continue;
            }

            $folder = $parts[0];

            if (!isset($nested[$folder])) {
                $nested[$folder] = [];
            }

            if (count($parts) === 2) {
                $nested[$folder][] = $parts[1];
            }
        }

        if (self::looks_like_root($type, $root)) {
            return;
        }

        $matches = [];

        foreach ($nested as $folder => $names) {
            if (self::looks_like_root($type, $names)) {
                $matches[] = (string) $folder;
            }
        }

        $prefix = $this->mapping->source_path === '' ? '' : $this->mapping->source_path . '/';

        if (count($matches) === 1) {
            $this->warn_run(
                sprintf(
                    $type === 'theme'
                        /* translators: %s: folder path inside the repository. */
                        ? __('This branch has no style.css at the top of the mapped folder, so WordPress will not list the result under Appearance. The theme looks like it lives in "%s" instead. Edit the mapping and set the repository folder to that path, then pull again.', 'github-sync')
                        /* translators: %s: folder path inside the repository. */
                        : __('This branch has no PHP file at the top of the mapped folder, so WordPress will not list the result on the Plugins screen. The plugin looks like it lives in "%s" instead. Edit the mapping and set the repository folder to that path, then pull again.', 'github-sync'),
                    $prefix . $matches[0]
                ),
                ['repository_folder' => $prefix . $matches[0]]
            );

            return;
        }

        $this->warn_run(
            $type === 'theme'
                ? __('This branch has no style.css at the top of the mapped folder. The files will be synced, but WordPress will not list them under Appearance until the mapped folder is the one holding style.css.', 'github-sync')
                : __('This branch has no PHP file at the top of the mapped folder. The files will be synced, but WordPress will not list them on the Plugins screen until the mapped folder is the one holding the main plugin file.', 'github-sync'),
            $matches ? ['candidate_folders' => array_slice($matches, 0, 20)] : []
        );
    }

    /**
     * Whether a listing of names, taken from one folder, is the root of a theme
     * or of a plugin. Only the file names are known here, so this is the same
     * shallow test WordPress itself starts from.
     *
     * @param string[] $names
     */
    private static function looks_like_root(string $type, array $names): bool {
        foreach ($names as $name) {
            $name = strtolower((string) $name);

            if ($type === 'theme' && $name === 'style.css') {
                return true;
            }

            if ($type === 'plugin' && substr($name, -4) === '.php') {
                return true;
            }
        }

        return false;
    }

    /**
     * After a pull, check that WordPress will really recognise what is now on
     * disk, and say what is missing when it will not.
     *
     * A pull that writes every file correctly is still a failure from the site
     * owner's point of view if the Themes screen stays empty afterwards, so the
     * run records the reason rather than only reporting success.
     */
    private function verify_destination(): void {
        $type = $this->mapping->dest_type;

        if ($type !== 'theme' && $type !== 'plugin') {
            return;
        }

        $dest_path = $this->destination_path();

        if (is_wp_error($dest_path) || !is_dir($dest_path)) {
            return;
        }

        if (self::has_header($dest_path, $type)) {
            return;
        }

        $nested = self::find_header_below($dest_path, $type);

        if ($nested !== null) {
            $this->warn_run(
                sprintf(
                    $type === 'theme'
                        /* translators: 1: destination folder, 2: subfolder name. */
                        ? __('The files were written to %1$s, but WordPress will not list a theme there: the theme itself is one level down, in the "%2$s" subfolder. Set the repository folder on this mapping to the folder that holds style.css, or point the mapping at wp-content/themes/%2$s.', 'github-sync')
                        /* translators: 1: destination folder, 2: subfolder name. */
                        : __('The files were written to %1$s, but WordPress will not list a plugin there: the plugin itself is one level down, in the "%2$s" subfolder. Set the repository folder on this mapping to the folder that holds the main plugin file, or point the mapping at wp-content/plugins/%2$s.', 'github-sync'),
                    $dest_path,
                    $nested
                ),
                ['destination' => $dest_path, 'found_in' => $nested]
            );

            return;
        }

        $this->warn_run(
            sprintf(
                $type === 'theme'
                    /* translators: %s: destination folder. */
                    ? __('The files were written to %s, but it holds no style.css with a "Theme Name:" header, so WordPress will not list it under Appearance.', 'github-sync')
                    /* translators: %s: destination folder. */
                    : __('The files were written to %s, but it holds no PHP file with a "Plugin Name:" header, so WordPress will not list it on the Plugins screen.', 'github-sync'),
                $dest_path
            ),
            ['destination' => $dest_path]
        );
    }

    /**
     * True when a folder holds the header file WordPress looks for.
     */
    private static function has_header(string $dir, string $type): bool {
        if ($type === 'theme') {
            return self::file_declares($dir . '/style.css', 'Theme Name');
        }

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (self::file_declares($file, 'Plugin Name')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first immediate subfolder that holds the header file, if any.
     */
    private static function find_header_below(string $dir, string $type): ?string {
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            if (self::has_header($child, $type)) {
                return basename($child);
            }
        }

        return null;
    }

    /**
     * Look for a file header field the way WordPress reads one: in the first
     * 8 KB, tolerant of the comment characters around it.
     */
    private static function file_declares(string $file, string $field): bool {
        if (!is_file($file)) {
            return false;
        }

        $handle = @fopen($file, 'r');

        if (!$handle) {
            return false;
        }

        $head = (string) fread($handle, 8192);
        fclose($handle);

        $head = str_replace("\r", "\n", $head);

        return (bool) preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':/mi', $head);
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
