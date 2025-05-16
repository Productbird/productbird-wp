<?php

namespace Productbird\Cron;

use Productbird\Admin\ProductGenerationStatusColumn;
use Productbird\Api\Client;

/**
 * Handles background polling of Productbird API for description generation status updates.
 *
 * This serves as a fallback mechanism in case the user's browser isn't running
 * the frontend JavaScript polling, or if the REST callback doesn't reach the site.
 */
class ProductbirdPoller
{
    /**
     * Initialize hooks and actions.
     *
     * @return void
     */
    public function init(): void
    {
        // Register the cron hook
        add_action('productbird_poll_statuses', [$this, 'run']);

        // Schedule the cron if it's not already scheduled
        if (!wp_next_scheduled('productbird_poll_statuses')) {
            wp_schedule_event(time() + 300, 'hourly', 'productbird_poll_statuses');
        }
    }

    /**
     * Run the poller to check for pending product descriptions.
     *
     * @return void
     */
    public function run(): void
    {
        // Find all products with a status ID (indicating they're pending)
        $query = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'meta_key'       => ProductGenerationStatusColumn::META_KEY_STATUS_ID,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        if (empty($query->posts)) {
            return;
        }

        // Get the API key from settings
        $options = get_option('productbird_settings', []);
        $api_key = $options['api_key'] ?? '';

        if (empty($api_key)) {
            return;
        }

        $client = new Client($api_key);

        foreach ($query->posts as $product_id) {
            $status_id = get_post_meta(
                $product_id,
                ProductGenerationStatusColumn::META_KEY_STATUS_ID,
                true
            );

            if (empty($status_id)) {
                continue;
            }

            $response = $client->get_product_description_status($status_id);

            if (is_wp_error($response)) {
                error_log(sprintf(
                    'Productbird Poller: Error checking status for product %d: %s',
                    $product_id,
                    $response->get_error_message()
                ));
                continue; // Skip this one on error
            }

            // Map workflowState to internal status
            $workflow_state = $response['status'] ?? '';
            error_log(sprintf(
                'Productbird Poller: Processing product %d with workflow state: %s',
                $product_id,
                $workflow_state
            ));

            switch ($workflow_state) {
                case 'RUN_SUCCESS':
                    // Update the product with the new description
                    $product = \wc_get_product($product_id);
                    if ($product && !empty($response['description'])) {
                        $product->set_description(wp_kses_post($response['description']));
                        $product->save();
                        error_log(sprintf(
                            'Productbird Poller: Successfully updated description for product %d',
                            $product_id
                        ));
                    } else {
                        error_log(sprintf(
                            'Productbird Poller: Failed to update product %d - Product not found or no description in response',
                            $product_id
                        ));
                    }

                    // Update status
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'completed'
                    );
                    delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);
                    error_log(sprintf(
                        'Productbird Poller: Marked product %d as completed',
                        $product_id
                    ));
                    break;

                case 'RUN_FAILED':
                case 'RUN_CANCELED':
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'error'
                    );
                    delete_post_meta($product_id, ProductGenerationStatusColumn::META_KEY_STATUS_ID);
                    error_log(sprintf(
                        'Productbird Poller: Marked product %d as failed/canceled with state: %s',
                        $product_id,
                        $workflow_state
                    ));
                    break;

                case 'RUN_STARTED':
                default:
                    update_post_meta(
                        $product_id,
                        ProductGenerationStatusColumn::META_KEY_GENERATION_STATUS,
                        'running'
                    );
                    error_log(sprintf(
                        'Productbird Poller: Product %d is still running (state: %s)',
                        $product_id,
                        $workflow_state
                    ));
                    break;
            }
        }
    }
}