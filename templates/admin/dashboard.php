<?php
/**
 * Mappings screen.
 *
 * @var bool $is_connected Whether GitHub credentials are saved.
 * @var bool $has_mappings Whether any mapping exists yet.
 */

defined('ABSPATH') || exit;
?>
<div class="wrap github-sync">
    <div class="github-sync-page-head">
        <div>
            <h1><?php esc_html_e('GitHub Sync', 'github-sync'); ?></h1>
            <p class="github-sync-page-head__sub">
                <?php esc_html_e('Syncing happens when you ask for it. Pull brings GitHub changes into WordPress. Push sends your WordPress changes back with a commit message.', 'github-sync'); ?>
            </p>
        </div>

        <?php if ($is_connected) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=github-sync-wizard')); ?>" class="button button-primary button-hero github-sync-add">
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Add Mapping', 'github-sync'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$is_connected) : ?>
        <div class="github-sync-callout github-sync-callout--warning">
            <p>
                <strong><?php esc_html_e('GitHub is not connected yet.', 'github-sync'); ?></strong>
                <?php esc_html_e('Add a personal access token or your GitHub App details to start syncing.', 'github-sync'); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=github-sync-settings')); ?>">
                    <?php esc_html_e('Open Settings', 'github-sync'); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <div class="github-sync-dashboard" data-has-mappings="<?php echo $has_mappings ? '1' : '0'; ?>">
        <div class="github-sync-card github-sync-card--loading">
            <p><?php esc_html_e('Loading mappings...', 'github-sync'); ?></p>
        </div>
    </div>

    <div class="github-sync-modal" id="github-sync-commit-modal" hidden>
        <div class="github-sync-modal__backdrop" data-close-modal></div>
        <div class="github-sync-modal__panel" role="dialog" aria-modal="true" aria-labelledby="github-sync-commit-title">
            <h2 id="github-sync-commit-title">
                <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                <?php esc_html_e('Describe your changes', 'github-sync'); ?>
            </h2>
            <p class="github-sync-modal__target"></p>

            <label class="github-sync-label" for="github-sync-commit-message"><?php esc_html_e('Commit message', 'github-sync'); ?></label>
            <textarea id="github-sync-commit-message" rows="4" maxlength="1000"></textarea>
            <p class="github-sync-hint"><?php esc_html_e('This message appears in the GitHub commit history.', 'github-sync'); ?></p>

            <p class="github-sync-modal__error" role="alert"></p>

            <div class="github-sync-modal__actions">
                <button type="button" class="button" data-close-modal><?php esc_html_e('Cancel', 'github-sync'); ?></button>
                <button type="button" class="button button-primary" id="github-sync-commit-confirm">
                    <?php esc_html_e('Push to GitHub', 'github-sync'); ?>
                </button>
            </div>
        </div>
    </div>

    <div class="github-sync-modal" id="github-sync-edit-modal" hidden>
        <div class="github-sync-modal__backdrop" data-close-modal></div>
        <div class="github-sync-modal__panel" role="dialog" aria-modal="true" aria-labelledby="github-sync-edit-title">
            <h2 id="github-sync-edit-title">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <?php esc_html_e('Mapping settings', 'github-sync'); ?>
            </h2>
            <p class="github-sync-modal__target"></p>

            <label class="github-sync-label" for="github-sync-edit-direction"><?php esc_html_e('Allowed directions', 'github-sync'); ?></label>
            <select id="github-sync-edit-direction">
                <option value="both"><?php esc_html_e('Pull and push', 'github-sync'); ?></option>
                <option value="pull"><?php esc_html_e('Pull only', 'github-sync'); ?></option>
                <option value="push"><?php esc_html_e('Push only', 'github-sync'); ?></option>
            </select>

            <label class="github-sync-checkbox">
                <input type="checkbox" id="github-sync-edit-delete">
                <?php esc_html_e('Also remove files that were deleted on the other side', 'github-sync'); ?>
            </label>

            <label class="github-sync-label" for="github-sync-edit-exclusions"><?php esc_html_e('Ignore these paths', 'github-sync'); ?></label>
            <textarea id="github-sync-edit-exclusions" rows="7" class="code"></textarea>
            <p class="github-sync-hint"><?php esc_html_e('One rule per line: a folder, a file name, or a pattern such as *.zip. Delete a line to stop ignoring it.', 'github-sync'); ?></p>

            <p class="github-sync-modal__error" role="alert"></p>

            <div class="github-sync-modal__actions">
                <button type="button" class="button" data-close-modal><?php esc_html_e('Cancel', 'github-sync'); ?></button>
                <button type="button" class="button button-primary" id="github-sync-edit-save">
                    <?php esc_html_e('Save changes', 'github-sync'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
