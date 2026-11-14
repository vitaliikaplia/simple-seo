<?php
/**
 * Plugin Name: Simple SEO
 * Description: A lightweight SEO plugin for WordPress.
 * Version: 0.3.0
 * Author: Vitalii Kaplia
 * Author URI: https://kaplia.pro/
 * Update URI: https://github.com/vitaliikaplia/simple-seo
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: simple-seo
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress refuses to activate or update the plugin on PHP older than 8.1, but PHP can be downgraded
// after activation. Show a notice and load nothing instead of a fatal error; this file parses on PHP 7.
if ( PHP_VERSION_ID < 80100 ) {
	$simple_seo_old_php = static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>Simple SEO requires PHP 8.1 or newer (current: ',
			esc_html( PHP_VERSION ), '). The plugin was not loaded.</p></div>';
	};
	add_action( 'admin_notices', $simple_seo_old_php );
	unset( $simple_seo_old_php );
	return;
}

define( 'SIMPLE_SEO_VERSION', '0.3.0' );
define( 'SIMPLE_SEO_FILE', __FILE__ );
define( 'SIMPLE_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLE_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'SIMPLE_SEO_BASENAME', plugin_basename( __FILE__ ) );

require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-settings.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-access.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-fields.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-context.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-head.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-variables.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-titles.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-github-updater.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-plugin.php';

register_activation_hook( __FILE__, array( 'Simple_SEO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Simple_SEO_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Simple_SEO_Plugin', 'init' ) );
