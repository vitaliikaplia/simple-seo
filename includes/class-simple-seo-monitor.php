<?php
/**
 * The monitor of 404s: every address of the site that answered "not found" is kept in a table of
 * its own — how many times it was asked for, the first and the last time, where the last visitor
 * came from; no IP addresses. A page under the SEO menu lists them, and on a site with the redirect
 * rules of the WP Base theme an address goes to a new rule there in one click: Simple SEO makes no
 * redirects itself.
 *
 * An address is counted after the redirects of the theme and of WordPress, which answer before it
 * and exit. One query per 404 writes it; a daily task forgets what was last asked for 90 days ago.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Monitor {
	public const OPTION = 'simple_seo_404_monitor';

	/** The table after the prefix of the site's tables. */
	public const TABLE = 'simple_seo_404';

	/** The version of the table made, in a row of its own. */
	public const DB_OPTION  = 'simple_seo_404_db';
	public const DB_VERSION = '1';

	public const PAGE = 'simple-seo-404';

	/** The daily task that forgets old addresses. */
	public const EVENT = 'simple_seo_404_cleanup';

	/** Days an address is kept after it was last asked for. */
	public const DAYS = 90;

	/** Addresses kept at most: a flood of addresses asked for once each does not fill the database. */
	public const MAX_ROWS = 10000;

	/** Characters of an address or a referrer kept. */
	public const MAX_LENGTH = 2048;

	public const PER_PAGE = 50;

	/** The post type of the redirect rules of WP Base. */
	public const RULES = 'redirect-rules';

	private const NONCE = 'simple_seo_404';

	/** Arguments of trackers: the same address with other ones of them is the same address. */
	private const TRACKERS = '/^(?:utm_[a-z0-9_]+|gclid|gbraid|wbraid|fbclid|msclkid|yclid|_ga|_gl|mc_cid|mc_eid)$/i';

	/** @var string The page's hook suffix. */
	private static string $hook = '';

	public static function init(): void {
		// Autoloaded: every 404 reads it.
		Simple_SEO_Settings::register( self::OPTION, array( 'enabled' => true ), array( self::class, 'sanitize' ), true );

		// After the redirects of the theme (10) and of WordPress (redirect_canonical, 10): they exit.
		add_action( 'template_redirect', array( self::class, 'record' ), PHP_INT_MAX );
		add_action( self::EVENT, array( self::class, 'cleanup' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
			// After Search Console (21).
			add_action( 'admin_menu', array( self::class, 'add_menu' ), 22 );
			add_action( 'admin_post_' . self::NONCE, array( self::class, 'handle' ) );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{enabled: bool}
	 */
	public static function sanitize( array $input ): array {
		return array( 'enabled' => ! empty( $input['enabled'] ) );
	}

	public static function table(): string {
		return $GLOBALS['wpdb']->prefix . self::TABLE;
	}

	/** Make the table, once for each version of it. */
	public static function install(): void {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(32) NOT NULL,
  url varchar(2048) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  hidden tinyint(1) NOT NULL DEFAULT 0,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  referrer varchar(2048) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY last_seen (last_seen),
  KEY hits (hits)
) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/*
	 * Counting.
	 */

	/** A page of the site that answers "not found" to a GET: counted. */
	public static function record(): void {
		if ( ! is_404() || 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) || ! Simple_SEO_Settings::value( self::OPTION, 'enabled' ) ) {
			return;
		}

		$url = self::clean_url( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned right here.
		if ( '' !== $url ) {
			self::hit( $url, self::clean_referrer( wp_unslash( (string) ( $_SERVER['HTTP_REFERER'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned right here.
		}
	}

	/**
	 * One more time an address was asked for: a row for it, or its count, its last time and its last
	 * referrer (an empty one keeps the one before).
	 */
	public static function hit( string $url, string $referrer = '', ?int $time = null ): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s', $time ?? time() );
		// A table not made yet must not print a database error on the page of a visitor.
		$suppress = $wpdb->suppress_errors();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . " ( url_hash, url, hits, first_seen, last_seen, referrer ) VALUES ( %s, %s, 1, %s, %s, %s )
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES( last_seen ), referrer = IF( VALUES( referrer ) = '', referrer, VALUES( referrer ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table's own name.
				md5( $url ),
				$url,
				$now,
				$now,
				$referrer
			)
		);
		$wpdb->suppress_errors( $suppress );

		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	/**
	 * The address of a request as kept: its path and query, without control characters and the
	 * arguments of trackers, cut to MAX_LENGTH; '' for what is not an address of the site.
	 */
	public static function clean_url( string $uri ): string {
		$uri = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $uri );
		if ( ! str_starts_with( $uri, '/' ) || str_starts_with( $uri, '//' ) ) {
			return '';
		}

		$parts = explode( '?', $uri, 2 );
		$query = array_filter(
			explode( '&', $parts[1] ?? '' ),
			static fn( string $pair ): bool => '' !== $pair && ! preg_match( self::TRACKERS, explode( '=', $pair, 2 )[0] )
		);

		return mb_substr( $parts[0] . ( $query ? '?' . implode( '&', $query ) : '' ), 0, self::MAX_LENGTH );
	}

	/** A referrer as kept: an http(s) address, cut to MAX_LENGTH; '' for none. */
	public static function clean_referrer( string $referrer ): string {
		$referrer = trim( $referrer );

		return preg_match( '#^https?://#i', $referrer ) ? mb_substr( esc_url_raw( $referrer ), 0, self::MAX_LENGTH ) : '';
	}

	/** Forget the addresses last asked for more than DAYS ago, and all but the newest MAX_ROWS. */
	public static function cleanup(): void {
		global $wpdb;

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table's own name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS ) ) );
		$cut = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( null !== $cut ) {
			$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT last_seen FROM {$table} WHERE id = %d", (int) $cut ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s OR ( last_seen = %s AND id <= %d )", $last, $last, (int) $cut ) );
		}
		// phpcs:enable
	}

	/*
	 * The list.
	 */

	/**
	 * A page of the addresses: those asked for most (or last) first; the hidden ones only when asked.
	 *
	 * @param string $order hits or last.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function rows( string $search = '', bool $hidden = false, string $order = 'hits', int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;

		$table = self::table();
		$where = $hidden ? 'hidden = 1' : 'hidden = 0';
		if ( '' !== $search ) {
			// The addresses are kept as asked for: a word is looked for as typed and as browsers encode it.
			$where .= $wpdb->prepare( ' AND ( url LIKE %s OR url LIKE %s )', '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( str_replace( '%2F', '/', rawurlencode( $search ) ) ) . '%' );
		}
		$by = 'last' === $order ? 'last_seen DESC, id DESC' : 'hits DESC, last_seen DESC, id DESC';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the table's own name and parts prepared above.
		$suppress = $wpdb->suppress_errors();
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, url, hits, hidden, first_seen, last_seen, referrer FROM {$table} WHERE {$where} ORDER BY {$by} LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable

		return array(
			'rows'  => array_map(
				static fn( array $row ): array => array(
					'id'         => (int) $row['id'],
					'url'        => (string) $row['url'],
					'hits'       => (int) $row['hits'],
					'hidden'     => (bool) $row['hidden'],
					'first_seen' => (string) $row['first_seen'],
					'last_seen'  => (string) $row['last_seen'],
					'referrer'   => (string) $row['referrer'],
				),
				$rows
			),
			'total' => $total,
		);
	}

	/** How many addresses are hidden. */
	public static function hidden_count(): int {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$count    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE hidden = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the table's own name.
		$wpdb->suppress_errors( $suppress );

		return $count;
	}

	/**
	 * A kept address by its ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function row( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, url, hits, hidden FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table's own name.

		return is_array( $row ) ? $row : null;
	}

	/*
	 * The redirect rules of WP Base.
	 */

	/** Whether the theme has the redirect rules of WP Base and the user may add one. */
	public static function rules_available(): bool {
		$type = get_post_type_object( self::RULES );

		return $type && function_exists( 'validate_redirect_rule' ) && current_user_can( $type->cap->create_posts );
	}

	/** The address of a kept path as a rule of WP Base holds it: with the protocol and host of the site. */
	public static function rule_url( string $url ): string {
		$home     = wp_parse_url( home_url() );
		$protocol = function_exists( 'redirect_rules_protocol' ) ? (string) redirect_rules_protocol() : (string) ( $home['scheme'] ?? 'https' );

		return $protocol . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
	}

	/**
	 * The rules of WP Base for addresses, whatever their state: rule address => the rule's ID.
	 *
	 * @param string[] $urls Kept paths.
	 * @return array<string, int>
	 */
	public static function rules_for( array $urls ): array {
		if ( ! $urls || ! post_type_exists( self::RULES ) ) {
			return array();
		}

		$addresses = array_map( array( self::class, 'rule_url' ), $urls );
		$posts     = get_posts(
			array(
				'post_type'        => self::RULES,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				// As the theme looks for a conflict: in the current language.
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the rules of one page of the list.
					array(
						'key'     => 'old_url',
						'value'   => $addresses,
						'compare' => 'IN',
					),
				),
			)
		);

		$rules = array();
		foreach ( $posts as $post ) {
			$rules[ (string) get_post_meta( $post->ID, 'old_url', true ) ] = (int) $post->ID;
		}

		return $rules;
	}

	/**
	 * The rule of WP Base for a kept address: the one there is, or a new draft with the address
	 * filled in and 301 — the person adds where it goes and publishes it on the theme's screen.
	 *
	 * @return int|WP_Error The rule's ID.
	 */
	public static function draft_rule( string $url ) {
		$address  = self::rule_url( $url );
		$existing = self::rules_for( array( $url ) );
		if ( isset( $existing[ $address ] ) ) {
			return $existing[ $address ];
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::RULES,
				'post_status' => 'draft',
				'post_title'  => rawurldecode( $url ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, 'old_url', $address );
		update_post_meta( $id, 'code', 301 );

		return (int) $id;
	}

	/*
	 * The screens.
	 */

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'monitor', __( '404', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 46 );
	}

	public static function add_menu(): void {
		$hook = add_submenu_page(
			Simple_SEO_Admin::PAGE,
			__( 'Pages not found', 'simple-seo' ),
			__( '404', 'simple-seo' ),
			Simple_SEO_Access::CAP_REPORTS,
			self::PAGE,
			array( self::class, 'render_page' )
		);
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
		}
	}

	/** The page's hook suffix; '' before the menu is built. */
	public static function hook(): string {
		return self::$hook;
	}

	/**
	 * The address of the page with its view: what differs from the default.
	 *
	 * @param array<string, mixed> $view s, hidden, order, paged (view()).
	 */
	public static function page_url( array $view = array() ): string {
		$args = array( 'page' => self::PAGE );
		if ( '' !== (string) ( $view['s'] ?? '' ) ) {
			$args['s'] = rawurlencode( (string) $view['s'] );
		}
		if ( ! empty( $view['hidden'] ) ) {
			$args['hidden'] = 1;
		}
		if ( 'last' === ( $view['order'] ?? '' ) ) {
			$args['order'] = 'last';
		}
		if ( (int) ( $view['paged'] ?? 1 ) > 1 ) {
			$args['paged'] = (int) $view['paged'];
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The view asked for: search, hidden, order, paged.
	 *
	 * @param array<string, mixed> $source $_GET, or the form.
	 * @return array{s: string, hidden: bool, order: string, paged: int}
	 */
	public static function view( array $source ): array {
		return array(
			's'      => trim( sanitize_text_field( (string) ( $source['s'] ?? '' ) ) ),
			'hidden' => ! empty( $source['hidden'] ),
			'order'  => 'last' === ( $source['order'] ?? '' ) ? 'last' : 'hits',
			// absint() would turn -4 into page 4.
			'paged'  => max( 1, (int) ( $source['paged'] ?? 1 ) ),
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		$view    = self::view( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$found   = self::rows( $view['s'], $view['hidden'], $view['order'], $view['paged'] );
		$hidden  = self::hidden_count();
		$enabled = (bool) Simple_SEO_Settings::value( self::OPTION, 'enabled' );
		$rules   = self::rules_available() ? self::rules_for( array_column( $found['rows'], 'url' ) ) : array();
		$theme   = post_type_exists( self::RULES );
		$nonce   = self::NONCE;
		$done    = isset( $_GET['simple-seo-done'] ) ? sanitize_key( wp_unslash( $_GET['simple-seo-done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message only.

		require SIMPLE_SEO_DIR . 'includes/views/monitor.php';
	}

	/** A form of the page: hide, show or delete an address, clear the list, or make a redirect of one. */
	public static function handle(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$input = wp_unslash( $_POST );
		$view  = self::view( $input );
		$done  = self::act( sanitize_key( (string) ( $input['do'] ?? '' ) ), absint( $input['id'] ?? 0 ) );
		if ( is_int( $done ) ) {
			wp_safe_redirect( (string) get_edit_post_link( $done, 'raw' ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'simple-seo-done', $done, self::page_url( $view ) ) );
		exit;
	}

	/**
	 * What a form asks for.
	 *
	 * @return string|int What was done (hidden, shown, deleted, cleared, rule-failed, nothing), or the
	 *                    ID of the redirect rule to open.
	 */
	public static function act( string $do, int $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $id ? self::row( $id ) : null;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		switch ( $do ) {
			case 'hide':
			case 'show':
				if ( $row ) {
					$wpdb->update( $table, array( 'hidden' => 'hide' === $do ? 1 : 0 ), array( 'id' => $id ) );
					return 'hide' === $do ? 'hidden' : 'shown';
				}
				break;
			case 'delete':
				if ( $row ) {
					$wpdb->delete( $table, array( 'id' => $id ) );
					return 'deleted';
				}
				break;
			case 'clear':
				$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table's own name.
				return 'cleared';
			case 'redirect':
				if ( $row && self::rules_available() ) {
					$rule = self::draft_rule( (string) $row['url'] );
					return is_wp_error( $rule ) ? 'rule-failed' : $rule;
				}
				break;
		}
		// phpcs:enable

		return 'nothing';
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );

		require SIMPLE_SEO_DIR . 'includes/views/tab-monitor.php';
	}
}
