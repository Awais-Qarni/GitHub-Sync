<?php

namespace GithubSync\API;

use GithubSync\Database\Models\FileState;
use GithubSync\Database\Models\Log;
use GithubSync\Database\Models\Mapping;
use GithubSync\Database\Models\Run;
use GithubSync\GitHub\Connection;
use GithubSync\Security\PathValidator;
use GithubSync\Sync\SyncManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Admin REST endpoints. Everything the dashboard does goes through here.
 */
class RestController {

    private const REST_NAMESPACE = 'github-sync/v1';

    /**
     * Register the routes.
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Route table.
     */
    public function register_routes(): void {
        $permission = [$this, 'check_permission'];

        register_rest_route(self::REST_NAMESPACE, '/mappings', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_mappings'],
                'permission_callback' => $permission,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_mapping'],
                'permission_callback' => $permission,
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/mappings/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_mapping'],
                'permission_callback' => $permission,
            ],
            [
                // POST is accepted as well because some hosts block PATCH.
                'methods'             => 'PATCH, PUT, POST',
                'callback'            => [$this, 'update_mapping'],
                'permission_callback' => $permission,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_mapping'],
                'permission_callback' => $permission,
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/mappings/(?P<id>\d+)/sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start_sync'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/runs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_runs'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/runs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_run'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/runs/(?P<id>\d+)/step', [
            'methods'             => 'POST',
            'callback'            => [$this, 'step_run'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/runs/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancel_run'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/logs', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_logs'],
                'permission_callback' => $permission,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'clear_logs'],
                'permission_callback' => $permission,
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/connection', [
            'methods'             => 'GET',
            'callback'            => [$this, 'test_connection'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/github/repos', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_repositories'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/github/branches', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_branches'],
            'permission_callback' => $permission,
        ]);
    }

    /**
     * Only users who can manage syncing may call these routes.
     */
    public function check_permission(): bool {
        return current_user_can('github_sync_manage');
    }

    /**
     * All mappings, each with its most recent run.
     */
    public function get_mappings(): WP_REST_Response {
        $mappings = [];

        foreach (Mapping::all() as $mapping) {
            $mappings[] = $this->mapping_payload($mapping);
        }

        return new WP_REST_Response($mappings, 200);
    }

    /**
     * One mapping.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_mapping(WP_REST_Request $request) {
        $mapping = Mapping::find((int) $request->get_param('id'));

        if (!$mapping) {
            return $this->not_found();
        }

        return new WP_REST_Response($this->mapping_payload($mapping), 200);
    }

    /**
     * Create a mapping.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_mapping(WP_REST_Request $request) {
        $params = $this->params($request);

        $mapping = new Mapping();
        $mapping->repo_owner = sanitize_text_field((string) ($params['repo_owner'] ?? ''));
        $mapping->repo_name = sanitize_text_field((string) ($params['repo_name'] ?? ''));
        $mapping->branch = sanitize_text_field((string) ($params['branch'] ?? ''));
        $mapping->source_path = PathValidator::validate_source_path((string) ($params['source_path'] ?? ''));
        $mapping->dest_type = $this->clean_dest_type((string) ($params['dest_type'] ?? 'custom'));
        $mapping->dest_path = sanitize_text_field((string) ($params['dest_path'] ?? ''));
        $mapping->sync_direction = $this->clean_direction((string) ($params['sync_direction'] ?? Mapping::DIRECTION_BOTH));
        $mapping->delete_policy = !empty($params['delete_policy']);
        $mapping->exclusions = $this->clean_exclusions($params['exclusions'] ?? []);

        if ($mapping->repo_owner === '' || $mapping->repo_name === '' || $mapping->branch === '') {
            return new WP_Error(
                'incomplete_mapping',
                __('Choose a repository and a branch first.', 'github-sync'),
                ['status' => 400]
            );
        }

        $destination = PathValidator::validate_destination($mapping->dest_path, $mapping->dest_type);

        if (is_wp_error($destination)) {
            $destination->add_data(['status' => 400]);

            return $destination;
        }

        if (Mapping::destination_taken($mapping->dest_type, $mapping->dest_path)) {
            return new WP_Error(
                'destination_taken',
                __('Another mapping already syncs into that folder.', 'github-sync'),
                ['status' => 409]
            );
        }

        if (!$mapping->save()) {
            return new WP_Error('mapping_not_saved', __('The mapping could not be saved.', 'github-sync'), ['status' => 500]);
        }

        Log::info(
            sprintf(
                /* translators: 1: repository, 2: destination folder. */
                __('Mapping created for %1$s into %2$s.', 'github-sync'),
                $mapping->repo_full_name(),
                $mapping->dest_path
            ),
            [],
            $mapping->id
        );

        return new WP_REST_Response($this->mapping_payload($mapping), 201);
    }

    /**
     * Change the settings of an existing mapping.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_mapping(WP_REST_Request $request) {
        $mapping = Mapping::find((int) $request->get_param('id'));

        if (!$mapping) {
            return $this->not_found();
        }

        $params = $this->params($request);

        if (array_key_exists('enabled', $params)) {
            $mapping->enabled = (bool) $params['enabled'];
        }

        if (array_key_exists('delete_policy', $params)) {
            $mapping->delete_policy = (bool) $params['delete_policy'];
        }

        if (array_key_exists('sync_direction', $params)) {
            $mapping->sync_direction = $this->clean_direction((string) $params['sync_direction']);
        }

        if (array_key_exists('branch', $params)) {
            $branch = sanitize_text_field((string) $params['branch']);

            if ($branch !== '' && $branch !== $mapping->branch) {
                $mapping->branch = $branch;
                // Tracking hashes describe the old branch, so start clean.
                FileState::clear($mapping->id);
                $mapping->last_commit_sha = null;
            }
        }

        if (array_key_exists('exclusions', $params)) {
            $mapping->exclusions = $this->clean_exclusions($params['exclusions']);
        }

        if (!$mapping->save()) {
            return new WP_Error('mapping_not_saved', __('The mapping could not be saved.', 'github-sync'), ['status' => 500]);
        }

        return new WP_REST_Response($this->mapping_payload($mapping), 200);
    }

    /**
     * Delete a mapping. Files on disk are left alone.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function delete_mapping(WP_REST_Request $request) {
        $mapping = Mapping::find((int) $request->get_param('id'));

        if (!$mapping) {
            return $this->not_found();
        }

        $active = Run::active_for_mapping($mapping->id);

        if ($active && !$active->is_stale()) {
            return new WP_Error(
                'sync_in_progress',
                __('A sync is running for this mapping. Cancel it before deleting.', 'github-sync'),
                ['status' => 409]
            );
        }

        $name = $mapping->repo_full_name();
        $mapping->delete();

        Log::info(
            sprintf(
                /* translators: %s: repository name. */
                __('Mapping for %s deleted.', 'github-sync'),
                $name
            )
        );

        return new WP_REST_Response(['deleted' => true], 200);
    }

    /**
     * Start a pull or a push.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function start_sync(WP_REST_Request $request) {
        $mapping = Mapping::find((int) $request->get_param('id'));

        if (!$mapping) {
            return $this->not_found();
        }

        $params = $this->params($request);
        $direction = (string) ($params['direction'] ?? '');

        if (!in_array($direction, [Run::TYPE_PULL, Run::TYPE_PUSH], true)) {
            return new WP_Error('invalid_direction', __('Choose either pull or push.', 'github-sync'), ['status' => 400]);
        }

        $run = SyncManager::start($mapping, $direction, (string) ($params['message'] ?? ''));

        if (is_wp_error($run)) {
            $run->add_data(['status' => 409]);

            return $run;
        }

        return new WP_REST_Response($run->to_array(), 201);
    }

    /**
     * Do the next slice of work for a run.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function step_run(WP_REST_Request $request) {
        $run = Run::find((int) $request->get_param('id'));

        if (!$run) {
            return $this->not_found();
        }

        $stepped = SyncManager::step($run);

        if (is_wp_error($stepped)) {
            $stepped->add_data(['status' => 409]);

            return $stepped;
        }

        return new WP_REST_Response($stepped->to_array(), 200);
    }

    /**
     * Current state of a run.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_run(WP_REST_Request $request) {
        $run = Run::find((int) $request->get_param('id'));

        if (!$run) {
            return $this->not_found();
        }

        return new WP_REST_Response($run->to_array(), 200);
    }

    /**
     * Stop a run.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function cancel_run(WP_REST_Request $request) {
        $run = Run::find((int) $request->get_param('id'));

        if (!$run) {
            return $this->not_found();
        }

        return new WP_REST_Response(SyncManager::cancel($run)->to_array(), 200);
    }

    /**
     * Recent sync history.
     */
    public function get_runs(WP_REST_Request $request): WP_REST_Response {
        $mapping_id = (int) $request->get_param('mapping_id');
        $limit = (int) ($request->get_param('limit') ?: 10);

        $runs = array_map(
            static function (Run $run): array {
                return $run->to_array();
            },
            Run::recent($mapping_id, $limit)
        );

        return new WP_REST_Response($runs, 200);
    }

    /**
     * Log entries for the Logs screen.
     */
    public function get_logs(WP_REST_Request $request): WP_REST_Response {
        $result = Log::query([
            'level'      => sanitize_text_field((string) $request->get_param('level')),
            'mapping_id' => (int) $request->get_param('mapping_id'),
            'run_id'     => (int) $request->get_param('run_id'),
            'search'     => sanitize_text_field((string) $request->get_param('search')),
            'page'       => (int) ($request->get_param('page') ?: 1),
            'per_page'   => (int) ($request->get_param('per_page') ?: 25),
        ]);

        $result['items'] = array_map(
            static function (Log $log): array {
                return $log->to_array();
            },
            $result['items']
        );

        return new WP_REST_Response($result, 200);
    }

    /**
     * Empty the log.
     */
    public function clear_logs(WP_REST_Request $request): WP_REST_Response {
        $deleted = Log::clear((int) $request->get_param('mapping_id'));

        return new WP_REST_Response(['deleted' => $deleted], 200);
    }

    /**
     * Check the saved GitHub credentials.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function test_connection() {
        $result = Connection::test();

        if (is_wp_error($result)) {
            $result->add_data(['status' => 400]);

            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Repositories the connection can reach.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_repositories() {
        $repos = Connection::list_repositories();

        if (is_wp_error($repos)) {
            $repos->add_data(['status' => 400]);

            return $repos;
        }

        return new WP_REST_Response($repos, 200);
    }

    /**
     * Branches in one repository.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_branches(WP_REST_Request $request) {
        $owner = sanitize_text_field((string) $request->get_param('owner'));
        $repo = sanitize_text_field((string) $request->get_param('repo'));

        if ($owner === '' || $repo === '') {
            return new WP_Error('missing_repository', __('Choose a repository first.', 'github-sync'), ['status' => 400]);
        }

        $branches = Connection::list_branches($owner, $repo);

        if (is_wp_error($branches)) {
            $branches->add_data(['status' => 400]);

            return $branches;
        }

        return new WP_REST_Response($branches, 200);
    }

    /**
     * Mapping data plus its latest run and tracked file count.
     *
     * @return array<string, mixed>
     */
    private function mapping_payload(Mapping $mapping): array {
        $payload = $mapping->to_array();
        $recent = Run::recent($mapping->id, 1);
        $latest = $recent[0] ?? null;
        $active = Run::active_for_mapping($mapping->id);

        $payload['tracked_files'] = FileState::count($mapping->id);
        $payload['latest_run'] = $latest ? $latest->to_array() : null;
        $payload['active_run'] = $active && !$active->is_stale() ? $active->to_array() : null;

        return $payload;
    }

    /**
     * Body parameters, whether sent as JSON or as a form.
     *
     * @return array<string, mixed>
     */
    private function params(WP_REST_Request $request): array {
        $json = $request->get_json_params();

        if (is_array($json) && $json) {
            return $json;
        }

        return (array) $request->get_body_params();
    }

    private function clean_dest_type(string $type): string {
        return in_array($type, ['theme', 'plugin', 'custom'], true) ? $type : 'custom';
    }

    private function clean_direction(string $direction): string {
        $allowed = [Mapping::DIRECTION_BOTH, Mapping::DIRECTION_PULL, Mapping::DIRECTION_PUSH];

        return in_array($direction, $allowed, true) ? $direction : Mapping::DIRECTION_BOTH;
    }

    /**
     * Accept exclusions as an array or as one rule per line.
     *
     * @param mixed $raw
     * @return string[]
     */
    private function clean_exclusions($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $rules = [];

        foreach ($raw as $rule) {
            $rule = trim(sanitize_text_field((string) $rule));

            if ($rule !== '') {
                $rules[] = $rule;
            }
        }

        return array_values(array_unique($rules));
    }

    private function not_found(): WP_Error {
        return new WP_Error('mapping_not_found', __('That item no longer exists.', 'github-sync'), ['status' => 404]);
    }
}
