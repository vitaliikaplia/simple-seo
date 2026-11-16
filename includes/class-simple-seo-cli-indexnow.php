<?php
/**
 * WP-CLI: wp simple-seo indexnow — the state of IndexNow, and sending addresses at once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tell search engines through IndexNow (Bing's endpoint) about pages that changed.
 *
 * ## EXAMPLES
 *
 *     $ wp simple-seo indexnow status
 *     $ wp simple-seo indexnow submit 12 https://example.com/news/
 */
final class Simple_SEO_CLI_IndexNow {
	/**
	 * Show whether IndexNow sends, its key, the batch waiting and the last answers of Bing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$key     = Simple_SEO_IndexNow::key();
		$next    = wp_next_scheduled( Simple_SEO_IndexNow::EVENT );
		$summary = array(
			'enabled'  => (bool) Simple_SEO_Settings::value( Simple_SEO_IndexNow::OPTION, 'enabled' ),
			'key'      => $key,
			'key_file' => '' !== $key ? Simple_SEO_IndexNow::key_location() : '',
			'waiting'  => count( Simple_SEO_IndexNow::queue()['urls'] ),
			'next'     => false !== $next ? (string) wp_date( 'Y-m-d H:i:s P', (int) $next ) : '',
		);
		$blockers = array_values( Simple_SEO_IndexNow::blockers() );
		$answers  = array_map(
			static fn( array $entry ): array => array(
				'time'    => (string) wp_date( 'Y-m-d H:i:s P', (int) ( $entry['time'] ?? 0 ) ),
				'count'   => (int) ( $entry['count'] ?? 0 ),
				'url'     => rawurldecode( (string) ( $entry['url'] ?? '' ) ),
				'code'    => (int) ( $entry['code'] ?? 0 ),
				'ok'      => ! empty( $entry['ok'] ),
				'message' => (string) ( $entry['message'] ?? '' ),
			),
			Simple_SEO_IndexNow::entries()
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' !== $format ) {
			WP_CLI::print_value(
				$summary + array(
					'blockers' => $blockers,
					'answers'  => $answers,
				),
				array( 'format' => $format )
			);
			return;
		}

		$formatter = new WP_CLI\Formatter( $assoc_args, array_keys( $summary ) );
		$formatter->display_item( $summary );
		foreach ( $blockers as $reason ) {
			WP_CLI::warning( $reason );
		}
		if ( $answers ) {
			WP_CLI\Utils\format_items( 'table', $answers, array( 'time', 'count', 'url', 'code', 'message' ) );
		} else {
			WP_CLI::log( __( 'Nothing has been sent yet.', 'simple-seo' ) );
		}
	}

	/**
	 * Send addresses to Bing now: of posts by their IDs, or addresses of this site.
	 *
	 * A post goes only if search engines may show it, as the sitemap lists it. The batch waiting
	 * goes with them.
	 *
	 * ## OPTIONS
	 *
	 * <target>...
	 * : IDs of posts or addresses of this site.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo indexnow submit 12 15 https://example.com/category/news/
	 *
	 * @param string[] $args
	 */
	public function submit( array $args ): void {
		self::stop_if_blocked();

		$urls = array();
		foreach ( $args as $target ) {
			if ( ctype_digit( $target ) ) {
				$post = Simple_SEO_Fields::object( 'post', (int) $target );
				$url  = $post instanceof WP_Post && Simple_SEO_IndexNow::watched( $post ) ? Simple_SEO_IndexNow::post_address( $post ) : '';
				if ( '' === $url ) {
					/* translators: %s: ID of a post. */
					WP_CLI::warning( sprintf( __( 'Post %s is not a page search engines may show: left out.', 'simple-seo' ), $target ) );
					continue;
				}
			} elseif ( Simple_SEO_IndexNow::own( $target ) ) {
				$url = $target;
			} else {
				/* translators: %s: an address. */
				WP_CLI::warning( sprintf( __( 'Not an address of this site: %s', 'simple-seo' ), $target ) );
				continue;
			}
			$urls[] = $url;
		}
		if ( ! $urls ) {
			WP_CLI::error( __( 'Nothing to send.', 'simple-seo' ) );
		}

		Simple_SEO_IndexNow::add( $urls );
		self::send_now();
	}

	/**
	 * Send the batch waiting now rather than when its minute is over.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo indexnow send
	 */
	public function send(): void {
		self::stop_if_blocked();
		if ( ! Simple_SEO_IndexNow::queue()['urls'] ) {
			WP_CLI::success( __( 'Nothing is waiting to be sent.', 'simple-seo' ) );
			return;
		}

		self::send_now();
	}

	/** Nothing goes while IndexNow is off or cannot work here: an error that says why. */
	private static function stop_if_blocked(): void {
		$blockers = Simple_SEO_IndexNow::blockers();
		if ( $blockers ) {
			WP_CLI::error( __( 'Nothing is sent:', 'simple-seo' ) . ' ' . implode( ' ', $blockers ) );
		}
	}

	/** Send the batch, the scheduled sending of it dropped; an error unless Bing took it all. */
	private static function send_now(): void {
		// A sending scheduled within ten minutes would stop the one a retry schedules.
		wp_clear_scheduled_hook( Simple_SEO_IndexNow::EVENT );

		$answers = Simple_SEO_IndexNow::send();
		$taken   = 0;
		foreach ( $answers as $answer ) {
			$where = $answer['count'] > 1
				/* translators: 1: an address, 2: how many more. */
				? sprintf( __( '%1$s and %2$s more', 'simple-seo' ), rawurldecode( $answer['url'] ), number_format_i18n( $answer['count'] - 1 ) )
				: rawurldecode( $answer['url'] );
			if ( $answer['ok'] ) {
				$taken += $answer['count'];
				WP_CLI::log( $where . ': ' . $answer['message'] );
			} else {
				WP_CLI::warning( $where . ': ' . $answer['message'] );
			}
		}

		if ( count( $answers ) !== count( array_filter( array_column( $answers, 'ok' ) ) ) ) {
			WP_CLI::error( __( 'Bing did not take all the addresses.', 'simple-seo' ) );
		}
		/* translators: %s: number of addresses. */
		WP_CLI::success( sprintf( __( 'Addresses Bing took: %s.', 'simple-seo' ), number_format_i18n( $taken ) ) );
	}
}
