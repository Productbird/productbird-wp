<?php

namespace Productbird\Admin;

use Kucrut\Vite;
use Productbird\Traits\ScriptLocalization;
use Productbird\Traits\ProductDataHelpers;
use Productbird\FeatureFlags;
use Productbird\Traits\ToolsConfig;

/**
 * Handles WooCommerce product bulk actions that rely on Productbird AI.
 *
 * @since 0.1.0
 */
class MagicDescriptionsBulkAction
{
    use ScriptLocalization;
    use ProductDataHelpers;
    use ToolsConfig;

    private $tool_config;

    /**
     * Option name used to store Productbird settings.
     */
    private const OPTION_NAME = 'productbird_settings';

    /**
     * Bulk action identifier for generating product descriptions.
     */
    private const BULK_ACTION_GENERATE_DESCRIPTION = 'productbird_magic_descriptions';

    /**
     * Maximum number of products that can be processed in a single bulk action.
     */
    private const MAX_BULK_ITEMS = 250;

    /**
     * Get tool config (lazy-loaded to avoid early translation loading).
     *
     * @return array
     */
    private function get_tool_config_lazy(): array
    {
        if ($this->tool_config === null) {
            $this->tool_config = $this->get_tool_config('MAGIC_DESCRIPTIONS');
        }
        return $this->tool_config;
    }

    /**
     * Initializes hooks for the bulk action.
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions'], 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer', [$this, 'output_app_container']);
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
        $new_actions[$this->get_tool_config_lazy()['bulk_action_group_label']] = '↓ ' . __('Productbird', 'productbird');

        // The real actionable item.
        $new_actions[self::BULK_ACTION_GENERATE_DESCRIPTION] = __('Generate description with AI', 'productbird');

        return $new_actions;
    }

    // ---------------------------------------------------------------------
    // Asset Enqueuing
    // ---------------------------------------------------------------------

    /**
     * Enqueues the compiled Vite asset that mounts the magic-descriptions modal.
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
        $source_entry = 'assets/ts/tools/magic-descriptions/index.ts';

        Vite\enqueue_asset(
            $dist_path,
            $source_entry,
            [
                'handle'       => 'productbird-magic-descriptions',
                'dependencies' => ['jquery', 'wp-api-fetch', 'wp-i18n'],
                'in-footer'    => true,
            ]
        );

        wp_localize_script(
            'productbird-magic-descriptions',
            'productbird_tool_magic_descriptions',
            array_merge(
                $this->get_common_localization_data(),
                [
                    'max_batch' => self::MAX_BULK_ITEMS,
                    'config' => $this->get_tool_config_lazy(),
                ]
            )
        );
    }

    /**
     * Outputs a hidden div container for the magic descriptions app.
     *
     * @since 0.1.0
     */
    public function output_app_container(): void
    {
        // Only output on the products list table
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product' || $screen->base !== 'edit') {
            return;
        }

        if (!FeatureFlags::is_enabled('product_description_bulk_modal')) {
            return;
        }

        echo '<div id="productbird-magic-descriptions-app" data-productbird-app="true" style="display: none;"></div>';
    }
}