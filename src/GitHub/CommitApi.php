<?php

namespace GithubSync\GitHub;

use WP_Error;

/**
 * Reads and writes commits and branch references.
 */
class CommitApi {

    private Client $client;
    private string $owner;
    private string $repo;

    public function __construct(Client $client, string $owner, string $repo) {
        $this->client = $client;
        $this->owner = $owner;
        $this->repo = $repo;
    }

    /**
     * The commit a branch currently points at.
     *
     * @return string|WP_Error
     */
    public function get_branch_head(string $branch) {
        $endpoint = sprintf(
            '/repos/%s/%s/git/ref/heads/%s',
            rawurlencode($this->owner),
            rawurlencode($this->repo),
            $this->encode_branch($branch)
        );

        $response = $this->client->get($endpoint);

        if (is_wp_error($response)) {
            if ($response->get_error_code() === 'github_not_found') {
                return new WP_Error(
                    'branch_not_found',
                    sprintf(
                        /* translators: %s: branch name. */
                        __('The branch "%s" no longer exists in this repository.', 'github-sync'),
                        $branch
                    )
                );
            }

            return $response;
        }

        if (empty($response['object']['sha'])) {
            return new WP_Error('branch_head_missing', __('GitHub did not report the latest commit for this branch.', 'github-sync'));
        }

        return (string) $response['object']['sha'];
    }

    /**
     * Full commit object, including its tree SHA and message.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_commit(string $commit_sha) {
        $endpoint = sprintf(
            '/repos/%s/%s/git/commits/%s',
            rawurlencode($this->owner),
            rawurlencode($this->repo),
            rawurlencode($commit_sha)
        );

        return $this->client->get($endpoint);
    }

    /**
     * Create a tree on top of an existing one.
     *
     * @param array<int, array<string, mixed>> $entries Tree entries.
     * @return array<string, mixed>|WP_Error
     */
    public function create_tree(array $entries, string $base_tree_sha) {
        $endpoint = sprintf('/repos/%s/%s/git/trees', rawurlencode($this->owner), rawurlencode($this->repo));

        return $this->client->post($endpoint, [
            'base_tree' => $base_tree_sha,
            'tree'      => array_values($entries),
        ]);
    }

    /**
     * Create a commit.
     *
     * @param string[]                   $parents Parent commit SHAs.
     * @param array{name: string, email: string}|null $author Optional commit author.
     * @return array<string, mixed>|WP_Error
     */
    public function create_commit(string $message, string $tree_sha, array $parents, ?array $author = null) {
        $endpoint = sprintf('/repos/%s/%s/git/commits', rawurlencode($this->owner), rawurlencode($this->repo));

        $body = [
            'message' => $message,
            'tree'    => $tree_sha,
            'parents' => array_values($parents),
        ];

        if ($author && !empty($author['name']) && !empty($author['email'])) {
            $body['author'] = [
                'name'  => $author['name'],
                'email' => $author['email'],
                'date'  => gmdate('c'),
            ];
        }

        return $this->client->post($endpoint, $body);
    }

    /**
     * Move a branch to a new commit. Never forced, so a branch that moved on
     * GitHub in the meantime is reported instead of overwritten.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function update_branch(string $branch, string $commit_sha) {
        $endpoint = sprintf(
            '/repos/%s/%s/git/refs/heads/%s',
            rawurlencode($this->owner),
            rawurlencode($this->repo),
            $this->encode_branch($branch)
        );

        $response = $this->client->patch($endpoint, [
            'sha'   => $commit_sha,
            'force' => false,
        ]);

        if (is_wp_error($response) && $this->client->last_status() === 422) {
            return new WP_Error(
                'branch_moved',
                __('The branch changed on GitHub while this push was running. Pull the latest changes first, then push again.', 'github-sync')
            );
        }

        return $response;
    }

    /**
     * Branch names may contain slashes, which must survive URL encoding.
     */
    private function encode_branch(string $branch): string {
        return implode('/', array_map('rawurlencode', explode('/', trim($branch, '/'))));
    }
}
