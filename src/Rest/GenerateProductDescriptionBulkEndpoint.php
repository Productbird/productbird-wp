<?php

namespace Productbird\Rest;

use Productbird\Api\Client;
use Productbird\Traits\ProductDataHelpers;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Productbird\Admin\ProductGenerationStatusColumn;

/**
 * REST API endpoint for bulk generating product descriptions.
 *
 * @package Productbird\Rest
 * @since 0.1.0
 */
class GenerateProductDescriptionBulkEndpoint
{
    use ProductDataHelpers;

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
        register_rest_route('productbird/v1', '/generate-product-description/bulk', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_bulk_generation'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'productIds' => [
                    'required'          => true,
                    'type'              => 'array',
                    'sanitize_callback' => function ($param) {
                        return array_map('absint', $param);
                    },
                ],
                'mode' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => ['auto-apply', 'review'],
                    'default'           => 'review',
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
     * Handle the bulk generation request.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object.
     */
    public function handle_bulk_generation(WP_REST_Request $request)
    {
        if (!$this->is_woocommerce_active()) {
            return new WP_Error(
                'woocommerce_not_active',
                __('WooCommerce is not active.', 'productbird'),
                ['status' => 400]
            );
        }

        $product_ids = $request->get_param('productIds');
        $mode = $request->get_param('mode');

        // Limit batch size to 250 products as mentioned in the plan
        if (count($product_ids) > 250) {
            return new WP_Error(
                'too_many_products',
                __('You can only generate descriptions for up to 250 products at a time.', 'productbird'),
                ['status' => 400]
            );
        }

        // Store the mode in user meta for this batch
        $batch_id = uniqid('batch_', true);
        update_user_meta(get_current_user_id(), 'productbird_last_batch_mode', $mode);
        update_user_meta(get_current_user_id(), 'productbird_last_batch_id', $batch_id);

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
        $status_ids = [];
        $payloads = [];

        // Build payloads for all products first
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // Mark product as queued using the shared meta key
            update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'queued');

            // Build the payload for this product
            $payload = $this->build_product_payload($product, [
                'tone' => $options['tone'] ?? null,
                'formality' => $options['formality'] ?? null,
            ]);

            // Add callback URL if needed
            $payload['callback_url'] = rest_url('productbird/v1/webhooks');

            $payloads[] = $payload;
        }

        // Early bail if nothing to process
        if (empty($payloads)) {
            return new WP_Error(
                'no_valid_products',
                __('No valid products found to process.', 'productbird'),
                ['status' => 400]
            );
        }

        // Process all products
        foreach ($payloads as $payload) {
            try {
                $result = $client->generate_product_description($payload);

                if (is_wp_error($result)) {
                    // If there's an error, mark as failed but continue with others
                    $product_id = (int) $payload['id'];

                    update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'error');
                    update_post_meta($product_id, '_productbird_error', $result->get_error_message());

                    // If the error is due to insufficient credits, return a WP_Error
                    if (isset($result->get_error_data()['productbird_api_error']['status']) && $result->get_error_data()['productbird_api_error']['status'] === 402) {
                        return new WP_Error('insufficient_credits', __('Insufficient credits', 'productbird'), ['status' => 402]);
                    }
                } else {
                    // Store the status ID for polling
                    $product_id = (int) $payload['id'];
                    $status_id = $result['statusId'] ?? null;
                    if ($status_id) {
                        update_post_meta($product_id, '_productbird_status_id', $status_id);
                        $status_ids[$product_id] = $status_id;
                    }
                }
            } catch (\Exception $e) {
                $product_id = (int) $payload['id'];
                update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'error');
                update_post_meta($product_id, '_productbird_error', $e->getMessage());
            }
        }

        return rest_ensure_response([
            'batchId' => $batch_id,
            'mode' => $mode,
            'results' => $status_ids,
            'total' => count($product_ids),
            'status' => 'queued'
        ]);
    }
}