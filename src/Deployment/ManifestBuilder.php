<?php

namespace GithubSync\Deployment;

use GithubSync\Database\Models\FileState;
use GithubSync\Database\Models\Mapping;
use GithubSync\Support\ExclusionFilter;

/**
 * Works out what a pull would change, by comparing the repository listing with
 * the hashes recorded for the last sync and with what is really on disk.
 */
class ManifestBuilder {

    private Mapping $mapping;
    private ExclusionFilter $filter;
    private string $dest_path;
    private int $max_file_bytes;

    public function __construct(Mapping $mapping, string $dest_path, int $max_file_bytes) {
        $this->mapping = $mapping;
        $this->filter = ExclusionFilter::for_rules($mapping->exclusions);
        $this->dest_path = untrailingslashit($dest_path);
        $this->max_file_bytes = $max_file_bytes;
    }

    /**
     * Compare a repository listing with local tracking state.
     *
     * @param array<int, array{path: string, sha: string, size: int}> $remote_files
     * @return array{
     *     changes: array<int, array{path: string, sha: string, size: int, operation: string}>,
     *     deletes: array<int, string>,
     *     unchanged: int,
     *     skipped: array<int, string>
     * }
     */
    public function build(array $remote_files): array {
        $tracked = FileState::get_map($this->mapping->id);

        $changes = [];
        $skipped = [];
        $unchanged = 0;

        foreach ($remote_files as $file) {
            $path = ltrim((string) $file['path'], '/');

            if ($path === '' || $this->filter->is_excluded($path)) {
                continue;
            }

            if ($this->max_file_bytes > 0 && (int) $file['size'] > $this->max_file_bytes) {
                $skipped[] = $path;
                unset($tracked[$path]);
                continue;
            }

            $remote_sha = (string) $file['sha'];
            $operation = 'add';

            if (isset($tracked[$path])) {
                if ($tracked[$path] === $remote_sha && $this->exists_locally($path, (int) $file['size'])) {
                    $unchanged++;
                    unset($tracked[$path]);
                    continue;
                }

                $operation = 'update';
                unset($tracked[$path]);
            } elseif ($this->exists_locally($path, (int) $file['size'])) {
                // Untracked but present: treat as an update so the backup covers it.
                $operation = 'update';
            }

            $changes[] = [
                'path'      => $path,
                'sha'       => $remote_sha,
                'size'      => (int) $file['size'],
                'operation' => $operation,
            ];
        }

        $deletes = [];

        if ($this->mapping->delete_policy) {
            foreach (array_keys($tracked) as $path) {
                if (!$this->filter->is_excluded($path)) {
                    $deletes[] = $path;
                }
            }
        }

        return [
            'changes'   => $changes,
            'deletes'   => $deletes,
            'unchanged' => $unchanged,
            'skipped'   => $skipped,
        ];
    }

    /**
     * A tracked file that was deleted or emptied on the server must be restored,
     * so presence on disk is part of the comparison.
     */
    private function exists_locally(string $relative_path, int $remote_size = 0): bool {
        $file = $this->dest_path . '/' . $relative_path;

        if (!is_file($file)) {
            return false;
        }

        // A file that was truncated locally still matches its recorded hash, so
        // an empty copy of a non empty file counts as missing.
        return $remote_size <= 0 || filesize($file) > 0;
    }
}
