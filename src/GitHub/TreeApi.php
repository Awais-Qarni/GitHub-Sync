<?php

namespace GithubSync\GitHub;

use WP_Error;

/**
 * Reads and writes Git objects: trees and blobs.
 */
class TreeApi {

    /**
     * Safety limit for the directory walk used on very large repositories.
     */
    private const MAX_WALK_REQUESTS = 500;

    private Client $client;
    private string $owner;
    private string $repo;

    public function __construct(Client $client, string $owner, string $repo) {
        $this->client = $client;
        $this->owner = $owner;
        $this->repo = $repo;
    }

    /**
     * List every file under a commit, limited to one subfolder when given.
     *
     * Paths in the result are relative to that subfolder. GitHub truncates a
     * recursive tree once it gets very large, so this narrows the request to the
     * mapped subfolder first and falls back to walking directory by directory.
     *
     * @return array<int, array{path: string, sha: string, size: int, mode: string}>|WP_Error
     */
    public function list_files(string $commit_sha, string $source_path = '') {
        $root_sha = $commit_sha;
        $source_path = trim($source_path, '/');

        if ($source_path !== '') {
            $root_sha = $this->resolve_subtree($commit_sha, $source_path);

            if (is_wp_error($root_sha)) {
                return $root_sha;
            }
        }

        $response = $this->get_tree($root_sha, true);

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['truncated'])) {
            return $this->collect_blobs($response['tree'] ?? []);
        }

        return $this->walk_tree($root_sha);
    }

    /**
     * Fetch a single tree object.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_tree(string $tree_sha, bool $recursive = false) {
        $endpoint = sprintf(
            '/repos/%s/%s/git/trees/%s%s',
            rawurlencode($this->owner),
            rawurlencode($this->repo),
            rawurlencode($tree_sha),
            $recursive ? '?recursive=1' : ''
        );

        $response = $this->client->get($endpoint);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['tree']) || !is_array($response['tree'])) {
            return new WP_Error('invalid_tree_response', __('GitHub returned an unexpected repository listing.', 'github-sync'));
        }

        return $response;
    }

    /**
     * Find the tree SHA for a subfolder inside a commit.
     *
     * @return string|WP_Error
     */
    public function resolve_subtree(string $commit_sha, string $source_path) {
        $current = $commit_sha;
        $walked = '';

        foreach (explode('/', trim($source_path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $tree = $this->get_tree($current);

            if (is_wp_error($tree)) {
                return $tree;
            }

            $match = null;

            foreach ($tree['tree'] as $entry) {
                if (($entry['path'] ?? '') === $segment && ($entry['type'] ?? '') === 'tree') {
                    $match = $entry;
                    break;
                }
            }

            if ($match === null) {
                $walked = ltrim($walked . '/' . $segment, '/');

                return new WP_Error(
                    'source_path_missing',
                    sprintf(
                        /* translators: %s: folder path inside the repository. */
                        __('The folder "%s" does not exist in this branch of the repository.', 'github-sync'),
                        $walked
                    )
                );
            }

            $current = (string) $match['sha'];
            $walked = ltrim($walked . '/' . $segment, '/');
        }

        return $current;
    }

    /**
     * Walk a tree one directory at a time. Slower than a recursive fetch, but it
     * is the only way to read a repository GitHub refuses to send in one piece.
     *
     * @return array<int, array{path: string, sha: string, size: int, mode: string}>|WP_Error
     */
    private function walk_tree(string $root_sha) {
        $files = [];
        $queue = [['sha' => $root_sha, 'prefix' => '']];
        $requests = 0;

        while ($queue) {
            $node = array_shift($queue);
            $requests++;

            if ($requests > self::MAX_WALK_REQUESTS) {
                return new WP_Error(
                    'tree_too_large',
                    __('This repository folder is too large to list. Map a subfolder instead of the whole repository.', 'github-sync')
                );
            }

            $tree = $this->get_tree($node['sha'], true);

            if (is_wp_error($tree)) {
                return $tree;
            }

            $truncated = !empty($tree['truncated']);

            foreach ($tree['tree'] as $entry) {
                $path = $node['prefix'] === '' ? (string) $entry['path'] : $node['prefix'] . '/' . $entry['path'];

                if (($entry['type'] ?? '') === 'blob') {
                    $files[] = [
                        'path' => $path,
                        'sha'  => (string) $entry['sha'],
                        'size' => (int) ($entry['size'] ?? 0),
                        'mode' => (string) ($entry['mode'] ?? '100644'),
                    ];
                    continue;
                }

                // Only descend when the recursive listing came back truncated,
                // and only into top level directories of this node.
                if ($truncated && ($entry['type'] ?? '') === 'tree' && strpos((string) $entry['path'], '/') === false) {
                    $queue[] = ['sha' => (string) $entry['sha'], 'prefix' => $path];
                }
            }
        }

        // A truncated parent can report a file twice; keep the last entry per path.
        $unique = [];

        foreach ($files as $file) {
            $unique[$file['path']] = $file;
        }

        return array_values($unique);
    }

    /**
     * Keep only blob entries from a recursive tree response.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array{path: string, sha: string, size: int, mode: string}>
     */
    private function collect_blobs(array $entries): array {
        $files = [];

        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') !== 'blob') {
                continue;
            }

            $files[] = [
                'path' => (string) $entry['path'],
                'sha'  => (string) $entry['sha'],
                'size' => (int) ($entry['size'] ?? 0),
                'mode' => (string) ($entry['mode'] ?? '100644'),
            ];
        }

        return $files;
    }

    /**
     * Download one file straight to disk.
     *
     * @return true|WP_Error
     */
    public function download_blob(string $file_sha, string $destination) {
        $endpoint = sprintf(
            '/repos/%s/%s/git/blobs/%s',
            rawurlencode($this->owner),
            rawurlencode($this->repo),
            rawurlencode($file_sha)
        );

        return $this->client->download($endpoint, $destination);
    }

    /**
     * Upload file contents and get back the blob SHA.
     *
     * @param string $content Raw file contents.
     * @return array<string, mixed>|WP_Error
     */
    public function create_blob(string $content) {
        $endpoint = sprintf('/repos/%s/%s/git/blobs', rawurlencode($this->owner), rawurlencode($this->repo));

        return $this->client->post($endpoint, [
            'content'  => base64_encode($content),
            'encoding' => 'base64',
        ]);
    }
}
