<?php
/**
 * Plugin Name: Productbird
 * Plugin URI:  https://productbird.ai
 * Description: Productbird helps ecommerce owners get more done by providing various AI tools.
 * Version:     1.1.0
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Author:      Productbird
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: productbird
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 *
 * @package Productbird
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

define('PRODUCTBIRD_VERSION', '1.1.0');
define('PRODUCTBIRD_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Require Composer autoloader.
$autoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    /** @psalm-suppress UnresolvableInclude */
    require_once $autoloader;
} else {
    wp_die(
        esc_html__('You must run `composer install` from the Productbird plugin directory before activating the plugin.', 'productbird')
    );
}

use Productbird\Plugin;

/**
 * Returns the singleton instance of the core plugin class.
 *
 * @since 0.1.0
 * @return Plugin
 */
function productbird(): Plugin
{
    static $instance = null;

    if ($instance === null) {
        $instance = new Plugin();
    }

    return $instance;
}

add_action('plugins_loaded', static function () {
    productbird()->init();
});

register_activation_hook(__FILE__, [productbird(), 'activate']);
register_deactivation_hook(__FILE__, [productbird(), 'deactivate']);