<?php

namespace Productbird\Rest;

use Productbird\Api\Client;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Productbird\Admin\ProductGenerationStatusColumn;

/**
 * REST API endpoint for regenerating a product description.
 *
 * @package Productbird\Rest
 * @since 0.1.0
 */
class RegenerateEndpoint
{
    /**
     * Initialize the endpoint.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the endpoint routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route('productbird/v1', '/regenerate', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_regeneration'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'productId' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'customPrompt' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Check if the current user has permission to use this endpoint.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if the user has permission, WP_Error otherwise.
     */
    public function check_permission(WP_REST_Request $request)
    {
        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to use this endpoint.', 'productbird'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle the regeneration request.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object.
     */
    public function handle_regeneration(WP_REST_Request $request)
    {
        $product_id = $request->get_param('productId');
        $custom_prompt = $request->get_param('customPrompt');

        // Get the current description to provide as context
        $current_draft = get_post_meta($product_id, '_productbird_description_draft', true);

        // Mark product as queued again using the shared meta key
        update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'queued');

        // Get the API key from settings
        $options = get_option('productbird_settings', []);
        $api_key = $options['api_key'] ?? '';

        if (empty($api_key)) {
            return new WP_Error(
                'api_key_missing',
                __('API key not configured', 'productbird'),
                ['status' => 400]
            );
        }

        $client = new Client($api_key);

        try {
            // Add custom options for regeneration
            $options = [];

            if (!empty($current_draft)) {
                $options['previous_description'] = $current_draft;
            }

            if (!empty($custom_prompt)) {
                $options['custom_prompt'] = $custom_prompt;
            }

            // Request description regeneration
            $result = $client->generate_product_description($product_id, $options);

            if (is_wp_error($result)) {
                return $result;
            }

            // Store the status ID for polling
            $status_id = $result['statusId'] ?? null;
            if ($status_id) {
                update_post_meta($product_id, '_productbird_status_id', $status_id);
            }

            return rest_ensure_response([
                'productId' => $product_id,
                'statusId' => $status_id,
                'status' => 'queued'
            ]);

        } catch (\Exception $e) {
            update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'error');
            update_post_meta($product_id, '_productbird_error', $e->getMessage());

            return new WP_Error(
                'regeneration_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}