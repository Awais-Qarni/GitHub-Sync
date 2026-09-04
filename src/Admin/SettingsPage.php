<?php

namespace GithubSync\Admin;

use GithubSync\GitHub\TokenProvider;
use GithubSync\Security\Encryption;
use GithubSync\Support\Settings;

/**
 * The Settings screen: how this site connects to GitHub, and how syncs behave.
 */
class SettingsPage {

    private const NONCE_ACTION = 'github_sync_save_settings';
    private const NONCE_FIELD = 'github_sync_settings_nonce';

    /**
     * Render the screen, saving first when the form was submitted.
     */
    public function render(): void {
        if (!current_user_can(Dashboard::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage GitHub Sync.', 'github-sync'));
        }

        if (isset($_POST[self::NONCE_FIELD])) {
            $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]));

            if (wp_verify_nonce($nonce, self::NONCE_ACTION)) {
                $this->save(wp_unslash($_POST));
            } else {
                add_settings_error(
                    'github_sync_messages',
                    'github_sync_nonce',
                    __('The form expired. Please try saving again.', 'github-sync'),
                    'error'
                );
            }
        }

        $settings = Settings::all();
        $has_private_key = Settings::private_key() !== '';
        $has_access_token = Settings::access_token() !== '';
        $app_id_locked = defined('GITHUB_SYNC_APP_ID') && GITHUB_SYNC_APP_ID;
        $key_locked = defined('GITHUB_SYNC_PRIVATE_KEY') && GITHUB_SYNC_PRIVATE_KEY;
        $token_locked = defined('GITHUB_SYNC_TOKEN') && GITHUB_SYNC_TOKEN;

        require GITHUB_SYNC_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Validate and store the submitted settings.
     *
     * @param array<string, mixed> $post
     */
    private function save(array $post): void {
        $values = [];
        $credentials_changed = false;

        $method = isset($post['auth_method']) && $post['auth_method'] === Settings::AUTH_TOKEN
            ? Settings::AUTH_TOKEN
            : Settings::AUTH_APP;

        if ($method !== Settings::auth_method()) {
            $credentials_changed = true;
        }

        $values['auth_method'] = $method;

        if (isset($post['app_id'])) {
            $app_id = preg_replace('/[^0-9]/', '', (string) $post['app_id']);

            if ($app_id !== (string) Settings::get('app_id', '')) {
                $credentials_changed = true;
            }

            $values['app_id'] = $app_id;
        }

        if (!empty($post['private_key'])) {
            $private_key = trim((string) $post['private_key']);

            if (strpos($private_key, 'PRIVATE KEY') === false) {
                add_settings_error(
                    'github_sync_messages',
                    'github_sync_key',
                    __('That does not look like a PEM private key, so it was not saved.', 'github-sync'),
                    'error'
                );
            } else {
                $values['private_key'] = Encryption::encrypt($private_key);
                $credentials_changed = true;
            }
        }

        if (!empty($post['clear_private_key'])) {
            $values['private_key'] = '';
            $credentials_changed = true;
        }

        if (!empty($post['access_token'])) {
            $values['access_token'] = Encryption::encrypt(trim((string) $post['access_token']));
            $credentials_changed = true;
        }

        if (!empty($post['clear_access_token'])) {
            $values['access_token'] = '';
            $credentials_changed = true;
        }

        if (isset($post['installation_id'])) {
            $installation_id = (int) $post['installation_id'];

            if ($installation_id !== (int) Settings::get('installation_id', 0)) {
                $credentials_changed = true;
            }

            $values['installation_id'] = $installation_id;
        }

        $values['default_commit_message'] = sanitize_text_field((string) ($post['default_commit_message'] ?? ''));
        $values['commit_author_name'] = sanitize_text_field((string) ($post['commit_author_name'] ?? ''));

        $email = sanitize_email((string) ($post['commit_author_email'] ?? ''));
        $values['commit_author_email'] = is_email($email) ? $email : '';

        $values['batch_size'] = $this->clamp((int) ($post['batch_size'] ?? 25), 1, 200);
        $values['step_seconds'] = $this->clamp((int) ($post['step_seconds'] ?? 15), 5, 50);
        $values['max_file_size_mb'] = $this->clamp((int) ($post['max_file_size_mb'] ?? 10), 1, 90);
        $values['log_retention_days'] = $this->clamp((int) ($post['log_retention_days'] ?? 30), 0, 365);
        $values['remove_data_on_uninstall'] = !empty($post['remove_data_on_uninstall']);

        Settings::update($values);

        if ($credentials_changed) {
            TokenProvider::flush_cache();
        }

        add_settings_error(
            'github_sync_messages',
            'github_sync_saved',
            __('Settings saved.', 'github-sync'),
            'updated'
        );
    }

    private function clamp(int $value, int $min, int $max): int {
        return max($min, min($max, $value));
    }
}
