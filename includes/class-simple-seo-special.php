<?php
/**
 * Special pages: the home page, the 404 page and password-protected content. Each has its title
 * and description templates here instead of on the Titles tab, the home page and protected
 * content also an image for social networks (404 gets no social tags).
 *
 * The settings are one option row kept per language when wp-loc is active (Simple_SEO_WP_Loc):
 * the site's home page in English can say something else than in Ukrainian.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Special {
	public const OPTION = 'simple_seo_special';

	/** The slots with settings here, and whether each has an image for social networks. */
	public const SLOTS = array(
		'home'     => true,
		'404'      => false,
		'password' => true,
	);

	public static function init(): void {
		Simple_SEO_Settings::register( self::OPTION, array(), array( self::class, 'sanitize' ), true, true );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab(
			'special',
			__( 'Special pages', 'simple-seo' ),
			array( self::OPTION ),
			array( self::class, 'render_tab' ),
			20,
			'search',
			static fn(): array => array(
				'home'      => __( 'Home page', 'simple-seo' ),
				'not-found' => __( 'Page not found (404)', 'simple-seo' ),
				'password'  => __( 'Password-protected content', 'simple-seo' ),
			)
		);
	}

	public static function handles( string $slot ): bool {
		return array_key_exists( $slot, self::SLOTS );
	}

	/**
	 * The saved template of a slot; null when nothing is saved and the default applies.
	 *
	 * @param string $which title or description.
	 */
	public static function saved_template( string $slot, string $which ): ?string {
		$saved = Simple_SEO_Settings::value( self::OPTION, $slot );

		return is_array( $saved ) && isset( $saved[ $which ] ) && is_string( $saved[ $which ] ) ? $saved[ $which ] : null;
	}

	/** The image of a slot for social networks, 0 for none. */
	public static function image_id( string $slot ): int {
		if ( empty( self::SLOTS[ $slot ] ) ) {
			return 0;
		}

		$saved = Simple_SEO_Settings::value( self::OPTION, $slot );

		return is_array( $saved ) ? absint( $saved['image'] ?? 0 ) : 0;
	}

	/**
	 * Only the known slots; a template equal to the default is not stored (a better default in a
	 * later version reaches it), one saved empty stays empty.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$clean = array();

		foreach ( self::SLOTS as $slot => $has_image ) {
			// PHP turns the key '404' into a number.
			$slot   = (string) $slot;
			$values = $input[ $slot ] ?? null;
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( array( 'title', 'description' ) as $which ) {
				if ( ! array_key_exists( $which, $values ) || ! is_scalar( $values[ $which ] ) ) {
					continue;
				}
				$value = trim( sanitize_text_field( (string) $values[ $which ] ) );
				if ( $value !== Simple_SEO_Titles::default_template( $slot, $which ) ) {
					$clean[ $slot ][ $which ] = $value;
				}
			}

			$image = $has_image ? Simple_SEO_Fields::image_attachment( $values['image'] ?? 0 ) : 0;
			if ( $image ) {
				$clean[ $slot ]['image'] = $image;
			}
		}

		return $clean;
	}

	public static function render_tab(): void {
		$front     = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_on_front' ) ) : null;
		$front     = $front instanceof WP_Post ? $front : null;
		$sections  = array(
			'home'     => array(
				'label' => __( 'Home page', 'simple-seo' ),
				'text'  => $front
					/* translators: %s: title of the page set as the front page. */
					? sprintf( __( 'The front page of the site is the page "%s": these settings apply to it where its own SEO fields are empty.', 'simple-seo' ), get_the_title( $front ) )
					: __( 'The front page of the site lists the latest posts.', 'simple-seo' ),
				'link'  => $front ? (string) get_edit_post_link( $front, 'raw' ) : '',
			),
			'404'      => array(
				'label' => __( 'Page not found (404)', 'simple-seo' ),
				'text'  => __( 'Shown for addresses that lead nowhere. Search engines do not index it and social networks get no preview of it, so it has no image. {title} here is "Page not found" in the language of the page.', 'simple-seo' ),
			),
			'password' => array(
				'label' => __( 'Password-protected content', 'simple-seo' ),
				'text'  => __( 'A post or page protected with a password shows only the password form until the visitor enters it. Meanwhile these templates replace those of its post type, and its featured image and excerpt stay hidden; an SEO title, description or image of its own still wins. {title} here is the post title.', 'simple-seo' ),
			),
		);
		$language  = Simple_SEO_WP_Loc::admin_language();
		$variables = Simple_SEO_Variables::labels();

		require SIMPLE_SEO_DIR . 'includes/views/tab-special.php';
	}
}
