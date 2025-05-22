<?php

namespace Productbird\Rest;

use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\FeatureFlags;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;
use WP_REST_Response;

/**
 * Registers a REST endpoint that receives asynchronous callbacks from the
 * Productbird API once a product description has been generated.
 *
 * The callback payload is expected to look like:
 *
 * {
 *   "orgId": "...",
 *   "productId": "123",
 *   "description": [
 *     { "tag": "p", "text": "..." },
 *     ...
 *   ]
 * }
 *
 * The handler will locate the WooCommerce product by ID and update its long
 * description accordingly.
 * @since 0.1.0
 */
class WebhookCallbackEndpoint
{
    /**
     * Bootstraps the REST route registration.
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers the callback REST route.
     * @since 0.1.0
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            'productbird/v1',
            '/webhooks',
            [
                'methods'             => WP_REST_Server::CREATABLE, // POST
                'callback'            => [$this, 'handle_callback'],
                'permission_callback' => [$this, 'verify_webhook_signature'],
            ]
        );
    }

    /**
     * Verifies the webhook signature.
     * @since 0.1.0
     * @param WP_REST_Request $request Incoming REST request.
     * @return bool|WP_Error True if authenticated, WP_Error otherwise.
     */
    public function verify_webhook_signature(WP_REST_Request $request)
    {
        $body = $request->get_body();
        $timestamp = $request->get_header('X-Productbird-Timestamp');
        $signature_header = $request->get_header('X-Productbird-Signature');

        // Check headers exist
        if (!$timestamp || !$signature_header) {
            return new WP_Error('missing_headers', 'Missing required headers', ['status' => 401]);
        }

        // Extract signature from header
        if (strpos($signature_header, 'sha256=') !== 0) {
            return new WP_Error('invalid_signature_format', 'Invalid signature format', ['status' => 401]);
        }

        // Extract the signature from the header
        $provided_signature = substr($signature_header, 7);

        // Recompute signature
        $secret = get_option('productbird_settings')['webhook_secret'];

        // Ensure we're working with the exact same string representation
        // The body should already be the raw JSON string from the request
        $signed_payload = $timestamp . '.' . $body;
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);

        if (!hash_equals($expected_signature, $provided_signature)) {
            return new WP_Error('invalid_signature', 'Signature mismatch', ['status' => 401]);
        }

        return true;
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
        $product_id    = isset($data['productId']) ? (int) $data['productId'] : 0;
        $description   = $data['description'] ?? null;

        if ($product_id <= 0 || !is_array($description)) {
            return new WP_Error('productbird_missing_fields', __('Missing productId or description.', 'productbird'), ['status' => 400]);
        }

        $product = \wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('productbird_product_not_found', __('Product not found.', 'productbird'), ['status' => 404]);
        }

        $html_parts = [];

        foreach ($description as $block) {
            if (!is_array($block) || empty($block['text'])) {
                continue;
            }

            $tag = isset($block['tag']) && preg_match('/^h[1-6]$/', $block['tag']) ? $block['tag'] : 'p';
            if ($tag === 'p') {
                // Allow only <p> to keep formatting simple and safe.
                $tag = 'p';
            }

            // Sanitize text.
            $text = wp_kses_post($block['text']);
            $html_parts[] = sprintf('<%1$s>%2$s</%1$s>', $tag, $text);
        }

        if (empty($html_parts)) {
            return new WP_Error('productbird_empty_description', __('Empty description content.', 'productbird'), ['status' => 400]);
        }

        update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'completed');
        delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);

        if (FeatureFlags::is_enabled('product_description_bulk_modal')) {
            // Store the generated description as a draft first
            $description_html = implode("\n", $html_parts);
            update_post_meta($product_id, '_productbird_description_draft', $description_html);

            // Get the batch mode to determine if we should auto-apply
            $batch_mode = get_user_meta(get_current_user_id(), 'productbird_last_batch_mode', true);
            if ($batch_mode === 'auto-apply') {
                // Auto-apply the description
                $product->set_description($description_html);
                $product->save();
            }
        } else {
            $product->set_description(implode("\n", $html_parts));
            $product->save();

            update_post_meta($product_id, '_productbird_generation_status', 'completed');
            delete_post_meta($product_id, '_productbird_status_id');
        }

        return new WP_REST_Response([
            'success'   => true,
            'productId' => $product_id,
        ]);
    }
}