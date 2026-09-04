<?php

namespace GithubSync\GitHub;

use WP_Error;

/**
 * Handles generating JWTs for GitHub App authentication.
 */
class AppAuthenticator {
    
    /**
     * Generate a JWT for the GitHub App.
     *
     * @param string $app_id The GitHub App ID.
     * @param string $private_key The PEM formatted private key.
     * @return string|WP_Error The JWT string or WP_Error.
     */
    public static function generate_jwt(string $app_id, string $private_key) {
        if (!extension_loaded('openssl')) {
            return new WP_Error('missing_openssl', __('The openssl extension is required for GitHub App authentication.', 'github-sync'));
        }

        if (empty($app_id) || empty($private_key)) {
            return new WP_Error('missing_credentials', __('GitHub App ID or Private Key is missing.', 'github-sync'));
        }

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        $now = time();
        $payload = [
            'iat' => $now - 60,       // Issued at time, 60 seconds in the past to allow for clock drift
            'exp' => $now + (10 * 60), // JWT expiration time (10 minute maximum)
            'iss' => $app_id
        ];

        $base64_header = self::base64url_encode(json_encode($header));
        $base64_payload = self::base64url_encode(json_encode($payload));

        $signature_data = $base64_header . '.' . $base64_payload;
        
        $signature = '';
        $key_resource = openssl_pkey_get_private($private_key);
        
        if (!$key_resource) {
            return new WP_Error('invalid_private_key', __('The provided GitHub Private Key is invalid.', 'github-sync'));
        }

        $success = openssl_sign($signature_data, $signature, $key_resource, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            return new WP_Error('signing_failed', __('Failed to sign the JWT.', 'github-sync'));
        }

        $base64_signature = self::base64url_encode($signature);

        return $signature_data . '.' . $base64_signature;
    }

    /**
     * URL safe base64 encoding.
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
