<?php

namespace Productbird\Traits;

use WC_Product;
use function wc_get_product_cat_ids;
use function wc_attribute_label;

/**
 * Trait containing helper methods for working with WooCommerce product data.
 *
 * @since 0.1.0
 */
trait ProductDataHelpers
{
    /**
     * Check if WooCommerce is active and loaded.
     *
     * @return bool
     */
    protected function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    /**
     * Returns the product's primary brand (if any) based on a `brand` or
     * `pa_brand` attribute/taxonomy.
     * @since 0.1.0
     */
    protected function get_product_brand(WC_Product $product): ?string
    {
        if (!$this->is_woocommerce_active()) {
            return null;
        }

        $brand_taxonomies = ['product_brand', 'pa_brand', 'brand'];

        foreach ($brand_taxonomies as $tax) {
            $terms = wp_get_post_terms($product->get_id(), $tax);
            if (!is_wp_error($terms) && !empty($terms)) {
                return $terms[0]->name;
            }
        }

        return null;
    }

    /**
     * Gets the product categories as simple objects with name properties.
     *
     * @since 0.1.0
     * @param int $product_id
     * @return array<int,array{name:string}>
     */
    protected function get_product_category_paths(int $product_id): array
    {
        if (!$this->is_woocommerce_active()) {
            return [];
        }

        $cat_ids = wc_get_product_cat_ids($product_id);
        $categories = [];

        foreach ($cat_ids as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $categories[] = [
                'name' => $term->name,
            ];
        }

        return $categories;
    }

    /**
     * Collects visible product attributes (non-variation) as name/value pairs.
     *
     * @since 0.1.0
     * @return array<int,array{ name:string, value:string }>
     */
    protected function get_product_attributes(WC_Product $product): array
    {
        if (!$this->is_woocommerce_active()) {
            return [];
        }

        $result = [];

        foreach ($product->get_attributes() as $attribute) {
            // Skip hidden or variation attributes.
            if ($attribute->get_visible() === false || $attribute->get_variation()) {
                continue;
            }

            $name = wc_attribute_label($attribute->get_name());

            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }
                $value = implode(', ', $terms);
            } else {
                $value = $attribute->get_options() ? implode(', ', $attribute->get_options()) : '';
            }

            if ($value !== '') {
                $result[] = [
                    'name'  => $name,
                    'value' => $value,
                ];
            }
        }

        return $result;
    }

    /**
     * Returns up to one image URL (featured image) for the product as required
     * by the API schema.
     *
     * @since 0.1.0
     * @return string[]
     */
    protected function get_product_image_urls(WC_Product $product): array
    {
        if (!$this->is_woocommerce_active()) {
            return [];
        }

        // When the site runs locally, image URLs are often inaccessible from
        // the cloud API. Skip sending them to avoid broken links and to keep
        // the payload small.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return [];
        }

        $image_id = $product->get_image_id();
        if ($image_id) {
            $url = wp_get_attachment_image_url($image_id, 'full');
            if ($url) {
                return [$url];
            }
        }

        return [];
    }

    /**
     * Builds a complete product payload for the API.
     *
     * @since 0.1.0
     * @param WC_Product $product The product to build the payload for.
     * @param array $options Additional options to include in the payload.
     * @return array The complete product payload.
     */
    protected function build_product_payload(WC_Product $product, array $options = []): array
    {
        if (!$this->is_woocommerce_active()) {
            return [];
        }

        $payload = [
            'tone'       => $options['tone'] ?? null,
            'formality'  => $options['formality'] ?? null,
            'callback_url' => $options['callback_url'] ?? rest_url('productbird/v1/webhooks'),
            'language'   => substr(get_locale(), 0, 2),
            'store_name' => get_option('blogname') ?: 'Store',
            'id'         => (string) $product->get_id(),
            'name'       => $product->get_name(),
            'brand_name' => $this->get_product_brand($product),
            'categories' => $this->get_product_category_paths($product->get_id()),
            'sku'        => $product->get_sku() ?: null,
            'attributes' => $this->get_product_attributes($product),
            'image_urls' => $this->get_product_image_urls($product),
        ];

        // Remove empty/null entries to keep the payload concise.
        return array_filter(
            $payload,
            static function ($value) {
                return $value !== null && $value !== '' && $value !== [];
            }
        );
    }
}