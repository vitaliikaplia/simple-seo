<?php
/**
 * Indexing: robots (noindex, nofollow), the canonical address and attachment pages.
 *
 * Search engines are told through core's wp_robots filter (one <meta name="robots">) and a
 * canonical link printed by the head output instead of core's rel_canonical, for every kind of
 * page. Whole kinds of pages are hidden by slot (the same slots as the title templates:
 * post:{type}, term:{taxonomy}, archive:{type}, author, date); a post or a term overrides its
 * kind with its own fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Indexing {
	public const OPTION = 'simple_seo_indexing';

	/** Attachment pages redirect to the file (core's own redirect). */
	public const ATTACHMENTS_FILE = 'file';

	/** Attachment pages redirect to the post they are attached to, else to the file. */
	public const ATTACHMENTS_PARENT = 'parent';

	/** Attachment pages stay. */
	public const ATTACHMENTS_KEEP = 'keep';

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			self::defaults(),
			array( self::class, 'sanitize' ),
			true
		);

		add_action( 'init', array( self::class, 'register_fields' ), 5 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );
		add_action( 'template_redirect', array( self::class, 'take_over' ), 1 );
		add_filter( 'pre_option_wp_attachment_pages_enabled', array( self::class, 'attachment_pages_option' ) );
		add_filter( 'wp_loc_canonical_already_handled', array( self::class, 'canonical_handled' ) );
		Simple_SEO_Head::add_provider( 'canonical', array( self::class, 'head_tags' ), 20 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			// Slots hidden from search engines. Date and author archives repeat the posts.
			'noindex'     => array( 'post:attachment', 'date', 'author' ),
			'attachments' => self::ATTACHMENTS_FILE,
		);
	}

	public static function register_fields(): void {
		Simple_SEO_Fields::register_group( 'indexing', __( 'Indexing', 'simple-seo' ) );
		Simple_SEO_Fields::register(
			'index',
			array(
				'label'   => __( 'Search engines', 'simple-seo' ),
				'type'    => 'select',
				'level'   => Simple_SEO_Fields::LEVEL_ADVANCED,
				'options' => array(
					''        => __( 'As set for this type of page', 'simple-seo' ),
					'index'   => __( 'Show in search results', 'simple-seo' ),
					'noindex' => __( 'Hide from search results (noindex)', 'simple-seo' ),
				),
				'order'   => 60,
				'group'   => 'indexing',
				'preview' => static fn( string $type, $object ): string => self::is_noindex( self::object_context( $type, $object ) )
					? __( 'hidden from search results', 'simple-seo' )
					: __( 'shown in search results', 'simple-seo' ),
			)
		);
		Simple_SEO_Fields::register(
			'nofollow',
			array(
				'label' => __( 'Ask search engines not to follow the links on this page (nofollow)', 'simple-seo' ),
				'type'  => 'checkbox',
				'level' => Simple_SEO_Fields::LEVEL_ADVANCED,
				'order' => 70,
				'group' => 'indexing',
			)
		);
		Simple_SEO_Fields::register(
			'canonical',
			array(
				'label'       => __( 'Canonical address', 'simple-seo' ),
				'description' => __( 'Only when this page repeats another one: the address of the original. Leave empty for the page\'s own address.', 'simple-seo' ),
				'type'        => 'url',
				'level'       => Simple_SEO_Fields::LEVEL_ADVANCED,
				'sanitize'    => array( self::class, 'sanitize_url' ),
				'order'       => 80,
				'group'       => 'indexing',
				'preview'     => static fn( string $type, $object ): string => self::canonical( self::object_context( $type, $object ) ),
			)
		);
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'indexing', __( 'Indexing', 'simple-seo' ), array( self::OPTION, Simple_SEO_Sitemaps::OPTION ), array( self::class, 'render_tab' ), 20 );
	}

	/**
	 * Whether a page is hidden from search engines.
	 *
	 * Search results and 404 always are; a post's or term's own choice wins over its kind; a whole
	 * site closed in Settings → Reading counts as hidden too (core prints the robots tag for it).
	 */
	public static function is_noindex( Simple_SEO_Context $context ): bool {
		if ( ! get_option( 'blog_public' ) ) {
			return true;
		}
		if ( in_array( $context->kind, array( Simple_SEO_Context::SEARCH, Simple_SEO_Context::NOT_FOUND ), true ) ) {
			return true;
		}

		$own = $context->field( 'index' );
		if ( 'noindex' === $own || 'index' === $own ) {
			$noindex = 'noindex' === $own;
		} else {
			$noindex = self::slot_is_noindex( Simple_SEO_Titles::slot_for( $context ) );
		}

		/**
		 * Whether a page is hidden from search engines.
		 *
		 * @param bool               $noindex True to hide it.
		 * @param Simple_SEO_Context $context What the page shows.
		 */
		return (bool) apply_filters( 'simple_seo_noindex', $noindex, $context );
	}

	public static function is_nofollow( Simple_SEO_Context $context ): bool {
		return '' !== $context->field( 'nofollow' ) && '0' !== $context->field( 'nofollow' );
	}

	public static function slot_is_noindex( string $slot ): bool {
		$noindex = Simple_SEO_Settings::value( self::OPTION, 'noindex' );

		return '' !== $slot && is_array( $noindex ) && in_array( $slot, $noindex, true );
	}

	/**
	 * The canonical address of a page; '' for pages hidden from search engines (noindex and
	 * canonical together send search engines two opposite signals) and for search results and 404.
	 */
	public static function canonical( Simple_SEO_Context $context ): string {
		$url = '';
		if ( ! self::is_noindex( $context ) ) {
			$own = $context->field( 'canonical' );
			$url = '' !== $own ? $own : self::own_address( $context );
		}

		/**
		 * The canonical address of a page; '' prints none.
		 *
		 * @param string             $url     Absolute address.
		 * @param Simple_SEO_Context $context What the page shows.
		 */
		return (string) apply_filters( 'simple_seo_canonical', $url, $context );
	}

	/** The address of the page itself, with its page number, without any query arguments. */
	public static function own_address( Simple_SEO_Context $context ): string {
		$url = '';

		switch ( $context->kind ) {
			case Simple_SEO_Context::HOME:
				$url = home_url( '/' );
				break;
			case Simple_SEO_Context::SINGULAR:
			case Simple_SEO_Context::POSTS_PAGE:
				$url = $context->object instanceof WP_Post && 'publish' === get_post_status( $context->object ) ? (string) get_permalink( $context->object ) : '';
				break;
			case Simple_SEO_Context::TERM:
				$link = $context->object instanceof WP_Term ? get_term_link( $context->object ) : '';
				$url  = is_string( $link ) ? $link : '';
				break;
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				$url = (string) get_post_type_archive_link( $context->subtype );
				break;
			case Simple_SEO_Context::AUTHOR:
				$url = get_author_posts_url( $context->object_id );
				break;
			case Simple_SEO_Context::DATE:
				$url = self::date_archive_link( $context );
				break;
		}

		if ( '' === $url || $context->page < 2 ) {
			return $url;
		}

		return Simple_SEO_Context::SINGULAR === $context->kind
			? self::paged( $url, $context->page, 'page', 'single_paged' )
			: self::paged( $url, $context->page, 'paged', 'paged' );
	}

	/**
	 * core's wp_robots: noindex and nofollow where the page or its kind asks for it.
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( is_admin() || '' !== Simple_SEO_Plugin::conflicting_plugin() || ! get_option( 'blog_public' ) ) {
			return $robots;
		}

		$context  = Simple_SEO_Context::current();
		$nofollow = self::is_nofollow( $context );

		if ( self::is_noindex( $context ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			if ( ! $nofollow ) {
				// The page leaves search results, but the pages it links to are still found through it.
				$robots['follow'] = true;
			}
		}
		if ( $nofollow ) {
			unset( $robots['follow'] );
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/** Our canonical replaces core's, and attachment pages go where the settings say. */
	public static function take_over(): void {
		if ( '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return;
		}

		remove_action( 'wp_head', 'rel_canonical' );

		// The Markdown version of an attachment page is none (404), not the file.
		if ( is_attachment() && ! Simple_SEO_Markdown::requested() ) {
			$attachment = get_queried_object();
			$target     = $attachment instanceof WP_Post ? self::attachment_redirect( $attachment ) : '';
			if ( '' !== $target ) {
				// A file can live on another host (a CDN, Simple Storage): wp_redirect, not wp_safe_redirect.
				wp_redirect( $target, 301, 'Simple SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		}
	}

	/**
	 * Where an attachment page goes; '' when it stays (or core redirects it to the file itself).
	 */
	public static function attachment_redirect( WP_Post $attachment ): string {
		if ( self::ATTACHMENTS_PARENT !== self::attachments_mode() ) {
			return '';
		}

		$parent = $attachment->post_parent ? get_post( (int) $attachment->post_parent ) : null;
		if ( $parent instanceof WP_Post && 'publish' === get_post_status( $parent ) && is_post_type_viewable( $parent->post_type ) ) {
			return (string) get_permalink( $parent );
		}

		return (string) wp_get_attachment_url( (int) $attachment->ID );
	}

	public static function attachments_mode(): string {
		$mode = (string) Simple_SEO_Settings::value( self::OPTION, 'attachments' );

		return in_array( $mode, array( self::ATTACHMENTS_FILE, self::ATTACHMENTS_PARENT, self::ATTACHMENTS_KEEP ), true ) ? $mode : self::ATTACHMENTS_FILE;
	}

	/**
	 * core's wp_attachment_pages_enabled follows the setting: off redirects attachment pages to the
	 * file (core does it in redirect_canonical), on keeps them. "Parent" keeps them on for core and
	 * redirects them in take_over().
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function attachment_pages_option( $value ) {
		if ( '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return $value;
		}

		return self::ATTACHMENTS_FILE === self::attachments_mode() ? '0' : '1';
	}

	/** wp-loc prints its own canonical only when nobody else does. */
	public static function canonical_handled( $handled ) {
		return '' === Simple_SEO_Plugin::conflicting_plugin() ? true : $handled;
	}

	/** @return array<string, array{tag: string, attributes: array<string, string>}> */
	public static function head_tags( Simple_SEO_Context $context ): array {
		$url = self::canonical( $context );
		if ( '' === $url ) {
			return array();
		}

		return array(
			'canonical' => array(
				'tag'        => 'link',
				'attributes' => array(
					'rel'  => 'canonical',
					'href' => $url,
				),
			),
		);
	}

	/**
	 * An absolute http(s) address, or a path on this site; anything else is dropped.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_url( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			$value = home_url( $value );
		}
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}

		$url = esc_url_raw( $value, array( 'http', 'https' ) );

		return '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Keep the noindex list and the attachment mode valid. The form sends every slot it shows as
	 * 1 (shown) or 0 (hidden); slots it does not show (a post type gone for now) keep their state.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$current = Simple_SEO_Settings::get( self::OPTION );
		$noindex = is_array( $current['noindex'] ?? null ) ? $current['noindex'] : array();

		$shown = isset( $input['index'] ) && is_array( $input['index'] ) ? $input['index'] : array();
		foreach ( $shown as $slot => $value ) {
			$slot = (string) $slot;
			if ( ! preg_match( '/^(?:(?:post|term|archive):[a-z0-9_-]+|author|date)$/', $slot ) ) {
				continue;
			}
			$noindex = array_values( array_diff( $noindex, array( $slot ) ) );
			if ( '1' !== (string) $value ) {
				$noindex[] = $slot;
			}
		}

		if ( isset( $input['noindex'] ) && is_array( $input['noindex'] ) && ! isset( $input['index'] ) ) {
			// A whole list given directly (import, WP-CLI): taken as it is, well-formed slots only.
			$noindex = array_values(
				array_filter(
					array_map( 'strval', $input['noindex'] ),
					static fn( string $slot ): bool => (bool) preg_match( '/^(?:(?:post|term|archive):[a-z0-9_-]+|author|date)$/', $slot )
				)
			);
		}

		$mode = isset( $input['attachments'] ) ? (string) $input['attachments'] : self::ATTACHMENTS_FILE;

		return array(
			'noindex'     => array_values( array_unique( $noindex ) ),
			'attachments' => in_array( $mode, array( self::ATTACHMENTS_FILE, self::ATTACHMENTS_PARENT, self::ATTACHMENTS_KEEP ), true ) ? $mode : self::ATTACHMENTS_FILE,
		);
	}

	public static function render_tab(): void {
		$groups = Simple_SEO_Titles::slot_groups();
		// Search results never go to search engines: no switch for them.
		unset( $groups['archives']['slots']['search'] );
		$attachments = self::attachments_mode();

		require SIMPLE_SEO_DIR . 'includes/views/tab-indexing.php';
	}

	private static function date_archive_link( Simple_SEO_Context $context ): string {
		if ( ! $context->timestamp ) {
			return '';
		}

		$year  = (int) gmdate( 'Y', $context->timestamp );
		$month = (int) gmdate( 'n', $context->timestamp );
		$day   = (int) gmdate( 'j', $context->timestamp );

		switch ( $context->subtype ) {
			case 'day':
				return (string) get_day_link( $year, $month, $day );
			case 'month':
				return (string) get_month_link( $year, $month );
			default:
				return (string) get_year_link( $year );
		}
	}

	/**
	 * Add a page number the way the site's permalinks do.
	 *
	 * @param string $query_var     page (parts of a post) or paged (archives).
	 * @param string $slash_context single_paged or paged, for user_trailingslashit().
	 */
	private static function paged( string $url, int $page, string $query_var, string $slash_context ): string {
		global $wp_rewrite;

		if ( ! get_option( 'permalink_structure' ) || str_contains( $url, '?' ) ) {
			return add_query_arg( $query_var, $page, $url );
		}

		$part = 'paged' === $query_var ? $wp_rewrite->pagination_base . '/' . $page : (string) $page;

		return trailingslashit( $url ) . user_trailingslashit( $part, $slash_context );
	}

	/** @param WP_Post|WP_Term $object */
	private static function object_context( string $type, $object ): Simple_SEO_Context {
		return 'term' === $type && $object instanceof WP_Term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );
	}
}
