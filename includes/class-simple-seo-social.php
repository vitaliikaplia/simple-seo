<?php
/**
 * Social networks: Open Graph tags (Facebook, Telegram, Viber, LinkedIn, …) and the X card.
 *
 * Title and description default to the SEO title and description; the image comes from the
 * post's or term's own social image, then the featured image, then the image set for the home page
 * or password-protected content, then the default image from the settings. X reads the og:* tags,
 * so only twitter:card and twitter:site are printed for it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Social {
	public const OPTION = 'simple_seo_social';

	/** Facebook wants language_TERRITORY; WordPress has bare languages for these. */
	private const LOCALE_TERRITORIES = array(
		'ar' => 'AR',
		'cs' => 'CZ',
		'da' => 'DK',
		'el' => 'GR',
		'en' => 'US',
		'et' => 'EE',
		'he' => 'IL',
		'hy' => 'AM',
		'ja' => 'JP',
		'ka' => 'GE',
		'kk' => 'KZ',
		'ko' => 'KR',
		'sq' => 'AL',
		'sv' => 'SE',
		'uk' => 'UA',
		'vi' => 'VN',
		'zh' => 'CN',
	);

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'default_image' => 0,
				'x_account'     => '',
			),
			array( self::class, 'sanitize' ),
			true
		);

		add_action( 'init', array( self::class, 'register_fields' ), 5 );
		Simple_SEO_Head::add_provider( 'social', array( self::class, 'head_tags' ), 30 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function register_fields(): void {
		Simple_SEO_Fields::register_group( 'social', __( 'Social networks', 'simple-seo' ) );
		Simple_SEO_Fields::register(
			'og_title',
			array(
				'label'       => __( 'Title for social networks', 'simple-seo' ),
				'description' => __( 'How a shared link is titled on Facebook, Telegram, X and the like. Leave empty to use the SEO title.', 'simple-seo' ),
				'order'       => 30,
				'group'       => 'social',
				'variables'   => true,
				'preview'     => static fn( string $type, $object ): string => self::title( self::object_context( $type, $object ) ),
			)
		);
		Simple_SEO_Fields::register(
			'og_description',
			array(
				'label'       => __( 'Description for social networks', 'simple-seo' ),
				'description' => __( 'Leave empty to use the meta description.', 'simple-seo' ),
				'type'        => 'textarea',
				'order'       => 40,
				'group'       => 'social',
				'variables'   => true,
				'preview'     => static fn( string $type, $object ): string => self::description( self::object_context( $type, $object ) ),
			)
		);
		Simple_SEO_Fields::register(
			'og_image',
			array(
				'label'       => __( 'Image for social networks', 'simple-seo' ),
				'description' => __( 'Best 1200 × 630 pixels. Without it: the featured image, then the default image from the settings.', 'simple-seo' ),
				'type'        => 'image',
				'order'       => 50,
				'group'       => 'social',
			)
		);
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'social', __( 'Social networks', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 10, 'markup' );
	}

	/** Whether a page gets social tags: everything Simple SEO knows except search results and 404. */
	public static function applies( Simple_SEO_Context $context ): bool {
		return '' !== Simple_SEO_Titles::slot_for( $context )
			&& ! in_array( $context->kind, array( Simple_SEO_Context::SEARCH, Simple_SEO_Context::NOT_FOUND ), true );
	}

	public static function title( Simple_SEO_Context $context ): string {
		$own = $context->field( 'og_title' );

		return '' !== $own
			? Simple_SEO_Variables::replace( $own, $context, Simple_SEO_Titles::separator() )
			: Simple_SEO_Titles::title( $context );
	}

	public static function description( Simple_SEO_Context $context ): string {
		$own = $context->field( 'og_description' );

		return '' !== $own
			? Simple_SEO_Variables::replace( $own, $context, Simple_SEO_Titles::separator() )
			: Simple_SEO_Titles::description( $context );
	}

	/**
	 * The attachment ID of the page's image, 0 for none: its own, the featured image, the image of
	 * the home page or of password-protected content from Special pages, the default image.
	 */
	public static function image_id( Simple_SEO_Context $context ): int {
		$id = (int) $context->field( 'og_image' );

		if ( ! $id && $context->object instanceof WP_Post && ! $context->password_required ) {
			$id = (int) get_post_thumbnail_id( $context->object );
		}
		if ( ! $id && Simple_SEO_Context::POST_TYPE_ARCHIVE === $context->kind && $context->object_id ) {
			// The featured image of the page of the archive (the shop page).
			$id = (int) get_post_thumbnail_id( $context->object_id );
		}
		if ( ! $id ) {
			$id = Simple_SEO_Special::image_id( Simple_SEO_Titles::template_slot( $context ) );
		}
		if ( ! $id ) {
			$id = (int) Simple_SEO_Settings::value( self::OPTION, 'default_image' );
		}

		/**
		 * The image a page is shared with.
		 *
		 * @param int                $id      Attachment ID, 0 for none.
		 * @param Simple_SEO_Context $context What the page shows.
		 */
		$id = (int) apply_filters( 'simple_seo_social_image', $id, $context );

		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** Open Graph wants language_TERRITORY: en_US, uk_UA. */
	public static function og_locale( string $locale ): string {
		$parts = explode( '_', $locale );
		$lang  = strtolower( $parts[0] );
		if ( ! preg_match( '/^[a-z]{2,3}$/', $lang ) ) {
			return 'en_US';
		}
		if ( isset( $parts[1] ) && preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ) {
			return $lang . '_' . strtoupper( $parts[1] );
		}

		return $lang . '_' . ( self::LOCALE_TERRITORIES[ $lang ] ?? strtoupper( $lang ) );
	}

	/** @return array<string, array{tag: string, attributes: array<string, string>}> */
	public static function head_tags( Simple_SEO_Context $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}

		$article = Simple_SEO_Context::SINGULAR === $context->kind && ! $context->is_front_page;
		$url     = Simple_SEO_Indexing::canonical( $context );
		if ( '' === $url ) {
			// Hidden pages have no canonical, but a shared link still needs its address.
			$url = Simple_SEO_Indexing::own_address( $context );
		}

		$tags = array(
			'og:locale'      => self::og_locale( get_locale() ),
			'og:type'        => $article ? 'article' : 'website',
			'og:title'       => self::title( $context ),
			'og:description' => self::description( $context ),
			'og:url'         => $url,
			'og:site_name'   => Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) ),
		);

		if ( $article && $context->object instanceof WP_Post ) {
			$tags['article:published_time'] = (string) get_post_time( 'c', true, $context->object );
			$tags['article:modified_time']  = (string) get_post_modified_time( 'c', true, $context->object );
		}

		$image = self::image_id( $context );
		if ( $image ) {
			$source = wp_get_attachment_image_src( $image, 'full' );
			if ( is_array( $source ) && ! empty( $source[0] ) ) {
				$tags['og:image'] = (string) $source[0];
				if ( ! empty( $source[1] ) && ! empty( $source[2] ) ) {
					$tags['og:image:width']  = (string) $source[1];
					$tags['og:image:height'] = (string) $source[2];
				}
				$tags['og:image:type'] = (string) get_post_mime_type( $image );
				$tags['og:image:alt']  = Simple_SEO_Variables::plain( (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) );
			}
		}

		$result = array();
		foreach ( $tags as $property => $content ) {
			$result[ $property ] = array(
				'tag'        => 'meta',
				'attributes' => array(
					'property' => $property,
					'content'  => $content,
				),
			);
		}

		$result['twitter:card'] = array(
			'tag'        => 'meta',
			'attributes' => array(
				'name'    => 'twitter:card',
				'content' => isset( $tags['og:image'] ) ? 'summary_large_image' : 'summary',
			),
		);
		$account = (string) Simple_SEO_Settings::value( self::OPTION, 'x_account' );
		if ( '' !== $account ) {
			$result['twitter:site'] = array(
				'tag'        => 'meta',
				'attributes' => array(
					'name'    => 'twitter:site',
					'content' => $account,
				),
			);
		}

		return $result;
	}

	/**
	 * An X account as @name: from "@name", "name" or an x.com / twitter.com address.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_account( $value ): string {
		$value = trim( (string) $value );
		if ( preg_match( '#^(?:https?://)?(?:www\.)?(?:x|twitter)\.com/@?([A-Za-z0-9_]{1,15})/?(?:[?\#].*)?$#i', $value, $match ) ) {
			return '@' . $match[1];
		}
		if ( preg_match( '/^@?([A-Za-z0-9_]{1,15})$/', $value, $match ) ) {
			return '@' . $match[1];
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		return array(
			'default_image' => Simple_SEO_Fields::image_attachment( $input['default_image'] ?? 0 ),
			'x_account'     => self::sanitize_account( $input['x_account'] ?? '' ),
		);
	}

	public static function render_tab(): void {
		$social = Simple_SEO_Settings::get( self::OPTION );

		require SIMPLE_SEO_DIR . 'includes/views/tab-social.php';
	}

	/** @param WP_Post|WP_Term $object */
	private static function object_context( string $type, $object ): Simple_SEO_Context {
		return 'term' === $type && $object instanceof WP_Term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );
	}
}
