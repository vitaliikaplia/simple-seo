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
		Simple_SEO_Variables::init();
		Simple_SEO_Titles::init();

		if ( is_admin() ) {
			require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-admin.php';
			require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-editor.php';

			Simple_SEO_Admin::init();
			Simple_SEO_Editor::init();
		}

		new Simple_SEO_GitHub_Updater();
	}

	/**
	 * Another active SEO plugin, by name; '' when there is none.
	 *
	 * While one is active Simple SEO prints nothing on the site, so pages never get two titles,
	 * two descriptions or two canonicals during a switch-over. Its settings and fields stay usable.
	 */
	public static function conflicting_plugin(): string {
		$plugins = array(
			'WPSEO_VERSION'              => 'Yoast SEO',
			'RANK_MATH_VERSION'          => 'Rank Math',
			'AIOSEO_VERSION'             => 'All in One SEO',
			'SEOPRESS_VERSION'           => 'SEOPress',
			'THE_SEO_FRAMEWORK_VERSION'  => 'The SEO Framework',
			'SLIM_SEO_VER'               => 'Slim SEO',
		);

		$found = '';
		foreach ( $plugins as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found = $name;
				break;
			}
		}

		/**
		 * The active SEO plugin Simple SEO steps aside for; return '' to print anyway.
		 *
		 * @param string $found Plugin name, '' when none was found.
		 */
		return (string) apply_filters( 'simple_seo_conflicting_plugin', $found );
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
