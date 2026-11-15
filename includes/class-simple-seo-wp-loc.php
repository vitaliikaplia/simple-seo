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
	/** @var array<string, string>|null Language code => hreflang, once per request. */
	private static ?array $languages = null;

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

	/**
	 * The site's languages by code, with the hreflang wp-loc prints for each; [] without wp-loc or
	 * with only one language. Codes are the WPML-style ones wp-loc's wpml_* filters use (uk, en).
	 *
	 * @return array<string, string>
	 */
	public static function languages(): array {
		if ( null !== self::$languages ) {
			return self::$languages;
		}
		if ( ! self::active() ) {
			return self::$languages = array();
		}

		$languages = array();
		foreach ( (array) apply_filters( 'wpml_active_languages', null ) as $code => $language ) {
			if ( ! is_array( $language ) || '' === (string) $code ) {
				continue;
			}
			$code = (string) $code;
			$slug = method_exists( 'WP_LOC_DB', 'from_db_language_code' ) ? ( WP_LOC_DB::from_db_language_code( $code ) ?: $code ) : $code;
			$tag  = (string) ( $language['tag'] ?? str_replace( '_', '-', (string) ( $language['default_locale'] ?? $code ) ) );

			// The value wp-loc prints in hreflang, so the sitemap and the page agree.
			$languages[ $code ] = (string) apply_filters( 'wp_loc_hreflang_code', $tag, $slug );
		}

		return self::$languages = count( $languages ) > 1 ? $languages : array();
	}

	/**
	 * An address of this site in a language: the site's own part of it moved under that language's
	 * home (wpml_home_url). wp-loc prefixes home_url() itself only on the site, not in WP-CLI or the
	 * admin; this gives the same address everywhere. Addresses already under it stay as they are.
	 */
	public static function language_url( string $url, string $code ): string {
		if ( '' === $code || ! self::languages() ) {
			return $url;
		}

		$home = trailingslashit( (string) apply_filters( 'wpml_home_url', '', $code ) );
		$raw  = trailingslashit( set_url_scheme( (string) get_option( 'home' ), (string) wp_parse_url( $url, PHP_URL_SCHEME ) ?: null ) );
		if ( '/' === $home || str_starts_with( $url, $home ) || ! str_starts_with( $url, $raw ) ) {
			return $url;
		}

		return $home . substr( $url, strlen( $raw ) );
	}

	/**
	 * Language codes, the default one first; [''] without wp-loc (one language, no codes).
	 *
	 * @return string[]
	 */
	public static function codes(): array {
		$codes = array_keys( self::languages() );
		if ( ! $codes ) {
			return array( '' );
		}

		$default = self::default_language();

		return array_values( array_unique( array_merge( in_array( $default, $codes, true ) ? array( $default ) : array(), array_map( 'strval', $codes ) ) ) );
	}

	/** Whether wp-loc keeps translations of a post type; true without wp-loc. */
	public static function translatable_post_type( string $type ): bool {
		if ( ! self::languages() ) {
			return true;
		}
		if ( 'page' === $type ) {
			// Every language has its home page.
			return true;
		}

		return class_exists( 'WP_LOC_Admin_Settings' ) && method_exists( 'WP_LOC_Admin_Settings', 'is_translatable' )
			? (bool) WP_LOC_Admin_Settings::is_translatable( $type )
			: true;
	}

	/** Whether wp-loc keeps translations of a taxonomy; true without wp-loc. */
	public static function translatable_taxonomy( string $taxonomy ): bool {
		return ! self::languages() || (bool) apply_filters( 'wpml_is_translated_taxonomy', true, $taxonomy );
	}

	/**
	 * SQL keeping one language's elements: a LEFT JOIN on the translation table (wp-loc keeps WPML's
	 * icl_translations layout) and the condition; the default language keeps the elements without a
	 * language too. ['', ''] without wp-loc or for ''.
	 *
	 * @param string $id_column        Column holding the element ID (post ID, term_taxonomy_id).
	 * @param string $element_type_sql SQL giving the element type: a quoted 'post_post', or CONCAT(…).
	 * @return array{0: string, 1: string} JOIN and WHERE parts.
	 */
	public static function language_sql( string $id_column, string $element_type_sql, string $code ): array {
		global $wpdb;

		$table = self::table();
		if ( '' === $code || '' === $table ) {
			return array( '', '' );
		}

		$join  = " LEFT JOIN {$table} sseo_lang ON sseo_lang.element_id = {$id_column} AND sseo_lang.element_type = {$element_type_sql}";
		$where = $code === self::default_language()
			? $wpdb->prepare( ' AND ( sseo_lang.language_code = %s OR sseo_lang.language_code IS NULL )', $code )
			: $wpdb->prepare( ' AND sseo_lang.language_code = %s', $code );

		return array( $join, $where );
	}

	/** wp-loc's translation table; '' without wp-loc. */
	private static function table(): string {
		if ( ! self::languages() || ! class_exists( 'WP_LOC' ) || ! method_exists( 'WP_LOC', 'instance' ) ) {
			return '';
		}

		$db = WP_LOC::instance()->db ?? null;

		return is_object( $db ) && method_exists( $db, 'get_table' ) ? (string) $db->get_table() : '';
	}

	/**
	 * A term in the language of a post: a value copied from another translation names the term of
	 * that translation's language. The term itself without wp-loc or without a translation.
	 */
	public static function term_for_post( int $term_id, string $taxonomy, WP_Post $post ): int {
		if ( ! $term_id || ! self::languages() ) {
			return $term_id;
		}

		$language = (string) apply_filters(
			'wpml_element_language_code',
			'',
			array(
				'element_id'   => (int) $post->ID,
				'element_type' => 'post_' . $post->post_type,
			)
		);

		return '' !== $language ? (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $language ) : $term_id;
	}

	/** Forget the remembered language list (after the languages or their hreflang filter change). */
	public static function flush(): void {
		self::$languages = null;
	}

	public static function current_language(): string {
		return self::languages() ? (string) apply_filters( 'wpml_current_language', null ) : '';
	}

	public static function default_language(): string {
		return self::languages() ? (string) apply_filters( 'wpml_default_language', null ) : '';
	}

	/**
	 * The language code of each element, '' for an element without one. Posts: element type
	 * post_{type} and post IDs; terms: tax_{taxonomy} and term_taxonomy IDs.
	 *
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public static function element_languages( array $ids, string $element_type ): array {
		if ( ! self::languages() ) {
			return array();
		}

		self::prime( $ids, $element_type );
		$languages = array();
		foreach ( $ids as $id ) {
			$languages[ (int) $id ] = (string) apply_filters(
				'wpml_element_language_code',
				'',
				array(
					'element_id'   => (int) $id,
					'element_type' => $element_type,
				)
			);
		}

		return $languages;
	}

	/**
	 * The translations of each element, itself included: element ID => [language code => ID].
	 * Elements without translations are left out.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, int>>
	 */
	public static function translation_groups( array $ids, string $element_type ): array {
		if ( ! self::languages() ) {
			return array();
		}

		self::prime( $ids, $element_type );
		$groups = array();
		foreach ( $ids as $id ) {
			$trid = (int) apply_filters( 'wpml_element_trid', 0, (int) $id, $element_type );
			if ( ! $trid ) {
				continue;
			}

			$group = array();
			foreach ( (array) apply_filters( 'wpml_get_element_translations', array(), $trid, $element_type ) as $code => $row ) {
				if ( is_object( $row ) && ! empty( $row->element_id ) ) {
					$group[ (string) $code ] = (int) $row->element_id;
				}
			}
			if ( count( $group ) > 1 ) {
				$groups[ (int) $id ] = $group;
			}
		}

		return $groups;
	}

	/**
	 * Run a callback with wp-loc switched to a language (links, options and locale of that
	 * language), then switch back. Without wp-loc, or for '', it just runs.
	 *
	 * @return mixed What the callback returns.
	 */
	public static function in_language( string $code, callable $callback ) {
		if ( '' === $code || ! self::languages() ) {
			return $callback();
		}

		$current = (string) apply_filters( 'wpml_current_language', null );
		if ( $code === $current ) {
			return $callback();
		}

		do_action( 'wpml_switch_language', $code );
		try {
			return $callback();
		} finally {
			do_action( 'wpml_switch_language', $current );
		}
	}

	/**
	 * Load the languages and translation groups of many elements in a few queries, when wp-loc
	 * offers it; otherwise every lookup queries on its own.
	 *
	 * @param int[] $ids
	 */
	private static function prime( array $ids, string $element_type ): void {
		if ( ! $ids || ! class_exists( 'WP_LOC' ) || ! method_exists( 'WP_LOC', 'instance' ) ) {
			return;
		}

		$db = WP_LOC::instance()->db ?? null;
		if ( is_object( $db ) && method_exists( $db, 'prime_elements' ) ) {
			$db->prime_elements( array_map( 'intval', $ids ), $element_type );
		}
	}

	/** The WordPress locale of a language code (uk, en_US); '' for none. */
	public static function locale_of( string $code ): string {
		if ( '' === $code || ! self::languages() ) {
			return '';
		}

		$languages = (array) apply_filters( 'wpml_active_languages', null );

		return (string) ( $languages[ $code ]['default_locale'] ?? '' );
	}

	/** Code of the language the admin edits (as the wpml_* filters name it), '' without wp-loc. */
	public static function admin_language_code(): string {
		if ( ! self::languages() || ! function_exists( 'wp_loc_get_admin_lang' ) ) {
			return '';
		}

		$slug = (string) wp_loc_get_admin_lang();

		return method_exists( 'WP_LOC_DB', 'to_db_language_code' ) ? (string) ( WP_LOC_DB::to_db_language_code( $slug ) ?: $slug ) : $slug;
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
