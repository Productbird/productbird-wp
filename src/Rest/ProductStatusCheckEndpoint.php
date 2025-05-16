<?php

namespace Productbird\Rest;

use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Api\Client;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST endpoint for checking the status of product description generation.
 *
 * This endpoint provides a way for the admin UI to check the status of
 * product descriptions that are being generated asynchronously.
 */
class ProductStatusCheckEndpoint
{
    /**
     * Initialize the REST route registration.
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the REST route for status checking.
     */
    public function register_routes(): void
    {
        register_rest_route(
            'productbird/v1',
            '/check-generation-status',
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => [$this, 'handle_status_check'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'productIds' => [
                        'required'          => true,
                        'type'              => 'array',
                        'items'             => [
                            'type' => 'integer',
                        ],
                        'sanitize_callback' => function ($param) {
                            return array_map('absint', $param);
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * Check if the current user has permission to use this endpoint.
     *
     * @return bool Whether the user has permission.
     */
    public function check_permissions(): bool
    {
        return current_user_can('edit_products');
    }

    /**
     * Handle the status check request.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response or error.
     */
    public function handle_status_check(WP_REST_Request $request)
    {
        $product_ids = $request->get_param('productIds');
        $statuses = [];

        if (empty($product_ids) || !is_array($product_ids)) {
            error_log('Productbird Status Check: No product IDs provided in request');
            return new WP_REST_Response(['message' => 'No product IDs provided'], 400);
        }

        // Get the API key from settings
        $options = get_option('productbird_settings', []);
        $api_key = $options['api_key'] ?? '';

        if (empty($api_key)) {
            error_log('Productbird Status Check: API key not configured');
            return new WP_REST_Response(['message' => 'API key not configured'], 400);
        }

        $client = new Client($api_key);

        foreach ($product_ids as $product_id) {
            $status_id = get_post_meta(
                $product_id,
                ProductGenerationStatusColumn::META_KEY_STATUS_ID,
                true
            );

            $current_status = get_post_meta(
                $product_id,
                ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                true
            );

            // If we have a completed or error status already, just return it
            if (in_array($current_status, ['completed', 'error'], true)) {
                error_log(sprintf(
                    'Productbird Status Check: Product %d already has final status: %s',
                    $product_id,
                    $current_status
                ));
                $statuses[$product_id] = $current_status;
                continue;
            }

            // If we don't have a status ID, mark as none
            if (empty($status_id)) {
                error_log(sprintf(
                    'Productbird Status Check: Product %d has no status ID',
                    $product_id
                ));
                $statuses[$product_id] = 'none';
                continue;
            }

            // Otherwise, we need to check with the API
            $response = $client->get_product_description_status($status_id);

            if (is_wp_error($response)) {
                error_log(sprintf(
                    'Productbird Status Check: Error checking status for product %d: %s',
                    $product_id,
                    $response->get_error_message()
                ));
                // Keep the current status on error, or default to 'running'
                $statuses[$product_id] = $current_status ?: 'running';
                continue;
            }

            // Map workflowState to internal status
            $workflow_state = $response['status'] ?? '';
            error_log(sprintf(
                'Productbird Status Check: Processing product %d with workflow state: %s',
                $product_id,
                $workflow_state
            ));

            switch ($workflow_state) {
                case 'RUN_SUCCESS':
                    // Update the product with the new description
                    $product = \wc_get_product($product_id);

                    if ($product && !empty($response['description'])) {
                        $product->set_description(wp_kses_post($response['description']));
                        $product->save();
                        error_log(sprintf(
                            'Productbird Status Check: Successfully updated description for product %d',
                            $product_id
                        ));
                    } else {
                        error_log(sprintf(
                            'Productbird Status Check: Failed to update product %d - Product not found or no description in response',
                            $product_id
                        ));
                    }

                    // Update meta to mark as completed
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'completed'
                    );
                    delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);
                    error_log(sprintf(
                        'Productbird Status Check: Marked product %d as completed',
                        $product_id
                    ));

                    $statuses[$product_id] = 'completed';
                    break;

                case 'RUN_FAILED':
                case 'RUN_CANCELED':
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'error'
                    );
                    delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);
                    error_log(sprintf(
                        'Productbird Status Check: Marked product %d as failed/canceled with state: %s',
                        $product_id,
                        $workflow_state
                    ));

                    $statuses[$product_id] = 'error';
                    break;

                case 'RUN_STARTED':
                default:
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'running'
                    );
                    error_log(sprintf(
                        'Productbird Status Check: Product %d is still running (state: %s)',
                        $product_id,
                        $workflow_state
                    ));

                    $statuses[$product_id] = 'running';
                    break;
            }
        }

        return new WP_REST_Response($statuses);
    }
}