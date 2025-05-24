<?php

namespace Productbird\Admin;

use Productbird\Traits\ScriptLocalization;

/**
 * Handles the global Productbird script that's loaded on all admin pages.
 *
 * @since 0.1.0
 */
class GlobalAdminScript {

	use ScriptLocalization;

	/**
	 * Initialize hooks.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_global_script' ) );
	}

	/**
	 * Enqueue the global Productbird script on all admin pages.
	 *
	 * @since 0.1.0
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public function enqueue_global_script( string $hook_suffix ): void {
		// Register and enqueue an empty script that we'll use to localize data
		wp_register_script(
			'productbird-global',
			'', // Empty source since we only need it for localization
			array(),
			PRODUCTBIRD_VERSION,
			true
		);
		wp_enqueue_script( 'productbird-global' );

		// Localize the global data
		wp_localize_script(
			'productbird-global',
			'productbird',
			$this->get_global_admin_data()
		);
	}
}
