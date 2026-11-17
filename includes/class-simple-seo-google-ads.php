<?php
/**
 * The Keyword Planner of Google Ads for the keyword research (Simple_SEO_Keywords): the search
 * volume of each search, its competition, cost per click and volumes of the last twelve months,
 * and more searches of the phrase with these — free, through the Google Ads API of a Google Cloud
 * project of the site's own.
 *
 * Google Ads API v26 (developers.google.com/google-ads/api): a service account signs in with a JWT
 * (the key of Search Console or one of its own) and must be a user of the Google Ads account; the
 * Cloud project needs the Basic access level for keyword planning. No developer token since
 * September 2026. Volumes and cost come as strings of int64; costs in micros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Google_Ads {
	public const API = 'https://googleads.googleapis.com/v26/';

	public const SCOPE = 'https://www.googleapis.com/auth/adwords';

	/** Prefix of the transients of answers and of the token. */
	public const CACHE = 'simple_seo_gads_';

	/** Volumes are monthly: an answer is kept a month. */
	public const TTL = MONTH_IN_SECONDS;

	/** Searches asked at once for their volumes (the API takes 10 000). */
	public const MAX = 10000;

	/** More searches of the phrase asked for. */
	public const IDEAS = 300;

	/** The language constants of Google Ads. */
	public const LANGUAGES = array(
		'en' => 1000,
		'de' => 1001,
		'fr' => 1002,
		'es' => 1003,
		'it' => 1004,
		'pl' => 1030,
		'ru' => 1031,
		'uk' => 1036,
	);

	private const MONTHS = array( 'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER' );

	/**
	 * The key the service account signs in with: that of Search Console when chosen, else its own;
	 * [] for none.
	 *
	 * @return array<string, string>
	 */
	public static function key(): array {
		$settings = Simple_SEO_Keywords::settings();
		$key      = $settings['gads_use_gsc'] ? Simple_SEO_Settings::value( Simple_SEO_Search_Console::OPTION, 'key' ) : $settings['gads_key'];

		return is_array( $key ) && is_string( $key['email'] ?? null ) && is_string( $key['secret'] ?? null ) && '' !== $key['secret'] ? $key : array();
	}

	/** Whether Google Ads gives the volumes: chosen, with an account and a key. */
	public static function on(): bool {
		$settings = Simple_SEO_Keywords::settings();

		return 'google_ads' === $settings['volumes'] && '' !== $settings['gads_customer'] && array() !== self::key();
	}

	/** What the answers depend on: the key, the account and the manager. */
	private static function account(): string {
		$settings = Simple_SEO_Keywords::settings();

		return md5( implode( '|', self::key() ) ) . '|' . $settings['gads_customer'] . '|' . $settings['gads_login'];
	}

	/**
	 * An access token of the service account for Google Ads, kept (encrypted) until shortly before
	 * it ends.
	 *
	 * @return string|WP_Error
	 */
	public static function token() {
		$key = self::key();
		if ( ! $key ) {
			return new WP_Error( 'simple_seo_gads_no_key', __( 'Google Ads is not connected: choose the key of a service account on the Keyword research tab.', 'simple-seo' ) );
		}

		$cache = self::CACHE . 'token';
		$saved = get_transient( $cache );
		if ( is_array( $saved ) && md5( implode( '|', $key ) ) === ( $saved['for'] ?? '' ) ) {
			$token = Simple_SEO_Search_Console::decrypt( (string) ( $saved['token'] ?? '' ) );
			if ( '' !== $token ) {
				return $token;
			}
		}

		$private = Simple_SEO_Search_Console::decrypt( $key['secret'] );
		if ( '' === $private ) {
			return new WP_Error( 'simple_seo_gads_secret', __( 'The saved key cannot be read: the keys and salts of the site in wp-config.php have changed since. Paste the key again.', 'simple-seo' ) );
		}

		$now = time();
		$jwt = Simple_SEO_Search_Console::jwt(
			array(
				'iss'   => $key['email'],
				'scope' => self::SCOPE,
				'aud'   => Simple_SEO_Search_Console::TOKEN_URL,
				'iat'   => $now,
				'exp'   => $now + HOUR_IN_SECONDS,
			),
			$private,
			(string) ( $key['id'] ?? '' )
		);
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			Simple_SEO_Search_Console::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::network_error( $response );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || ! is_string( $data['access_token'] ?? null ) || '' === $data['access_token'] ) {
			$why = is_array( $data ) ? (string) ( $data['error_description'] ?? ( $data['error'] ?? '' ) ) : '';

			return new WP_Error(
				'simple_seo_gads_token',
				/* translators: %s: what Google answered. */
				sprintf( __( 'Google did not let the service account in (%s): check that its key is not deleted in Google Cloud, or paste a new one.', 'simple-seo' ), '' !== $why ? $why : (string) wp_remote_retrieve_response_code( $response ) )
			);
		}

		set_transient(
			$cache,
			array(
				'for'   => md5( implode( '|', $key ) ),
				'token' => Simple_SEO_Search_Console::encrypt( $data['access_token'] ),
			),
			max( 60, (int) ( $data['expires_in'] ?? HOUR_IN_SECONDS ) - 120 )
		);

		return $data['access_token'];
	}

	private static function network_error( WP_Error $error ): WP_Error {
		/* translators: %s: the error. */
		return new WP_Error( 'simple_seo_gads_network', sprintf( __( 'Google Ads cannot be reached from this server: %s', 'simple-seo' ), $error->get_error_message() ) );
	}

	/**
	 * A request to the Google Ads API: its answer, or why there is none.
	 *
	 * @param array<string, mixed>|null $body Null for a GET.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request( string $path, ?array $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$settings = Simple_SEO_Keywords::settings();
		$args     = array(
			'method'  => null === $body ? 'GET' : 'POST',
			'timeout' => 40,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( '' !== $settings['gads_login'] ) {
			$args['headers']['login-customer-id'] = $settings['gads_login'];
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		}

		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return self::network_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $data ) ) {
			return $data;
		}
		if ( 401 === $code ) {
			delete_transient( self::CACHE . 'token' );
		}

		return self::error( $code, is_array( $data ) ? $data : array() );
	}

	/**
	 * What an answer of Google Ads that is no data means, and what to do.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function error( int $code, array $data ): WP_Error {
		$message = is_array( $data['error'] ?? null ) ? (string) ( $data['error']['message'] ?? '' ) : '';
		$detail  = $data['error']['details'][0]['errors'][0] ?? null;
		if ( is_array( $detail ) ) {
			$reason  = is_array( $detail['errorCode'] ?? null ) ? implode( ', ', array_map( 'strval', array_values( $detail['errorCode'] ) ) ) : '';
			$message = trim( ( '' !== $reason ? $reason . ': ' : '' ) . (string) ( $detail['message'] ?? $message ) );
		}

		if ( 403 === $code && preg_match( '/has not been used|is disabled|SERVICE_DISABLED/i', $message ) ) {
			$text = __( 'The Google Ads API is off in the project of the service account: turn it on in Google Cloud (APIs and services, Library), wait a few minutes and try again.', 'simple-seo' );
		} elseif ( preg_match( '/CLOUD_PROJECT_NOT_APPROVED|DEVELOPER_TOKEN|access level|SERVICE_ACCESS_DENIED/i', $message ) ) {
			$text = __( 'The Google Cloud project of the service account may not plan keywords yet: ask for the Basic access level of the Google Ads API on its Google Ads API page in the Cloud Console.', 'simple-seo' );
		} elseif ( 403 === $code || preg_match( '/USER_PERMISSION_DENIED|CUSTOMER_NOT_FOUND/i', $message ) ) {
			$text = __( 'The service account has no access to the Google Ads account: add its email as a user of the account (Admin, Access and security), or give the ID of the manager account it is reached through.', 'simple-seo' );
		} elseif ( 429 === $code ) {
			$text = __( 'Google Ads asks to wait: too many requests. Try again in a minute.', 'simple-seo' );
		} else {
			/* translators: 1: HTTP status, 2: what Google Ads said. */
			$text = sprintf( __( 'Google Ads answered %1$s: %2$s', 'simple-seo' ), (string) $code, '' !== $message ? $message : '—' );
		}

		return new WP_Error( 'simple_seo_gads_' . $code, $text );
	}

	/**
	 * After the settings are saved: whether the service account reaches Google Ads, asked now and
	 * kept a day, so the tab shows it without asking each time it is opened.
	 */
	public static function check(): void {
		Simple_SEO_Settings::flush();
		delete_transient( self::CACHE . 'status' );
		delete_transient( self::CACHE . 'token' );
		if ( ! self::on() ) {
			return;
		}

		$result   = self::request( 'customers:listAccessibleCustomers' );
		$accounts = array();
		foreach ( is_wp_error( $result ) ? array() : (array) ( $result['resourceNames'] ?? array() ) as $name ) {
			if ( is_string( $name ) && preg_match( '#^customers/(\d+)$#', $name, $found ) ) {
				$accounts[] = $found[1];
			}
		}
		set_transient(
			self::CACHE . 'status',
			array(
				'account'  => md5( self::account() ),
				'time'     => time(),
				'accounts' => $accounts,
				'error'    => is_wp_error( $result ) ? $result->get_error_message() : '',
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Whether the service account reached Google Ads when the settings were saved, and the accounts
	 * it sees; null when not asked for this key and account.
	 *
	 * @return array{time: int, accounts: string[], error: string}|null
	 */
	public static function status(): ?array {
		$saved = get_transient( self::CACHE . 'status' );

		return is_array( $saved ) && md5( self::account() ) === ( $saved['account'] ?? '' ) ? $saved : null;
	}

	/**
	 * The metrics of a search of Google Ads: the volume, competition (0–100), cost per click (the
	 * average, else the middle of the bids at the top of the page), the volumes of the last twelve
	 * months from the oldest; no difficulty.
	 *
	 * @param array<string, mixed> $metrics keywordMetrics or keywordIdeaMetrics.
	 * @return array{volume: int|null, competition: int|null, cpc: float|null, trend: int[]|null, kd: null}
	 */
	public static function metrics( array $metrics ): array {
		$months = array();
		foreach ( is_array( $metrics['monthlySearchVolumes'] ?? null ) ? $metrics['monthlySearchVolumes'] : array() as $month ) {
			$number = is_array( $month ) ? array_search( $month['month'] ?? '', self::MONTHS, true ) : false;
			if ( false !== $number && is_numeric( $month['year'] ?? null ) ) {
				$months[ sprintf( '%04d-%02d', (int) $month['year'], $number + 1 ) ] = (int) ( $month['monthlySearches'] ?? 0 );
			}
		}
		ksort( $months );

		$micros = $metrics['averageCpcMicros'] ?? null;
		if ( ! is_numeric( $micros ) && is_numeric( $metrics['lowTopOfPageBidMicros'] ?? null ) && is_numeric( $metrics['highTopOfPageBidMicros'] ?? null ) ) {
			$micros = ( (float) $metrics['lowTopOfPageBidMicros'] + (float) $metrics['highTopOfPageBidMicros'] ) / 2;
		}

		return array(
			'volume'      => is_numeric( $metrics['avgMonthlySearches'] ?? null ) ? (int) $metrics['avgMonthlySearches'] : null,
			'competition' => is_numeric( $metrics['competitionIndex'] ?? null ) ? (int) $metrics['competitionIndex'] : null,
			'cpc'         => is_numeric( $micros ) ? round( (float) $micros / 1000000, 2 ) : null,
			'trend'       => $months ? array_values( array_slice( $months, -12 ) ) : null,
			'kd'          => null,
		);
	}

	/**
	 * What a planning request asks: the language, the country and the network of Google search.
	 *
	 * @return array<string, mixed>
	 */
	private static function target( string $language, string $country ): array {
		return array(
			'language'           => 'languageConstants/' . ( self::LANGUAGES[ $language ] ?? self::LANGUAGES['en'] ),
			'geoTargetConstants' => array( 'geoTargetConstants/' . ( Simple_SEO_DataForSEO::LOCATIONS[ $country ] ?? Simple_SEO_DataForSEO::LOCATIONS['US'] ) ),
			'keywordPlanNetwork' => 'GOOGLE_SEARCH',
		);
	}

	/**
	 * A planning request kept a month by what it asks: the answer, or why there is none (not kept).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|WP_Error
	 */
	private static function kept( string $method, array $body ) {
		$settings = Simple_SEO_Keywords::settings();
		$cache    = self::CACHE . md5( self::account() . '|' . $method . '|' . wp_json_encode( $body ) );
		$saved    = get_transient( $cache );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		$data = self::request( 'customers/' . $settings['gads_customer'] . ':' . $method, $body );
		if ( ! is_wp_error( $data ) ) {
			set_transient( $cache, $data, self::TTL );
		}

		return $data;
	}

	/**
	 * More searches of the phrase from the Keyword Planner, with their metrics.
	 *
	 * @return array<string, array<string, mixed>>|WP_Error keyword => metrics()
	 */
	public static function ideas( string $seed, string $language, string $country ) {
		$data = self::kept(
			'generateKeywordIdeas',
			array( 'keywordSeed' => array( 'keywords' => array( $seed ) ) ) + self::target( $language, $country ) + array( 'pageSize' => self::IDEAS )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$found = array();
		foreach ( is_array( $data['results'] ?? null ) ? $data['results'] : array() as $result ) {
			$keyword = is_array( $result ) && is_string( $result['text'] ?? null ) ? Simple_SEO_Keywords::clean( $result['text'] ) : '';
			if ( '' !== $keyword && ! isset( $found[ $keyword ] ) ) {
				$found[ $keyword ] = self::metrics( is_array( $result['keywordIdeaMetrics'] ?? null ) ? $result['keywordIdeaMetrics'] : array() );
			}
		}

		return $found;
	}

	/**
	 * The volumes of searches from the Keyword Planner, MAX at a time; close variants Google merges
	 * get the metrics of their search.
	 *
	 * @param string[] $keywords
	 * @return array<string, array<string, mixed>>|WP_Error keyword => metrics()
	 */
	public static function volumes( array $keywords, string $language, string $country ) {
		$keywords = array_values( array_filter( array_unique( $keywords ), array( Simple_SEO_DataForSEO::class, 'takes' ) ) );
		$found    = array();
		foreach ( array_chunk( $keywords, self::MAX ) as $chunk ) {
			sort( $chunk );
			$data = self::kept( 'generateKeywordHistoricalMetrics', array( 'keywords' => $chunk ) + self::target( $language, $country ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( is_array( $data['results'] ?? null ) ? $data['results'] : array() as $result ) {
				if ( ! is_array( $result ) || ! is_string( $result['text'] ?? null ) ) {
					continue;
				}
				$metrics = self::metrics( is_array( $result['keywordMetrics'] ?? null ) ? $result['keywordMetrics'] : array() );
				foreach ( array_merge( array( $result['text'] ), is_array( $result['closeVariants'] ?? null ) ? $result['closeVariants'] : array() ) as $variant ) {
					$keyword = is_string( $variant ) ? Simple_SEO_Keywords::clean( $variant ) : '';
					if ( '' !== $keyword && ! isset( $found[ $keyword ] ) ) {
						$found[ $keyword ] = $metrics;
					}
				}
			}
		}

		return $found;
	}
}
