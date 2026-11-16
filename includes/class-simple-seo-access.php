<?php
/**
 * Who may change the plugin settings and who may edit SEO fields.
 *
 * The capabilities are granted on the fly from the simple_seo_access option through the
 * user_has_cap filter: nothing is written into the roles stored in the database, so there is
 * nothing to clean up when the plugin is removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Access {
	public const OPTION = 'simple_seo_access';

	/** Change the plugin settings. Users who can manage_options always have it. */
	public const CAP_SETTINGS = 'simple_seo_manage_settings';

	/** Edit the SEO title, description and other basic fields of what the user can edit. */
	public const CAP_FIELDS = 'simple_seo_edit_fields';

	/** Edit the fields that change indexing: robots, canonical and the like. */
	public const CAP_ADVANCED = 'simple_seo_edit_advanced';

	/** See the data of Google Search Console: its page and the part of the SEO box. */
	public const CAP_REPORTS = 'simple_seo_view_reports';

	/** What users who can manage_options always have; administrators always keep them in the setting. */
	private const ALWAYS = array( self::CAP_SETTINGS, self::CAP_REPORTS );

	/** Option key => capability. */
	private const KEYS = array(
		'settings' => self::CAP_SETTINGS,
		'fields'   => self::CAP_FIELDS,
		'advanced' => self::CAP_ADVANCED,
		'reports'  => self::CAP_REPORTS,
	);

	public static function init(): void {
		Simple_SEO_Settings::register( self::OPTION, self::defaults(), array( self::class, 'sanitize' ), true );
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 4 );
	}

	/** @return array<string, string[]> */
	public static function defaults(): array {
		return array(
			'settings' => array( 'administrator' ),
			'fields'   => array( 'administrator', 'editor', 'author', 'contributor' ),
			'advanced' => array( 'administrator', 'editor' ),
			'reports'  => array( 'administrator', 'editor' ),
		);
	}

	/** @return array<string, string> Option key => capability. */
	public static function keys(): array {
		return self::KEYS;
	}

	/**
	 * Grant the plugin's capabilities to the roles chosen in the settings.
	 *
	 * Runs on every capability check, so it returns at once unless one of ours is asked for.
	 *
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param array<int, mixed>   $args
	 * @return array<string, bool>
	 */
	public static function grant( array $allcaps, array $caps, array $args, WP_User $user ): array {
		$asked = array_intersect( $caps, self::KEYS );
		if ( ! $asked ) {
			return $allcaps;
		}

		$roles  = (array) $user->roles;
		$access = Simple_SEO_Settings::get( self::OPTION );

		foreach ( $asked as $cap ) {
			$key = (string) array_search( $cap, self::KEYS, true );

			if ( in_array( $cap, self::ALWAYS, true ) && ! empty( $allcaps['manage_options'] ) ) {
				// Whoever can manage the site can never be locked out of the plugin settings, nor
				// lose sight of how the site does in search.
				$allcaps[ $cap ] = true;
				continue;
			}

			$allowed = isset( $access[ $key ] ) && is_array( $access[ $key ] ) ? $access[ $key ] : array();
			if ( array_intersect( $roles, $allowed ) ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * Keep only roles that exist; administrators always keep the settings.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, string[]>
	 */
	public static function sanitize( array $input ): array {
		$existing = array_keys( wp_roles()->get_names() );
		$clean    = array();

		foreach ( array_keys( self::KEYS ) as $key ) {
			$roles         = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? array_map( 'sanitize_key', $input[ $key ] ) : array();
			$clean[ $key ] = array_values( array_intersect( $existing, $roles ) );
		}

		foreach ( array( 'settings', 'reports' ) as $key ) {
			if ( in_array( 'administrator', $existing, true ) && ! in_array( 'administrator', $clean[ $key ], true ) ) {
				array_unshift( $clean[ $key ], 'administrator' );
			}
		}

		return $clean;
	}

	/** Render the Access tab of the settings page. */
	public static function render_tab(): void {
		$access = Simple_SEO_Settings::get( self::OPTION );
		$roles  = wp_roles()->get_names();
		$rows   = array(
			'settings' => array(
				'label' => __( 'Change the settings', 'simple-seo' ),
				'help'  => __( 'Open this page and change any Simple SEO settings. Administrators always can.', 'simple-seo' ),
			),
			'fields'   => array(
				'label' => __( 'Edit SEO fields', 'simple-seo' ),
				'help'  => __( 'The SEO title, description and other basic fields of the posts, pages and terms the user can edit anyway.', 'simple-seo' ),
			),
			'advanced' => array(
				'label' => __( 'Edit indexing fields', 'simple-seo' ),
				'help'  => __( 'Fields that decide whether and how search engines index a page: robots, canonical address. A wrong value can take a page out of search.', 'simple-seo' ),
			),
			'reports'  => array(
				'label' => __( 'See Search Console data', 'simple-seo' ),
				'help'  => __( 'The clicks, impressions and queries of Google Search Console: on its page in the SEO menu, and in the SEO box of the posts the user can edit. Administrators always can.', 'simple-seo' ),
			),
		);

		require SIMPLE_SEO_DIR . 'includes/views/tab-access.php';
	}
}
