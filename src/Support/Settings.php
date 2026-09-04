<?php

namespace GithubSync\Support;

use GithubSync\Security\Encryption;

/**
 * Typed access to the single plugin option, with defaults in one place.
 */
class Settings {

    public const OPTION_KEY = 'github_sync_settings';

    public const AUTH_APP = 'app';
    public const AUTH_TOKEN = 'token';

    /**
     * Defaults for every supported key.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            'auth_method'             => self::AUTH_APP,
            'app_id'                  => '',
            'private_key'             => '',
            'installation_id'         => 0,
            'access_token'            => '',
            'commit_author_name'      => '',
            'commit_author_email'     => '',
            'default_commit_message'  => 'Update from WordPress',
            'batch_size'              => 25,
            'step_seconds'            => 15,
            'max_file_size_mb'        => 10,
            'log_retention_days'      => 30,
            'remove_data_on_uninstall' => false,
        ];
    }

    /**
     * All settings, merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function all(): array {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * A single setting.
     *
     * @return mixed
     */
    public static function get(string $key, $fallback = null) {
        $settings = self::all();

        return $settings[$key] ?? $fallback;
    }

    /**
     * Persist a partial set of settings.
     *
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        update_option(self::OPTION_KEY, array_merge($stored, $values), false);
    }

    /**
     * The configured authentication method.
     */
    public static function auth_method(): string {
        $method = (string) self::get('auth_method', self::AUTH_APP);

        return $method === self::AUTH_TOKEN ? self::AUTH_TOKEN : self::AUTH_APP;
    }

    /**
     * GitHub App ID, from wp-config.php when defined.
     */
    public static function app_id(): string {
        if (defined('GITHUB_SYNC_APP_ID') && GITHUB_SYNC_APP_ID) {
            return (string) GITHUB_SYNC_APP_ID;
        }

        return (string) self::get('app_id', '');
    }

    /**
     * GitHub App private key, from wp-config.php when defined.
     */
    public static function private_key(): string {
        if (defined('GITHUB_SYNC_PRIVATE_KEY') && GITHUB_SYNC_PRIVATE_KEY) {
            return (string) GITHUB_SYNC_PRIVATE_KEY;
        }

        $stored = (string) self::get('private_key', '');

        return $stored === '' ? '' : Encryption::decrypt($stored);
    }

    /**
     * Personal access token, from wp-config.php when defined.
     */
    public static function access_token(): string {
        if (defined('GITHUB_SYNC_TOKEN') && GITHUB_SYNC_TOKEN) {
            return (string) GITHUB_SYNC_TOKEN;
        }

        $stored = (string) self::get('access_token', '');

        return $stored === '' ? '' : Encryption::decrypt($stored);
    }

    /**
     * True when enough credentials exist to talk to GitHub.
     */
    public static function is_connected(): bool {
        if (self::auth_method() === self::AUTH_TOKEN) {
            return self::access_token() !== '';
        }

        return self::app_id() !== '' && self::private_key() !== '';
    }

    /**
     * How many files one sync step processes before returning to the browser.
     */
    public static function batch_size(): int {
        return max(1, min(200, (int) self::get('batch_size', 25)));
    }

    /**
     * How long one sync step may run, in seconds.
     */
    public static function step_seconds(): int {
        return max(5, min(50, (int) self::get('step_seconds', 15)));
    }

    /**
     * Largest single file the plugin will transfer, in bytes.
     */
    public static function max_file_bytes(): int {
        $mb = max(1, min(90, (int) self::get('max_file_size_mb', 10)));

        return $mb * 1024 * 1024;
    }
}
