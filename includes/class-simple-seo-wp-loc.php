<?php
/**
 * The bond with wp-loc, Vitalii's multilingual plugin. wp-loc owns languages and translations,
 * Simple SEO the SEO output; this class tells wp-loc what it needs to know and does nothing when
 * wp-loc is not active — every hook here is wp-loc's own or core's.
 *
 * Localized options: a row registered with Simple_SEO_Settings as localized (the Special pages
 * row) goes into wp-loc's list of multilingual options. wp-loc then keeps the default language's
 * values in the row itself and every other language's in {row}_{language}: on the site it returns
 * the row of the page's language, on the Simple SEO settings page that of the admin language,
 * and options.php saves into the admin language's row.
 *
 * Canonical: Simple_SEO_Indexing::canonical_handled() answers wp-loc's
 * wp_loc_canonical_already_handled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_WP_Loc {
	public static function init(): void {
		add_filter( 'wp_loc_default_multilingual_options', array( self::class, 'multilingual_options' ) );
		add_filter( 'wp_loc_is_localized_options_screen', array( self::class, 'localized_options_screen' ), 10, 2 );

		// The cached rows hold one language's values: forget them when the language changes mid-request
		// (wp-loc switches the locale with it) and once wp-loc has set up the admin screen.
		add_action( 'change_locale', array( self::class, 'flush_settings' ) );
		add_action( 'wpml_switch_language', array( self::class, 'flush_settings' ), 20 );
		add_action( 'current_screen', array( self::class, 'flush_settings' ), 20 );
	}

	public static function active(): bool {
		return function_exists( 'wp_loc_get_current_lang' ) && class_exists( 'WP_LOC_Languages' );
	}

	/**
	 * @param mixed $options
	 * @return array<int, string>
	 */
	public static function multilingual_options( $options ): array {
		return array_values( array_unique( array_merge( (array) $options, Simple_SEO_Settings::localized() ) ) );
	}

	/**
	 * The Simple SEO settings page edits the admin language's values of the localized rows.
	 *
	 * @param mixed $is_settings
	 * @param mixed $screen
	 */
	public static function localized_options_screen( $is_settings, $screen ): bool {
		return ( $screen instanceof WP_Screen && 'toplevel_page_simple-seo' === $screen->id ) || (bool) $is_settings;
	}

	public static function flush_settings(): void {
		Simple_SEO_Settings::flush();
	}

	/** Name of the language the admin edits, '' without wp-loc or with only one language. */
	public static function admin_language(): string {
		if ( ! self::active() || ! function_exists( 'wp_loc_get_admin_lang' )
			|| ! method_exists( 'WP_LOC_Languages', 'get_active_languages' ) || ! method_exists( 'WP_LOC_Languages', 'get_display_name' ) ) {
			return '';
		}
		if ( count( (array) WP_LOC_Languages::get_active_languages() ) < 2 ) {
			return '';
		}

		$language = (string) wp_loc_get_admin_lang();

		return '' !== $language ? (string) WP_LOC_Languages::get_display_name( $language ) : '';
	}
}
