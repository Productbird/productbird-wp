<?php

namespace Productbird\Api;

use WP_Error;

/**
 * Handles HTTP communication with the Productbird AI service.
 *
 * The base URL is automatically determined based on whether the current
 * WordPress site is running locally ( localhost / 127.0.0.1 / *.local ) or
 * remotely. A different base URL can also be supplied explicitly via the
 * constructor or by filtering the `productbird_api_base_url` hook.
 *
 * Example usage:
 *
 * ```php
 * $client = new \Productbird\Api\Client( $api_key );
 * $response = $client->generate_product_description( $payload );
 * ```
 */
class Client
{
    /**
     * Endpoint path for generating a product description.
     */
    private const GENERATE_PRODUCT_DESCRIPTION_ENDPOINT = '/api/v1/generate/product-description';

        /**
     * Endpoint path for generating a product description in bulk
     */
    private const GENERATE_PRODUCT_DESCRIPTION_BULK_ENDPOINT = '/api/v1/generate/product-description/bulk';

    /**
     * Production base URL.
     */
    private const PROD_BASE_URL = 'https://app.productbird.ai';

    /**
     * Local development base URL.
     */
    private const LOCAL_BASE_URL = 'http://localhost:5173';

    /**
     * The API key used for authorization.
     *
     * @var string
     */
    private string $api_key;

    /**
     * Base URL for all requests.
     *
     * @var string
     */
    private string $base_url;

    /**
     * Constructor.
     *
     * @param string      $api_key  The secret API key.
     * @param string|null $base_url Optional base URL override. If omitted, this
     *                              class will decide automatically based on the
     *                              current environment.
     */
    public function __construct(string $api_key, ?string $base_url = null)
    {
        $this->api_key  = $api_key;
        $this->base_url = $base_url ?? self::determine_base_url();

        /**
         * Filter the API base URL that will be used for subsequent requests.
         *
         * @param string $base_url URL that will be used. Either the constructor
         *                         override, the automatically detected local
         *                         URL, or the default production URL.
         */
        $this->base_url = apply_filters('productbird_api_base_url', $this->base_url);

        // Ensure no trailing slash to keep path concatenation predictable.
        $this->base_url = rtrim($this->base_url, '/');
    }

    /**
     * Generates a product description via the Productbird workflow API.
     *
     * @see WorkflowInput for the expected payload
     *
     * @param array<string,mixed> $payload Data matching the WorkflowInput schema.
     * @return array<string,mixed>|WP_Error The decoded JSON response on success
     *                                      or a WP_Error on failure.
     */
    public function generate_product_description(array $payload)
    {
        return $this->post(self::GENERATE_PRODUCT_DESCRIPTION_ENDPOINT, $payload);
    }

    /**
     * Generates multiple product descriptions via the Productbird workflow API.
     *
     * The bulk endpoint accepts an array of the same payload that would be
     * sent to the single-generation endpoint. Each element in the outer array
     * represents one product description request.
     *
     * @see WorkflowInput for the expected payload structure of each element.
     *
     * @param array<int,array<string,mixed>> $payloads Array of WorkflowInput payloads.
     * @return array<string,mixed>|WP_Error The decoded JSON response on success
     *                                      or a WP_Error on failure.
     */
    public function generate_product_description_bulk(array $payloads)
    {
        return $this->post(self::GENERATE_PRODUCT_DESCRIPTION_BULK_ENDPOINT, $payloads);
    }

    /**
     * Polls for the status of a product description generation job.
     *
     * This method checks if a previously requested description has been generated
     * and is ready for retrieval.
     *
     * @param string $status_id The status ID returned from the generation request.
     * @return array<string,mixed>|WP_Error The decoded JSON response on success
     *                                      or a WP_Error on failure.
     */
    public function get_product_description_status(string $status_id)
    {
        return $this->get('/api/v1/generate/product-description?statusId=' . $status_id);
    }

    /**
     * Perform an authenticated POST request and decode the JSON response.
     *
     * @param string               $endpoint An endpoint path starting with '/'.
     * @param array<string,mixed>  $body     Request body.
     * @return array<string,mixed>|WP_Error  Decoded JSON on success or WP_Error.
     */
    private function post(string $endpoint, array $body)
    {
        $url = $this->base_url . $endpoint;

        $args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . trim($this->api_key),
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            error_log('Productbird API request failed: ' . $response->get_error_message());

            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }

        return new WP_Error(
            'productbird_api_error',
            sprintf(
                /* Translators: %d is the HTTP status code. */
                esc_html__('Productbird API request failed with status %d.', 'productbird'),
                $code
            ),
            [
                'status' => $code,
                'body'   => $data ?? $raw,
            ]
        );
    }

    /**
     * Perform an authenticated GET request and decode the JSON response.
     *
     * @param string $endpoint An endpoint path starting with '/'.
     * @return array<string,mixed>|WP_Error Decoded JSON on success or WP_Error.
     */
    private function get(string $endpoint)
    {
        $url = $this->base_url . $endpoint;

        $args = [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . trim($this->api_key),
            ],
            'timeout' => 30,
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('Productbird API request failed: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);


        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }

        return new WP_Error(
            'productbird_api_error',
            sprintf(
                /* Translators: %d is the HTTP status code. */
                esc_html__('Productbird API request failed with status %d.', 'productbird'),
                $code
            ),
            [
                'status' => $code,
                'body'   => $data ?? $raw,
            ]
        );
    }

    /**
     * Decide which base URL should be used by default.
     */
    public static function determine_base_url(): string
    {
        return self::is_local_site() ? self::LOCAL_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * Detects whether the site is running on a localhost-style domain.
     */
    public static function is_local_site(): bool
    {
        $site_url = home_url();

        return strpos($site_url, 'localhost') !== false
            || strpos($site_url, '127.0.0.1') !== false
            || strpos($site_url, '.local') !== false;
    }
}