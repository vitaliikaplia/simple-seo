<?php
/**
 * The links of the audit: every address the pages of the audit link to, on this site and
 * elsewhere, checked once — a HEAD request, a GET where a server does not take HEAD. A link that
 * did not answer, or answered 429 or 5xx, is checked again a day later before it counts as broken.
 * A link of this site that redirects is no broken one, and is listed apart.
 *
 * The links are the second part of a run of the audit (Simple_SEO_Audit takes the steps); the
 * checks a day later are a task of WP-Cron of their own. Requests to one host of another site go
 * at most once a second.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Links {
	/** The table after the prefix of the site's tables: one row a link of the last run. */
	public const TABLE = 'simple_seo_links';

	/** The version of the table made, in a row of its own. */
	public const DB_OPTION  = 'simple_seo_links_db';
	public const DB_VERSION = '1';

	/** The task of WP-Cron that checks again the links that did not answer. */
	public const RETRY = 'simple_seo_links_retry';

	/** The hosts of other sites asked last and when, for the next step to keep its distance. */
	public const RECENT = 'simple_seo_links_recent';

	/** Links a run checks at most. */
	public const MAX_LINKS = 5000;

	/** Seconds a link has to answer. */
	public const TIMEOUT = 10;

	/** Bytes of a page read when a server takes GET only. */
	public const GET_SIZE = 65536;

	/** Seconds before a link that did not answer is checked again. */
	public const RETRY_AFTER = 86400;

	public const PER_PAGE = 50;

	/** What the report of links lists. */
	public const VIEWS = array( 'broken', 'redirects', 'waiting' );

	/** Codes of a HEAD that a GET may answer otherwise: servers that do not take HEAD. */
	private const HEAD_REFUSED = array( 400, 403, 405, 406, 501 );

	public static function init(): void {
		add_action( self::RETRY, array( self::class, 'retry' ) );
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
  host varchar(255) NOT NULL DEFAULT '',
  internal tinyint(1) NOT NULL DEFAULT 0,
  done tinyint(1) NOT NULL DEFAULT 0,
  tries tinyint(3) unsigned NOT NULL DEFAULT 0,
  code smallint(5) unsigned NOT NULL DEFAULT 0,
  error varchar(2048) NOT NULL DEFAULT '',
  next_at datetime DEFAULT NULL,
  checked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY done (done)
) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/** Links a run checks at most. */
	public static function max_links(): int {
		/**
		 * Links an audit checks at most (5000 by default).
		 *
		 * @param int $links
		 */
		return max( 1, (int) apply_filters( 'simple_seo_audit_max_links', self::MAX_LINKS ) );
	}

	/** Seconds between two requests to one host of another site. */
	public static function interval(): float {
		/**
		 * Seconds between two requests of the audit to one host of another site (1 by default).
		 *
		 * @param float $seconds
		 */
		return max( 0.0, (float) apply_filters( 'simple_seo_audit_host_interval', 1.0 ) );
	}

	/** No link, no check waiting: the start of a run. */
	public static function reset(): void {
		global $wpdb;

		self::install();
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		wp_clear_scheduled_hook( self::RETRY );
		delete_option( self::RECENT );
	}

	/**
	 * The links of the pages of the audit, in their order, each once, at most max_links() (a page
	 * that did not open has none: fetch() reads only those that do). A link to a page of the audit
	 * has its answer already: it is not asked again.
	 *
	 * @return array{total: int, capped: bool}
	 */
	public static function queue(): array {
		global $wpdb;

		self::install();
		$audit = Simple_SEO_Audit::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own tables.
		$pages = (array) $wpdb->get_results( "SELECT url, code, error, links FROM {$audit} WHERE done = 1 ORDER BY id", ARRAY_A );
		$own   = array();
		$known = array();
		foreach ( $pages as $page ) {
			$own[ strtolower( (string) wp_parse_url( (string) $page['url'], PHP_URL_HOST ) ) ] = true;
			$known[ (string) $page['url'] ] = array( (int) $page['code'], (string) $page['error'] );
		}

		$max    = self::max_links();
		$links  = array();
		$capped = false;
		foreach ( $pages as $page ) {
			foreach ( (array) json_decode( (string) $page['links'], true ) as $link ) {
				$link = (string) $link;
				if ( '' === $link || isset( $links[ $link ] ) ) {
					continue;
				}
				if ( count( $links ) >= $max ) {
					$capped = true;
					break 2;
				}
				$links[ $link ] = true;
			}
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_chunk( array_keys( $links ), 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $link ) {
				$host     = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
				$internal = isset( $own[ $host ] ) ? 1 : 0;
				$values[] = isset( $known[ $link ] )
					? $wpdb->prepare( '( %s, %s, %s, %d, 1, %d, %s, %s )', md5( $link ), $link, $host, $internal, $known[ $link ][0], $known[ $link ][1], $now )
					: $wpdb->prepare( '( %s, %s, %s, %d, 0, 0, %s, NULL )', md5( $link ), $link, $host, $internal, '' );
			}
			$wpdb->query( 'INSERT IGNORE INTO ' . self::table() . ' ( url_hash, url, host, internal, done, code, error, checked_at ) VALUES ' . implode( ', ', $values ) );
		}
		// phpcs:enable

		return array(
			'total'  => self::count( 'all' ),
			'capped' => $capped,
		);
	}

	/** Stop a run: the links not checked to the end leave the report. */
	public static function stop(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE done <> 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		wp_clear_scheduled_hook( self::RETRY );
		delete_option( self::RECENT );
	}

	/** Check the links of the queue until $deadline (one at least). */
	public static function step( float $deadline ): void {
		self::process( 'done = 0', $deadline );
	}

	/**
	 * The task a day later: the links that did not answer, checked again under the lock of the
	 * steps of the audit; the next one scheduled for those still waiting.
	 */
	public static function retry(): void {
		global $wpdb;

		if ( ! Simple_SEO_Audit::lock() ) {
			wp_schedule_single_event( time() + 60, self::RETRY );
			return;
		}
		try {
			self::process( $wpdb->prepare( 'done = 2 AND next_at <= %s', gmdate( 'Y-m-d H:i:s' ) ), microtime( true ) + Simple_SEO_Audit::BUDGET );
		} finally {
			Simple_SEO_Audit::unlock();
		}
		self::schedule_retry();
	}

	/** The next check of the links waiting, when the first of them is due. */
	public static function schedule_retry(): void {
		global $wpdb;

		wp_clear_scheduled_hook( self::RETRY );
		$next = $wpdb->get_var( 'SELECT MIN(next_at) FROM ' . self::table() . ' WHERE done = 2' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		if ( null !== $next ) {
			wp_schedule_single_event( max( time(), (int) strtotime( $next . ' UTC' ) ), self::RETRY );
		}
	}

	/**
	 * Check the links that match $where, in their order, until $deadline: one host of another site
	 * at most once an interval(), the hosts asked last remembered for the next step.
	 */
	private static function process( string $where, float $deadline ): void {
		global $wpdb;

		$interval = self::interval();
		$recent   = get_option( self::RECENT );
		$recent   = is_array( $recent ) ? array_map( 'floatval', $recent ) : array();
		$checked  = false;
		do {
			$rows = (array) $wpdb->get_results( 'SELECT id, url, host, internal, tries FROM ' . self::table() . " WHERE {$where} ORDER BY id LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table, a condition of this class.
			$free = INF;
			$any  = false;
			foreach ( $rows as $row ) {
				if ( $checked && microtime( true ) >= $deadline ) {
					break 2;
				}
				$host = (string) $row['host'];
				// Only the hosts of other sites are remembered: this site is asked without waiting.
				if ( isset( $recent[ $host ] ) && microtime( true ) < $recent[ $host ] + $interval ) {
					$free = min( $free, $recent[ $host ] + $interval );
					continue;
				}
				self::check( $row );
				$checked = true;
				$any     = true;
				if ( ! $row['internal'] ) {
					$recent[ $host ] = microtime( true );
				}
			}
			if ( $rows && ! $any ) {
				// Every link left is of a host asked a moment ago: wait for the first one free.
				if ( $checked && $free > $deadline ) {
					break;
				}
				usleep( (int) ceil( max( 0.0, $free - microtime( true ) ) * 1000000 ) );
			}
		} while ( $rows );

		$now = microtime( true );
		update_option( self::RECENT, array_filter( $recent, static fn( float $time ): bool => $time > $now - $interval ), false );
	}

	/**
	 * Ask a link: HEAD, then GET where HEAD is refused. A link of this site follows no redirect (it
	 * is listed as one); a link elsewhere follows up to five. No answer, 429 or 5xx the first time:
	 * waiting, to be asked again a day later.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function check( array $row ): void {
		global $wpdb;

		$url      = (string) $row['url'];
		$internal = ! empty( $row['internal'] );
		$args     = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => $internal ? 0 : 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; Simple SEO link check; ' . home_url( '/' ) . ')',
			// This site's own address, checked as WordPress checks itself (Site Health).
			'sslverify'   => $internal ? (bool) apply_filters( 'https_local_ssl_verify', false ) : true,
		);
		$response = wp_remote_head( $url, $args );
		if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), self::HEAD_REFUSED, true ) ) {
			$response = wp_remote_get( $url, $args + array( 'limit_response_size' => self::GET_SIZE ) );
		}

		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$error = is_wp_error( $response ) ? $response->get_error_message() : '';
		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$error    = '' !== $location ? WP_Http::make_absolute_url( $location, $url ) : '';
		}
		$again = ( 0 === $code || 429 === $code || $code >= 500 ) && 0 === (int) $row['tries'];

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			self::table(),
			array(
				'done'       => $again ? 2 : 1,
				'tries'      => (int) $row['tries'] + 1,
				'code'       => $code,
				'error'      => mb_substr( $error, 0, 2048 ),
				'next_at'    => $again ? gmdate( 'Y-m-d H:i:s', time() + self::RETRY_AFTER ) : null,
				'checked_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $row['id'] )
		);
	}

	/*
	 * The report.
	 */

	/** The SQL condition of a view: broken, redirects, waiting; or all, queued, checked. */
	private static function where( string $view ): string {
		switch ( $view ) {
			case 'broken':
				return 'done = 1 AND ( code = 0 OR code >= 400 )';
			case 'redirects':
				return 'done = 1 AND internal = 1 AND code >= 300 AND code < 400';
			case 'waiting':
				return 'done = 2';
			case 'queued':
				return 'done = 0';
			case 'checked':
				return 'done <> 0';
		}

		return '1 = 1';
	}

	public static function count( string $view ): int {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$count    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . self::where( $view ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		$wpdb->suppress_errors( $suppress );

		return $count;
	}

	/**
	 * How many links each view lists.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		$counts = array();
		foreach ( self::VIEWS as $view ) {
			$counts[ $view ] = self::count( $view );
		}

		return $counts;
	}

	/**
	 * A page of a view: the links in their order, each with the pages of the audit it is on (the
	 * first five) and how many those are.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function rows( string $view, int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;

		$view  = in_array( $view, self::VIEWS, true ) ? $view : 'broken';
		$where = self::where( $view );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table, a condition of this class.
		$suppress = $wpdb->suppress_errors();
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, url, internal, done, code, error, next_at FROM {$table} WHERE {$where} ORDER BY id LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable

		$found = array();
		foreach ( $rows as $row ) {
			$on      = self::pages_with( (string) $row['url'] );
			$found[] = array(
				'id'       => (int) $row['id'],
				'url'      => (string) $row['url'],
				'internal' => (bool) $row['internal'],
				'waiting'  => 2 === (int) $row['done'],
				'code'     => (int) $row['code'],
				'error'    => (string) $row['error'],
				'next_at'  => null === $row['next_at'] ? 0 : (int) strtotime( $row['next_at'] . ' UTC' ),
				'pages'    => $on['pages'],
				'on'       => $on['total'],
			);
		}

		return array(
			'rows'  => $found,
			'total' => $total,
		);
	}

	/**
	 * The pages of the audit that link to an address: the first $limit and how many.
	 *
	 * @return array{pages: string[], total: int}
	 */
	public static function pages_with( string $url, int $limit = 5 ): array {
		global $wpdb;

		// The links of a page are kept as JSON: the address as JSON, quotes and all, is one link whole.
		$like  = '%' . $wpdb->esc_like( (string) wp_json_encode( $url ) ) . '%';
		$audit = Simple_SEO_Audit::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE done = 1 AND links LIKE %s", $like ) );
		$pages = $total ? (array) $wpdb->get_col( $wpdb->prepare( "SELECT url FROM {$audit} WHERE done = 1 AND links LIKE %s ORDER BY id LIMIT %d", $like, $limit ) ) : array();
		// phpcs:enable

		return array(
			'pages' => array_map( 'strval', $pages ),
			'total' => $total,
		);
	}

	/** The address of the page of the audit with a view of links and its page number. */
	public static function page_url( string $view = 'broken', int $paged = 1 ): string {
		$args = array(
			'page'  => Simple_SEO_Audit::PAGE,
			'links' => in_array( $view, self::VIEWS, true ) ? $view : 'broken',
		);
		if ( $paged > 1 ) {
			$args['lpaged'] = $paged;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) ) . '#links';
	}
}
