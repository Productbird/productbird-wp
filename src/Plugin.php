<?php

namespace Productbird;

use Productbird\Admin\Admin;
use Productbird\Admin\ProductDescriptionBulkAction;
use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Admin\NoDescriptionFilter;
use Productbird\Rest\ProductDescriptionCallbackEndpoint;
use Productbird\Rest\ProductStatusCheckEndpoint;
use Productbird\Rest\OidcCallbackEndpoint;
use Productbird\Auth\OidcClient;

/**
 * Core plugin class.
 *
 * @package Productbird
 */
class Plugin
{
    /**
     * Initialize plugin parts.
     *
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

        (new ProductDescriptionCallbackEndpoint())->init();
        (new ProductStatusCheckEndpoint())->init();

        // OIDC integration (connect WordPress with Productbird login)
        (new OidcCallbackEndpoint())->init();
        // The OidcClient registers its own hooks (e.g. disconnect handler).
        (new OidcClient())->init();
    }

    /**
     * Runs on plugin activation.
     *
     * @return void
     */
    public function activate(): void
    {
        // Plugin activation logic.
    }

    /**
     * Runs on plugin deactivation.
     *
     * @return void
     */
    public function deactivate(): void
    {
        // Plugin deactivation logic.
    }
}