<?php
/**
 * Add Mapping screen.
 *
 * @var bool     $is_connected          Whether GitHub credentials are saved.
 * @var string[] $protected_exclusions  Rules that always apply and cannot be removed.
 */

defined('ABSPATH') || exit;
?>
<div class="wrap github-sync">
    <div class="github-sync-page-head">
        <h1><?php esc_html_e('Add Mapping', 'github-sync'); ?></h1>
        <p class="github-sync-page-head__sub">
            <?php esc_html_e('A mapping links one folder in a GitHub branch to one folder on this site. You choose when to pull or push.', 'github-sync'); ?>
        </p>
    </div>

    <?php if (!$is_connected) : ?>
        <div class="github-sync-callout github-sync-callout--warning">
            <p><strong><?php esc_html_e('Connect your GitHub account before adding a mapping.', 'github-sync'); ?></strong></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=github-sync-settings')); ?>">
                    <?php esc_html_e('Open Settings', 'github-sync'); ?>
                </a>
            </p>
        </div>
    <?php else : ?>
        <div id="github-sync-wizard-app">
            <div class="github-sync-card github-sync-card--loading">
                <p><?php esc_html_e('Loading your repositories...', 'github-sync'); ?></p>
            </div>
        </div>

        <p class="github-sync-fineprint">
            <?php
            printf(
                /* translators: %s: list of file patterns. */
                esc_html__('%s is always skipped, because a repository folder must never sync into or out of itself.', 'github-sync'),
                '<code>' . esc_html(implode(', ', $protected_exclusions)) . '</code>'
            );
            ?>
        </p>
    <?php endif; ?>
</div>
