<?php

namespace Productbird\Rest;

use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Api\Client;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST endpoint for checking the status of product description generation.
 *
 * This endpoint provides a way for the admin UI to check the status of
 * product descriptions that are being generated asynchronously.
 * @since 0.1.0
 */
class ProductStatusCheckEndpoint
{
    /**
     * Initialize the REST route registration.
     * @since 0.1.0
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the REST route for status checking.
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        register_rest_route(
            'productbird/v1',
            '/check-generation-status',
            [
                'methods'             => WP_REST_Server::CREATABLE, // POST
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

        // Add a new endpoint for polling completed descriptions (for bulk feature)
        register_rest_route(
            'productbird/v1',
            '/description-completed',
            [
                'methods'             => WP_REST_Server::READABLE, // GET
                'callback'            => [$this, 'get_completed_descriptions'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'productIds' => [
                        'required'          => true,
                        'type'              => 'string',
                        'validate_callback' => function($param) {
                            return !empty($param);
                        },
                        'sanitize_callback' => function($param) {
                            $ids = explode(',', $param);
                            return array_map('absint', $ids);
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * Check if the current user has permission to use this endpoint.
     *
     * @since 0.1.0
     * @return bool Whether the user has permission.
     */
    public function check_permissions(): bool
    {
        return current_user_can('edit_products');
    }

    /**
     * Handle the status check request.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response or error.
     */
    public function handle_status_check(WP_REST_Request $request)
    {
        $product_ids = $request->get_param('productIds');
        $statuses = [];

        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_REST_Response(['message' => 'No product IDs provided'], 400);
        }

        // Get the API key from settings
        $options = get_option('productbird_settings', []);
        $api_key = $options['api_key'] ?? '';

        if (empty($api_key)) {
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
                $statuses[$product_id] = $current_status;
                continue;
            }

            // If we don't have a status ID, mark as none
            if (empty($status_id)) {
                $statuses[$product_id] = 'none';
                continue;
            }

            // Otherwise, we need to check with the API
            $response = $client->get_product_description_status($status_id);

            if (is_wp_error($response)) {
                // Keep the current status on error, or default to 'running'
                $statuses[$product_id] = $current_status ?: 'running';
                continue;
            }

            // Map workflowState to internal status
            $workflow_state = $response['status'] ?? '';

            switch ($workflow_state) {
                case 'RUN_SUCCESS':
                    // Update the product with the new description
                    $product = wc_get_product($product_id);

                    if ($product && !empty($response['description'])) {
                        // Store as draft first
                        update_post_meta($product_id, '_productbird_description_draft', $response['description']);

                        // Get the batch mode to determine if we should auto-apply
                        $batch_mode = get_user_meta(get_current_user_id(), 'productbird_last_batch_mode', true);
                        if ($batch_mode === 'auto-apply') {
                            // Auto-apply the description
                            $product->set_description(wp_kses_post($response['description']));
                            $product->save();
                        }
                    }

                    // Update meta to mark as completed
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'completed'
                    );
                    delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);

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

                    $statuses[$product_id] = 'error';
                    break;

                case 'RUN_STARTED':
                default:
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'running'
                    );

                    $statuses[$product_id] = 'running';
                    break;
            }
        }

        return new WP_REST_Response($statuses);
    }

    /**
     * Get the status of completed descriptions.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Incoming REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_completed_descriptions(WP_REST_Request $request)
    {
        $product_ids = $request->get_param('productIds');

        if (empty($product_ids) || !is_array($product_ids)) {
            return new WP_Error(
                'missing_product_ids',
                __('No product IDs provided.', 'productbird'),
                ['status' => 400]
            );
        }

        $completed_items = [];
        $remaining_count = 0;

        foreach ($product_ids as $product_id) {
            $status = get_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, true);

            if ($status === 'completed') {
                // Skip if we've already delivered this description
                $already_delivered = (bool) get_post_meta($product_id, '_productbird_delivered', true);
                if ($already_delivered) {
                    continue;
                }

                // Only include products that have been marked as completed
                $description_draft = get_post_meta($product_id, '_productbird_description_draft', true);

                if (!empty($description_draft)) {
                    $product = \wc_get_product($product_id);
                    $completed_items[] = [
                        'productId' => $product_id,
                        'productName' => $product ? $product->get_name() : '',
                        'descriptionHtml' => $description_draft,
                    ];

                    // Mark this as delivered so we don't send it again
                    update_post_meta($product_id, '_productbird_delivered', true);
                }
            } else if ($status === 'queued' || $status === 'running') {
                // Count how many are still in progress
                $remaining_count++;
            }
        }

        return new WP_REST_Response([
            'completed' => $completed_items,
            'remaining' => $remaining_count,
        ]);
    }
}