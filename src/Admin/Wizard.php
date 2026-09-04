<?php

namespace GithubSync\Admin;

use GithubSync\Support\ExclusionFilter;
use GithubSync\Support\Settings;

/**
 * The Add Mapping screen. The steps themselves are rendered by the admin script.
 */
class Wizard {

    /**
     * Render the screen.
     */
    public function render(): void {
        if (!current_user_can(Dashboard::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage GitHub Sync.', 'github-sync'));
        }

        // The suggested rules are pre-filled into the form by the admin script;
        // only the protected ones need to be shown as a note.
        $is_connected = Settings::is_connected();
        $protected_exclusions = ExclusionFilter::protected_rules();

        require GITHUB_SYNC_PLUGIN_DIR . 'templates/admin/wizard.php';
    }
}
