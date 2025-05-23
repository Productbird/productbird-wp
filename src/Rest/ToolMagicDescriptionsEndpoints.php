<?php

namespace Productbird\Rest;

use Productbird\Api\Client;
use Productbird\FeatureFlags;
use Productbird\Logger;
use Productbird\Traits\ProductDataHelpers;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Productbird\Traits\ToolsConfig;
use Productbird\Rest\RestUtils;

/**
 * REST API endpoint for bulk generating product descriptions.
 *
 * This endpoint uses tool-specific meta keys for the Magic Descriptions tool:
 * - generation_status: '_productbird_magic_descriptions_status'
 * - status_id: '_productbird_magic_descriptions_status_id'
 * - error: '_productbird_magic_descriptions_error'
 * - description_draft: '_productbird_magic_descriptions_draft'
 * - delivered: '_productbird_magic_descriptions_delivered'
 *
 * @package Productbird\Rest
 * @since 0.1.0
 */
class ToolMagicDescriptionsEndpoints
{
    use ProductDataHelpers;
    use ToolsConfig;

    private $tool_config;

    /**
     * Status meta key
     *
     * @var string
     */
    private $meta_status_key;

    /**
     * Draft meta key
     *
     * @var string
     */
    private $meta_draft_key;

    /**
     * Delivered meta key
     *
     * @var string
     */
    private $meta_delivered_key;

    /**
     * Status ID meta key
     *
     * @var string
     */
    private $meta_status_id_key;

    /**
     * Error meta key
     *
     * @var string
     */
    private $meta_error_key;

    /**
     * Initialize the endpoint.
     *
     * @return void
     */
    public function init(): void
    {
        $this->meta_status_key      = self::MAGIC_DESCRIPTIONS_META_KEY_STATUS;
        $this->meta_draft_key       = self::MAGIC_DESCRIPTIONS_META_KEY_DRAFT;
        $this->meta_delivered_key   = self::MAGIC_DESCRIPTIONS_META_KEY_DELIVERED;
        $this->meta_status_id_key   = self::MAGIC_DESCRIPTIONS_META_KEY_STATUS_ID;
        $this->meta_error_key       = self::MAGIC_DESCRIPTIONS_META_KEY_ERROR;

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the endpoint routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route('productbird/v1', '/magic-descriptions/bulk', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_bulk_generation'],
            'permission_callback' => [RestUtils::class, 'can_manage_woocommerce_permission'],
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

        register_rest_route('productbird/v1', '/magic-descriptions/callback', [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'handle_callback'],
            'permission_callback' => [RestUtils::class, 'verify_webhook_signature'],
        ]);

        register_rest_route('productbird/v1', '/magic-descriptions/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_status_check'],
            'permission_callback' => [RestUtils::class, 'can_manage_woocommerce_permission'],
            'args'                => [
                'product_ids' => [
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
        ]);
    }

    /**
     * Check if a product has a pending description that needs review.
     *
     * @param int $product_id The product ID
     * @return array|false Returns product data if it needs review, false otherwise
     */
    private function get_product_needing_review(int $product_id)
    {
        $draft = get_post_meta($product_id, $this->meta_draft_key, true);
        $delivered = get_post_meta($product_id, $this->meta_delivered_key, true);
        $status = get_post_meta($product_id, $this->meta_status_key, true);

        if (!empty($draft) && !$delivered && $status === 'completed') {
            $product = wc_get_product($product_id);
            if (!$product) {
                return false;
            }

            return [
                'id' => $product_id,
                'name' => $product->get_name(),
                'html' => $draft
            ];
        }

        return false;
    }

    /**
     * Handle the bulk generation request.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object.
     */
    public function handle_bulk_generation(WP_REST_Request $request)
    {
        $product_ids = $request->get_param('productIds');
        $mode = $request->get_param('mode');

        // Ensure the list contains unique product IDs to avoid redundant processing and duplicate
        // entries in both `scheduled_items` and `pending_items` responses.
        if ( is_array( $product_ids ) ) {
            $product_ids = array_values( array_unique( array_map( 'absint', $product_ids ) ) );
        }

        Logger::info('Bulk generation request started', [
            'product_count' => count($product_ids),
            'mode' => $mode,
            'product_ids' => $product_ids
        ]);

        $is_valid_batch_size = RestUtils::is_valid_batch_size( count( $product_ids ), 'magic-descriptions' );

        if ( $is_valid_batch_size instanceof WP_REST_Response ) {
            Logger::warning('Bulk generation failed: invalid batch size', [
                'product_count' => count($product_ids),
                'product_ids' => $product_ids
            ]);
            return $is_valid_batch_size;
        }

        // Get the API key from settings
        $options = get_option('productbird_settings', []);
        $api_key = $options['api_key'] ?? '';

        if (empty($api_key)) {
            Logger::error('Bulk generation failed: API key not configured');
            return new \WP_REST_Response([
                'error' => __('API key not configured', 'productbird'),
                'code' => 'api_key_missing',
                'data' => ['status' => 400]
            ], 400);
        }

        $client = new Client( $api_key );
        $status_ids = [];
        $payloads = [];
        $products_needing_review = [];

        // Build payloads for all products first
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                Logger::warning('Product not found during bulk generation', [
                    'product_id' => $product_id
                ]);
                continue;
            }

            // Check if product has a pending draft that needs review
            $product_review_data = $this->get_product_needing_review($product_id);
            if ($product_review_data !== false) {
                $products_needing_review[] = $product_review_data;

                Logger::info('Product skipped - needs review', [
                    'product_id' => $product_id,
                    'product_name' => $product->get_name()
                ]);
                continue;
            }

            // Clear delivered flag from any previous generation run so the product can be processed again
            delete_post_meta($product_id, $this->meta_delivered_key);

            if (!$this->set_generation_status($product_id, 'queued')) {
                Logger::error('Failed to set queued status for product', [
                    'product_id' => $product_id
                ]);
                return new \WP_REST_Response([
                    'error' => __('Failed to set queued status for product', 'productbird'),
                    'code' => 'failed_to_set_queued_status',
                    'data' => ['status' => 400]
                ], 400);
            }

            Logger::log_product_processing($product_id, 'bulk_generation', 'queued');

            $payload = $this->build_product_payload($product, [
                'tone' => $options['tone'] ?? null,
                'formality' => $options['formality'] ?? null,
                'callback_url' => rest_url("productbird/v1/magic-descriptions/callback?mode={$mode}"),
            ]);

            $payloads[] = $payload;
        }

        if ( empty( $payloads ) && empty( $products_needing_review ) ) {
            Logger::error('Bulk generation failed: no valid products found', [
                'original_product_ids' => $product_ids,
                'valid_products_count' => 0
            ]);
            return new \WP_REST_Response([
                'error' => __('No valid products found to process.', 'productbird'),
                'code' => 'no_valid_products',
                'data' => ['status' => 400]
            ]);
        }

        // Only make API call if we have products to schedule
        if (!empty($payloads)) {
            Logger::log_api_request('generate_product_description_bulk', $payloads);

            $response = $client->generate_product_description_bulk($payloads);

            if (is_wp_error($response)) {
                Logger::log_api_response('generate_product_description_bulk', $response, true);
                return new \WP_REST_Response([
                    'error' => $response->get_error_message(),
                    'code' => $response->get_error_code(),
                    'data' => ['status' => 400]
                ], 400);
            }

            Logger::log_api_response('generate_product_description_bulk', $response);

            $status_ids = $response['results'] ?? [];
        }

        // Transform status_ids to use snake_case keys for scheduled_items
        $scheduled_items = [];
        foreach ($status_ids as $item) {
            $scheduled_items[] = [
                'product_id' => $item['productId'] ?? null,
                'status_id' => $item['statusId'] ?? null,
            ];
        }

        Logger::info('Bulk generation request completed successfully', [
            'mode' => $mode,
            'scheduled_count' => count($status_ids),
            'needing_review_count' => count($products_needing_review),
            'total_products' => count($status_ids) + count($products_needing_review)
        ]);

        return rest_ensure_response([
            'mode' => $mode,
            'status_ids' => $status_ids,
            'has_scheduled_items' => !empty($status_ids),
            'scheduled_items' => $scheduled_items,
            'pending_items' => $products_needing_review,
            'has_pending_items' => !empty($products_needing_review),
            'status' => 'queued'
        ]);
    }

    /**
     * Handles the incoming callback and updates the product.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Incoming REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_callback(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        $mode = $request->get_param('mode');

        $product_id    = isset($data['productId']) ? (int) $data['productId'] : 0;
        $description   = $data['description'] ?? null;

        Logger::info('Callback received', [
            'product_id' => $product_id,
            'mode' => $mode,
            'has_description' => !empty($description),
            'description_blocks' => is_array($description) ? count($description) : 0
        ]);

        if ($product_id <= 0 || !is_array($description)) {
            Logger::error('Callback failed: missing productId or description', [
                'product_id' => $product_id,
                'description_type' => gettype($description)
            ]);
            return new WP_Error('productbird_missing_fields', __('Missing productId or description.', 'productbird'), ['status' => 400]);
        }

        $product = \wc_get_product($product_id);
        if (!$product) {
            Logger::error('Callback failed: product not found', [
                'product_id' => $product_id
            ]);
            return new WP_Error('productbird_product_not_found', __('Product not found.', 'productbird'), ['status' => 404]);
        }

        $html_parts = [];

        foreach ($description as $block) {
            if (!is_array($block) || empty($block['text'])) {
                Logger::warning('Skipping invalid description block', [
                    'product_id' => $product_id,
                    'block_type' => gettype($block),
                    'has_text' => isset($block['text'])
                ]);
                continue;
            }

            // Define allowed tags
            $allowed_tags = ['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'span'];

            // Check if tag is allowed, default to 'p' if not
            $tag = isset($block['tag']) && in_array($block['tag'], $allowed_tags) ? $block['tag'] : 'p';

            // Sanitize text
            $text = wp_kses_post($block['text']);

            // Handle attributes if provided
            $attributes = '';
            if (isset($block['attributes']) && is_array($block['attributes'])) {
                foreach ($block['attributes'] as $attr_name => $attr_value) {
                    // Sanitize attribute name and value
                    $attr_name = sanitize_key($attr_name);
                    $attr_value = esc_attr($attr_value);
                    $attributes .= sprintf(' %s="%s"', $attr_name, $attr_value);
                }
            }

            $html_parts[] = sprintf('<%1$s%2$s>%3$s</%1$s>', $tag, $attributes, $text);
        }

        if (empty($html_parts)) {
            Logger::error('Callback failed: empty description content', [
                'product_id' => $product_id,
                'original_blocks_count' => count($description)
            ]);
            return new WP_Error('productbird_empty_description', __('Empty description content.', 'productbird'), ['status' => 400]);
        }

        Logger::log_product_processing($product_id, 'description_generated', 'processing', [
            'html_blocks_count' => count($html_parts),
            'mode' => $mode
        ]);

        update_post_meta($product_id, $this->meta_status_key, 'completed');
        delete_post_meta($product_id, $this->meta_status_id_key);

          // Store the generated description as a draft first
          $description_html = implode("\n", $html_parts);
          update_post_meta($product_id, $this->meta_draft_key, $description_html);

          Logger::debug('Description stored as draft', [
              'product_id' => $product_id,
              'description_length' => strlen($description_html)
          ]);

          if ($mode === 'auto-apply') {
              // Auto-apply the description
              $product->set_description($description_html);
              $product->save();

              Logger::log_product_processing($product_id, 'description_auto_applied', 'completed');

              $this->set_generation_status($product_id, 'completed');

              // Mark as delivered when descriptions are auto-applied so they are not offered for review later
              $this->set_delivered($product_id, true);

              delete_post_meta($product_id, $this->meta_status_id_key);
          } else {
              Logger::log_product_processing($product_id, 'description_ready_for_review', 'completed');
          }

        Logger::info('Callback processed successfully', [
            'product_id' => $product_id,
            'mode' => $mode
        ]);

        // Return a 200 response
        return rest_ensure_response([
            'success' => true,
        ]);
    }

    /**
     * Get the status of completed descriptions.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Incoming REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_status_check(WP_REST_Request $request)
    {
        $product_ids = $request->get_param('product_ids');

        Logger::debug('Getting completed descriptions', [
            'product_ids' => $product_ids,
            'product_count' => is_array($product_ids) ? count($product_ids) : 0
        ]);

        if (empty($product_ids) || !is_array($product_ids)) {
            Logger::warning('No product IDs provided for completed descriptions check');
            return new WP_Error(
                'missing_product_ids',
                __('No product IDs provided.', 'productbird'),
                ['status' => 400]
            );
        }

        $completed_items = [];
        $remaining_count = 0;

        foreach ($product_ids as $product_id) {
            $status = get_post_meta($product_id, $this->meta_status_key, true);

            // Add more detailed logging
            Logger::debug('Checking product status', [
                'product_id' => $product_id,
                'status' => $status,
                'has_draft' => !empty(get_post_meta($product_id, $this->meta_draft_key, true)),
                'is_delivered' => !empty(get_post_meta($product_id, $this->meta_delivered_key, true))
            ]);

            if ($status === 'completed') {
                // Skip if we've already delivered this description
                $already_delivered = (bool) get_post_meta($product_id, $this->meta_delivered_key, true);

                if ($already_delivered) {
                    Logger::debug('Skipping already delivered description', [
                        'product_id' => $product_id
                    ]);
                    continue;
                }

                // Only include products that have been marked as completed
                $description_draft = get_post_meta($product_id, $this->meta_draft_key, true);

                if (!empty($description_draft)) {
                    $product = \wc_get_product($product_id);

                    $completed_items[] = [
                        'id' => $product_id,
                        'name' => $product ? $product->get_name() : '',
                        'html' => $description_draft,
                    ];

                    Logger::debug('Description added to completed items', [
                        'product_id' => $product_id,
                        'product_name' => $product ? $product->get_name() : 'Unknown'
                    ]);
                }
            } elseif ($status === 'queued' || $status === 'running') {
                // Count how many are still in progress
                $remaining_count++;
            }
        }

        // NOTE: We no longer mark descriptions as delivered here. In review mode the merchant still needs to
        // accept or decline the draft, so doing so would hide the description from subsequent review sessions.
        // Drafts are now marked as delivered either when they are auto-applied (see above) or when the merchant
        // explicitly accepts them via the dedicated "apply" endpoint.

        Logger::info('Completed descriptions retrieved', [
            'completed_count' => count($completed_items),
            'remaining_count' => $remaining_count,
            'total_checked' => count($product_ids)
        ]);

        return new WP_REST_Response([
            'completed_items' => $completed_items,
            'remaining_count' => $remaining_count,
        ]);
    }

    /**
     * Set the generation status for a product.
     *
     * @param int $product_id The product ID
     * @param string $status The status ('queued', 'processing', 'completed', 'error')
     * @return bool True on success, false on failure
     */
    private function set_generation_status(int $product_id, string $status): bool
    {
        if (!in_array($status, ['queued', 'processing', 'completed', 'error'], true)) {
            Logger::error('Invalid generation status provided', [
                'product_id' => $product_id,
                'status' => $status,
                'valid_statuses' => ['queued', 'processing', 'completed', 'error']
            ]);
            error_log("Invalid generation status: {$status} for product {$product_id}");
            return false;
        }

        $result = update_post_meta($product_id, $this->meta_status_key, $status);

        return $result;
    }

    /**
     * Set the status ID for a product.
     *
     * @param int $product_id The product ID
     * @param string $status_id The status ID from the API
     * @return bool True on success, false on failure
     */
    private function set_status_id(int $product_id, string $status_id): bool
    {
        if (empty($status_id)) {
            Logger::error('Empty status ID provided', [
                'product_id' => $product_id
            ]);
            error_log("Empty status ID provided for product {$product_id}");
            return false;
        }

        $result = update_post_meta($product_id, $this->meta_status_id_key, $status_id);

        return $result;
    }

    /**
     * Set an error message for a product.
     *
     * @param int $product_id The product ID
     * @param string $error_message The error message
     * @return bool True on success, false on failure
     */
    private function set_error(int $product_id, string $error_message): bool
    {
        if (empty($error_message)) {
            Logger::error('Empty error message provided', [
                'product_id' => $product_id
            ]);
            error_log("Empty error message provided for product {$product_id}");
            return false;
        }

        $result = update_post_meta($product_id, $this->meta_error_key, $error_message);

        return $result;
    }

    /**
     * Set the description draft for a product.
     *
     * @param int $product_id The product ID
     * @param string $draft The draft description
     * @return bool True on success, false on failure
     */
    private function set_description_draft(int $product_id, string $draft): bool
    {
        if (empty($draft)) {
            error_log("Empty draft provided for product {$product_id}");
            return false;
        }

        $result = update_post_meta($product_id, $this->meta_draft_key, $draft);

        return $result;
    }

    /**
     * Mark a product as delivered.
     *
     * @param int $product_id The product ID
     * @param bool $delivered Whether the product description was delivered
     * @return bool True on success, false on failure
     */
    private function set_delivered(int $product_id, bool $delivered = true): bool
    {
        return update_post_meta($product_id, $this->meta_delivered_key, $delivered ? 'yes' : 'no') !== false;
    }

    /**
     * Clear all Magic Descriptions metadata for a product.
     *
     * @param int $product_id The product ID
     * @return bool True if all metadata was cleared successfully
     */
    private function clear_metadata(int $product_id): bool
    {
        Logger::debug('Clearing metadata for product', [
            'product_id' => $product_id
        ]);

        $meta_keys = [
            $this->meta_status_key,
            $this->meta_status_id_key,
            $this->meta_error_key,
            $this->meta_draft_key,
            $this->meta_delivered_key,
        ];

        $success = true;
        $cleared_keys = [];
        $failed_keys = [];

        foreach ($meta_keys as $meta_key) {
            if (delete_post_meta($product_id, $meta_key)) {
                $cleared_keys[] = $meta_key;
            } else {
                $failed_keys[] = $meta_key;
                $success = false;
            }
        }

        if ($success) {
            Logger::info('All metadata cleared successfully', [
                'product_id' => $product_id,
                'cleared_keys' => $cleared_keys
            ]);
        } else {
            Logger::error('Failed to clear some metadata', [
                'product_id' => $product_id,
                'cleared_keys' => $cleared_keys,
                'failed_keys' => $failed_keys
            ]);
        }

        return $success;
    }
}