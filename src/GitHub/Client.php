<?php

namespace GithubSync\GitHub;

use WP_Error;

/**
 * HTTP transport for the GitHub REST API.
 *
 * Handles authentication headers, transient failures, rate limit reporting and
 * raw (non JSON) responses such as file contents.
 */
class Client {

    private const BASE_URL = 'https://api.github.com';
    private const API_VERSION = '2022-11-28';

    /**
     * Attempts per request, including the first one.
     */
    private const MAX_ATTEMPTS = 3;

    private ?string $token = null;
    private int $timeout = 30;
    private int $last_status = 0;

    /**
     * Set the bearer token used for requests.
     */
    public function set_token(string $token): void {
        $this->token = $token;
    }

    /**
     * Change the per request timeout, in seconds.
     */
    public function set_timeout(int $seconds): void {
        $this->timeout = max(5, min(120, $seconds));
    }

    /**
     * HTTP status of the most recent response.
     */
    public function last_status(): int {
        return $this->last_status;
    }

    /**
     * GET a JSON endpoint.
     *
     * @return mixed|WP_Error
     */
    public function get(string $endpoint, array $args = []) {
        return $this->request('GET', $endpoint, $args);
    }

    /**
     * GET an endpoint and return the body untouched, used for file contents.
     *
     * @return string|WP_Error
     */
    public function get_raw(string $endpoint, string $accept = 'application/vnd.github.raw') {
        return $this->request('GET', $endpoint, ['headers' => ['Accept' => $accept]], true);
    }

    /**
     * POST a JSON body.
     *
     * @param array<string, mixed> $body
     * @return mixed|WP_Error
     */
    public function post(string $endpoint, array $body = [], array $args = []) {
        if ($body) {
            $args['body'] = wp_json_encode($body);
        }

        return $this->request('POST', $endpoint, $args);
    }

    /**
     * PATCH a JSON body.
     *
     * @param array<string, mixed> $body
     * @return mixed|WP_Error
     */
    public function patch(string $endpoint, array $body = [], array $args = []) {
        $args['body'] = wp_json_encode($body);

        return $this->request('PATCH', $endpoint, $args);
    }

    /**
     * Fetch every page of a list endpoint.
     *
     * @param string      $endpoint  Endpoint including any query string.
     * @param string|null $items_key Key holding the array when the response is an object.
     * @return array<int, mixed>|WP_Error
     */
    public function get_all_pages(string $endpoint, ?string $items_key = null, int $max_pages = 10) {
        $items = [];
        $page = 1;
        $separator = strpos($endpoint, '?') === false ? '?' : '&';

        while ($page <= $max_pages) {
            $response = $this->get($endpoint . $separator . 'per_page=100&page=' . $page);

            if (is_wp_error($response)) {
                return $response;
            }

            $chunk = $items_key === null ? $response : ($response[$items_key] ?? []);

            if (!is_array($chunk) || !$chunk) {
                break;
            }

            $items = array_merge($items, $chunk);

            if (count($chunk) < 100) {
                break;
            }

            $page++;
        }

        return $items;
    }

    /**
     * Stream an endpoint straight to a file, so large files never sit in memory.
     *
     * @return true|WP_Error
     */
    public function download(string $endpoint, string $destination, string $accept = 'application/vnd.github.raw') {
        $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($endpoint, '/');

        $args = [
            'method'      => 'GET',
            'timeout'     => max($this->timeout, 60),
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers'     => array_merge($this->default_headers(), ['Accept' => $accept]),
            'stream'      => true,
            'filename'    => $destination,
        ];

        $attempt = 0;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            $response = wp_safe_remote_get($url, $args);

            if (is_wp_error($response)) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep($this->backoff_seconds($attempt));
                    continue;
                }

                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $this->last_status = $status;

            if ($status < 400) {
                return true;
            }

            // With streaming enabled the error body lands in the target file.
            $body = is_readable($destination) ? (string) file_get_contents($destination) : '';
            @unlink($destination);

            if ($attempt < self::MAX_ATTEMPTS && $this->should_retry($status, $response)) {
                sleep($this->retry_delay($response, $attempt));
                continue;
            }

            return $this->error_from_body($status, $body, $response);
        }

        return new WP_Error('github_download_failed', __('The file could not be downloaded from GitHub.', 'github-sync'));
    }

    /**
     * Perform a request, retrying once or twice on transient failures.
     *
     * @return mixed|WP_Error
     */
    private function request(string $method, string $endpoint, array $args = [], bool $raw = false) {
        $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($endpoint, '/');

        $headers = $this->default_headers();

        if (isset($args['headers']) && is_array($args['headers'])) {
            $headers = array_merge($headers, $args['headers']);
        }

        $request_args = array_merge($args, [
            'method'      => $method,
            'timeout'     => $this->timeout,
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers'     => $headers,
        ]);

        $attempt = 0;
        $last_error = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            $response = wp_safe_remote_request($url, $request_args);

            if (is_wp_error($response)) {
                $last_error = $response;

                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep($this->backoff_seconds($attempt));
                    continue;
                }

                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $this->last_status = $status;

            if ($attempt < self::MAX_ATTEMPTS && $this->should_retry($status, $response)) {
                sleep($this->retry_delay($response, $attempt));
                continue;
            }

            return $this->handle_response($response, $raw);
        }

        return $last_error ?: new WP_Error('github_request_failed', __('The GitHub request could not be completed.', 'github-sync'));
    }

    /**
     * Retry server errors, and the short secondary rate limit that GitHub
     * signals with Retry-After. A 403 without that header is a permission
     * problem, and a spent hourly quota is not worth waiting out inside a
     * request, so neither is retried.
     */
    private function should_retry(int $status, $response): bool {
        if ($status >= 500) {
            return true;
        }

        if ($status !== 403 && $status !== 429) {
            return false;
        }

        $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');

        return $retry_after > 0 && $retry_after <= 20;
    }

    /**
     * Honour Retry-After when GitHub sends it.
     */
    private function retry_delay($response, int $attempt): int {
        $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');

        if ($retry_after > 0) {
            return min(20, $retry_after);
        }

        return $this->backoff_seconds($attempt);
    }

    private function backoff_seconds(int $attempt): int {
        return min(8, 2 ** $attempt);
    }

    /**
     * Headers sent with every request.
     *
     * @return array<string, string>
     */
    private function default_headers(): array {
        $headers = [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            'User-Agent'           => 'WordPress-GitHub-Sync/' . (defined('GITHUB_SYNC_VERSION') ? GITHUB_SYNC_VERSION : '2.0.0'),
        ];

        if ($this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }

    /**
     * Turn a WordPress HTTP response into data or a WP_Error.
     *
     * @return mixed|WP_Error
     */
    private function handle_response($response, bool $raw) {
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status >= 400) {
            return $this->error_from_body($status, $body, $response);
        }

        if ($raw) {
            return $body;
        }

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('github_invalid_json', __('GitHub returned a response that could not be read.', 'github-sync'));
        }

        return $data;
    }

    /**
     * Build a readable error, including the rate limit case.
     */
    private function error_from_body(int $status, string $body, $response): WP_Error {
        $data = json_decode($body, true);
        $message = is_array($data) && !empty($data['message']) ? (string) $data['message'] : '';

        $remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');

        if (($status === 403 || $status === 429) && $remaining !== '' && (int) $remaining === 0) {
            $reset = (int) wp_remote_retrieve_header($response, 'x-ratelimit-reset');
            $minutes = $reset > time() ? (int) ceil(($reset - time()) / 60) : 0;

            return new WP_Error(
                'github_rate_limit',
                $minutes > 0
                    /* translators: %d: minutes until the GitHub rate limit resets. */
                    ? sprintf(__('GitHub API rate limit reached. Try again in about %d minutes.', 'github-sync'), $minutes)
                    : __('GitHub API rate limit reached. Try again shortly.', 'github-sync')
            );
        }

        if ($status === 401) {
            return new WP_Error('github_unauthorized', __('GitHub rejected the credentials. Check the connection settings.', 'github-sync'));
        }

        if ($status === 404) {
            return new WP_Error(
                'github_not_found',
                $message !== '' ? $message : __('GitHub could not find that repository, branch or file.', 'github-sync')
            );
        }

        if ($message === '') {
            /* translators: %d: HTTP status code returned by GitHub. */
            $message = sprintf(__('GitHub returned HTTP %d.', 'github-sync'), $status);
        }

        $errors = is_array($data) && !empty($data['errors']) ? $data['errors'] : [];

        if ($errors) {
            $details = [];

            foreach ($errors as $error) {
                if (is_array($error) && !empty($error['message'])) {
                    $details[] = (string) $error['message'];
                }
            }

            if ($details) {
                $message .= ' (' . implode('; ', $details) . ')';
            }
        }

        return new WP_Error('github_api_error_' . $status, $message, ['status' => $status]);
    }
}
