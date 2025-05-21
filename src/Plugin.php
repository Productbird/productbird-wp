<?php

namespace Productbird;

use Productbird\Admin\Admin;
use Productbird\Admin\ProductDescriptionBulkAction;
use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Admin\NoDescriptionFilter;
use Productbird\Rest\WebhookCallbackEndpoint;
use Productbird\Rest\ProductStatusCheckEndpoint;
use Productbird\Rest\OidcCallbackEndpoint;
use Productbird\Auth\OidcClient;
use Productbird\Rest\OrganizationsEndpoint;
use Productbird\Rest\SettingsEndpoint;
use Productbird\FeatureFlags;

/**
 * Core plugin class.
 *
 * @package Productbird
 * @since 0.1.0
 */
class Plugin
{
    /**
     * Initialize plugin parts.
     *
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        load_plugin_textdomain(
            'productbird',
            false,
            dirname(plugin_basename(__FILE__), 2) . '/languages'
        );

        if (is_admin()) {
            (new Admin())->init();
            (new ProductDescriptionBulkAction())->init();
            (new ProductGenerationStatusColumn())->init();
            (new NoDescriptionFilter())->init();
        }

        (new WebhookCallbackEndpoint())->init();
        (new ProductStatusCheckEndpoint())->init();
        (new OrganizationsEndpoint())->init();
        (new SettingsEndpoint())->init();

        // Only bootstrap OIDC-related functionality if the feature flag is enabled.
        if (FeatureFlags::is_enabled('oidc')) {
            (new OidcCallbackEndpoint())->init();

            // The OidcClient registers its own hooks (e.g. disconnect handler).
            (new OidcClient())->init();
        }
    }

    /**
     * Runs on plugin activation.
     *
     * @since 0.1.0
     * @return void
     */
    public function activate(): void
    {
        // Silence is golden.
    }

    /**
     * Runs on plugin deactivation.
     *
     * @since 0.1.0
     * @return void
     */
    public function deactivate(): void
    {
        // Silence is golden.
    }
}