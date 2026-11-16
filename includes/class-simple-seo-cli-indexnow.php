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
		$status   = Simple_SEO_IndexNow::status();
		$summary  = array_intersect_key( $status, array_flip( array( 'enabled', 'key', 'key_file', 'waiting', 'next' ) ) );
		$blockers = $status['blockers'];
		$answers  = $status['answers'];

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

		$found = Simple_SEO_IndexNow::resolve( $args );
		foreach ( $found['left'] as $why ) {
			WP_CLI::warning( $why );
		}
		$urls = $found['urls'];
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

	/** Send the batch now; an error unless Bing took it all. */
	private static function send_now(): void {
		$answers = Simple_SEO_IndexNow::send_now();
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
