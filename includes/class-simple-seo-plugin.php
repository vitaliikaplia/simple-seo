<?php
/**
 * Plugin lifecycle and subsystem orchestration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Plugin {
	public const VERSION_OPTION = 'simple_seo_version';

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 20 );

		Simple_SEO_Access::init();
		Simple_SEO_Fields::init();
		Simple_SEO_Head::init();

		if ( is_admin() ) {
			require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-admin.php';
			require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-editor.php';

			Simple_SEO_Admin::init();
			Simple_SEO_Editor::init();
		}

		new Simple_SEO_GitHub_Updater();
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'simple-seo', false, dirname( SIMPLE_SEO_BASENAME ) . '/languages' );
	}

	/** An update does not run the activation hook: per-version migrations go here. */
	public static function maybe_upgrade(): void {
		if ( SIMPLE_SEO_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		Simple_SEO_Settings::install();
		update_option( self::VERSION_OPTION, SIMPLE_SEO_VERSION, true );
	}

	public static function activate( bool $network_wide = false ): void {
	}

	public static function deactivate( bool $network_wide = false ): void {
	}
}
