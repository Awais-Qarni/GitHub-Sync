<?php

namespace GithubSync\Support;

/**
 * The plugin stores its timestamps in GMT, so display goes through here and the
 * site's timezone is applied exactly once.
 */
class Dates {

    /**
     * Format a stored GMT timestamp for the admin screens.
     */
    public static function format(?string $gmt_datetime): string {
        if (empty($gmt_datetime)) {
            return '';
        }

        $timestamp = strtotime($gmt_datetime . ' UTC');

        if (!$timestamp) {
            return (string) $gmt_datetime;
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Current time, stored form.
     */
    public static function now(): string {
        return current_time('mysql', true);
    }
}
