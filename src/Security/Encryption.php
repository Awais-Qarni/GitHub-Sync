<?php

namespace GithubSync\Security;

/**
 * Provides basic encryption for secrets (like GitHub Private Keys) if stored in the database.
 * Relies on WordPress salts.
 */
class Encryption {
    /**
     * Encrypt a string.
     */
    public static function encrypt(string $value): string {
        if (empty($value)) {
            return '';
        }
        
        if (!extension_loaded('openssl')) {
            return base64_encode($value); // Extremely weak fallback if openssl is missing
        }

        $key = self::get_key();
        $cipher = 'AES-256-CBC';
        $ivlen = openssl_cipher_iv_length($cipher);
        
        if ($ivlen === false) {
            return base64_encode($value);
        }

        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($value, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $ciphertext_raw, $key, true);
        
        return base64_encode($iv . $hmac . $ciphertext_raw);
    }

    /**
     * Decrypt a string.
     */
    public static function decrypt(string $value): string {
        if (empty($value)) {
            return '';
        }

        if (!extension_loaded('openssl')) {
            return base64_decode($value);
        }

        $c = base64_decode($value);
        $key = self::get_key();
        $cipher = 'AES-256-CBC';
        $ivlen = openssl_cipher_iv_length($cipher);

        if ($ivlen === false || strlen($c) <= $ivlen + 32) {
             return ''; // Malformed
        }

        $iv = substr($c, 0, $ivlen);
        $hmac = substr($c, $ivlen, 32);
        $ciphertext_raw = substr($c, $ivlen + 32);
        
        $calcmac = hash_hmac('sha256', $ciphertext_raw, $key, true);
        
        if (hash_equals($hmac, $calcmac)) {
            return openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv) ?: '';
        }

        return '';
    }

    /**
     * Get the encryption key based on WP salts.
     */
    private static function get_key(): string {
        $salt = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '';
        $salt .= defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '';
        
        if (empty($salt)) {
             $salt = 'github_sync_fallback_salt_do_not_use_in_prod';
        }
        
        return hash('sha256', $salt, true);
    }
}
