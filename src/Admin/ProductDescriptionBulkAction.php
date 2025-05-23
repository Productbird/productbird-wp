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
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions'], 1);
        // add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
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
                    'bulk_action_group_label' => self::BULK_ACTION_GROUP_LABEL,
                ]
            )
        );
    }
}