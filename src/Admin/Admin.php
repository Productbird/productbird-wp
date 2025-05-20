<?php

namespace Productbird\Admin;

use Productbird\Auth\OidcClient;
use Kucrut\Vite;

/**
 * Handles WordPress admin integration.
 */
class Admin
{
    /**
     * The option name used to store all settings.
     */
    private const OPTION_NAME = 'productbird_settings';

    /**
     * Allowed tone options.
     *
     * @var string[]
     */
    private array $tones = [
        'expert'         => 'Expert',
        'daring'         => 'Daring',
        'playful'        => 'Playful',
        'sophisticated'  => 'Sophisticated',
        'persuasive'     => 'Persuasive',
        'supportive'     => 'Supportive',
    ];

    /**
     * Allowed formality options.
     *
     * @var string[]
     */
    private array $formalities = [
        'formal'    => 'Formal',
        'informal'  => 'Informal',
    ];

    /**
     * Bootstraps admin hooks.
     */
    public function init(): void
    {
        // Add menu entry and register settings.
        add_action('admin_menu', [$this, 'add_settings_page']);

        // Register settings early so they are available in both admin pages and REST API requests.
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
            self::OPTION_NAME,
            [
                // Store the option as an object and make it available via REST.
                'type'              => 'object',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [
                    'api_key'       => '',
                    'tone'          => 'expert',
                    'formality'     => 'informal',
                    'selectedOrgId' => '',
                ],
                'show_in_rest'      => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'api_key'   => [
                                'type' => 'string',
                            ],
                            'tone'      => [
                                'type' => 'string',
                                'enum' => array_keys($this->tones),
                            ],
                            'formality' => [
                                'type' => 'string',
                                'enum' => array_keys($this->formalities),
                            ],
                            'selectedOrgId' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
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
        error_log('Productbird sanitize_settings called');
        error_log('Input: ' . print_r($input, true));

        $output = [];

        // API key – allow only trimmed string.
        if (isset($input['api_key'])) {
            $output['api_key'] = sanitize_text_field($input['api_key']);
        }

        // Tone – validate against whitelist.
        $output['tone'] = isset($input['tone'], $this->tones[$input['tone']])
            ? $input['tone']
            : 'expert';

        // Formality – validate against whitelist.
        $output['formality'] = isset($input['formality'], $this->formalities[$input['formality']])
            ? $input['formality']
            : 'informal';

        // Selected organization ID - allow only trimmed string.
        if (isset($input['selectedOrgId'])) {
            $output['selectedOrgId'] = sanitize_text_field($input['selectedOrgId']);
        }

        error_log('Output: ' . print_r($output, true));
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
     * Render API key input field.
     */
    public function render_api_key_field(): void
    {
        $options = get_option(self::OPTION_NAME);
        ?>
        <div class="productbird-api-key-wrap">
            <input
                type="password"
                id="productbird_api_key"
                name="<?php echo esc_attr(self::OPTION_NAME . '[api_key]'); ?>"
                value="<?php echo isset($options['api_key']) ? esc_attr($options['api_key']) : ''; ?>"
                class="regular-text"
                autocomplete="off"
                data-1p-ignore="true"
                data-lpignore="true"
            />
            <button type="button" class="button productbird-toggle-api-key" aria-label="<?php esc_attr_e('Show API Key', 'productbird'); ?>">
                <span class="dashicons dashicons-visibility"></span>
            </button>
        </div>
        <?php
    }

    /**
     * Render tone dropdown field.
     */
    public function render_tone_field(): void
    {
        $options = get_option(self::OPTION_NAME);
        $current = $options['tone'] ?? 'expert';
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME . '[tone]'); ?>">
            <?php foreach ($this->tones as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render formality dropdown field.
     */
    public function render_formality_field(): void
    {
        $options = get_option(self::OPTION_NAME);
        $current = $options['formality'] ?? 'informal';
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME . '[formality]'); ?>">
            <?php foreach ($this->formalities as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
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

        // Prepare OIDC connection data for the Svelte frontend.
        $oidc              = new OidcClient();
        $is_connected      = $oidc->is_connected();
        $auth_url          = $is_connected ? '' : $oidc->build_authorization_url();

        $disconnect_url    = '';
        $connected_user    = '';

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

        wp_localize_script(
            'productbird-admin',
            'productbird_admin',
            [
                'nonce' => wp_create_nonce('wp_rest'),
                'admin_url' => admin_url(),
                'settings_page_url' => menu_page_url('productbird', false),
                'api_root_url' => get_rest_url(),
                'current_user' => [
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name,
                ],
                // Surface OIDC connection state to the Svelte app.
                'oidc' => [
                    'is_connected'   => $is_connected,
                    'auth_url'       => $auth_url,
                    'disconnect_url' => $disconnect_url,
                    'name'           => $connected_user,
                ],
            ]
        );
    }
}