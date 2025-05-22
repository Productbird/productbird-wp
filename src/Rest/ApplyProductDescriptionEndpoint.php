<?php

namespace Productbird\Rest;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST endpoint to apply a generated description to a product.
 *
 * This endpoint is used by the admin UI when the user approves a generated
 * description in review mode. It saves the HTML description to the product's
 * description field and cleans up temporary meta fields.
 *
 * @package Productbird\Rest
 * @since 0.1.0
 */
class ApplyProductDescriptionEndpoint
{
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
        register_rest_route('productbird/v1', '/apply-product-description', [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'handle_apply'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'productId'  => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'description' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);
    }

    /**
     * Checks if the current user may update the product.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error
     */
    public function check_permission(WP_REST_Request $request)
    {
        $product_id = (int) $request->get_param('productId');
        if (!$product_id) {
            return new WP_Error('productbird_invalid_product', __('Invalid product ID.', 'productbird'), ['status' => 400]);
        }

        // The capability required to update a product.
        return current_user_can('edit_product', $product_id);
    }

    /**
     * Handles saving the description.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_apply(WP_REST_Request $request)
    {
        $product_id  = (int) $request->get_param('productId');
        $description = (string) $request->get_param('description');

        if (!$product_id || $description === '') {
            return new WP_Error('productbird_missing_fields', __('Missing productId or description.', 'productbird'), ['status' => 400]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('productbird_product_not_found', __('Product not found.', 'productbird'), ['status' => 404]);
        }

        // Sanitize description but allow basic markup.
        $description_html = wp_kses_post($description);

        $product->set_description($description_html);
        $product->save();

        // Update meta fields to reflect that the description has been applied.
        update_post_meta($product_id, '_productbird_generation_status', 'applied');
        // Mark as delivered so it won't appear again in polling
        update_post_meta($product_id, '_productbird_delivered', true);
        delete_post_meta($product_id, '_productbird_description_draft');

        return new WP_REST_Response([
            'success'   => true,
            'productId' => $product_id,
        ]);
    }
}