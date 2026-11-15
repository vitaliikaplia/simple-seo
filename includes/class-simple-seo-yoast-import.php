<?php
/**
 * What the import takes from Yoast SEO: its settings, the SEO fields of posts (post meta
 * _yoast_wpseo_*) and of terms (the option wpseo_taxonomy_meta), in Simple SEO's form.
 *
 * The import only fills what Simple SEO does not have. A setting counts as Simple SEO's own once
 * its tab was saved (Simple SEO's rows start empty, so a stored key is a saved choice); a template
 * or a field, once it holds a value. Running the import again is therefore safe. Yoast's data is
 * read straight from the database, past the meta aliases and wp-loc's language filters, and is
 * never changed. Yoast's %%variables%% become Simple SEO's {variables}; one Simple SEO has no
 * counterpart for is left out of the text and named in the report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The import shares the meta aliases' reading of Yoast's values; it runs next to Yoast too, without them.
require_once SIMPLE_SEO_DIR . 'includes/compat/class-simple-seo-yoast-meta.php';

final class Simple_SEO_Yoast_Import {
	/** Yoast's separators by name => Simple SEO's. */
	private const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/** Yoast's crawl settings => Simple SEO's clean-up switches. Only what Yoast removed carries over. */
	private const CLEANUP = array(
		'remove_generator'              => 'generator',
		'remove_shortlinks'             => 'shortlink',
		'remove_rsd_wlw_links'          => 'rsd',
		'remove_rest_api_links'         => 'rest',
		'remove_oembed_links'           => 'oembed',
		'remove_emoji_scripts'          => 'emoji',
		'remove_feed_global'            => 'feed_posts',
		'remove_feed_global_comments'   => 'feed_comments',
		'remove_feed_post_comments'     => 'feed_post_comments',
		'remove_feed_categories'        => 'feed_categories',
		'remove_feed_tags'              => 'feed_tags',
		'remove_feed_custom_taxonomies' => 'feed_taxonomies',
		'remove_feed_authors'           => 'feed_authors',
		'remove_feed_search'            => 'feed_search',
		'remove_feed_post_types'        => 'feed_post_types',
	);

	/** Yoast's keys of a term's SEO => the same thing under the name of Yoast's post meta key. */
	private const TERM_KEYS = array(
		'wpseo_title'                 => 'title',
		'wpseo_desc'                  => 'metadesc',
		'wpseo_opengraph-title'       => 'opengraph-title',
		'wpseo_opengraph-description' => 'opengraph-description',
		'wpseo_opengraph-image-id'    => 'opengraph-image-id',
		'wpseo_opengraph-image'       => 'opengraph-image',
		'wpseo_noindex'               => 'meta-robots-noindex',
		'wpseo_canonical'             => 'canonical',
	);

	/** Texts that may hold variables, under the names of Yoast's post meta keys. */
	private const TEXTS = array( 'title', 'metadesc', 'opengraph-title', 'opengraph-description' );

	/** Yoast's post meta Simple SEO has no field for, by what the report calls it. */
	private const LEFT = array(
		'focuskw'    => array( '_yoast_wpseo_focuskw' ),
		'bctitle'    => array( '_yoast_wpseo_bctitle' ),
		'twitter'    => array( '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', '_yoast_wpseo_twitter-image', '_yoast_wpseo_twitter-image-id' ),
		'robots_adv' => array( '_yoast_wpseo_meta-robots-adv' ),
	);

	/** Whether the site has data of Yoast SEO: its settings, or SEO fields of posts or terms. */
	public static function found(): bool {
		global $wpdb;

		return is_array( self::option( 'wpseo_titles' ) )
			|| (bool) self::term_list()
			|| (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%' LIMIT 1" );
	}

	/**
	 * What there is to import: Yoast's settings, how many posts and terms have SEO fields.
	 *
	 * @return array{settings: bool, posts: int, terms: int}
	 */
	public static function summary(): array {
		global $wpdb;

		return array(
			'settings' => is_array( self::option( 'wpseo_titles' ) ),
			'posts'    => (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE " . self::keys_sql() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
			'terms'    => count( self::term_list() ),
		);
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * Take Yoast's settings: separator, templates, indexing, social networks, the home and 404
	 * pages, breadcrumbs, sitemaps and clean-up; with wp-loc, the home and 404 pages and the
	 * breadcrumb labels of the other languages too.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function settings( array &$report ): void {
		$titles = self::option( 'wpseo_titles' );
		$social = self::option( 'wpseo_social' );
		$main   = self::option( 'wpseo' );

		if ( is_array( $titles ) ) {
			self::titles( $titles, $report );
			self::indexing( $titles, $report );
			self::breadcrumbs( $titles, $report );
			self::special( $titles, is_array( $social ) ? $social : array(), '', $report );
			self::labels( $titles, '', $report );
			self::left_settings( $titles, $report );
		}
		if ( is_array( $social ) ) {
			self::social( $social, $report );
		}
		if ( is_array( $main ) ) {
			self::sitemaps( $main, $report );
			self::cleanup( $main, $report );
		}

		// wp-loc keeps Yoast's settings of another language in a copy of the row named after it.
		foreach ( array_slice( Simple_SEO_WP_Loc::codes(), 1 ) as $code ) {
			$row = self::language_option( 'wpseo_titles', $code );
			if ( null !== $row ) {
				self::special( $row, array(), $code, $report );
				self::labels( $row, $code, $report );
				$report['languages'][] = $code;
			}
		}
	}

	/** @param array<string, mixed> $titles */
	private static function titles( array $titles, array &$report ): void {
		$row    = self::row( Simple_SEO_Titles::OPTION );
		$before = Simple_SEO_Settings::sanitize( Simple_SEO_Titles::OPTION, $row );
		$clean  = $before;

		$separator = self::SEPARATORS[ self::scalar( $titles['separator'] ?? '' ) ] ?? '';
		$taken     = '' !== $separator && ! array_key_exists( 'separator', $row );
		if ( $taken ) {
			$clean['separator'] = $separator;
			self::add( $report, 'settings', 'separator' );
		}

		foreach ( self::slots() as $slot => $keys ) {
			foreach ( $keys as $which => $key ) {
				// A template Simple SEO has stays; an empty one of Yoast's leaves Simple SEO's default.
				$value = isset( $before['templates'][ $slot ][ $which ] ) ? '' : self::text( self::scalar( $titles[ $key ] ?? '' ), $slot, $report );
				if ( '' !== $value ) {
					$clean['templates'][ $slot ][ $which ] = $value;
				}
			}
		}

		// What equals Simple SEO's default is not kept as a template of its own, nor counted.
		$clean = Simple_SEO_Settings::sanitize( Simple_SEO_Titles::OPTION, $clean );
		$count = 0;
		foreach ( $clean['templates'] as $slot => $values ) {
			$count += count( array_diff_key( $values, (array) ( $before['templates'][ $slot ] ?? array() ) ) );
		}
		if ( $count ) {
			self::add( $report, 'settings', 'templates', $count );
		}
		if ( $count || $taken ) {
			self::save( Simple_SEO_Titles::OPTION, $clean );
		}
	}

	/**
	 * Simple SEO's slots with Yoast's keys of their title and description templates. The home and
	 * 404 pages are Simple SEO's special pages; attachments have no SEO fields here.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function slots(): array {
		$slots = array(
			'author' => array(
				'title'       => 'title-author-wpseo',
				'description' => 'metadesc-author-wpseo',
			),
			'date'   => array(
				'title'       => 'title-archive-wpseo',
				'description' => 'metadesc-archive-wpseo',
			),
			'search' => array( 'title' => 'title-search-wpseo' ),
		);
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$slots[ 'post:' . $type ] = array(
				'title'       => 'title-' . $type,
				'description' => 'metadesc-' . $type,
			);
			if ( ! empty( get_post_type_object( $type )->has_archive ) ) {
				$slots[ 'archive:' . $type ] = array(
					'title'       => 'title-ptarchive-' . $type,
					'description' => 'metadesc-ptarchive-' . $type,
				);
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$slots[ 'term:' . $taxonomy ] = array(
				'title'       => 'title-tax-' . $taxonomy,
				'description' => 'metadesc-tax-' . $taxonomy,
			);
		}

		return $slots;
	}

	/**
	 * Which kinds of pages search engines should leave out, and where attachment pages go.
	 *
	 * @param array<string, mixed> $titles
	 */
	private static function indexing( array $titles, array &$report ): void {
		$row   = self::row( Simple_SEO_Indexing::OPTION );
		$clean = Simple_SEO_Indexing::sanitize( $row + Simple_SEO_Indexing::defaults() );
		$on    = static fn( string $key ): bool => ! empty( $titles[ $key ] );
		$count = 0;

		if ( ! array_key_exists( 'noindex', $row ) ) {
			$noindex = array();
			foreach ( array_merge( Simple_SEO_Fields::post_types(), array( 'attachment' ) ) as $type ) {
				if ( $on( 'noindex-' . $type ) || ( 'attachment' === $type && $on( 'disable-attachment' ) ) ) {
					$noindex[] = 'post:' . $type;
				}
				if ( $on( 'noindex-ptarchive-' . $type ) ) {
					$noindex[] = 'archive:' . $type;
				}
			}
			foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
				if ( $on( 'noindex-tax-' . $taxonomy ) ) {
					$noindex[] = 'term:' . $taxonomy;
				}
			}
			// Archives Yoast switched off entirely Simple SEO hides from search engines.
			if ( $on( 'noindex-author-wpseo' ) || $on( 'disable-author' ) ) {
				$noindex[] = 'author';
			}
			if ( $on( 'noindex-archive-wpseo' ) || $on( 'disable-date' ) ) {
				$noindex[] = 'date';
			}
			$clean['noindex'] = $noindex;
			self::add( $report, 'settings', 'indexing' );
			++$count;
		}

		// Yoast sent attachment pages to the file itself, or kept them.
		if ( ! array_key_exists( 'attachments', $row ) && array_key_exists( 'disable-attachment', $titles ) ) {
			$clean['attachments'] = $on( 'disable-attachment' ) ? Simple_SEO_Indexing::ATTACHMENTS_FILE : Simple_SEO_Indexing::ATTACHMENTS_KEEP;
			self::add( $report, 'settings', 'attachments' );
			++$count;
		}

		if ( $count ) {
			self::save( Simple_SEO_Indexing::OPTION, $clean );
		}
	}

	/** @param array<string, mixed> $social */
	private static function social( array $social, array &$report ): void {
		$row   = self::row( Simple_SEO_Social::OPTION );
		$clean = Simple_SEO_Social::sanitize( $row );
		$count = 0;

		$image = Simple_SEO_Fields::image_attachment( $social['og_default_image_id'] ?? 0 );
		if ( $image && ! array_key_exists( 'default_image', $row ) ) {
			$clean['default_image'] = $image;
			self::add( $report, 'settings', 'default_image' );
			++$count;
		}
		$account = Simple_SEO_Social::sanitize_account( self::scalar( $social['twitter_site'] ?? '' ) );
		if ( '' !== $account && ! array_key_exists( 'x_account', $row ) ) {
			$clean['x_account'] = $account;
			self::add( $report, 'settings', 'x_account' );
			++$count;
		}

		if ( $count ) {
			self::save( Simple_SEO_Social::OPTION, $clean );
		}
	}

	/**
	 * The home and 404 pages of one language ('' — the default one): their templates and the home
	 * page's image for social networks.
	 *
	 * @param array<string, mixed> $titles
	 * @param array<string, mixed> $social
	 */
	private static function special( array $titles, array $social, string $code, array &$report ): void {
		$name   = self::language_row_name( Simple_SEO_Special::OPTION, $code );
		$before = Simple_SEO_Special::sanitize( self::row( $name ) );
		$clean  = $before;

		foreach ( array(
			'home' => array(
				'title'       => 'title-home-wpseo',
				'description' => 'metadesc-home-wpseo',
			),
			'404'  => array( 'title' => 'title-404-wpseo' ),
		) as $slot => $keys ) {
			// PHP turns the key '404' into a number.
			$slot = (string) $slot;
			foreach ( $keys as $which => $key ) {
				$value = isset( $before[ $slot ][ $which ] ) ? '' : self::text( self::scalar( $titles[ $key ] ?? '' ), $slot, $report );
				if ( '' !== $value ) {
					$clean[ $slot ][ $which ] = $value;
				}
			}
		}

		// Newer Yoast keeps the front page's image with the titles, older with the social settings.
		$image = Simple_SEO_Fields::image_attachment( $titles['open_graph_frontpage_image_id'] ?? ( $social['og_frontpage_image_id'] ?? 0 ) );
		if ( $image && empty( $before['home']['image'] ) ) {
			$clean['home']['image'] = $image;
		}

		// What equals Simple SEO's default is not kept, nor counted.
		$clean = Simple_SEO_Special::sanitize( $clean );
		$count = 0;
		foreach ( $clean as $slot => $values ) {
			$count += count( array_diff_key( $values, (array) ( $before[ $slot ] ?? array() ) ) );
		}
		if ( $count ) {
			self::add( $report, 'settings', 'special', $count );
			self::save( $name, $clean );
		}
	}

	/** @param array<string, mixed> $titles */
	private static function breadcrumbs( array $titles, array &$report ): void {
		$row    = self::row( Simple_SEO_Breadcrumbs::OPTION );
		$before = Simple_SEO_Breadcrumbs::sanitize( $row );
		$input  = array(
			'separator'    => $before['separator'],
			'show_current' => $before['show_current'] ? '1' : '0',
			'show_blog'    => $before['show_blog'] ? '1' : '0',
			'taxonomies'   => $before['taxonomies'],
		);

		$separator = self::scalar( html_entity_decode( self::scalar( $titles['breadcrumbs-sep'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' !== $separator && ! array_key_exists( 'separator', $row ) ) {
			$input['separator'] = $separator;
		}
		if ( array_key_exists( 'breadcrumbs-display-blog-page', $titles ) && ! array_key_exists( 'show_blog', $row ) ) {
			$input['show_blog'] = empty( $titles['breadcrumbs-display-blog-page'] ) ? '0' : '1';
		}
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$taxonomy = self::scalar( $titles[ 'post_types-' . $type . '-maintax' ] ?? '' );
			if ( '' !== $taxonomy && ! isset( $before['taxonomies'][ $type ] ) ) {
				$input['taxonomies'][ $type ] = $taxonomy;
			}
		}

		// Yoast's 0 (none chosen), a taxonomy the type does not have and Simple SEO's default one are
		// not kept, nor counted.
		$clean = Simple_SEO_Breadcrumbs::sanitize( $input );
		$count = (int) ( $clean['separator'] !== $before['separator'] ) + (int) ( $clean['show_blog'] !== $before['show_blog'] )
			+ count( array_diff_assoc( $clean['taxonomies'], $before['taxonomies'] ) );
		if ( $count ) {
			self::add( $report, 'settings', 'breadcrumbs', $count );
			self::save( Simple_SEO_Breadcrumbs::OPTION, $clean );
		}
	}

	/**
	 * The breadcrumb labels of one language as Yoast had them: themes find and replace Yoast's
	 * texts ('Home' becomes an icon), so they are not translated to Simple SEO's.
	 *
	 * @param array<string, mixed> $titles
	 */
	private static function labels( array $titles, string $code, array &$report ): void {
		$name  = self::language_row_name( Simple_SEO_Breadcrumbs::LABELS, $code );
		$clean = Simple_SEO_Breadcrumbs::sanitize_labels( self::row( $name ) );
		$count = 0;

		foreach ( array(
			'home'   => 'breadcrumbs-home',
			'prefix' => 'breadcrumbs-prefix',
		) as $label => $key ) {
			$value = self::scalar( $titles[ $key ] ?? '' );
			if ( '' !== $value && '' === $clean[ $label ] ) {
				$clean[ $label ] = $value;
				++$count;
			}
		}

		if ( $count ) {
			self::add( $report, 'settings', 'labels', $count );
			self::save( $name, Simple_SEO_Breadcrumbs::sanitize_labels( $clean ) );
		}
	}

	/** @param array<string, mixed> $main */
	private static function sitemaps( array $main, array &$report ): void {
		if ( array_key_exists( 'enable_xml_sitemap', $main ) && empty( $main['enable_xml_sitemap'] ) && ! array_key_exists( 'enabled', self::row( Simple_SEO_Sitemaps::OPTION ) ) ) {
			self::save( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => false ) );
			self::add( $report, 'settings', 'sitemaps' );
		}
	}

	/** @param array<string, mixed> $main */
	private static function cleanup( array $main, array &$report ): void {
		$row   = self::row( Simple_SEO_Cleanup::OPTION );
		$clean = array_replace( Simple_SEO_Cleanup::defaults(), $row );
		$count = 0;

		foreach ( self::CLEANUP as $yoast => $key ) {
			if ( ! empty( $main[ $yoast ] ) && ! array_key_exists( $key, $row ) && ! $clean[ $key ] ) {
				$clean[ $key ] = true;
				++$count;
			}
		}

		if ( $count ) {
			self::add( $report, 'settings', 'cleanup', $count );
			self::save( Simple_SEO_Cleanup::OPTION, array_map( static fn( $on ): string => $on ? '1' : '0', $clean ) );
		}
	}

	/**
	 * Yoast's settings Simple SEO has nothing for: templates for social networks, the texts
	 * Yoast's breadcrumbs put before archives and search results and on 404.
	 *
	 * @param array<string, mixed> $titles
	 */
	private static function left_settings( array $titles, array &$report ): void {
		foreach ( $titles as $key => $value ) {
			$key = (string) $key;
			if ( '' === self::scalar( $value ) ) {
				continue;
			}
			if ( preg_match( '/^(?:social-(?:title|description)-|open_graph_frontpage_(?:title|desc)$)/', $key ) ) {
				$report['left']['social_templates'] = 1;
			} elseif ( in_array( $key, array( 'breadcrumbs-archiveprefix', 'breadcrumbs-searchprefix', 'breadcrumbs-404crumb' ), true ) ) {
				$report['left']['breadcrumb_texts'] = 1;
			}
		}
	}

	// ----------------------------------------------------------------------------------------
	// Posts and terms.

	/**
	 * Take the SEO fields of the next posts after a post ID.
	 *
	 * @param array<string, mixed> $report
	 * @return int The last post ID taken; 0 when no post is left.
	 */
	public static function posts( int $after, int $limit, array &$report ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the key condition is built from constants.
		$ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id > %d AND ", $after ) . self::keys_sql() . $wpdb->prepare( ' ORDER BY post_id LIMIT %d', $limit ) )
		);
		if ( ! $ids ) {
			return 0;
		}
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND ' . self::keys_sql() . ' ORDER BY meta_id', ARRAY_A );
		// phpcs:enable

		$values = array();
		foreach ( $rows as $row ) {
			// The first row of a key, as get_post_meta( …, true ) reads it.
			$values[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] ??= (string) $row['meta_value'];
		}

		$types = Simple_SEO_Fields::post_types();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $types, true ) ) {
				continue;
			}

			$fields = array();
			foreach ( self::post_keys( array_keys( $values[ $id ] ?? array() ) ) as $meta_key ) {
				$key = Simple_SEO_Yoast_Meta::field( $meta_key, $id );
				if ( '' !== $key ) {
					$fields[ $key ][] = array( substr( $meta_key, 13 ), $values[ $id ][ $meta_key ] );
				}
			}
			self::take( 'post', $id, $fields, $report );
		}

		return (int) end( $ids );
	}

	/**
	 * Take the SEO fields of the next terms of Yoast's list.
	 *
	 * @param array<string, mixed> $report
	 * @return int Where the next batch starts in the list; 0 when no term is left.
	 */
	public static function terms( int $offset, int $limit, array &$report ): int {
		$batch = array_slice( self::term_list(), $offset, $limit );
		if ( ! $batch ) {
			return 0;
		}

		$taxonomies = Simple_SEO_Fields::taxonomies();
		foreach ( $batch as [ $taxonomy, $id, $meta ] ) {
			$term = in_array( $taxonomy, $taxonomies, true ) ? get_term( $id, $taxonomy ) : null;
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$fields = array();
			foreach ( self::TERM_KEYS as $yoast => $name ) {
				$value = self::scalar( $meta[ $yoast ] ?? '' );
				if ( 'meta-robots-noindex' === $name ) {
					// Yoast says noindex, index or default for a term.
					$value = array_search( $value, array( '1' => 'noindex', '2' => 'index' ), true );
				}
				// Every one of these fields is a term's too.
				if ( '' !== (string) $value ) {
					$fields[ Simple_SEO_Yoast_Meta::field_of( $name ) ][] = array( $name, (string) $value );
				}
			}
			self::take( 'term', $id, $fields, $report );
		}

		return $offset + count( $batch );
	}

	/**
	 * Fill the empty fields of a post or term from Yoast's values; a field Simple SEO has stays.
	 * A field Yoast keeps in two forms (the image as ID and as address) takes the first that fits.
	 *
	 * @param string                                                 $type   post or term.
	 * @param array<string, array<int, array{0: string, 1: string}>> $fields Field => Yoast's values (name of its key, value).
	 * @param array<string, mixed>                                   $report
	 */
	private static function take( string $type, int $id, array $fields, array &$report ): void {
		self::add( $report, $type, 'seen' );
		$changed = false;

		foreach ( $fields as $key => $values ) {
			$meta_key = Simple_SEO_Fields::meta_key( $key );
			if ( '' !== (string) get_metadata( $type, $id, $meta_key, true ) ) {
				self::add( $report, $type, 'kept' );
				continue;
			}

			$clean = '';
			foreach ( $values as [ $name, $value ] ) {
				$clean = Simple_SEO_Yoast_Meta::clean( $name, $key, in_array( $name, self::TEXTS, true ) ? self::text( $value, '', $report ) : $value );
				if ( '' !== $clean && null !== $clean ) {
					break;
				}
			}

			if ( null === $clean ) {
				self::add( $report, $type, 'refused' );
			} elseif ( '' !== $clean ) {
				// update_metadata() expects slashed data and unslashes it itself.
				update_metadata( $type, $id, $meta_key, wp_slash( $clean ) );
				self::add( $report, $type, 'fields' );
				$changed = true;
			}
		}

		if ( $changed ) {
			self::add( $report, $type, 'changed' );
		}
	}

	/**
	 * How many posts have Yoast's data Simple SEO has no field for, by what the report calls it.
	 *
	 * @return array<string, int>
	 */
	public static function left(): array {
		global $wpdb;

		$left = array();
		foreach ( self::LEFT as $what => $keys ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key IN (" . self::in( $keys ) . ") AND meta_value NOT IN ( '', '0' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- keys from a constant.
			if ( $count ) {
				$left[ $what ] = $count;
			}
		}

		return $left;
	}

	// ----------------------------------------------------------------------------------------
	// Helpers.

	/**
	 * A text of Yoast's in Simple SEO's syntax. A variable Simple SEO has no counterpart for goes
	 * away and is counted; on a post type archive %%pt_plural%% is the archive's {title}.
	 *
	 * @param array<string, mixed> $report
	 */
	private static function text( string $value, string $slot, array &$report ): string {
		$value = (string) preg_replace_callback(
			'/%%([A-Za-z0-9_-]+)%%/',
			static function ( array $match ) use ( $slot, &$report ): string {
				$name = strtolower( $match[1] );
				if ( 'pt_plural' === $name && str_starts_with( $slot, 'archive:' ) ) {
					return '{title}';
				}
				self::add( $report, 'dropped', $name );

				return '';
			},
			Simple_SEO_Yoast_Compat::to_simple( $value )
		);

		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $value ) );
	}

	/**
	 * The keys of a post in the order they are taken: the image by its ID before its address,
	 * primary terms last.
	 *
	 * @param string[] $present
	 * @return string[]
	 */
	private static function post_keys( array $present ): array {
		$keys = array_values( array_intersect( Simple_SEO_Yoast_Meta::keys(), $present ) );
		foreach ( $present as $key ) {
			if ( str_starts_with( $key, '_yoast_wpseo_primary_' ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/** SQL condition on postmeta: the keys the import takes, with a value other than Yoast's default. */
	private static function keys_sql(): string {
		return '( meta_key IN (' . self::in( Simple_SEO_Yoast_Meta::keys() ) . ") OR meta_key LIKE '\\_yoast\\_wpseo\\_primary\\_%' ) AND meta_value NOT IN ( '', '0' )";
	}

	/** @param string[] $values */
	private static function in( array $values ): string {
		return implode( ',', array_map( static fn( string $value ): string => "'" . esc_sql( $value ) . "'", $values ) );
	}

	/**
	 * Yoast's terms with SEO data, in a fixed order: taxonomy, term ID, Yoast's values.
	 *
	 * @return array<int, array{0: string, 1: int, 2: array<string, mixed>}>
	 */
	private static function term_list(): array {
		$option = self::option( 'wpseo_taxonomy_meta' );
		$list   = array();
		foreach ( is_array( $option ) ? $option : array() as $taxonomy => $terms ) {
			foreach ( is_array( $terms ) ? $terms : array() as $id => $meta ) {
				if ( is_array( $meta ) && (int) $id > 0 ) {
					$list[] = array( (string) $taxonomy, (int) $id, $meta );
				}
			}
		}
		usort( $list, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );

		return $list;
	}

	/**
	 * A row of options as stored, read past every filter (wp-loc's language copies included);
	 * null when there is none.
	 *
	 * @return mixed
	 */
	private static function option( string $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}

	/**
	 * Yoast's copy of a row for a language (wp-loc names it by the language's code or slug).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function language_option( string $name, string $code ): ?array {
		$suffixes = array( $code );
		if ( method_exists( 'WP_LOC_DB', 'from_db_language_code' ) ) {
			$suffixes[] = (string) WP_LOC_DB::from_db_language_code( $code );
		}
		foreach ( array_unique( array_filter( $suffixes ) ) as $suffix ) {
			$row = self::option( $name . '_' . $suffix );
			if ( is_array( $row ) ) {
				return $row;
			}
		}

		return null;
	}

	/** The name of Simple SEO's row of a language ('' — the default one: the row itself). */
	private static function language_row_name( string $name, string $code ): string {
		return '' !== $code ? $name . '_' . $code : $name;
	}

	/**
	 * Simple SEO's row as stored: without defaults, so a key in it is a saved choice.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( string $name ): array {
		$row = self::option( $name );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Save a row of Simple SEO's under its exact name. wp-loc sends what an admin saves in another
	 * language to that language's copy; the row of the default language is written as itself.
	 *
	 * @param array<string, mixed> $value
	 */
	private static function save( string $name, array $value ): void {
		$cookie = $_COOKIE['admin_lang'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- set aside and put back unchanged.
		unset( $_COOKIE['admin_lang'] );
		try {
			update_option( $name, $value );
		} finally {
			if ( null !== $cookie ) {
				$_COOKIE['admin_lang'] = $cookie;
			}
		}
		Simple_SEO_Settings::flush( $name );
	}

	/** @param array<string, mixed> $report */
	private static function add( array &$report, string $group, string $what, int $count = 1 ): void {
		$report[ $group ][ $what ] = (int) ( $report[ $group ][ $what ] ?? 0 ) + $count;
	}

	/**
	 * A scalar as trimmed text; '' for anything else.
	 *
	 * @param mixed $value
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
