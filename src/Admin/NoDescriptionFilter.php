<?php

namespace Productbird\Admin;

/**
 * Adds a filter to the WooCommerce products list to find products without descriptions.
 * @since 0.1.0
 */
class NoDescriptionFilter
{
    /**
     * Initializes hooks for the filter.
     * @since 0.1.0
     * @return void
     */
    public function init(): void
    {
        add_action('restrict_manage_posts', [$this, 'add_empty_description_filter'], 10, 2);
        add_action('pre_get_posts', [$this, 'filter_products_by_empty_description']);
    }

    /**
     * Adds a dropdown to filter products by empty description.
     *
     * @since 0.1.0
     * @param string $post_type The current post type.
     * @param string $which     Position of the filter (top or bottom).
     * @return void
     */
    public function add_empty_description_filter(string $post_type, string $which): void
    {
        if ($post_type !== 'product' || $which !== 'top') {
            return;
        }

        $current_value = isset($_GET['empty_description']) ? sanitize_text_field($_GET['empty_description']) : '';

        ?>
        <select name="empty_description" id="filter-by-empty-description">
            <option value=""><?php esc_html_e('Filter by description', 'productbird'); ?></option>
            <option value="1" <?php selected($current_value, '1'); ?>><?php esc_html_e('No description', 'productbird'); ?></option>
            <option value="ai" <?php selected($current_value, 'ai'); ?>><?php esc_html_e('AI generated', 'productbird'); ?></option>
        </select>
        <?php
    }

    /**
     * Modifies the query to filter products with empty descriptions.
     *
     * @since 0.1.0
     * @param \WP_Query $query The WordPress query object.
     * @return void
     */
    public function filter_products_by_empty_description(\WP_Query $query): void
    {
        global $pagenow;

        // Only run on the admin products page
        if (!is_admin() || $pagenow !== 'edit.php' || !isset($query->query['post_type']) || $query->query['post_type'] !== 'product') {
            return;
        }

        // Check if our filter is set and not empty
        if (isset($_GET['empty_description'])) {
            $filter_value = sanitize_text_field($_GET['empty_description']);

            if ($filter_value === '1') {
                add_filter('posts_where', [$this, 'filter_where_empty_content']);
            } elseif ($filter_value === 'ai') {
                if (!isset($query->query_vars['meta_query'])) {
                    $query->query_vars['meta_query'] = [];
                }

                $query->query_vars['meta_query'][] = [
                    'key'     => '_productbird_generation_status',
                    'value'   => 'completed',
                    'compare' => '=',
                ];
            }
        }
    }

    /**
     * Adds a WHERE clause to the SQL query to find products with empty content.
     *
     * @since 0.1.0
     * @param string $where The current WHERE clause.
     * @return string Modified WHERE clause.
     */
    public function filter_where_empty_content(string $where): string
    {
        global $wpdb;

        $where .= " AND ($wpdb->posts.post_content = '' OR $wpdb->posts.post_content IS NULL)";

        remove_filter('posts_where', [$this, 'filter_where_empty_content']);

        return $where;
    }
}