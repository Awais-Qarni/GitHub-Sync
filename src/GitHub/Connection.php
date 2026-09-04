<?php

namespace GithubSync\GitHub;

use GithubSync\Support\Settings;
use WP_Error;

/**
 * Single entry point for getting an authenticated client, and for reporting
 * connection status on the Settings screen.
 */
class Connection {

    /**
     * An authenticated client, or the reason one could not be built.
     *
     * @return Client|WP_Error
     */
    public static function client() {
        $token = TokenProvider::get_token();

        if (is_wp_error($token)) {
            return $token;
        }

        $client = new Client();
        $client->set_token($token);

        return $client;
    }

    /**
     * Check the saved credentials and describe what they can reach.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function test() {
        if (!Settings::is_connected()) {
            return new WP_Error('not_configured', __('No GitHub credentials are saved yet.', 'github-sync'));
        }

        if (Settings::auth_method() === Settings::AUTH_TOKEN) {
            return self::test_token();
        }

        return self::test_app();
    }

    /**
     * Personal access token: confirm who it belongs to and how many repositories it sees.
     *
     * @return array<string, mixed>|WP_Error
     */
    private static function test_token() {
        $client = self::client();

        if (is_wp_error($client)) {
            return $client;
        }

        $user = $client->get('/user');

        if (is_wp_error($user)) {
            return $user;
        }

        return [
            'method'      => Settings::AUTH_TOKEN,
            'account'     => $user['login'] ?? '',
            'account_url' => $user['html_url'] ?? '',
            'message'     => sprintf(
                /* translators: %s: GitHub account name. */
                __('Connected to GitHub as %s.', 'github-sync'),
                $user['login'] ?? __('unknown account', 'github-sync')
            ),
        ];
    }

    /**
     * GitHub App: confirm the app, the installation and the repositories it covers.
     *
     * @return array<string, mixed>|WP_Error
     */
    private static function test_app() {
        $jwt = TokenProvider::get_jwt();

        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $app_client = new Client();
        $app_client->set_token($jwt);

        $app = $app_client->get('/app');

        if (is_wp_error($app)) {
            return $app;
        }

        $installation_id = TokenProvider::get_installation_id();

        if (is_wp_error($installation_id)) {
            return $installation_id;
        }

        $client = self::client();

        if (is_wp_error($client)) {
            return $client;
        }

        $repos = $client->get('/installation/repositories?per_page=1');

        if (is_wp_error($repos)) {
            return $repos;
        }

        $count = (int) ($repos['total_count'] ?? 0);

        return [
            'method'          => Settings::AUTH_APP,
            'app_name'        => $app['name'] ?? '',
            'account'         => $app['owner']['login'] ?? '',
            'installation_id' => (int) $installation_id,
            'repo_count'      => $count,
            'message'         => sprintf(
                /* translators: 1: GitHub App name, 2: number of repositories. */
                _n(
                    'Connected as the app %1$s, with access to %2$d repository.',
                    'Connected as the app %1$s, with access to %2$d repositories.',
                    $count,
                    'github-sync'
                ),
                $app['name'] ?? __('unknown app', 'github-sync'),
                $count
            ),
        ];
    }

    /**
     * Repositories the connection can reach.
     *
     * @return array<int, array<string, string>>|WP_Error
     */
    public static function list_repositories() {
        $client = self::client();

        if (is_wp_error($client)) {
            return $client;
        }

        if (Settings::auth_method() === Settings::AUTH_TOKEN) {
            $repos = $client->get_all_pages('/user/repos?affiliation=owner,collaborator,organization_member&sort=updated');
        } else {
            $repos = $client->get_all_pages('/installation/repositories', 'repositories');
        }

        if (is_wp_error($repos)) {
            return $repos;
        }

        $list = [];

        foreach ($repos as $repo) {
            if (empty($repo['name']) || empty($repo['owner']['login'])) {
                continue;
            }

            $list[] = [
                'owner'          => (string) $repo['owner']['login'],
                'name'           => (string) $repo['name'],
                'full_name'      => (string) ($repo['full_name'] ?? $repo['owner']['login'] . '/' . $repo['name']),
                'default_branch' => (string) ($repo['default_branch'] ?? ''),
                'private'        => !empty($repo['private']),
            ];
        }

        usort($list, static function (array $a, array $b): int {
            return strcasecmp($a['full_name'], $b['full_name']);
        });

        return $list;
    }

    /**
     * Branch names in a repository.
     *
     * @return string[]|WP_Error
     */
    public static function list_branches(string $owner, string $repo) {
        $client = self::client();

        if (is_wp_error($client)) {
            return $client;
        }

        $branches = $client->get_all_pages('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/branches');

        if (is_wp_error($branches)) {
            return $branches;
        }

        $names = [];

        foreach ($branches as $branch) {
            if (!empty($branch['name'])) {
                $names[] = (string) $branch['name'];
            }
        }

        return $names;
    }
}
