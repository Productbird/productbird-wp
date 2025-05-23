<?php

namespace Productbird;

/**
 * Logger class for Productbird plugin.
 *
 * Only logs when WP_DEBUG is enabled or when running on a local site.
 *
 * @package Productbird
 * @since 0.1.0
 */
class Logger
{
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    /**
     * Check if logging is enabled.
     *
     * @return bool True if logging should occur, false otherwise.
     */
    private static function should_log(): bool
    {
        // Log if WP_DEBUG is enabled or if running on local site
        return (defined('WP_DEBUG') && WP_DEBUG) || Utils::is_local_site();
    }

    /**
     * Write a log message.
     *
     * @param string $level Log level
     * @param string $message The message to log
     * @param array $context Additional context data
     * @return void
     */
    private static function write_log(string $level, string $message, array $context = []): void
    {
        if (!self::should_log()) {
            return;
        }

        $formatted_message = sprintf(
            '[%s] [Productbird] %s',
            $level,
            $message
        );

        // Add context if provided
        if (!empty($context)) {
            $formatted_message .= ' Context: ' . wp_json_encode($context);
        }

        // Use WordPress error_log
        error_log($formatted_message);
    }

    /**
     * Log debug message.
     *
     * @param string $message The message to log
     * @param array $context Additional context data
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write_log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log info message.
     *
     * @param string $message The message to log
     * @param array $context Additional context data
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::write_log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message.
     *
     * @param string $message The message to log
     * @param array $context Additional context data
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write_log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message.
     *
     * @param string $message The message to log
     * @param array $context Additional context data
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::write_log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log API request details.
     *
     * @param string $endpoint The API endpoint
     * @param array $payload The request payload
     * @param string $method HTTP method
     * @return void
     */
    public static function log_api_request(string $endpoint, array $payload = [], string $method = 'POST'): void
    {
        self::debug("API Request", [
            'endpoint' => $endpoint,
            'method' => $method,
            'payload_size' => sizeof($payload),
            'has_payload' => !empty($payload)
        ]);
    }

    /**
     * Log API response details.
     *
     * @param string $endpoint The API endpoint
     * @param mixed $response The API response
     * @param bool $is_error Whether the response is an error
     * @return void
     */
    public static function log_api_response(string $endpoint, $response, bool $is_error = false): void
    {
        $level = $is_error ? self::LEVEL_ERROR : self::LEVEL_DEBUG;

        $context = [
            'endpoint' => $endpoint,
            'is_error' => $is_error
        ];

        if (is_wp_error($response)) {
            $context['error_code'] = $response->get_error_code();
            $context['error_message'] = $response->get_error_message();
            self::write_log($level, "API Response Error", $context);
        } else {
            $context['response_type'] = gettype($response);
            if (is_array($response)) {
                $context['response_keys'] = array_keys($response);
            }
            self::write_log($level, "API Response Success", $context);
        }
    }

    /**
     * Log product processing details.
     *
     * @param int $product_id Product ID
     * @param string $action Action being performed
     * @param string $status Status of the action
     * @param array $additional_data Additional data to log
     * @return void
     */
    public static function log_product_processing(int $product_id, string $action, string $status, array $additional_data = []): void
    {
        self::info("Product Processing", array_merge([
            'product_id' => $product_id,
            'action' => $action,
            'status' => $status
        ], $additional_data));
    }
}
