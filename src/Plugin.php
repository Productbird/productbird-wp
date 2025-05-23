<?php

namespace Productbird;

use Productbird\Admin\Admin;
use Productbird\Admin\MagicDescriptionsBulkAction;
use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Admin\NoDescriptionFilter;
use Productbird\Admin\GlobalAdminScript;
use Productbird\Admin\ProductDescriptionRowAction;
use Productbird\Rest\WebhookCallbackEndpoint;
use Productbird\Rest\ProductStatusCheckEndpoint;
use Productbird\Rest\OidcCallbackEndpoint;
use Productbird\Auth\OidcClient;
use Productbird\Rest\OrganizationsEndpoint;
use Productbird\Rest\SettingsEndpoint;
use Productbird\Rest\ToolMagicDescriptionsEndpoints;
use Productbird\Rest\ApplyProductDescriptionEndpoint;
use Productbird\Rest\RegenerateEndpoint;
use Productbird\Rest\ClearProductMetaEndpoint;
use Productbird\FeatureFlags;

/**
 * Main plugin class.
 *
 * @package Productbird
 * @since 0.1.0
 */
class Plugin
{
    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function init(): void
    {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Hook text domain loading to the init action
        add_action('init', [$this, 'load_text_domain']);

        // Initialize plugin components
        $this->init_components();
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool
     */
    private function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    /**
     * Display admin notice if WooCommerce is not active.
     *
     * @return void
     */
    public function woocommerce_missing_notice(): void
    {
        ?>
        <div class="error notice is-dismissible">
            <p><?php esc_html_e('Productbird requires WooCommerce to be installed and active.', 'productbird'); ?></p>
        </div>
        <?php
    }

    /**
     * Load plugin text domain for translations.
     *
     * @return void
     */
    public function load_text_domain(): void
    {
        load_plugin_textdomain(
            'productbird',
            false,
            dirname(plugin_basename(__FILE__), 2) . '/languages'
        );
    }

    /**
     * Initialize plugin components.
     *
     * @return void
     */
    private function init_components(): void
    {
        if (is_admin()) {
            (new GlobalAdminScript())->init();
            (new Admin())->init();
            (new MagicDescriptionsBulkAction())->init();
            (new ProductGenerationStatusColumn())->init();
            (new NoDescriptionFilter())->init();
            (new ProductDescriptionRowAction())->init();
        }

        (new WebhookCallbackEndpoint())->init();
        (new ProductStatusCheckEndpoint())->init();
        (new OrganizationsEndpoint())->init();
        (new SettingsEndpoint())->init();
        (new ApplyProductDescriptionEndpoint())->init();
        (new RegenerateEndpoint())->init();
        (new ClearProductMetaEndpoint())->init();

        /**
         * Tool endpoints
         */
        (new ToolMagicDescriptionsEndpoints())->init();

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