<?php

namespace Productbird\Admin;

use Productbird\Auth\OidcClient;
use Productbird\Api\Client;
use Kucrut\Vite;
use Productbird\FeatureFlags;

/**
 * Handles WordPress admin integration.
 */
class Admin
{
    /**
     * The option name used to store all settings.
     */
    private const SETTINGS_OPTION_NAME = 'productbird_settings';

    /**
     * Bootstraps admin hooks.
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

    }

    /**
     * Registers the Productbird settings and fields.
     *
     * @return void
     */
    public function register_settings(): void
    {
        register_setting(
            'productbird_settings_group',
            self::SETTINGS_OPTION_NAME,
            [
                // Store the option as an object and make it available via REST.
                'type'              => 'object',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [
                    'api_key'       => '',
                    'tone'          => 'expert',
                    'formality'     => 'informal',
                    'selected_org_id' => '',
                ]
            ]
        );
    }

    /**
     * Adds the Productbird settings page as a top-level menu item.
     *
     * @return void
     */
    public function add_settings_page(): void
    {
        // phpcs:ignore -- this is a base64 encoded SVG icon for the WP admin menu.
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(plugin_dir_path(dirname(__FILE__, 2)) . 'assets/images/menu-icon.svg'));

        add_menu_page(
            __('Productbird', 'productbird'),
            __('Productbird', 'productbird'),
            'manage_options',
            'productbird',
            [$this, 'render_settings_page'],
            $icon_svg,
			'99.999'
        );
    }

    /**
     * Sanitize and validate settings before saving.
     *
     * @param array $input Raw option values.
     * @return array Sanitized values.
     */
    public function sanitize_settings(array $input): array
    {
        $output = [];

        $output['api_key'] = sanitize_text_field($input['api_key']);
        $output['tone'] = sanitize_text_field($input['tone']);
        $output['formality'] = sanitize_text_field($input['formality']);
        $output['selected_org_id'] = sanitize_text_field($input['selected_org_id']);

        return $output;
    }

    /**
     * Render settings page markup.
     */
    public function render_settings_page(): void
    {
        ?>
            <div id="productbird-admin-settings"></div>
        <?php
    }

    /**
     * Enqueue the compiled/admin Vite assets on Productbird settings page.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        // Only load our assets on the Productbird settings page.
        if ('toplevel_page_productbird' !== $hook_suffix) {
            return;
        }

        /**
         * Small hack to ensure the hash is always set to the root path.
         * This is required by our SPA router.
         */
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                if (window.location.hash === "") {
                    window.location.hash = "/";
                }
            });
        ');

        $dist_path = PRODUCTBIRD_PLUGIN_DIR . '/dist';
        $source_entry = 'assets/admin-settings/index.ts';

        Vite\enqueue_asset(
            $dist_path,
            $source_entry,
            [
                'handle'       => 'productbird-admin',
                'dependencies' => ['jquery', 'wp-api-fetch', 'wp-i18n'],
                // Load the script in footer to avoid render-blocking.
                'in-footer'    => true,
            ]
        );

        $user = wp_get_current_user();

        // ------------------------------------------------------------------
        // Feature flags
        // ------------------------------------------------------------------
        $features      = FeatureFlags::get_all();
        $oidc_enabled  = $features['oidc'] ?? false;

        // ------------------------------------------------------------------
        // Prepare OIDC connection data for the Svelte frontend (optional).
        // ------------------------------------------------------------------
        $oidc_data = [
            'is_enabled' => $oidc_enabled,
        ];

        if ($oidc_enabled) {
            $oidc         = new OidcClient();
            $is_connected = $oidc->is_connected();
            $auth_url     = $is_connected ? '' : $oidc->build_authorization_url();

            $disconnect_url = '';
            $connected_user = '';

            if ($is_connected) {
                // Build the nonce-protected disconnect URL similar to the legacy PHP UI.
                $disconnect_url = wp_nonce_url(
                    admin_url('admin-post.php?action=productbird_oidc_disconnect'),
                    'productbird_disconnect'
                );

                // Try to retrieve the user info ( best-effort, ignore errors ).
                $userinfo = $oidc->get_userinfo();
                if (!is_wp_error($userinfo)) {
                    $connected_user = $userinfo['name'] ?? ($userinfo['email'] ?? '');
                }
            }

            // Make sure URLs are not HTML-encoded when passing to JS
            // using wp_nonce_url() can produce HTML entities like &amp;
            // which causes problems when used directly in href attributes.
            if ($disconnect_url) {
                $disconnect_url = html_entity_decode($disconnect_url);
            }

            if ($auth_url) {
                $auth_url = html_entity_decode($auth_url);
            }

            $oidc_data = [
                'is_enabled'    => true,
                'is_connected'  => $is_connected,
                'auth_url'      => $auth_url,
                'disconnect_url'=> $disconnect_url,
                'name'          => $connected_user,
            ];
        }

        wp_localize_script(
            'productbird-admin',
            'productbird_admin',
            [
                'nonce' => wp_create_nonce('wp_rest'),
                'admin_url' => admin_url(),
                'settings_page_url' => menu_page_url('productbird', false),
                'app_url' => Client::determine_base_url(),
                'api_root_url' => get_rest_url(),
                'current_user' => [
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name,
                ],
                'features' => $features,
                'oidc'     => $oidc_data,
            ]
        );
    }
}