<?php
namespace Productbird\Rest;

use Productbird\Admin\Admin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Provides GET and POST endpoints for Productbird plugin settings.
 *
 * Route: /wp-json/productbird/v1/settings
 *
 * @since 0.1.0
 */
class SettingsEndpoint {
	/**
	 * Bootstraps the REST route registration.
	 *
	 * @since 0.1.0
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		// Read settings
		register_rest_route(
			'productbird/v1',
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE, // GET
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Update settings
		register_rest_route(
			'productbird/v1',
			'/settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE, // POST
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'api_key'         => array(
						'type'     => 'string',
						'required' => false,
					),
					'webhook_secret'  => array(
						'type'     => 'string',
						'required' => false,
					),
					'tone'            => array(
						'type'     => 'string',
						'required' => false,
					),
					'formality'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'selected_org_id' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission callback – only allow admins.
	 *
	 * @since 0.1.0
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the current plugin settings.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request The request object.
	 */
	public function get_settings( WP_REST_Request $request ) {
		$default_settings = array(
			'api_key'         => '',
			'webhook_secret'  => '',
			'tone'            => 'expert',
			'formality'       => 'informal',
			'selected_org_id' => '',
		);
		$saved_settings   = get_option( 'productbird_settings', array() );

		// Merge with default settings.
		$settings = array_merge( $default_settings, $saved_settings );

		return new WP_REST_Response( $settings );
	}

	/**
	 * Updates plugin settings.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request The request object.
	 */
	public function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid payload' ), 400 );
		}

		// Merge with existing settings so we only overwrite provided keys.
		$current = get_option( 'productbird_settings', array() );
		$merged  = array_merge( $current, $params );

		// Sanitize via existing helper.
		$admin     = new Admin();
		$sanitized = $admin->sanitize_settings( $merged );

		update_option( 'productbird_settings', $sanitized );

		return new WP_REST_Response( $sanitized );
	}
}
