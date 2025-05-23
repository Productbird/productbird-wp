<?php

namespace Productbird;

class Utils
{
    /**
     * Detects whether the site is running on a localhost-style domain.
     * @since 0.1.0
     * @return bool True if the site URL matches local patterns, false otherwise.
     */
    public static function is_local_site(): bool
    {
        $site_url = home_url();

        return strpos($site_url, 'localhost') !== false
            || strpos($site_url, '127.0.0.1') !== false
            || strpos($site_url, '.local') !== false;
    }
}