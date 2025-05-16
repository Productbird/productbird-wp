<?php

namespace Productbird\Admin;

use Productbird\Api\Client;

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
     * Bulk action identifier for generating product descriptions.
     */
    private const BULK_ACTION_GENERATE_DESCRIPTION = 'productbird_generate_description';

    /**
     * Non-selectable group label to visually separate Productbird actions.
     */
    private const BULK_ACTION_GROUP_LABEL = 'productbird_ai_options';

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
        add_action('admin_init', [$this, 'register_settings']);
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
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [
                    'api_key'   => '',
                    'tone'      => 'expert',
                    'ffmality' => 'informal',
                ],
            ]
        );

        add_settings_section(
            'productbird_main_section',
            __('General Settings', 'productbird'),
            '__return_false', // No callback needed – we render nothing here.
            'productbird'
        );

        // API Key field.
        add_settings_field(
            'productbird_api_key',
            __('API Key', 'productbird'),
            [$this, 'render_api_key_field'],
            'productbird',
            'productbird_main_section'
        );

        // Tone dropdown.
        add_settings_field(
            'productbird_tone',
            __('Default Tone', 'productbird'),
            [$this, 'render_tone_field'],
            'productbird',
            'productbird_main_section'
        );

        // Formality dropdown.
        add_settings_field(
            'productbird_formality',
            __('Default Formality', 'productbird'),
            [$this, 'render_formality_field'],
            'productbird',
            'productbird_main_section'
        );
    }

    /**
     * Adds the Productbird settings page under Settings.
     *
     * @return void
     */
    public function add_settings_page(): void
    {
        add_options_page(
            __('Productbird', 'productbird'),
            __('Productbird', 'productbird'),
            'manage_options',
            'productbird',
            [$this, 'render_settings_page']
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

        return $output;
    }

    /**
     * Render settings page markup.
     */
    public function render_settings_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Productbird Settings', 'productbird'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('productbird_settings_group');
                do_settings_sections('productbird');
                submit_button();
                ?>
            </form>
            <style>
                /* Simple styling for the API key wrapper */
                .productbird-api-key-wrap {
                    display: flex;
                    align-items: center;
                }

                .productbird-api-key-wrap .productbird-toggle-api-key {
                    margin-left: 4px;
                }
            </style>

            <script>
                (function () {
                    const btn = document.querySelector('.productbird-toggle-api-key');
                    if (!btn) return;

                    const input = document.getElementById('productbird_api_key');
                    const icon = btn.querySelector('.dashicons');

                    btn.addEventListener('click', () => {
                        if (input.type === 'password') {
                            input.type = 'text';
                            icon.classList.remove('dashicons-visibility');
                            icon.classList.add('dashicons-hidden');
                            btn.setAttribute('aria-label', '<?php echo esc_js(__('Hide API Key', 'productbird')); ?>');
                        } else {
                            input.type = 'password';
                            icon.classList.remove('dashicons-hidden');
                            icon.classList.add('dashicons-visibility');
                            btn.setAttribute('aria-label', '<?php echo esc_js(__('Show API Key', 'productbird')); ?>');
                        }
                    });
                })();
            </script>
        </div>
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
}