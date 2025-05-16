<?php

namespace Productbird\Admin;

use Productbird\Api\Client;

/**
 * Handles WooCommerce product bulk actions that rely on Productbird AI.
 *
 */
class ProductDescriptionBulkAction
{
    /**
     * Option name used to store Productbird settings.
     */
    private const OPTION_NAME = 'productbird_settings';

    /**
     * Bulk action identifier for generating product descriptions.
     */
    private const BULK_ACTION_GENERATE_DESCRIPTION = 'productbird_generate_description';

    /**
     * Non-selectable group label to visually separate Productbird actions.
     */
    private const BULK_ACTION_GROUP_LABEL = 'productbird_ai_options';

    /**
     * Maximum number of products that can be processed in a single bulk action.
     */
    private const MAX_BULK_ITEMS = 250;

    /**
     * Initializes hooks for the bulk action.
     */
    public function init(): void
    {
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notices']);
        add_action('admin_footer-edit.php', [$this, 'disable_group_label_option']);
    }

    // ---------------------------------------------------------------------
    // Hooks
    // ---------------------------------------------------------------------

    /**
     * Adds Productbird-specific items to the bulk-action selector.
     *
     * @param array<string,string> $actions Existing bulk actions.
     * @return array<string,string> Modified actions.
     */
    public function add_bulk_actions(array $actions): array
    {
        $new_actions = $actions;

        // Add an opt-group-like label that we later disable
        // in JS so that the user cannot actually select it.
        $new_actions[self::BULK_ACTION_GROUP_LABEL] = '↓ ' . __('Productbird', 'productbird');

        // The real actionable item.
        $new_actions[self::BULK_ACTION_GENERATE_DESCRIPTION] = __('Generate description with AI', 'productbird');

        return $new_actions;
    }

    /**
     * Handles the Productbird "Generate AI Description" bulk action.
     *
     * @param string  $redirect_to URL to send the user back to.
     * @param string  $action      Selected bulk action key.
     * @param int[]   $post_ids    IDs of the selected products.
     * @return string Modified redirect URL.
     */
    public function handle_bulk_action(string $redirect_to, string $action, array $post_ids): string
    {
        if ($action !== self::BULK_ACTION_GENERATE_DESCRIPTION) {
            return $redirect_to;
        }

        if (count($post_ids) > self::MAX_BULK_ITEMS) {
            return add_query_arg('productbird_bulk_error', 'too_many_items', $redirect_to);
        }

        $options = get_option(self::OPTION_NAME);
        $api_key = $options['api_key'] ?? '';

        if (empty($api_key)) {
            return add_query_arg('productbird_bulk_error', 'no_api_key', $redirect_to);
        }

        $client    = new Client($api_key);
        $success   = 0;
        $payloads  = [];
        $products  = [];

        // -----------------------------------------------------------------
        // Build payloads for all selected products first.
        // -----------------------------------------------------------------
        foreach ($post_ids as $product_id) {
            $product = \wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $payload = [
                'tone'       => $options['tone'] ?? null,
                'formality'  => $options['formality'] ?? null,
                'language'   => substr(get_locale(), 0, 2),
                'store_name' => get_option('blogname') ?: 'Store',
                'id'         => (string) $product_id,
                'name'       => $product->get_name(),
                'brand_name' => $this->get_product_brand($product),
                'categories' => $this->get_product_category_paths($product_id),
                'sku'        => $product->get_sku() ?: null,
                'attributes' => $this->get_product_attributes($product),
                'image_urls' => $this->get_product_image_urls($product),
                'callback_url' => rest_url('productbird/v1/description-completed'),
            ];

            // Remove empty/null entries to keep the payload concise.
            $payload = array_filter(
                $payload,
                static function ($value) {
                    return $value !== null && $value !== '' && $value !== [];
                }
            );

            // Store for later use.
            $payloads[]            = $payload;
            $products[(string) $product_id] = $product;
        }

        // -----------------------------------------------------------------
        // Early bail if nothing to process.
        // -----------------------------------------------------------------
        if (empty($payloads)) {
            return $redirect_to;
        }

        // -----------------------------------------------------------------
        // Decide between single and bulk API call.
        // -----------------------------------------------------------------
        if (count($payloads) === 1) {
            $response = $client->generate_product_description($payloads[0]);

            // Check for unauthorized error
            if (is_wp_error($response) && isset($response->get_error_data()['status']) && $response->get_error_data()['status'] === 401) {
                return add_query_arg('productbird_bulk_error', 'unauthorized', $redirect_to);
            }

            // Treat any non-error response as successfully queued.
            if (!is_wp_error($response)) {
                $success = 1;
                $product_id = (int) $payloads[0]['id'];

                if (isset($response['statusId'])) {
                    update_post_meta($product_id, '_productbird_status_id', sanitize_text_field($response['statusId']));
                    update_post_meta($product_id, '_productbird_generation_status', 'queued');
                }
            }
        } else {
            $response = $client->generate_product_description_bulk($payloads);

            if (is_wp_error($response) && isset($response->get_error_data()['status']) && $response->get_error_data()['status'] === 401) {
                return add_query_arg('productbird_bulk_error', 'unauthorized', $redirect_to);
            }

            if (!is_wp_error($response)) {
                $success = count($post_ids);

                if (isset($response['results']) && is_array($response['results'])) {
                    foreach ($response['results'] as $result) {
                        if (!is_array($result) || empty($result['productId'])) {
                            continue;
                        }

                        $product_id = (int) $result['productId'];

                        if (!empty($result['statusId'])) {
                            update_post_meta($product_id, '_productbird_status_id', sanitize_text_field($result['statusId']));
                            update_post_meta($product_id, '_productbird_generation_status', 'queued');
                        }
                    }
                }
            }
        }

        return add_query_arg(
            [
                'productbird_generated' => $success,
                'productbird_selected'  => count($post_ids),
            ],
            $redirect_to
        );
    }

    /**
     * Displays a result notice after the bulk action has completed.
     */
    public function bulk_action_notices(): void
    {
        // Check for too many items error
        if (isset($_GET['productbird_bulk_error']) && $_GET['productbird_bulk_error'] === 'too_many_items') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="error notice is-dismissible"><p>' . esc_html(
                sprintf(
                    __('Productbird can only process up to %d products at once. Please select fewer products and try again.', 'productbird'),
                    self::MAX_BULK_ITEMS
                )
            ) . '</p></div>';
            return;
        }

        // Check for API key errors
        if (isset($_GET['productbird_bulk_error']) && $_GET['productbird_bulk_error'] === 'no_api_key') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="error notice is-dismissible"><p>' . esc_html__(
                'Productbird API key is missing. Please configure it in the settings.',
                'productbird'
            ) . '</p></div>';
            return;
        }

        // Check for API unauthorized errors
        if (isset($_GET['productbird_bulk_error']) && $_GET['productbird_bulk_error'] === 'unauthorized') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="error notice is-dismissible"><p>' . esc_html__(
                'Productbird API returned Unauthorized. Please check your API key.',
                'productbird'
            ) . '</p></div>';
            return;
        }

        if (!isset($_GET['productbird_generated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $generated = (int) $_GET['productbird_generated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected  = (int) ($_GET['productbird_selected'] ?? $generated); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($generated > 0) {
            printf(
                '<div class="updated notice is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        _n(
                            'Productbird has queued description generation for %d product. Status will update in the "AI Desc." column.',
                            'Productbird has queued description generation for %d products. Status will update in the "AI Desc." column.',
                            $generated,
                            'productbird'
                        ),
                        $generated
                    )
                )
            );
        } else {
            echo '<div class="error notice is-dismissible"><p>' . esc_html__(
                'Productbird could not generate descriptions for the selected products.',
                'productbird'
            ) . '</p></div>';
        }
    }

    /**
     * Outputs JS that disables the label option in the bulk-action dropdown.
     * The label acts purely as a visual separator and must not be selectable.
     */
    public function disable_group_label_option(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        ?>
        <script>
            (function($){
                $(document).ready(function(){
                    $('select[name="action"], select[name="action2"]').find('option[value="<?php echo esc_js(self::BULK_ACTION_GROUP_LABEL); ?>"]').prop('disabled', true);
                });
            })(jQuery);
        </script>
        <?php
    }

    // ---------------------------------------------------------------------
    // Helpers to map WooCommerce product data to the API schema.
    // ---------------------------------------------------------------------

    /**
     * Returns the product's primary brand (if any) based on a `brand` or
     * `pa_brand` attribute/taxonomy.
     */
    private function get_product_brand(\WC_Product $product): ?string
    {
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
     * @param int $product_id
     * @return array<int,array{name:string}>
     */
    private function get_product_category_paths(int $product_id): array
    {
        $cat_ids = \wc_get_product_cat_ids($product_id);
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
     * @return array<int,array{ name:string, value:string }>
     */
    private function get_product_attributes(\WC_Product $product): array
    {
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
     * @return string[]
     */
    private function get_product_image_urls(\WC_Product $product): array
    {
        // When the site runs locally, image URLs are often inaccessible from
        // the cloud API. Skip sending them to avoid broken links and to keep
        // the payload small.
        if (Client::is_local_site()) {
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
}