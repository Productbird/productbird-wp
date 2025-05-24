<?php

namespace Productbird\Rest;

use Productbird\Traits\ToolsConfig;

class RestUtils {

	use ToolsConfig;

	/**
	 * Verifies the webhook signature.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	public static function verify_webhook_signature( \WP_REST_Request $request ) {
		$body             = $request->get_body();
		$timestamp        = $request->get_header( 'X-Productbird-Timestamp' );
		$signature_header = $request->get_header( 'X-Productbird-Signature' );

		// Check headers exist
		if ( ! $timestamp || ! $signature_header ) {
			return new \WP_REST_Response(
				array(
					'message' => 'Missing required headers',
					'status'  => 401,
				),
				401
			);
		}

		// Extract signature from header
		if ( strpos( $signature_header, 'sha256=' ) !== 0 ) {
			return new \WP_REST_Response(
				array(
					'message' => 'Invalid signature format',
					'status'  => 401,
				),
				401
			);
		}

		// Extract the signature from the header
		$provided_signature = substr( $signature_header, 7 );

		// Recompute signature
		$secret = get_option( 'productbird_settings' )['webhook_secret'];

		// Ensure we're working with the exact same string representation
		// The body should already be the raw JSON string from the request
		$signed_payload     = $timestamp . '.' . $body;
		$expected_signature = hash_hmac( 'sha256', $signed_payload, $secret );

		if ( ! hash_equals( $expected_signature, $provided_signature ) ) {
			return new \WP_REST_Response(
				array(
					'message' => 'Signature mismatch',
					'status'  => 401,
				),
				401
			);
		}

		return new \WP_REST_Response(
			array(
				'message' => 'Signature verified',
				'status'  => 200,
			),
			200
		);
	}

	/**
	 * Check if the current user has permission to manage WooCommerce.
	 *
	 * @return \WP_REST_Response|WP_Error|bool True if the user has permission, WP_Error otherwise.
	 */
	public static function can_manage_woocommerce_permission() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use this endpoint.', 'productbird' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if items are too many for the tool.
	 *
	 * @param int    $item_count The number of items.
	 * @param string $tool_name The name of the tool.
	 * @return bool|WP_REST_Response True if valid, WP_REST_Response with error otherwise.
	 */
	public static function is_valid_batch_size( int $item_count, string $tool_name ) {
		$max_batch_size = self::get_max_batch_size();

		if ( $item_count > $max_batch_size ) {
			return new \WP_REST_Response(
				array(
					'error' => sprintf(
						/* translators: %s: tool name, %d: max batch size */
						__( 'You can only generate %1$s for up to %2$d products at a time.', 'productbird' ),
						$tool_name,
						$max_batch_size
					),
					'code'  => 'too_many_items',
					'data'  => array( 'status' => 400 ),
				),
				400
			);
		}

		return true;
	}

	/**
	 * Max batch size for the tool.
	 *
	 * @return int The max batch size.
	 */
	private static function get_max_batch_size() {
		return self::MAX_BATCH_SIZE;
	}
}
