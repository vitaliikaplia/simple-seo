<?php
/**
 * Clean-up of the code of pages: what WordPress adds to every <head> (and to some HTTP headers)
 * that a site may not want — the WordPress version, the shortlink, discovery links of XML-RPC,
 * the REST API and oEmbed, the emoji script and the links to feeds. Each item is a switch of its
 * own; only the links, tags and scripts go: XML-RPC, the REST API, oEmbed and the feeds keep
 * working at their addresses.
 *
 * Out of the box only what nobody uses is removed: the WordPress version, the shortlink and the
 * XML-RPC discovery. The rest stays until it is switched off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Cleanup {
	public const OPTION = 'simple_seo_cleanup';

	/** Switches of links to feeds => the core filter that decides whether the link is printed. */
	public const FEEDS = array(
		'feed_posts'         => 'feed_links_show_posts_feed',
		'feed_comments'      => 'feed_links_show_comments_feed',
		'feed_post_comments' => 'feed_links_extra_show_post_comments_feed',
		'feed_categories'    => 'feed_links_extra_show_category_feed',
		'feed_tags'          => 'feed_links_extra_show_tag_feed',
		'feed_taxonomies'    => 'feed_links_extra_show_tax_feed',
		'feed_authors'       => 'feed_links_extra_show_author_feed',
		'feed_search'        => 'feed_links_extra_show_search_feed',
		'feed_post_types'    => 'feed_links_extra_show_post_type_archive_feed',
	);

	/**
	 * Switches of the other items => the hooks of WordPress they unhook: [hook, callback, priority].
	 * X-Pingback (rsd) and the feed generator tags (generator) are filters, see init().
	 */
	private const ACTIONS = array(
		'generator' => array(
			array( 'wp_head', 'wp_generator', 10 ),
		),
		'shortlink' => array(
			array( 'wp_head', 'wp_shortlink_wp_head', 10 ),
			array( 'template_redirect', 'wp_shortlink_header', 11 ),
		),
		'rsd'       => array(
			array( 'wp_head', 'rsd_link', 10 ),
		),
		'rest'      => array(
			array( 'wp_head', 'rest_output_link_wp_head', 10 ),
			array( 'template_redirect', 'rest_output_link_header', 11 ),
		),
		'oembed'    => array(
			array( 'wp_head', 'wp_oembed_add_discovery_links', 4 ),
			array( 'wp_head', 'wp_oembed_add_discovery_links', 10 ),
		),
		'emoji'     => array(
			array( 'wp_head', 'print_emoji_detection_script', 7 ),
			array( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles', 10 ),
			array( 'wp_print_styles', 'print_emoji_styles', 10 ),
			array( 'embed_head', 'print_emoji_detection_script', 10 ),
			array( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles', 10 ),
		),
	);

	public static function init(): void {
		Simple_SEO_Settings::register( self::OPTION, self::defaults(), array( self::class, 'sanitize' ), true );

		// After the main query, before template_redirect (Link headers at 11) and wp_head.
		add_action( 'wp', array( self::class, 'apply' ), 1 );
		// Headers are sent before the wp action.
		add_filter( 'wp_headers', array( self::class, 'headers' ) );
		// The version in feeds (<generator>), which have no wp_head.
		add_filter( 'the_generator', array( self::class, 'generator' ) );
		foreach ( self::FEEDS as $filter ) {
			add_filter( $filter, array( self::class, 'feed_link' ), 20 );
		}

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	/**
	 * true: removed. The WordPress version, the shortlink and the XML-RPC discovery serve nobody,
	 * so they go out of the box; the rest is used by feed readers, other sites or apps.
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		$defaults = array(
			'generator' => true,
			'shortlink' => true,
			'rsd'       => true,
			'rest'      => false,
			'oembed'    => false,
			'emoji'     => false,
		);
		foreach ( array_keys( self::FEEDS ) as $key ) {
			$defaults[ $key ] = false;
		}

		return $defaults;
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab(
			'cleanup',
			__( 'Clean-up', 'simple-seo' ),
			array( self::OPTION ),
			array( self::class, 'render_tab' ),
			50,
			'crawl',
			static fn(): array => array(
				'links' => __( 'Links and tags', 'simple-seo' ),
				'feeds' => __( 'Links to feeds', 'simple-seo' ),
			)
		);
	}

	/** Whether an item is removed on this request: switched off, on the site, no other SEO plugin. */
	public static function removes( string $key ): bool {
		return ! is_admin()
			&& (bool) Simple_SEO_Settings::value( self::OPTION, $key )
			&& '' === Simple_SEO_Plugin::conflicting_plugin();
	}

	/** Unhook what is switched off. */
	public static function apply(): void {
		foreach ( self::ACTIONS as $key => $hooks ) {
			if ( ! self::removes( $key ) ) {
				continue;
			}
			foreach ( $hooks as $hook ) {
				remove_action( $hook[0], $hook[1], $hook[2] );
			}
		}
	}

	/**
	 * X-Pingback goes with the RSD link: both only point XML-RPC clients to xmlrpc.php.
	 *
	 * @param mixed $headers
	 * @return mixed
	 */
	public static function headers( $headers ) {
		if ( is_array( $headers ) && isset( $headers['X-Pingback'] ) && self::removes( 'rsd' ) ) {
			unset( $headers['X-Pingback'] );
		}

		return $headers;
	}

	/**
	 * @param mixed $generator
	 * @return mixed
	 */
	public static function generator( $generator ) {
		return self::removes( 'generator' ) ? '' : $generator;
	}

	/**
	 * A link to a feed stays unless its switch is off; another plugin's "no" stays a "no".
	 *
	 * @param mixed $show
	 * @return mixed
	 */
	public static function feed_link( $show ) {
		$key = array_search( current_filter(), self::FEEDS, true );

		return false !== $key && self::removes( (string) $key ) ? false : $show;
	}

	/**
	 * Every switch is sent (a hidden 0 before each checkbox), so an untouched one keeps its value
	 * and a key the form does not know is dropped.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, bool>
	 */
	public static function sanitize( array $input ): array {
		$clean = array();
		foreach ( self::defaults() as $key => $default ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? '1' === (string) $input[ $key ] : (bool) Simple_SEO_Settings::value( self::OPTION, $key );
		}

		return $clean;
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );

		require SIMPLE_SEO_DIR . 'includes/views/tab-cleanup.php';
	}
}
