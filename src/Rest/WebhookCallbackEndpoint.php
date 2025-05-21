<?php

namespace Productbird\Rest;

use Productbird\Admin\ProductGenerationStatusColumn;
use WP_REST_Request;
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
            '/description-completed',
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST, PUT, etc.
                'callback'            => [$this, 'handle_callback'],
                'permission_callback' => function() {
                    // TODO: Implement proper security check here.
                    // Example: check_a_shared_secret_in_header();
                    return false;
                },
            ]
        );
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

        $product->set_description(implode("\n", $html_parts));
        $product->save();

        update_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS, 'completed');
        delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);

        return new WP_REST_Response([
            'success'   => true,
            'productId' => $product_id,
        ]);
    }
}