<?php

namespace Productbird\Rest;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST endpoint to clear Productbird post meta.
 *
 * This endpoint is used to clear all Productbird-related post meta from products,
 * which can help resolve issues with stuck or corrupted meta data.
 *
 * @package Productbird\Rest
 * @since 0.1.0
 */
class ClearProductMetaEndpoint
{
    /**
     * Meta key prefix for Productbird.
     */
    private const META_PREFIX = '_productbird_';

    /**
     * Register hooks.
     *
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers the REST route.
     *
     * @since 0.1.0
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route('productbird/v1', '/clear-product-meta', [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'handle_clear'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Checks if the current user has permission to clear meta.
     *
     * @since 0.1.0
     * @return bool|WP_Error
     */
    public function check_permission()
    {
        return current_user_can('manage_woocommerce');
    }

    /**
     * Handles clearing the meta.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_clear(WP_REST_Request $request)
    {
        global $wpdb;

        // Get all product IDs
        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
                'product'
            )
        );

        if (empty($product_ids)) {
            return new WP_REST_Response([
                'success' => true,
                'cleared' => 0,
            ]);
        }

        // Delete all meta keys with our prefix in one query
        $cleared = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta}
                WHERE post_id IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")
                AND meta_key LIKE %s",
                array_merge($product_ids, [self::META_PREFIX . '%'])
            )
        );

        return new WP_REST_Response([
            'success' => true,
            'cleared' => (int) $cleared,
        ]);
    }
}