<?php

namespace Productbird\Admin;

use Productbird\Api\Client;
use Productbird\Traits\ProductDataHelpers;

/**
 * Adds a row action to generate product descriptions from the product list table.
 *
 * @since 0.1.0
 */
class ProductDescriptionRowAction {

	use ProductDataHelpers;

	/**
	 * Option name used to store Productbird settings.
	 */
	private const OPTION_NAME = 'productbird_settings';

	/**
	 * Row action identifier for generating product descriptions.
	 */
	private const ROW_ACTION_GENERATE_DESCRIPTION = 'productbird_magic_descriptions';

	/**
	 * Initialize hooks for the row action.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function init(): void {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_row_action' ) );
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
	}

	/**
	 * Adds the generate description action to the row actions.
	 *
	 * @since 0.1.0
	 * @param array<string,string> $actions Existing row actions.
	 * @param \WP_Post             $post The post object.
	 * @return array<string,string> Modified actions.
	 */
	public function add_row_action( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== 'product' ) {
			return $actions;
		}

		$actions[ self::ROW_ACTION_GENERATE_DESCRIPTION ] = sprintf(
			'<a href="%s">%s</a>',
			wp_nonce_url(
				admin_url( sprintf( 'edit.php?post_type=product&action=%s&post=%d', self::ROW_ACTION_GENERATE_DESCRIPTION, $post->ID ) ),
				'productbird_magic_descriptions_' . $post->ID
			),
			__( 'Generate AI Description', 'productbird' )
		);

		return $actions;
	}

	/**
	 * Handles the row action when clicked.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function handle_row_action(): void {
		if ( ! isset( $_GET['action'] ) || $_GET['action'] !== self::ROW_ACTION_GENERATE_DESCRIPTION ) {
			return;
		}

		// Skip if this is a bulk action request (handled by ProductDescriptionBulkAction)
		if ( isset( $_GET['bulk_action'] ) || isset( $_POST['bulk_action'] ) ||
			( isset( $_GET['post'] ) && is_array( $_GET['post'] ) ) ||
			( isset( $_POST['post'] ) && is_array( $_POST['post'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['post'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			wp_die( __( 'Invalid request.', 'productbird' ) );
		}

		$post_id = absint( $_GET['post'] );
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'productbird_magic_descriptions_' . $post_id ) ) {
			wp_die( __( 'Security check failed.', 'productbird' ) );
		}

		$options = get_option( self::OPTION_NAME );
		$api_key = $options['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			wp_redirect( add_query_arg( 'productbird_error', 'no_api_key', wp_get_referer() ) );
			exit;
		}

		$product = \wc_get_product( $post_id );
		if ( ! $product ) {
			wp_redirect( add_query_arg( 'productbird_error', 'invalid_product', wp_get_referer() ) );
			exit;
		}

		$client = new Client( $api_key );

		// Build the payload for this product
		$payload = $this->build_product_payload(
			$product,
			array(
				'tone'         => $options['tone'] ?? null,
				'formality'    => $options['formality'] ?? null,
				'callback_url' => rest_url( 'productbird/v1/webhooks' ) . '?tool=magic-descriptions',
			)
		);

		// Remove empty/null entries to keep the payload concise
		$payload = array_filter(
			$payload,
			static function ( $value ) {
				return $value !== null && $value !== '' && $value !== array();
			}
		);

		$response = $client->generate_product_description( $payload );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status     = $error_data['status'] ?? 0;

			error_log( print_r( $response, true ) );

			if ( $status === 401 ) {
				wp_redirect( add_query_arg( 'productbird_error', 'unauthorized', wp_get_referer() ) );
				exit;
			}
			if ( $status === 402 ) {
				wp_redirect( add_query_arg( 'productbird_error', 'insufficient_credits', wp_get_referer() ) );
				exit;
			}

			wp_redirect( add_query_arg( 'productbird_error', 'generation_failed', wp_get_referer() ) );
			exit;
		}

		if ( isset( $response['statusId'] ) ) {
			update_post_meta( $post_id, '_productbird_status_id', sanitize_text_field( $response['statusId'] ) );
			update_post_meta( $post_id, '_productbird_generation_status', 'queued' );
		}

		wp_redirect( add_query_arg( 'productbird_generated', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Displays admin notices for row action results.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function display_notices(): void {
		if ( ! isset( $_GET['productbird_error'] ) && ! isset( $_GET['productbird_generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( isset( $_GET['productbird_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error = sanitize_text_field( $_GET['productbird_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			switch ( $error ) {
				case 'no_api_key':
					$message = __( 'Productbird API key is missing. Please configure it in the settings.', 'productbird' );
					break;
				case 'invalid_product':
					$message = __( 'Invalid product selected.', 'productbird' );
					break;
				case 'unauthorized':
					$message = __( 'Productbird API returned Unauthorized. Please check your API key.', 'productbird' );
					break;
				case 'insufficient_credits':
					$message = __( 'Insufficient credits available. Please purchase more credits to continue using Productbird.', 'productbird' );
					break;
				case 'generation_failed':
					$message = __( 'Failed to generate product description. Please try again.', 'productbird' );
					break;
				default:
					$message = __( 'An unknown error occurred.', 'productbird' );
			}

			printf(
				'<div class="error notice is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
			return;
		}

		if ( isset( $_GET['productbird_generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="updated notice is-dismissible"><p>%s</p></div>',
				esc_html__( 'Productbird has queued description generation. Status will update in the "AI Desc." column.', 'productbird' )
			);
		}
	}
}
