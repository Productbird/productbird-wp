<?php

namespace Productbird\Traits;

/**
 * Trait for sharing common script localization data between admin classes.
 *
 * @since 0.1.0
 */
trait ScriptLocalization {

	/**
	 * Get common data to be localized for all Productbird scripts.
	 *
	 * @since 0.1.0
	 * @return array<string,mixed> Common localization data.
	 */
	protected function get_common_localization_data(): array {
		return array(
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'api_root_url' => get_rest_url(),
			'admin_url'    => admin_url(),
		);
	}

	/**
	 * Get global data that should be available in all admin pages.
	 *
	 * @since 0.1.0
	 * @return array<string,mixed> Global admin data.
	 */
	protected function get_global_admin_data(): array {
		return array_merge(
			$this->get_common_localization_data(),
			array(
				'app_url' => \Productbird\Api\Client::determine_base_url(),
				'version' => PRODUCTBIRD_VERSION,
			)
		);
	}
}
