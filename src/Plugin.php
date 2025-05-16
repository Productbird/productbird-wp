<?php

namespace Productbird;

use Productbird\Admin\Admin;
use Productbird\Frontend\Frontend;
use Productbird\Admin\ProductDescriptionBulkAction;
use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Admin\NoDescriptionFilter;
use Productbird\Rest\ProductDescriptionCallbackEndpoint;
use Productbird\Rest\ProductStatusCheckEndpoint;
use Productbird\Cron\ProductbirdPoller;

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
        } else {
            (new Frontend())->init();
        }

        (new ProductDescriptionCallbackEndpoint())->init();
        (new ProductStatusCheckEndpoint())->init();

        (new ProductbirdPoller())->init();
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
        // Clear scheduled cron job
        $timestamp = wp_next_scheduled('productbird_poll_statuses');

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'productbird_poll_statuses');
        }

        // Plugin deactivation logic.
    }
}