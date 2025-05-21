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

        wp_register_script(
            'productbird-status-updater',
            false,
            [],
            '1.0',
            true
        );

        wp_add_inline_script(
            'productbird-status-updater',
            $this->get_status_updater_script()
        );

        wp_enqueue_script('productbird-status-updater');

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

    /**
     * Get the JavaScript for auto-updating status.
     *
     * @since 0.1.0
     * @return string The JavaScript code.
     */
    private function get_status_updater_script(): string
    {
        return <<<'JS'
(function() {
    // Find all products with pending statuses
    function collectPendingProductIds() {
        const pendingStatuses = document.querySelectorAll(
            '.productbird-status-queued, .productbird-status-running'
        );

        const productIds = [];
        pendingStatuses.forEach(element => {
            const productId = element.closest('.productbird-status').dataset.productId;
            if (productId) {
                productIds.push(parseInt(productId, 10));
            }
        });

        return productIds;
    }

    // Update the status badges based on API response
    function updateStatusBadges(statuses) {
        for (const [productId, status] of Object.entries(statuses)) {
            const statusEl = document.querySelector(`.productbird-status[data-product-id="${productId}"]`);
            if (!statusEl) continue;

            // Clear current content
            statusEl.innerHTML = '';

            // Create new status element based on response
            let newStatusHtml = '';

            if (status === 'completed') {
                newStatusHtml = '<span class="productbird-status-completed" title="Description generated successfully"><span class="dashicons dashicons-yes-alt"></span></span>';
            } else if (status === 'error') {
                newStatusHtml = '<span class="productbird-status-error" title="Error generating description"><span class="dashicons dashicons-no-alt"></span></span>';
            } else if (status === 'queued') {
                newStatusHtml = '<span class="productbird-status-queued" title="Description generation queued"><span class="dashicons dashicons-clock"></span></span>';
            } else if (status === 'none') {
                newStatusHtml = '<span class="productbird-status-none" title="No AI description requested">—</span>';
            } else {
                // Default to "running" for any other status
                newStatusHtml = '<span class="productbird-status-running" title="Description generation in progress"><span class="dashicons dashicons-update"></span></span>';
            }

            statusEl.innerHTML = newStatusHtml;
        }
    }

    function checkStatuses() {
        const productIds = collectPendingProductIds();
        if (productIds.length === 0) return;

        fetch(productbirdStatus.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': productbirdStatus.nonce
            },
            body: JSON.stringify({ productIds: productIds })
        })
        .then(response => response.json())
        .then(data => {
            if (data && typeof data === 'object') {
                updateStatusBadges(data);
            }
        })
        .catch(error => console.error('Productbird status check failed:', error));
    }

    checkStatuses();
    setInterval(checkStatuses, productbirdStatus.pollInterval);
})();
JS;
    }
}