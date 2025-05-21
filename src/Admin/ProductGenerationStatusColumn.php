<?php

namespace Productbird\Admin;

/**
 * Adds and manages an "AI Description" column in the WooCommerce products list
 * to show the status of Productbird-generated descriptions.
 * @since 0.1.0
 */
class ProductGenerationStatusColumn
{
    /**
     * Meta key used to store the status ID.
     */
    public const META_KEY_STATUS_ID = '_productbird_status_id';

    /**
     * Meta key used to store the generation status.
     */
    public const META_KEY_GENERATION_STATUS = '_productbird_generation_status';

    /**
     * Initialize hooks and actions.
     *
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        add_filter('manage_edit-product_columns', [$this, 'add_status_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_status_column'], 10, 2);
        add_action('admin_head', [$this, 'add_column_styles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_status_scripts']);
    }

    /**
     * Add the AI Description column to the product list table.
     *
     * @since 0.1.0
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_status_column(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'name') {
                $new_columns['productbird_ai_status'] = __('AI Desc.', 'productbird');
            }
        }

        return $new_columns;
    }

    /**
     * Render the content of the AI Description column for each product.
     *
     * @since 0.1.0
     * @param string $column_name The name of the column being rendered.
     * @param int    $product_id  The ID of the product.
     * @return void
     */
    public function render_status_column(string $column_name, int $product_id): void
    {
        if ($column_name !== 'productbird_ai_status') {
            return;
        }

        $status_id = get_post_meta($product_id, self::META_KEY_STATUS_ID, true);
        $status = get_post_meta($product_id, self::META_KEY_GENERATION_STATUS, true);

        echo '<span class="productbird-status" data-product-id="' . esc_attr($product_id) . '">';

        if (!$status && !$status_id) {
            echo '<span class="productbird-status-none" title="' . esc_attr__('No AI description requested', 'productbird') . '">—</span>';
        } elseif ($status === 'completed') {
            echo '<span class="productbird-status-completed" title="' . esc_attr__('Description generated successfully', 'productbird') . '"><span class="dashicons dashicons-yes-alt"></span></span>';
        } elseif ($status === 'error') {
            echo '<span class="productbird-status-error" title="' . esc_attr__('Error generating description', 'productbird') . '"><span class="dashicons dashicons-no-alt"></span></span>';
        } elseif ($status === 'queued') {
            echo '<span class="productbird-status-queued" title="' . esc_attr__('Description generation queued', 'productbird') . '"><span class="dashicons dashicons-clock"></span></span>';
        } else {
            // Default to "running" for any other status or when status_id exists but no status yet
            echo '<span class="productbird-status-running" title="' . esc_attr__('Description generation in progress', 'productbird') . '"><span class="dashicons dashicons-update"></span></span>';
        }

        echo '</span>';
    }

    /**
     * Add custom CSS for the status column.
     *
     * @since 0.1.0
     * @return void
     */
    public function add_column_styles(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'product') {
            return;
        }

        ?>
        <style>
            .column-productbird_ai_status {
                width: 80px;
                text-align: center;
            }
            .productbird-status-none {
                color: #ccc;
            }
            .productbird-status-completed .dashicons {
                color: #46b450;
            }
            .productbird-status-error .dashicons {
                color: #dc3232;
            }
            .productbird-status-queued .dashicons {
                color: #999;
            }
            .productbird-status-running .dashicons {
                color: #0073aa;
                animation: productbird-spin 2s linear infinite;
            }
            @keyframes productbird-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
        <?php
    }

    /**
     * Enqueue JavaScript for auto-updating status.
     *
     * @since 0.1.0
     * @param string $hook The current admin page.
     * @return void
     */
    public function enqueue_status_scripts(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen || $hook !== 'edit.php' || $screen->post_type !== 'product') {
            return;
        }

        // Enqueue the external JS file that handles status polling.
        wp_enqueue_script(
            'productbird-status-updater',
            plugin_dir_url(dirname(__DIR__, 2) . '/productbird.php') . 'assets/js/columns.js',
            [],
            PRODUCTBIRD_VERSION,
            true
        );

        wp_localize_script(
            'productbird-status-updater',
            'productbirdStatus',
            [
                'restUrl' => esc_url_raw(rest_url('productbird/v1/check-generation-status')),
                'nonce' => wp_create_nonce('wp_rest'),
                'pollInterval' => 10000, // Changed from 3000ms to 10000ms (10 seconds)
            ]
        );
    }
}