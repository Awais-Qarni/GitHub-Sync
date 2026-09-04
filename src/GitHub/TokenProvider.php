<?php

namespace GithubSync\GitHub;

use GithubSync\Support\Settings;
use WP_Error;

/**
 * Produces the bearer token used for GitHub requests.
 *
 * Two connection styles are supported: a GitHub App, which mints a short lived
 * installation token, and a personal access token, which is used directly.
 */
class TokenProvider {

    private const TOKEN_TRANSIENT_PREFIX = 'github_sync_token_';

    private const INSTALLATION_TRANSIENT = 'github_sync_installation_lookup';

    /**
     * The token to authenticate normal API calls with.
     *
     * @return string|WP_Error
     */
    public static function get_token() {
        if (Settings::auth_method() === Settings::AUTH_TOKEN) {
            $token = Settings::access_token();

            if ($token === '') {
                return new WP_Error(
                    'missing_access_token',
                    __('No GitHub personal access token is saved. Add one on the Settings screen.', 'github-sync')
                );
            }

            return $token;
        }

        $installation_id = self::get_installation_id();

        if (is_wp_error($installation_id)) {
            return $installation_id;
        }

        return self::get_installation_token((int) $installation_id);
    }

    /**
     * A short lived installation access token, cached until shortly before it expires.
     *
     * @return string|WP_Error
     */
    public static function get_installation_token(int $installation_id) {
        $cache_key = self::TOKEN_TRANSIENT_PREFIX . $installation_id;
        $cached = get_transient($cache_key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $jwt = self::get_jwt();

        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $client = new Client();
        $client->set_token($jwt);

        $response = $client->post("/app/installations/{$installation_id}/access_tokens");

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['token'])) {
            return new WP_Error(
                'token_generation_failed',
                __('GitHub did not return an installation token. Check that the app is installed on the repository.', 'github-sync')
            );
        }

        $token = (string) $response['token'];
        $expires_in = isset($response['expires_at']) ? strtotime($response['expires_at']) - time() - 300 : 3000;

        if ($expires_in > 0) {
            set_transient($cache_key, $token, $expires_in);
        }

        return $token;
    }

    /**
     * A signed app JWT, used only for app level endpoints.
     *
     * @return string|WP_Error
     */
    public static function get_jwt() {
        $app_id = Settings::app_id();
        $private_key = Settings::private_key();

        if ($app_id === '' || $private_key === '') {
            return new WP_Error(
                'missing_app_credentials',
                __('The GitHub App ID or private key is missing. Add them on the Settings screen.', 'github-sync')
            );
        }

        return AppAuthenticator::generate_jwt($app_id, $private_key);
    }

    /**
     * The installation to act as, discovering and saving it when unset.
     *
     * @return int|WP_Error
     */
    public static function get_installation_id() {
        $stored = (int) Settings::get('installation_id', 0);

        if ($stored > 0) {
            return $stored;
        }

        $installations = self::list_installations();

        if (is_wp_error($installations)) {
            return $installations;
        }

        if (!$installations) {
            return new WP_Error(
                'no_installation',
                __('The GitHub App is not installed on any account yet. Open GitHub and install it on the repositories you want to sync.', 'github-sync')
            );
        }

        $installation_id = (int) $installations[0]['id'];
        Settings::update(['installation_id' => $installation_id]);

        return $installation_id;
    }

    /**
     * Installations this app can act on.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public static function list_installations() {
        $cached = get_transient(self::INSTALLATION_TRANSIENT);

        if (is_array($cached)) {
            return $cached;
        }

        $jwt = self::get_jwt();

        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $client = new Client();
        $client->set_token($jwt);

        $installations = $client->get('/app/installations');

        if (is_wp_error($installations)) {
            return $installations;
        }

        $installations = is_array($installations) ? $installations : [];
        set_transient(self::INSTALLATION_TRANSIENT, $installations, 5 * MINUTE_IN_SECONDS);

        return $installations;
    }

    /**
     * Drop cached tokens, for example after the credentials change.
     */
    public static function flush_cache(): void {
        global $wpdb;

        delete_transient(self::INSTALLATION_TRANSIENT);

        $like = $wpdb->esc_like('_transient_' . self::TOKEN_TRANSIENT_PREFIX) . '%';
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

        foreach ($names ?: [] as $name) {
            delete_transient(substr($name, strlen('_transient_')));
        }
    }
}
