<?php

namespace GithubSync\Support;

/**
 * Computes Git blob SHA-1 hashes, the same identifiers GitHub reports for files.
 *
 * Hashing is streamed so a large file never has to be held in memory.
 */
class FileHasher {

    /**
     * Files read in 1 MB slices while hashing.
     */
    private const CHUNK_BYTES = 1048576;

    /**
     * Hash a file on disk. Returns an empty string when the file cannot be read.
     */
    public static function hash_file(string $absolute_path): string {
        $size = @filesize($absolute_path);

        if ($size === false) {
            return '';
        }

        $handle = @fopen($absolute_path, 'rb');

        if (!$handle) {
            return '';
        }

        $context = hash_init('sha1');
        hash_update($context, 'blob ' . $size . "\0");

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false) {
                fclose($handle);
                return '';
            }

            hash_update($context, $chunk);
        }

        fclose($handle);

        return hash_final($context);
    }

    /**
     * Hash an in-memory string using the same algorithm.
     */
    public static function hash_string(string $content): string {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }
}
