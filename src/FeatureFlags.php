<?php

namespace Productbird;

/**
 * Simple feature-flag helper.
 *
 * Flags can be modified by hooking into the `productbird_feature_flags` filter or by
 * defining a `PRODUCTBIRD_<FLAG>_ENABLED` constant (boolean).
 */
class FeatureFlags
{
    /**
     * Default feature matrix.  Extend as required.
     */
    private const DEFAULT_FLAGS = [
        // OpenID-Connect
        'oidc' => false,
    ];

    /**
     * Check whether a feature is enabled.
     */
    public static function is_enabled(string $flag): bool
    {
        // Honour a constant override first (e.g. define( 'PRODUCTBIRD_OIDC_ENABLED', false ); )
        $const_name = 'PRODUCTBIRD_' . strtoupper($flag) . '_ENABLED';
        if (defined($const_name)) {
            return (bool) constant($const_name);
        }

        // Allow external code to filter feature flags.
        $filtered = apply_filters('productbird_feature_flags', []);

        if (isset($filtered[$flag])) {
            return (bool) $filtered[$flag];
        }

        return self::DEFAULT_FLAGS[$flag] ?? false;
    }

    /**
     * Return the complete flag map after applying filters / constant overrides.
     */
    public static function get_all(): array
    {
        $flags = self::DEFAULT_FLAGS;

        // Constant overrides.
        foreach (array_keys($flags) as $flag) {
            $const_name = 'PRODUCTBIRD_' . strtoupper($flag) . '_ENABLED';
            if (defined($const_name)) {
                $flags[$flag] = (bool) constant($const_name);
            }
        }

        // Filter overrides.
        $filtered = apply_filters('productbird_feature_flags', []);

        if (is_array($filtered)) {
            $flags = array_merge($flags, $filtered);
        }

        return $flags;
    }
}