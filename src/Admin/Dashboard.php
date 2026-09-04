<?php

namespace GithubSync\Admin;

use GithubSync\Database\Models\Mapping;
use GithubSync\Support\ExclusionFilter;
use GithubSync\Support\Settings;

/**
 * Admin menus, screens and asset loading.
 */
class Dashboard {

    public const CAPABILITY = 'github_sync_manage';

    public const PAGE_DASHBOARD = 'github-sync';
    public const PAGE_WIZARD = 'github-sync-wizard';
    public const PAGE_LOGS = 'github-sync-logs';
    public const PAGE_SETTINGS = 'github-sync-settings';

    /**
     * Hook the admin screens up.
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Menu and submenus.
     */
    public function register_menus(): void {
        add_menu_page(
            __('GitHub Sync', 'github-sync'),
            __('GitHub Sync', 'github-sync'),
            self::CAPABILITY,
            self::PAGE_DASHBOARD,
            [$this, 'render_dashboard'],
            'dashicons-cloud',
            80
        );

        add_submenu_page(
            self::PAGE_DASHBOARD,
            __('Mappings', 'github-sync'),
            __('Mappings', 'github-sync'),
            self::CAPABILITY,
            self::PAGE_DASHBOARD,
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            self::PAGE_DASHBOARD,
            __('Add Mapping', 'github-sync'),
            __('Add Mapping', 'github-sync'),
            self::CAPABILITY,
            self::PAGE_WIZARD,
            [new Wizard(), 'render']
        );

        add_submenu_page(
            self::PAGE_DASHBOARD,
            __('Logs', 'github-sync'),
            __('Logs', 'github-sync'),
            self::CAPABILITY,
            self::PAGE_LOGS,
            [new Logs(), 'render']
        );

        add_submenu_page(
            self::PAGE_DASHBOARD,
            __('Settings', 'github-sync'),
            __('Settings', 'github-sync'),
            self::CAPABILITY,
            self::PAGE_SETTINGS,
            [new SettingsPage(), 'render']
        );
    }

    /**
     * Load the admin styles and script on this plugin's screens only.
     */
    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'github-sync') === false) {
            return;
        }

        wp_enqueue_style(
            'github-sync-admin',
            GITHUB_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $this->asset_version('assets/css/admin.css')
        );

        wp_enqueue_script(
            'github-sync-admin',
            GITHUB_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            [],
            $this->asset_version('assets/js/admin.js'),
            true
        );

        wp_localize_script('github-sync-admin', 'githubSyncData', [
            'restUrl'        => esc_url_raw(rest_url('github-sync/v1/')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'dashboardUrl'   => esc_url_raw(admin_url('admin.php?page=' . self::PAGE_DASHBOARD)),
            'wizardUrl'      => esc_url_raw(admin_url('admin.php?page=' . self::PAGE_WIZARD)),
            'settingsUrl'    => esc_url_raw(admin_url('admin.php?page=' . self::PAGE_SETTINGS)),
            'logsUrl'        => esc_url_raw(admin_url('admin.php?page=' . self::PAGE_LOGS)),
            'isConnected'    => Settings::is_connected(),
            'defaultCommitMessage' => (string) Settings::get('default_commit_message', ''),
            'suggestedExclusions'  => ExclusionFilter::suggested_rules(),
            'i18n'           => $this->script_strings(),
        ]);
    }

    /**
     * Every string the admin script needs, translated server side.
     *
     * @return array<string, string>
     */
    private function script_strings(): array {
        return [
            'genericError'    => __('Something went wrong. Please try again.', 'github-sync'),
            'loading'         => __('Loading...', 'github-sync'),
            'noMappings'      => __('No mappings yet. Add one to start syncing.', 'github-sync'),
            'pull'            => __('Pull from GitHub', 'github-sync'),
            'push'            => __('Push to GitHub', 'github-sync'),
            'cancel'          => __('Cancel', 'github-sync'),
            'delete'          => __('Delete', 'github-sync'),
            'pause'           => __('Pause', 'github-sync'),
            'resume'          => __('Resume', 'github-sync'),
            'confirmPull'     => __('Pull the latest files from GitHub? Files in the destination folder will be replaced, and a backup is taken first.', 'github-sync'),
            'confirmDelete'   => __('Delete this mapping? Files already on your site are kept, but syncing stops.', 'github-sync'),
            'confirmCancel'   => __('Stop this sync?', 'github-sync'),
            'commitTitle'     => __('Describe your changes', 'github-sync'),
            'commitLabel'     => __('Commit message', 'github-sync'),
            'commitHint'      => __('This message appears in the GitHub commit history.', 'github-sync'),
            'commitRequired'  => __('Enter a commit message before pushing.', 'github-sync'),
            'startPush'       => __('Push to GitHub', 'github-sync'),
            'close'           => __('Close', 'github-sync'),
            'never'           => __('Never', 'github-sync'),
            'lastSync'        => __('Last sync', 'github-sync'),
            'trackedFiles'    => __('Tracked files', 'github-sync'),
            'branch'          => __('Branch', 'github-sync'),
            'destination'     => __('Destination', 'github-sync'),
            'repository'      => __('Repository', 'github-sync'),
            'status'          => __('Status', 'github-sync'),
            'actions'         => __('Actions', 'github-sync'),
            'active'          => __('Active', 'github-sync'),
            'paused'          => __('Paused', 'github-sync'),
            'syncing'         => __('Syncing', 'github-sync'),
            'completed'       => __('Completed', 'github-sync'),
            'failed'          => __('Failed', 'github-sync'),
            'cancelled'       => __('Cancelled', 'github-sync'),
            'notConnected'    => __('Connect your GitHub account on the Settings screen before adding a mapping.', 'github-sync'),
            'openSettings'    => __('Open Settings', 'github-sync'),
            'viewLogs'        => __('View logs', 'github-sync'),
            'testing'         => __('Testing...', 'github-sync'),
            'addMapping'      => __('Add Mapping', 'github-sync'),
            'selectRepository' => __('Select a repository', 'github-sync'),
            'selectBranch'    => __('Select a branch', 'github-sync'),
            'privateSuffix'   => __('(private)', 'github-sync'),
            'foldersRules'    => __('Folders and rules', 'github-sync'),
            'repoFolder'      => __('Folder in the repository', 'github-sync'),
            'repoFolderHint'  => __('Leave empty to sync the whole repository', 'github-sync'),
            'destTypeLabel'   => __('Destination type', 'github-sync'),
            'pluginFolder'    => __('Plugin folder', 'github-sync'),
            'themeFolder'     => __('Theme folder', 'github-sync'),
            'customFolder'    => __('Custom folder inside wp-content', 'github-sync'),
            'destFolder'      => __('Destination folder', 'github-sync'),
            'destFolderHint'  => __('The folder to sync into. It is created if it does not exist yet.', 'github-sync'),
            'directions'      => __('Allowed directions', 'github-sync'),
            'dirBoth'         => __('Pull and push', 'github-sync'),
            'dirPull'         => __('Pull only', 'github-sync'),
            'dirPush'         => __('Push only', 'github-sync'),
            'deletions'       => __('Deletions', 'github-sync'),
            'deletionsLabel'  => __('Also remove files that were deleted on the other side', 'github-sync'),
            'ignorePaths'     => __('Ignore these paths', 'github-sync'),
            'ignoreHint'      => __('One rule per line: a folder such as tests/, a file name, or a pattern such as *.zip. These lines are only a suggestion, so delete any of them you do want synced.', 'github-sync'),
            'createMapping'   => __('Create mapping', 'github-sync'),
            'noRepos'         => __('This connection cannot see any repositories yet. Check that the app or token has access to them.', 'github-sync'),
            'edit'            => __('Settings', 'github-sync'),
            'editHint'        => __('Change the ignore list, the allowed directions and how deletions are handled.', 'github-sync'),
            'pauseHint'       => __('Hide the Pull and Push buttons for this mapping so nobody syncs it by accident. Nothing is deleted, and you can resume at any time.', 'github-sync'),
            'resumeHint'      => __('Show the Pull and Push buttons again for this mapping.', 'github-sync'),
            'deleteHint'      => __('Forget this mapping. Files already on your site and in GitHub are left alone.', 'github-sync'),
            'settingsSaved'   => __('Mapping settings saved.', 'github-sync'),
            'added'           => __('added', 'github-sync'),
            'updated'         => __('updated', 'github-sync'),
            'deleted'         => __('deleted', 'github-sync'),
            'nothingChanged'  => __('nothing needed changing', 'github-sync'),
            'destCustomHint'  => __('Any folder inside wp-content, for example mu-plugins/my-code.', 'github-sync'),
            'destCustomPlaceholder' => __('my-folder', 'github-sync'),
        ];
    }

    /**
     * Use the file timestamp so browsers pick up changes during development.
     */
    private function asset_version(string $relative_path): string {
        $file = GITHUB_SYNC_PLUGIN_DIR . $relative_path;
        $modified = file_exists($file) ? filemtime($file) : 0;

        return $modified ? GITHUB_SYNC_VERSION . '.' . $modified : GITHUB_SYNC_VERSION;
    }

    /**
     * Mappings screen.
     */
    public function render_dashboard(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage GitHub Sync.', 'github-sync'));
        }

        $is_connected = Settings::is_connected();
        $has_mappings = (bool) Mapping::all();

        require GITHUB_SYNC_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }
}
