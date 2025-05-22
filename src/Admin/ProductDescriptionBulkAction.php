<?php

namespace Productbird\Admin;

use Productbird\Api\Client;
use Kucrut\Vite;
use Productbird\Traits\ScriptLocalization;
use Productbird\Traits\ProductDataHelpers;
use Productbird\FeatureFlags;

/**
 * Handles WooCommerce product bulk actions that rely on Productbird AI.
 *
 * @since 0.1.0
 */
class ProductDescriptionBulkAction
{
    use ScriptLocalization;
    use ProductDataHelpers;

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
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notices']);
        add_action('admin_footer-edit.php', [$this, 'disable_group_label_option']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    // ---------------------------------------------------------------------
    // Hooks
    // ---------------------------------------------------------------------

    /**
     * Adds Productbird-specific items to the bulk-action selector.
     *
     * @since 0.1.0
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

            // Build the payload for this product
            $payload = $this->build_product_payload($product, [
                'tone' => $options['tone'] ?? null,
                'formality' => $options['formality'] ?? null
            ]);

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
            if (is_wp_error($response) && isset($response->get_error_data()['status'])) {
                $status = $response->get_error_data()['status'];
                if ($status === 401) {
                    return add_query_arg('productbird_bulk_error', 'unauthorized', $redirect_to);
                }
                if ($status === 402) {
                    return add_query_arg('productbird_bulk_error', 'insufficient_credits', $redirect_to);
                }
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

            if (is_wp_error($response) && isset($response->get_error_data()['status'])) {
                $status = $response->get_error_data()['status'];
                if ($status === 401) {
                    return add_query_arg('productbird_bulk_error', 'unauthorized', $redirect_to);
                }
                if ($status === 402) {
                    return add_query_arg('productbird_bulk_error', 'insufficient_credits', $redirect_to);
                }
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

        // Remove any existing error parameters from the URL
        $redirect_to = remove_query_arg('productbird_bulk_error', $redirect_to);

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
     * @since 0.1.0
     * @return void
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
                __('Productbird API returned Unauthorized. Please check your API key.', 'productbird'),
                'productbird'
            ) . '</p></div>';
            return;
        }

        // Check for insufficient credits error
        if (isset($_GET['productbird_bulk_error']) && $_GET['productbird_bulk_error'] === 'insufficient_credits') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="error notice is-dismissible"><p>' . esc_html__(
                __('Insufficient credits available. Please purchase more credits to continue using Productbird.', 'productbird'),
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
                            __('Productbird has queued description generation for %d product. Status will update in the "AI Desc." column.', 'productbird'),
                            __('Productbird has queued description generation for %d products. Status will update in the "AI Desc." column.', 'productbird'),
                            $generated,
                            'productbird'
                        ),
                        $generated
                    )
                )
            );
        } else {
            echo '<div class="error notice is-dismissible"><p>' . esc_html__(
                __('Productbird could not generate descriptions for the selected products.', 'productbird'),
                'productbird'
            ) . '</p></div>';
        }
    }

    /**
     * Outputs JS that disables the label option in the bulk-action dropdown.
     * The label acts purely as a visual separator and must not be selectable.
     * @since 0.1.0
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
    // Asset Enqueuing
    // ---------------------------------------------------------------------

    /**
     * Enqueues the compiled Vite asset that mounts the product-description modal.
     *
     * @since 0.1.0
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        // Only load on the products list table (edit.php for post_type=product).
        if ($hook_suffix !== 'edit.php') {
            return;
        }

        if (!FeatureFlags::is_enabled('product_description_bulk_modal')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        $dist_path    = PRODUCTBIRD_PLUGIN_DIR . '/assets/dist';
        $source_entry = 'assets/ts/tools/product-description/index.ts';

        Vite\enqueue_asset(
            $dist_path,
            $source_entry,
            [
                'handle'       => 'productbird-product-description',
                'dependencies' => ['jquery', 'wp-api-fetch', 'wp-i18n'],
                'in-footer'    => true,
            ]
        );

        wp_localize_script(
            'productbird-product-description',
            'productbird_bulk',
            array_merge(
                $this->get_common_localization_data(),
                [
                    'max_batch' => self::MAX_BULK_ITEMS,
                ]
            )
        );
    }
}