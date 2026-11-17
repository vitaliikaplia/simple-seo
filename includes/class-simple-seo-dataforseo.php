<?php
/**
 * DataForSEO for the keyword research (Simple_SEO_Keywords): the search volume of each search, its
 * competition, cost per click and volume of the last twelve months, its keyword difficulty, more
 * searches of the phrase with all of these, and the interest over time of Google Trends — paid by
 * the request from the balance of the account whose API login and password are saved.
 *
 * API v3 (docs.dataforseo.com): HTTP Basic, a JSON array of one task, an envelope whose status_code
 * 20000 is ok, at the top and in the task. Locations are 2000 + the ISO number of the country.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_DataForSEO {
	public const API = 'https://api.dataforseo.com/v3/';

	/** Free, with made-up data: to try the connection. */
	public const SANDBOX = 'https://sandbox.dataforseo.com/v3/';

	/** Prefix of the transients of answers. */
	public const CACHE = 'simple_seo_dfs_';

	/** Volumes are monthly: an answer is kept a month. */
	public const TTL = MONTH_IN_SECONDS;

	/** The balance asked when the settings are saved is kept a day. */
	public const BALANCE_TTL = DAY_IN_SECONDS;

	/** Searches asked at once for their volume (the API takes 1000). */
	public const MAX = 1000;

	/** More searches of the phrase asked for. */
	public const SUGGESTIONS = 300;

	/** The locations of the countries of the research: 2000 + the ISO number. */
	public const LOCATIONS = array(
		'UA' => 2804,
		'US' => 2840,
		'GB' => 2826,
		'CA' => 2124,
		'AU' => 2036,
		'IE' => 2372,
		'PL' => 2616,
		'DE' => 2276,
		'AT' => 2040,
		'CH' => 2756,
		'FR' => 2250,
		'ES' => 2724,
		'IT' => 2380,
		'CZ' => 2203,
		'MD' => 2498,
		'LT' => 2440,
		'LV' => 2428,
		'EE' => 2233,
		'KZ' => 2398,
	);

	/** Whether DataForSEO gives the volumes: chosen, with a login and a password. */
	public static function on(): bool {
		$settings = Simple_SEO_Keywords::settings();

		return 'dataforseo' === $settings['volumes'] && '' !== $settings['dfs_login'] && '' !== $settings['dfs_secret'];
	}

	/**
	 * A request: the result of its task, or why there is none.
	 *
	 * @param array<string, mixed>|null $task Null for a GET.
	 * @return array<int, mixed>|WP_Error
	 */
	public static function request( string $path, ?array $task = null, int $timeout = 40 ) {
		$settings = Simple_SEO_Keywords::settings();
		$password = Simple_SEO_Search_Console::decrypt( $settings['dfs_secret'] );
		if ( '' === $settings['dfs_login'] || '' === $password ) {
			return new WP_Error( 'simple_seo_dfs_login', __( 'DataForSEO is not connected: save the API login and password on the Keyword research tab.', 'simple-seo' ) );
		}

		$args = array(
			'method'  => null === $task ? 'GET' : 'POST',
			'timeout' => $timeout,
			'headers' => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic.
				'Authorization' => 'Basic ' . base64_encode( $settings['dfs_login'] . ':' . $password ),
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $task ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( array( $task ), JSON_UNESCAPED_UNICODE );
		}

		$response = wp_remote_request( ( $settings['dfs_sandbox'] ? self::SANDBOX : self::API ) . $path, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the error. */
			return new WP_Error( 'simple_seo_dfs_network', sprintf( __( 'DataForSEO cannot be reached from this server: %s', 'simple-seo' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code ) {
			return self::error( 40100, '' );
		}
		if ( ! is_array( $data ) ) {
			/* translators: %s: HTTP status. */
			return new WP_Error( 'simple_seo_dfs_answer', sprintf( __( 'DataForSEO answered %s with nothing that could be read.', 'simple-seo' ), (string) $code ) );
		}

		$task_data = is_array( $data['tasks'][0] ?? null ) ? $data['tasks'][0] : array();
		foreach ( array( $data, $task_data ) as $level ) {
			$status = (int) ( $level['status_code'] ?? 0 );
			if ( 20000 !== $status ) {
				return self::error( $status, (string) ( $level['status_message'] ?? '' ) );
			}
		}

		return is_array( $task_data['result'] ?? null ) ? $task_data['result'] : array();
	}

	/** What a status of DataForSEO that is not ok means, and what to do. */
	private static function error( int $status, string $message ): WP_Error {
		if ( in_array( $status, array( 40100, 40104 ), true ) ) {
			$text = __( 'DataForSEO did not accept the login and password: copy the API login and password from app.dataforseo.com (API access) — the API password is not that of the account.', 'simple-seo' );
		} elseif ( in_array( $status, array( 40200, 40210 ), true ) ) {
			$text = __( 'The balance of the DataForSEO account is not enough: top it up at app.dataforseo.com.', 'simple-seo' );
		} elseif ( in_array( $status, array( 40202, 40209 ), true ) ) {
			$text = __( 'DataForSEO asks to wait: too many requests. Try again in a minute.', 'simple-seo' );
		} else {
			/* translators: 1: status code of DataForSEO, 2: what it said. */
			$text = sprintf( __( 'DataForSEO answered %1$s: %2$s', 'simple-seo' ), (string) $status, '' !== $message ? $message : '—' );
		}

		return new WP_Error( 'simple_seo_dfs_' . $status, $text );
	}

	/**
	 * After the settings are saved: the balance of the account asked now and kept a day, so the tab
	 * shows at once whether the login works — without asking DataForSEO each time it is opened.
	 */
	public static function check(): void {
		Simple_SEO_Settings::flush();
		delete_transient( self::CACHE . 'balance' );
		if ( ! self::on() ) {
			return;
		}

		$result = self::request( 'appendix/user_data', null, 15 );
		set_transient(
			self::CACHE . 'balance',
			array(
				'account' => md5( self::account() ),
				'time'    => time(),
				'balance' => is_wp_error( $result ) ? null : (float) ( $result[0]['money']['balance'] ?? 0 ),
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
			),
			self::BALANCE_TTL
		);
	}

	/**
	 * The balance as last asked, in USD, or why it could not be; null when not asked for these
	 * login and password.
	 *
	 * @return array{time: int, balance: float|null, error: string}|null
	 */
	public static function balance(): ?array {
		$saved = get_transient( self::CACHE . 'balance' );

		return is_array( $saved ) && md5( self::account() ) === ( $saved['account'] ?? '' ) ? $saved : null;
	}

	/** What the answers depend on: the login, the password and the sandbox. */
	private static function account(): string {
		$settings = Simple_SEO_Keywords::settings();

		return $settings['dfs_login'] . '|' . $settings['dfs_secret'] . '|' . ( $settings['dfs_sandbox'] ? 'sandbox' : 'live' );
	}

	/** A search as Google Ads takes it: 80 characters and ten words at most, letters, digits and few signs. */
	public static function takes( string $keyword ): bool {
		return '' !== $keyword && mb_strlen( $keyword ) <= 80 && count( preg_split( '/\s+/u', $keyword ) ) <= 10
			&& 1 === preg_match( "/^[\\p{L}\\p{M}\\p{N}\\s'’.&-]+$/u", $keyword );
	}

	/**
	 * The metrics of one item of DataForSEO: the volume, competition (0–100), cost per click,
	 * the volumes of the last twelve months (oldest first) and the difficulty, each null when
	 * missing.
	 *
	 * @param array<string, mixed> $info     keyword_info of Labs, or a result of Google Ads.
	 * @param array<string, mixed> $property keyword_properties of Labs.
	 * @return array{volume: int|null, competition: int|null, cpc: float|null, trend: int[]|null, kd: int|null}
	 */
	public static function metrics( array $info, array $property = array() ): array {
		$months = array();
		foreach ( is_array( $info['monthly_searches'] ?? null ) ? $info['monthly_searches'] : array() as $month ) {
			if ( is_array( $month ) && is_numeric( $month['year'] ?? null ) && is_numeric( $month['month'] ?? null ) ) {
				$months[ sprintf( '%04d-%02d', (int) $month['year'], (int) $month['month'] ) ] = (int) ( $month['search_volume'] ?? 0 );
			}
		}
		ksort( $months );

		$competition = $info['competition_index'] ?? ( is_numeric( $info['competition'] ?? null ) ? round( 100 * (float) $info['competition'] ) : null );

		return array(
			'volume'      => is_numeric( $info['search_volume'] ?? null ) ? (int) $info['search_volume'] : null,
			'competition' => is_numeric( $competition ) ? (int) $competition : null,
			'cpc'         => is_numeric( $info['cpc'] ?? null ) ? round( (float) $info['cpc'], 2 ) : null,
			'trend'       => $months ? array_values( array_slice( $months, -12 ) ) : null,
			'kd'          => is_numeric( $property['keyword_difficulty'] ?? null ) ? (int) $property['keyword_difficulty'] : null,
		);
	}

	/**
	 * A request kept a month by what it asks: the result, or why there is none (not kept).
	 *
	 * @param array<string, mixed> $task
	 * @return array<int, mixed>|WP_Error
	 */
	private static function kept( string $path, array $task, int $timeout = 40 ) {
		$cache = self::CACHE . md5( self::account() . '|' . $path . '|' . wp_json_encode( $task ) );
		$saved = get_transient( $cache );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		$result = self::request( $path, $task, $timeout );
		if ( ! is_wp_error( $result ) ) {
			set_transient( $cache, $result, self::TTL );
		}

		return $result;
	}

	/**
	 * More searches with the phrase in them, from the database of DataForSEO Labs, with their
	 * metrics and difficulty.
	 *
	 * @return array<string, array<string, mixed>>|WP_Error keyword => metrics()
	 */
	public static function suggestions( string $seed, string $language, string $country ) {
		$result = self::kept(
			'dataforseo_labs/google/keyword_suggestions/live',
			array(
				'keyword'              => $seed,
				'location_code'        => self::LOCATIONS[ $country ] ?? self::LOCATIONS['US'],
				'language_code'        => $language,
				'include_seed_keyword' => true,
				'limit'                => self::SUGGESTIONS,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$found = array();
		$items = is_array( $result[0]['items'] ?? null ) ? $result[0]['items'] : array();
		if ( is_array( $result[0]['seed_keyword_data'] ?? null ) ) {
			array_unshift( $items, $result[0]['seed_keyword_data'] );
		}
		foreach ( $items as $item ) {
			$keyword = is_array( $item ) && is_string( $item['keyword'] ?? null ) ? Simple_SEO_Keywords::clean( $item['keyword'] ) : '';
			if ( '' !== $keyword && ! isset( $found[ $keyword ] ) ) {
				$found[ $keyword ] = self::metrics(
					is_array( $item['keyword_info'] ?? null ) ? $item['keyword_info'] : array(),
					is_array( $item['keyword_properties'] ?? null ) ? $item['keyword_properties'] : array()
				);
			}
		}

		return $found;
	}

	/**
	 * The volumes of searches from Google Ads, MAX at a time: those Google Ads does not take are left
	 * out.
	 *
	 * @param string[] $keywords
	 * @return array<string, array<string, mixed>>|WP_Error keyword => metrics()
	 */
	public static function volumes( array $keywords, string $language, string $country ) {
		$keywords = array_values( array_filter( array_unique( $keywords ), array( self::class, 'takes' ) ) );
		$found    = array();
		foreach ( array_chunk( $keywords, self::MAX ) as $chunk ) {
			sort( $chunk );
			$result = self::kept(
				'keywords_data/google_ads/search_volume/live',
				array(
					'keywords'      => $chunk,
					'location_code' => self::LOCATIONS[ $country ] ?? self::LOCATIONS['US'],
					'language_code' => $language,
				),
				60
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( $result as $item ) {
				$keyword = is_array( $item ) && is_string( $item['keyword'] ?? null ) ? Simple_SEO_Keywords::clean( $item['keyword'] ) : '';
				if ( '' !== $keyword ) {
					$found[ $keyword ] = self::metrics( $item );
				}
			}
		}

		return $found;
	}

	/**
	 * The keyword difficulty of searches (0–100), from DataForSEO Labs, MAX at a time.
	 *
	 * @param string[] $keywords
	 * @return array<string, int>|WP_Error
	 */
	public static function difficulty( array $keywords, string $language, string $country ) {
		$keywords = array_values( array_filter( array_unique( $keywords ), array( self::class, 'takes' ) ) );
		$found    = array();
		foreach ( array_chunk( $keywords, self::MAX ) as $chunk ) {
			sort( $chunk );
			$result = self::kept(
				'dataforseo_labs/google/bulk_keyword_difficulty/live',
				array(
					'keywords'      => $chunk,
					'location_code' => self::LOCATIONS[ $country ] ?? self::LOCATIONS['US'],
					'language_code' => $language,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( is_array( $result[0]['items'] ?? null ) ? $result[0]['items'] : array() as $item ) {
				$keyword = is_array( $item ) && is_string( $item['keyword'] ?? null ) ? Simple_SEO_Keywords::clean( $item['keyword'] ) : '';
				if ( '' !== $keyword && is_numeric( $item['keyword_difficulty'] ?? null ) ) {
					$found[ $keyword ] = (int) $item['keyword_difficulty'];
				}
			}
		}

		return $found;
	}

	/**
	 * The interest over time of a phrase in Google Trends for the last five years: points of a
	 * date and a value of 0–100.
	 *
	 * @return array<int, array{date: string, value: int}>|WP_Error
	 */
	public static function trends( string $seed, string $language, string $country ) {
		$result = self::kept(
			'keywords_data/google_trends/explore/live',
			array(
				'keywords'      => array( (string) preg_replace( '/[<>|"\-+=~!:*()\[\]{},]/u', ' ', $seed ) ),
				'location_code' => self::LOCATIONS[ $country ] ?? self::LOCATIONS['US'],
				'language_code' => $language,
				'time_range'    => 'past_5_years',
				'item_types'    => array( 'google_trends_graph' ),
			),
			60
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$points = array();
		foreach ( is_array( $result[0]['items'] ?? null ) ? $result[0]['items'] : array() as $item ) {
			if ( ! is_array( $item ) || 'google_trends_graph' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( is_array( $item['data'] ?? null ) ? $item['data'] : array() as $point ) {
				$value = is_array( $point ) ? ( $point['values'][0] ?? ( $point['values'] ?? null ) ) : null;
				if ( is_array( $point ) && is_string( $point['date_from'] ?? null ) && is_numeric( $value ) && empty( $point['missing_data'] ) ) {
					$points[] = array(
						'date'  => $point['date_from'],
						'value' => (int) $value,
					);
				}
			}
		}

		return $points;
	}
}
