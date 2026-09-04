<?php
/**
 * Settings screen.
 *
 * @var array<string, mixed> $settings         Current settings merged with defaults.
 * @var bool                 $has_private_key  Whether a private key is stored.
 * @var bool                 $has_access_token Whether a token is stored.
 * @var bool                 $app_id_locked    Whether the App ID comes from wp-config.php.
 * @var bool                 $key_locked       Whether the private key comes from wp-config.php.
 * @var bool                 $token_locked     Whether the token comes from wp-config.php.
 */

defined('ABSPATH') || exit;

$auth_method = $settings['auth_method'] ?? 'app';
?>
<div class="wrap github-sync">
    <div class="github-sync-page-head">
        <div>
            <h1><?php esc_html_e('GitHub Sync Settings', 'github-sync'); ?></h1>
            <p class="github-sync-page-head__sub">
                <?php esc_html_e('How this site talks to GitHub, and how syncing behaves.', 'github-sync'); ?>
            </p>
        </div>

        <div class="github-sync-connection-status">
            <button type="button" class="button button-secondary" id="github-sync-test-connection">
                <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                <?php esc_html_e('Test connection', 'github-sync'); ?>
            </button>
            <span class="github-sync-connection-result" role="status"></span>
        </div>
    </div>

    <?php settings_errors('github_sync_messages'); ?>

    <form method="post" action="" class="github-sync-settings-form">
        <?php wp_nonce_field('github_sync_save_settings', 'github_sync_settings_nonce'); ?>

        <h2><?php esc_html_e('Connection', 'github-sync'); ?></h2>
        <p class="description">
            <?php esc_html_e('Syncing runs only when you click Pull or Push, so GitHub never needs to reach your site. No webhook is required.', 'github-sync'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Connection method', 'github-sync'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="auth_method" value="app" <?php checked($auth_method, 'app'); ?> class="github-sync-auth-toggle">
                                <?php esc_html_e('GitHub App (recommended for organisations)', 'github-sync'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="auth_method" value="token" <?php checked($auth_method, 'token'); ?> class="github-sync-auth-toggle">
                                <?php esc_html_e('Personal access token (quickest to set up)', 'github-sync'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="github-sync-auth-panel" data-auth-panel="app">
            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e('Setting up a GitHub App', 'github-sync'); ?></strong></p>
                <ol>
                    <li><?php echo wp_kses_post(__('In GitHub, open <strong>Settings &rarr; Developer settings &rarr; GitHub Apps</strong> and choose <strong>New GitHub App</strong>.', 'github-sync')); ?></li>
                    <li><?php echo wp_kses_post(__('Give it a name and a homepage URL. Under <strong>Webhook</strong>, untick <strong>Active</strong>: this plugin does not use webhooks.', 'github-sync')); ?></li>
                    <li><?php echo wp_kses_post(__('Under <strong>Repository permissions</strong>, set <strong>Contents</strong> to <strong>Read and write</strong>. Nothing else is needed.', 'github-sync')); ?></li>
                    <li><?php echo wp_kses_post(__('Create the app, copy the <strong>App ID</strong>, then choose <strong>Generate a private key</strong> and open the downloaded <code>.pem</code> file.', 'github-sync')); ?></li>
                    <li><?php echo wp_kses_post(__('Choose <strong>Install App</strong> and pick the repositories you want to sync.', 'github-sync')); ?></li>
                    <li><?php esc_html_e('Paste the App ID and the private key below, save, then use Test connection.', 'github-sync'); ?></li>
                </ol>
            </div>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="app_id"><?php esc_html_e('App ID', 'github-sync'); ?></label></th>
                        <td>
                            <input name="app_id" type="text" id="app_id" class="regular-text"
                                value="<?php echo esc_attr((string) ($settings['app_id'] ?? '')); ?>"
                                <?php disabled($app_id_locked); ?>>
                            <?php if ($app_id_locked) : ?>
                                <p class="description"><?php esc_html_e('Set by GITHUB_SYNC_APP_ID in wp-config.php.', 'github-sync'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="private_key"><?php esc_html_e('Private key (PEM)', 'github-sync'); ?></label></th>
                        <td>
                            <?php if ($key_locked) : ?>
                                <p><?php esc_html_e('Set by GITHUB_SYNC_PRIVATE_KEY in wp-config.php.', 'github-sync'); ?></p>
                            <?php else : ?>
                                <textarea name="private_key" id="private_key" rows="5" class="large-text code"
                                    placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea>
                                <p class="description">
                                    <?php
                                    echo $has_private_key
                                        ? esc_html__('A private key is saved. Leave this empty to keep it.', 'github-sync')
                                        : esc_html__('Paste the whole contents of the .pem file, including the BEGIN and END lines.', 'github-sync');
                                    ?>
                                </p>
                                <?php if ($has_private_key) : ?>
                                    <label>
                                        <input type="checkbox" name="clear_private_key" value="1">
                                        <?php esc_html_e('Remove the saved private key', 'github-sync'); ?>
                                    </label>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="installation_id"><?php esc_html_e('Installation ID', 'github-sync'); ?></label></th>
                        <td>
                            <input name="installation_id" type="number" id="installation_id" class="small-text" min="0"
                                value="<?php echo esc_attr((string) ($settings['installation_id'] ?? 0)); ?>">
                            <p class="description"><?php esc_html_e('Filled in automatically the first time the plugin talks to GitHub. Leave it at 0 unless the app is installed on several accounts.', 'github-sync'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="github-sync-auth-panel" data-auth-panel="token">
            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e('Using a personal access token', 'github-sync'); ?></strong></p>
                <ol>
                    <li><?php echo wp_kses_post(__('In GitHub, open <strong>Settings &rarr; Developer settings &rarr; Personal access tokens &rarr; Fine-grained tokens</strong>.', 'github-sync')); ?></li>
                    <li><?php esc_html_e('Generate a token, choose the repositories you want to sync, and set an expiry date you are happy with.', 'github-sync'); ?></li>
                    <li><?php echo wp_kses_post(__('Under <strong>Repository permissions</strong>, set <strong>Contents</strong> to <strong>Read and write</strong>.', 'github-sync')); ?></li>
                    <li><?php esc_html_e('Copy the token and paste it below. GitHub only shows it once.', 'github-sync'); ?></li>
                </ol>
            </div>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="access_token"><?php esc_html_e('Access token', 'github-sync'); ?></label></th>
                        <td>
                            <?php if ($token_locked) : ?>
                                <p><?php esc_html_e('Set by GITHUB_SYNC_TOKEN in wp-config.php.', 'github-sync'); ?></p>
                            <?php else : ?>
                                <input name="access_token" type="password" id="access_token" class="regular-text"
                                    autocomplete="new-password" placeholder="github_pat_...">
                                <p class="description">
                                    <?php
                                    echo $has_access_token
                                        ? esc_html__('A token is saved. Leave this empty to keep it.', 'github-sync')
                                        : esc_html__('The token is encrypted before it is stored.', 'github-sync');
                                    ?>
                                </p>
                                <?php if ($has_access_token) : ?>
                                    <label>
                                        <input type="checkbox" name="clear_access_token" value="1">
                                        <?php esc_html_e('Remove the saved token', 'github-sync'); ?>
                                    </label>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2><?php esc_html_e('Commits', 'github-sync'); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="default_commit_message"><?php esc_html_e('Default commit message', 'github-sync'); ?></label></th>
                    <td>
                        <input name="default_commit_message" type="text" id="default_commit_message" class="regular-text"
                            value="<?php echo esc_attr((string) ($settings['default_commit_message'] ?? '')); ?>">
                        <p class="description"><?php esc_html_e('Pre-filled in the push dialog. You can change it every time you push.', 'github-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="commit_author_name"><?php esc_html_e('Commit author name', 'github-sync'); ?></label></th>
                    <td>
                        <input name="commit_author_name" type="text" id="commit_author_name" class="regular-text"
                            value="<?php echo esc_attr((string) ($settings['commit_author_name'] ?? '')); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="commit_author_email"><?php esc_html_e('Commit author email', 'github-sync'); ?></label></th>
                    <td>
                        <input name="commit_author_email" type="email" id="commit_author_email" class="regular-text"
                            value="<?php echo esc_attr((string) ($settings['commit_author_email'] ?? '')); ?>">
                        <p class="description"><?php esc_html_e('Leave both fields empty to let GitHub attribute commits to the connected account.', 'github-sync'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Performance', 'github-sync'); ?></h2>
        <p class="description">
            <?php esc_html_e('A sync runs in small steps so it never hits the PHP time limit. Lower these values on slower hosting, raise them to sync large repositories faster.', 'github-sync'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="batch_size"><?php esc_html_e('Files per step', 'github-sync'); ?></label></th>
                    <td>
                        <input name="batch_size" type="number" id="batch_size" class="small-text" min="1" max="200"
                            value="<?php echo esc_attr((string) ($settings['batch_size'] ?? 25)); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="step_seconds"><?php esc_html_e('Seconds per step', 'github-sync'); ?></label></th>
                    <td>
                        <input name="step_seconds" type="number" id="step_seconds" class="small-text" min="5" max="50"
                            value="<?php echo esc_attr((string) ($settings['step_seconds'] ?? 15)); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="max_file_size_mb"><?php esc_html_e('Largest file (MB)', 'github-sync'); ?></label></th>
                    <td>
                        <input name="max_file_size_mb" type="number" id="max_file_size_mb" class="small-text" min="1" max="90"
                            value="<?php echo esc_attr((string) ($settings['max_file_size_mb'] ?? 10)); ?>">
                        <p class="description"><?php esc_html_e('Bigger files are skipped and listed in the log.', 'github-sync'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Housekeeping', 'github-sync'); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="log_retention_days"><?php esc_html_e('Keep logs for (days)', 'github-sync'); ?></label></th>
                    <td>
                        <input name="log_retention_days" type="number" id="log_retention_days" class="small-text" min="0" max="365"
                            value="<?php echo esc_attr((string) ($settings['log_retention_days'] ?? 30)); ?>">
                        <p class="description"><?php esc_html_e('Set to 0 to keep every entry.', 'github-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('On uninstall', 'github-sync'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="remove_data_on_uninstall" value="1"
                                <?php checked(!empty($settings['remove_data_on_uninstall'])); ?>>
                            <?php esc_html_e('Delete all mappings, logs, backups and settings when the plugin is deleted', 'github-sync'); ?>
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
