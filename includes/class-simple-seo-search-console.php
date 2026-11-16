<?php
/**
 * Search Console (decisions/integrations): the data of Google Search Console in the admin — the
 * clicks, impressions, CTR and position of the site with its top queries and pages, on a page
 * under the SEO menu, and those of a post in its SEO box.
 *
 * Simple SEO reads them as a service account of Google: its JSON key is pasted on the Search
 * Console tab, and the account is added to the property in Search Console as a user. The token is
 * a JWT signed with the account's private key (RS256, openssl) and exchanged at Google for an
 * access token for an hour; no library of Google. The private key is kept encrypted with a key
 * made from the salts of the site (wp-config.php), so a copy of the database alone does not give
 * it away, and it is never shown again. Google's answers are cached for some hours: Search
 * Console updates its data once a day, two or three days late.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Search_Console {
	public const OPTION = 'simple_seo_search_console';

	/** The page of the data under the SEO menu. */
	public const PAGE = 'simple-seo-search-console';

	/** Read access to Search Console, nothing else. */
	public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	public const API = 'https://www.googleapis.com/webmasters/v3/';

	/** Transients: the token, the properties the account sees and the answers of Google. */
	public const CACHE = 'simple_seo_gsc_';

	/** How long an answer of Google is kept, in seconds (6 hours). */
	public const TTL = 21600;

	/** How long the list of properties is kept, in seconds: an account just added shows up soon. */
	public const SITES_TTL = 600;

	/** The periods of the page, in days; the first one of them by default is 28. */
	public const PERIODS = array( 7, 28, 90 );

	public const DEFAULT_DAYS = 28;

	/** Top queries and pages of the site shown. */
	public const ROWS = 25;

	/** Top queries of a post shown. */
	public const POST_ROWS = 10;

	/** Search Console counts days in Pacific time. */
	private const TIMEZONE = 'America/Los_Angeles';

	private static string $hook = '';

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'key'      => array(),
				'property' => '',
			),
			array( self::class, 'sanitize' ),
			false
		);

		add_action( 'rest_api_init', array( self::class, 'register_route' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
			// After the bulk editing (20): the menu lists the pages in the order they are added.
			add_action( 'admin_menu', array( self::class, 'add_menu' ), 21 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'search-console', __( 'Search Console', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 42 );
	}

	public static function add_menu(): void {
		$hook = add_submenu_page(
			Simple_SEO_Admin::PAGE,
			__( 'Search Console', 'simple-seo' ),
			__( 'Search Console', 'simple-seo' ),
			Simple_SEO_Access::CAP_REPORTS,
			self::PAGE,
			array( self::class, 'render_page' )
		);
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
		}
	}

	/** The admin page's hook suffix; '' before the menu is built. */
	public static function hook(): string {
		return self::$hook;
	}

	/*
	 * The key.
	 */

	/**
	 * The settings as the form sends them: a new key (the JSON file of the service account) or
	 * none, the box that removes the saved key, and the property. A key that cannot be used is
	 * refused with a message and the saved one stays.
	 *
	 * @param array<string, mixed> $input
	 * @return array{key: array<string, string>, property: string}
	 */
	public static function sanitize( array $input ): array {
		$old   = Simple_SEO_Settings::get( self::OPTION );
		$clean = array(
			'key'      => self::stored_key( $old['key'] ?? array() ),
			'property' => self::clean_property( $input['property'] ?? ( $old['property'] ?? '' ) ),
		);

		// The row as it is stored: add_option() sanitizes it once more after update_option() did.
		if ( isset( $input['key'] ) && is_array( $input['key'] ) ) {
			$clean['key'] = self::stored_key( $input['key'] );

			return $clean;
		}

		if ( ! empty( $input['forget'] ) ) {
			$clean['key'] = array();
		}

		$json = isset( $input['json'] ) && is_string( $input['json'] ) ? trim( $input['json'] ) : '';
		if ( '' !== $json ) {
			$key = self::read_key( $json );
			if ( is_wp_error( $key ) ) {
				add_settings_error( self::OPTION, $key->get_error_code(), $key->get_error_message() );
			} else {
				$clean['key'] = $key;
			}
		}

		return $clean;
	}

	/**
	 * The key as it is kept: the email and project of the account, the ID of the key and the
	 * private key encrypted; [] for anything else.
	 *
	 * @param mixed $key
	 * @return array<string, string>
	 */
	private static function stored_key( $key ): array {
		if ( ! is_array( $key ) || ! is_string( $key['email'] ?? null ) || ! is_string( $key['secret'] ?? null ) || '' === $key['secret'] || ! is_email( $key['email'] ) ) {
			return array();
		}

		return array(
			'email'   => $key['email'],
			'project' => is_string( $key['project'] ?? null ) ? sanitize_text_field( $key['project'] ) : '',
			'id'      => is_string( $key['id'] ?? null ) ? sanitize_text_field( $key['id'] ) : '',
			'secret'  => $key['secret'],
		);
	}

	/**
	 * Read the JSON key of a service account: the email of the account and its private key, which
	 * must be readable, encrypted for keeping.
	 *
	 * @return array<string, string>|WP_Error
	 */
	public static function read_key( string $json ) {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'simple_seo_gsc_openssl', __( 'The PHP of this server has no openssl extension: Simple SEO cannot sign in to Google without it.', 'simple-seo' ) );
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'simple_seo_gsc_json', __( 'The key is not JSON: paste the whole content of the file Google gave.', 'simple-seo' ) );
		}
		if ( 'service_account' !== ( $data['type'] ?? '' ) ) {
			return new WP_Error( 'simple_seo_gsc_type', __( 'This is not the key of a service account: create a key of the service account as a JSON file.', 'simple-seo' ) );
		}

		$email   = is_string( $data['client_email'] ?? null ) ? trim( $data['client_email'] ) : '';
		$private = is_string( $data['private_key'] ?? null ) ? $data['private_key'] : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'simple_seo_gsc_email', __( 'The key has no email of the service account (client_email).', 'simple-seo' ) );
		}
		if ( '' === $private || false === openssl_pkey_get_private( $private ) ) {
			self::clear_openssl_errors();
			return new WP_Error( 'simple_seo_gsc_private', __( 'The private key in the file cannot be read (private_key): create a new key and paste the file as it is.', 'simple-seo' ) );
		}

		$secret = self::encrypt( $private );
		if ( '' === $secret ) {
			return new WP_Error( 'simple_seo_gsc_encrypt', __( 'The key cannot be kept encrypted on this server.', 'simple-seo' ) );
		}

		return array(
			'email'   => $email,
			'project' => is_string( $data['project_id'] ?? null ) ? sanitize_text_field( $data['project_id'] ) : '',
			'id'      => is_string( $data['private_key_id'] ?? null ) ? sanitize_text_field( $data['private_key_id'] ) : '',
			'secret'  => $secret,
		);
	}

	/**
	 * A property of Search Console: a domain (sc-domain:example.com) or an address prefix ending
	 * with a slash; '' for anything else.
	 *
	 * @param mixed $value
	 */
	public static function clean_property( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( preg_match( '/^sc-domain:([a-z0-9][a-z0-9.\-]*\.[a-z0-9\-]+)$/i', $value, $match ) ) {
			return 'sc-domain:' . strtolower( $match[1] );
		}
		if ( preg_match( '#^https?://[a-z0-9.\-]+(?::\d+)?/[^\s"\'<>]*$#i', $value ) && str_ends_with( $value, '/' ) ) {
			return $value;
		}

		return '';
	}

	/** Whether a key is saved. */
	public static function connected(): bool {
		return array() !== self::stored_key( Simple_SEO_Settings::value( self::OPTION, 'key' ) );
	}

	/** The email of the service account; '' without a key. */
	public static function account(): string {
		return self::stored_key( Simple_SEO_Settings::value( self::OPTION, 'key' ) )['email'] ?? '';
	}

	/** What changes with the key: a new key gets a new token and new answers. */
	private static function fingerprint(): string {
		$key = self::stored_key( Simple_SEO_Settings::value( self::OPTION, 'key' ) );

		return md5( implode( '|', $key ) );
	}

	/*
	 * Encryption of what is kept: AES-256-GCM with a key made from the salts of the site.
	 */

	private static function cipher_key(): string {
		return hash_hmac( 'sha256', 'simple-seo-search-console', wp_salt( 'secure_auth' ), true );
	}

	public static function encrypt( string $text ): string {
		$iv   = random_bytes( 12 );
		$tag  = '';
		$data = openssl_encrypt( $text, 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, $iv, $tag );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary kept as text.
		return false === $data ? '' : base64_encode( $iv . $tag . $data );
	}

	/** The text, or '' when it cannot be decrypted (other salts, a changed value). */
	public static function decrypt( string $secret ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary kept as text.
		$raw = base64_decode( $secret, true );
		if ( false === $raw || strlen( $raw ) <= 28 ) {
			return '';
		}

		$text = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

		return false === $text ? '' : $text;
	}

	private static function clear_openssl_errors(): void {
		while ( false !== openssl_error_string() ) {
			// Read out, so the next openssl call does not report them.
		}
	}

	/*
	 * Google.
	 */

	/**
	 * A JWT signed with RS256.
	 *
	 * @param array<string, mixed> $claims
	 * @return string|WP_Error
	 */
	public static function jwt( array $claims, string $private_key, string $key_id = '' ) {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		if ( '' !== $key_id ) {
			$header['kid'] = $key_id;
		}

		$input     = self::base64url( (string) wp_json_encode( $header, JSON_UNESCAPED_SLASHES ) ) . '.' . self::base64url( (string) wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
		$signature = '';
		$pkey      = openssl_pkey_get_private( $private_key );
		if ( false === $pkey || ! openssl_sign( $input, $signature, $pkey, OPENSSL_ALGO_SHA256 ) ) {
			self::clear_openssl_errors();
			return new WP_Error( 'simple_seo_gsc_sign', __( 'The private key cannot sign the request to Google: paste the key again.', 'simple-seo' ) );
		}

		return $input . '.' . self::base64url( $signature );
	}

	private static function base64url( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding of a JWT.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * An access token of the service account: kept (encrypted) until shortly before it ends.
	 *
	 * @return string|WP_Error
	 */
	public static function token() {
		$key = self::stored_key( Simple_SEO_Settings::value( self::OPTION, 'key' ) );
		if ( ! $key ) {
			return new WP_Error( 'simple_seo_gsc_no_key', __( 'Search Console is not connected.', 'simple-seo' ) );
		}

		$cache = self::CACHE . 'token';
		$saved = get_transient( $cache );
		if ( is_array( $saved ) && self::fingerprint() === ( $saved['for'] ?? '' ) ) {
			$token = self::decrypt( (string) ( $saved['token'] ?? '' ) );
			if ( '' !== $token ) {
				return $token;
			}
		}

		$private = self::decrypt( $key['secret'] );
		if ( '' === $private ) {
			return new WP_Error( 'simple_seo_gsc_secret', __( 'The saved key cannot be read: the keys and salts of the site in wp-config.php have changed since. Paste the key again.', 'simple-seo' ) );
		}

		$now = time();
		$jwt = self::jwt(
			array(
				'iss'   => $key['email'],
				'scope' => self::SCOPE,
				'aud'   => self::TOKEN_URL,
				'iat'   => $now,
				'exp'   => $now + HOUR_IN_SECONDS,
			),
			$private,
			$key['id']
		);
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
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

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || ! is_string( $data['access_token'] ?? null ) || '' === $data['access_token'] ) {
			$why = is_array( $data ) ? (string) ( $data['error_description'] ?? ( $data['error'] ?? '' ) ) : '';

			return new WP_Error(
				'simple_seo_gsc_token',
				/* translators: %s: what Google answered. */
				sprintf( __( 'Google did not let the service account in (%s): check that its key is not deleted in Google Cloud, or paste a new one.', 'simple-seo' ), '' !== $why ? $why : (string) $code )
			);
		}

		set_transient(
			$cache,
			array(
				'for'   => self::fingerprint(),
				'token' => self::encrypt( $data['access_token'] ),
			),
			max( 60, (int) ( $data['expires_in'] ?? HOUR_IN_SECONDS ) - 120 )
		);

		return $data['access_token'];
	}

	/**
	 * A request to the API of Search Console.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|WP_Error
	 */
	public static function api( string $method, string $path, ?array $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
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
			// The token was taken back: the next request asks for a new one.
			delete_transient( self::CACHE . 'token' );
		}
		$message = is_array( $data ) && is_array( $data['error'] ?? null ) ? (string) ( $data['error']['message'] ?? '' ) : '';

		return new WP_Error( 'simple_seo_gsc_' . $code, self::explain( $code, $message ), array( 'status' => $code ) );
	}

	/** What an answer of Search Console that is no data means, and what to do. */
	private static function explain( int $code, string $message ): string {
		if ( 403 === $code && preg_match( '/has not been used|is disabled|SERVICE_DISABLED/i', $message ) ) {
			return __( 'The Google Search Console API is off in the project of the service account: turn it on in Google Cloud (APIs and services, Library), wait a few minutes and open this page again.', 'simple-seo' );
		}
		if ( 403 === $code ) {
			/* translators: %s: email of the service account. */
			return sprintf( __( 'The service account has no access to the property: in Search Console, add %s as a user of the property (Settings, Users and permissions; Restricted is enough).', 'simple-seo' ), self::account() );
		}
		if ( 404 === $code ) {
			return __( 'Search Console has no such property: choose another one on the Search Console tab of the settings.', 'simple-seo' );
		}
		if ( 429 === $code ) {
			return __( 'Google asks to wait: too many requests. Open this page again in a few minutes.', 'simple-seo' );
		}

		/* translators: 1: HTTP status, 2: what Google said. */
		return sprintf( __( 'Search Console answered %1$s: %2$s', 'simple-seo' ), (string) $code, '' !== $message ? $message : '—' );
	}

	private static function network_error( WP_Error $error ): WP_Error {
		/* translators: %s: the error. */
		return new WP_Error( 'simple_seo_gsc_network', sprintf( __( 'Google cannot be reached from this server: %s', 'simple-seo' ), $error->get_error_message() ) );
	}

	/**
	 * The properties the service account sees, with its permission in each.
	 *
	 * @return array<string, string>|WP_Error property => permission level
	 */
	public static function sites() {
		$cache = self::CACHE . 'sites_' . self::fingerprint();
		$saved = get_transient( $cache );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		$data = self::api( 'GET', 'sites' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$sites = array();
		foreach ( (array) ( $data['siteEntry'] ?? array() ) as $entry ) {
			$property   = self::clean_property( is_array( $entry ) ? ( $entry['siteUrl'] ?? '' ) : '' );
			$permission = is_array( $entry ) && is_string( $entry['permissionLevel'] ?? null ) ? $entry['permissionLevel'] : '';
			if ( '' !== $property && 'siteUnverifiedUser' !== $permission ) {
				$sites[ $property ] = $permission;
			}
		}
		ksort( $sites );
		// No list kept while it is empty: the account is about to be added somewhere.
		if ( $sites ) {
			set_transient( $cache, $sites, self::SITES_TTL );
		}

		return $sites;
	}

	/**
	 * The property of this site among some: its domain, else the address prefix of its home (with
	 * or without www, http or https), else the longest prefix the home is under; '' when none.
	 *
	 * @param string[] $properties
	 */
	public static function match( array $properties ): string {
		$home = (string) get_option( 'home' );
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		$bare = preg_replace( '/^www\./', '', $host );

		foreach ( array( $bare, 'www.' . $bare ) as $domain ) {
			if ( in_array( 'sc-domain:' . $domain, $properties, true ) ) {
				return 'sc-domain:' . $domain;
			}
		}

		$found = '';
		$home  = trailingslashit( strtolower( preg_replace( '#^https?://(www\.)?#i', '', $home ) ) );
		foreach ( $properties as $property ) {
			if ( str_starts_with( $property, 'sc-domain:' ) ) {
				continue;
			}
			$prefix = strtolower( (string) preg_replace( '#^https?://(www\.)?#i', '', $property ) );
			if ( str_starts_with( $home, $prefix ) && strlen( $property ) > strlen( $found ) ) {
				$found = $property;
			}
		}

		return $found;
	}

	/**
	 * The property the data comes from: the one chosen on the tab, else the one of this site
	 * among those the account sees.
	 *
	 * @return string|WP_Error
	 */
	public static function property() {
		$saved = (string) Simple_SEO_Settings::value( self::OPTION, 'property' );
		if ( '' !== $saved ) {
			return $saved;
		}

		$sites = self::sites();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}

		$property = self::match( array_keys( $sites ) );
		if ( '' === $property ) {
			return new WP_Error(
				'simple_seo_gsc_no_property',
				/* translators: %s: email of the service account. */
				sprintf( __( 'The service account sees no property of this site: in Search Console, add %s as a user of the property of this site, or choose a property on the Search Console tab of the settings.', 'simple-seo' ), self::account() )
			);
		}

		return $property;
	}

	/**
	 * Rows of the search analytics of a property: the values of the dimensions asked for, the
	 * clicks, the impressions and the average position; kept for some hours.
	 *
	 * @param array<string, mixed> $request The body of searchAnalytics.query.
	 * @return array<int, array{keys: string[], clicks: int, impressions: int, position: float}>|WP_Error
	 */
	public static function query( string $property, array $request ) {
		$cache = self::CACHE . md5( self::fingerprint() . '|' . $property . '|' . wp_json_encode( $request ) );
		$saved = get_transient( $cache );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		$data = self::api( 'POST', 'sites/' . rawurlencode( $property ) . '/searchAnalytics/query', $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$rows = array();
		foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'keys'        => array_map( 'strval', array_filter( (array) ( $row['keys'] ?? array() ), 'is_scalar' ) ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}
		set_transient( $cache, $rows, self::TTL );

		return $rows;
	}

	/*
	 * Reports.
	 */

	/** A period of the page: one of PERIODS. */
	public static function period( $days ): int {
		$days = is_numeric( $days ) ? (int) $days : 0;

		return in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * The days of a property with data, enough for two periods: the last day Search Console has
	 * data of is the end of the period.
	 *
	 * @return array<string, array{keys: string[], clicks: int, impressions: int, position: float}>|WP_Error date => row
	 */
	private static function days( string $property, int $days ) {
		$today = new DateTimeImmutable( 'now', new DateTimeZone( self::TIMEZONE ) );
		$rows  = self::query(
			$property,
			array(
				'startDate'  => $today->modify( '-' . ( 2 * $days + 7 ) . ' days' )->format( 'Y-m-d' ),
				'endDate'    => $today->format( 'Y-m-d' ),
				'dimensions' => array( 'date' ),
				'rowLimit'   => 1000,
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$by_date = array();
		foreach ( $rows as $row ) {
			$date = (string) ( $row['keys'][0] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$by_date[ $date ] = $row;
			}
		}
		ksort( $by_date );

		return $by_date;
	}

	/**
	 * A period of some days ending on a day, and the one before it.
	 *
	 * @return array{start: string, end: string, previous_start: string, previous_end: string}
	 */
	public static function window( string $end, int $days ): array {
		$last = new DateTimeImmutable( $end, new DateTimeZone( 'UTC' ) );

		return array(
			'start'          => $last->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ),
			'end'            => $last->format( 'Y-m-d' ),
			'previous_start' => $last->modify( '-' . ( 2 * $days - 1 ) . ' days' )->format( 'Y-m-d' ),
			'previous_end'   => $last->modify( '-' . $days . ' days' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * Clicks, impressions, CTR and average position of rows: the position weighted by the
	 * impressions, as Search Console averages it.
	 *
	 * @param array<int|string, array{clicks: int, impressions: int, position: float}> $rows
	 * @return array{clicks: int, impressions: int, ctr: float, position: float}
	 */
	public static function totals( array $rows ): array {
		$clicks      = 0;
		$impressions = 0;
		$weighted    = 0.0;
		foreach ( $rows as $row ) {
			$clicks      += (int) $row['clicks'];
			$impressions += (int) $row['impressions'];
			$weighted    += (float) $row['position'] * (int) $row['impressions'];
		}

		return array(
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions > 0 ? $clicks / $impressions : 0.0,
			'position'    => $impressions > 0 ? $weighted / $impressions : 0.0,
		);
	}

	/**
	 * The data of the site for a period: the totals of the period and of the one before it, the
	 * top queries and the top pages; with no rows when Search Console has no data yet.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function site_report( int $days ) {
		$days     = self::period( $days );
		$property = self::property();
		if ( is_wp_error( $property ) ) {
			return $property;
		}

		$by_date = self::days( $property, $days );
		if ( is_wp_error( $by_date ) ) {
			return $by_date;
		}
		$report = array(
			'property' => $property,
			'days'     => $days,
			'window'   => array(),
			'current'  => self::totals( array() ),
			'previous' => self::totals( array() ),
			'queries'  => array(),
			'pages'    => array(),
		);
		if ( ! $by_date ) {
			return $report;
		}

		$window             = self::window( (string) array_key_last( $by_date ), $days );
		$report['window']   = $window;
		$report['current']  = self::totals( array_filter( $by_date, static fn( string $date ): bool => $date >= $window['start'] && $date <= $window['end'], ARRAY_FILTER_USE_KEY ) );
		$report['previous'] = self::totals( array_filter( $by_date, static fn( string $date ): bool => $date >= $window['previous_start'] && $date <= $window['previous_end'], ARRAY_FILTER_USE_KEY ) );

		foreach ( array( 'queries' => 'query', 'pages' => 'page' ) as $part => $dimension ) {
			$rows = self::query(
				$property,
				array(
					'startDate'  => $window['start'],
					'endDate'    => $window['end'],
					'dimensions' => array( $dimension ),
					'rowLimit'   => self::ROWS,
				)
			);
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$report[ $part ] = $rows;
		}

		return $report;
	}

	/**
	 * The address Search Console counts a post under: its own (wp-loc gives each post the address
	 * of its language, whatever language is on); '' while it is not published.
	 */
	public static function post_url( WP_Post $post ): string {
		return 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
	}

	/**
	 * The data of a post for the default period: its totals and top queries.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function post_report( WP_Post $post ) {
		$url = self::post_url( $post );
		if ( '' === $url ) {
			return new WP_Error( 'simple_seo_gsc_unpublished', __( 'Search Console has data of published pages only.', 'simple-seo' ) );
		}

		$property = self::property();
		if ( is_wp_error( $property ) ) {
			return $property;
		}
		$by_date = self::days( $property, self::DEFAULT_DAYS );
		if ( is_wp_error( $by_date ) ) {
			return $by_date;
		}

		$report = array(
			'url'     => $url,
			'window'  => array(),
			'totals'  => self::totals( array() ),
			'queries' => array(),
		);
		if ( ! $by_date ) {
			return $report;
		}

		$window = self::window( (string) array_key_last( $by_date ), self::DEFAULT_DAYS );
		$filter = array(
			array(
				'filters' => array(
					array(
						'dimension'  => 'page',
						'operator'   => 'equals',
						'expression' => $url,
					),
				),
			),
		);
		$base   = array(
			'startDate'             => $window['start'],
			'endDate'               => $window['end'],
			'dimensionFilterGroups' => $filter,
		);

		$page = self::query( $property, $base + array( 'dimensions' => array( 'page' ), 'rowLimit' => 1 ) );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$queries = self::query( $property, $base + array( 'dimensions' => array( 'query' ), 'rowLimit' => self::POST_ROWS ) );
		if ( is_wp_error( $queries ) ) {
			return $queries;
		}

		$report['window']  = $window;
		$report['totals']  = self::totals( $page );
		$report['queries'] = $queries;

		return $report;
	}

	/*
	 * The SEO box of a post.
	 */

	public static function register_route(): void {
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/search-console/post/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_post' ),
				'permission_callback' => static fn( WP_REST_Request $request ): bool => self::can_see_post( (int) $request['id'] ),
			)
		);
	}

	/** Whoever sees the data of Search Console and edits the post sees the post's. */
	public static function can_see_post( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof WP_Post
			&& in_array( $post->post_type, Simple_SEO_Fields::post_types(), true )
			&& current_user_can( 'edit_post', $post_id )
			&& current_user_can( Simple_SEO_Access::CAP_REPORTS );
	}

	/** @return array{html: string} */
	public static function rest_post( WP_REST_Request $request ): array {
		return array( 'html' => self::render_post_report( get_post( (int) $request['id'] ) ) );
	}

	/**
	 * The part of the SEO box: a place the editor fills from the REST route, so Google's answer
	 * never holds up the editor; nothing when Search Console is not connected, the post is not
	 * published or the user does not see the data.
	 */
	public static function meta_box_part( WP_Post $post ): string {
		if ( ! self::connected() || '' === self::post_url( $post ) || ! self::can_see_post( (int) $post->ID ) ) {
			return '';
		}

		$data = array(
			'url'    => rest_url( Simple_SEO_Analysis::NAMESPACE . '/search-console/post/' . (int) $post->ID ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'failed' => __( 'The data of Search Console cannot be loaded now.', 'simple-seo' ),
		);

		return sprintf(
			'<div class="simple-seo-gsc" data-simple-seo-gsc="%1$s"><h4>%2$s</h4><div class="simple-seo-gsc-body"><p class="description">%3$s</p></div></div>',
			esc_attr( (string) wp_json_encode( $data ) ),
			esc_html__( 'Search Console', 'simple-seo' ),
			esc_html__( 'Loading the data of Search Console…', 'simple-seo' )
		);
	}

	/** The data of a post as the SEO box shows it, escaped. */
	public static function render_post_report( WP_Post $post ): string {
		$report = self::post_report( $post );
		if ( is_wp_error( $report ) ) {
			return sprintf( '<p class="simple-seo-gsc-error">%s</p>', esc_html( $report->get_error_message() ) );
		}
		if ( ! $report['window'] ) {
			return sprintf( '<p class="description">%s</p>', esc_html__( 'Search Console has no data of the site yet.', 'simple-seo' ) );
		}

		$totals = $report['totals'];
		$html   = sprintf(
			'<p class="description">%s</p>',
			/* translators: 1: first day, 2: last day. */
			esc_html( sprintf( __( 'Google search, %1$s – %2$s:', 'simple-seo' ), self::date( $report['window']['start'] ), self::date( $report['window']['end'] ) ) )
		);
		$html  .= self::render_totals( $totals );

		if ( ! $report['queries'] ) {
			$html .= sprintf( '<p class="description">%s</p>', esc_html__( 'No queries of this page in these days: Google shows it to no one yet, or leaves out its rare queries.', 'simple-seo' ) );
			return $html;
		}

		return $html . self::render_table( $report['queries'], __( 'Query', 'simple-seo' ) );
	}

	/**
	 * Clicks, impressions, CTR and position; each with how it changed since the period before,
	 * when there is one.
	 *
	 * @param array{clicks: int, impressions: int, ctr: float, position: float}      $totals
	 * @param array{clicks: int, impressions: int, ctr: float, position: float}|null $previous
	 */
	public static function render_totals( array $totals, ?array $previous = null ): string {
		$items = array(
			'clicks'      => array( __( 'Clicks', 'simple-seo' ), number_format_i18n( $totals['clicks'] ) ),
			'impressions' => array( __( 'Impressions', 'simple-seo' ), number_format_i18n( $totals['impressions'] ) ),
			'ctr'         => array( __( 'CTR', 'simple-seo' ), self::percent( $totals['ctr'] ) ),
			'position'    => array( __( 'Average position', 'simple-seo' ), $totals['impressions'] > 0 ? number_format_i18n( $totals['position'], 1 ) : '—' ),
		);

		$html = '<dl class="simple-seo-gsc-totals">';
		foreach ( $items as $key => $item ) {
			$change = null !== $previous ? self::change( $key, $totals, $previous ) : null;
			$html  .= sprintf(
				'<div><dt>%1$s</dt><dd><strong>%2$s</strong>%3$s</dd></div>',
				esc_html( $item[0] ),
				esc_html( $item[1] ),
				$change ? sprintf( ' <span class="simple-seo-gsc-change is-%1$s">%2$s</span>', esc_attr( $change['rating'] ), esc_html( $change['text'] ) ) : ''
			);
		}

		return $html . '</dl>';
	}

	/**
	 * How a value changed since the period before: a share for clicks, impressions and CTR, a
	 * difference for the position, where less is better; null when there is nothing to compare.
	 *
	 * @param array{clicks: int, impressions: int, ctr: float, position: float} $now
	 * @param array{clicks: int, impressions: int, ctr: float, position: float} $before
	 * @return array{text: string, rating: string}|null
	 */
	public static function change( string $key, array $now, array $before ): ?array {
		if ( 'position' === $key ) {
			if ( $now['impressions'] <= 0 || $before['impressions'] <= 0 ) {
				return null;
			}
			$difference = round( $now['position'] - $before['position'], 1 );

			return array(
				'text'   => ( $difference > 0 ? '+' : ( $difference < 0 ? '−' : '' ) ) . number_format_i18n( abs( $difference ), 1 ),
				'rating' => $difference < 0 ? 'good' : ( $difference > 0 ? 'bad' : 'same' ),
			);
		}

		$was = (float) $before[ $key ];
		if ( $was <= 0 ) {
			return null;
		}
		$share = (int) round( ( (float) $now[ $key ] - $was ) / $was * 100 );

		return array(
			'text'   => ( $share > 0 ? '+' : ( $share < 0 ? '−' : '' ) ) . number_format_i18n( abs( $share ) ) . '%',
			'rating' => $share > 0 ? 'good' : ( $share < 0 ? 'bad' : 'same' ),
		);
	}

	/**
	 * A table of rows: what the first column holds (a query, or a page linked), then clicks,
	 * impressions, CTR and position; the language of each page with wp-loc.
	 *
	 * @param array<int, array{keys: string[], clicks: int, impressions: int, position: float}> $rows
	 */
	public static function render_table( array $rows, string $heading, bool $pages = false ): string {
		$languages = $pages ? Simple_SEO_WP_Loc::languages() : array();

		$columns = array( __( 'Clicks', 'simple-seo' ), __( 'Impressions', 'simple-seo' ), __( 'CTR', 'simple-seo' ), __( 'Position', 'simple-seo' ) );

		$html  = '<table class="widefat striped simple-seo-gsc-table"><thead><tr>';
		$html .= sprintf( '<th scope="col">%s</th>', esc_html( $heading ) );
		if ( $languages ) {
			$html .= sprintf( '<th scope="col" class="simple-seo-gsc-language">%s</th>', esc_html__( 'Language', 'simple-seo' ) );
		}
		foreach ( $columns as $column ) {
			$html .= sprintf( '<th scope="col" class="num">%s</th>', esc_html( $column ) );
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$key   = (string) ( $row['keys'][0] ?? '' );
			$first = $pages
				? sprintf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $key ), esc_html( self::path( $key ) ) )
				: esc_html( $key );
			$ctr   = $row['impressions'] > 0 ? $row['clicks'] / $row['impressions'] : 0.0;

			$html .= '<tr><td>' . $first . '</td>';
			if ( $languages ) {
				$html .= '<td class="simple-seo-gsc-language">' . esc_html( self::language_of( $key ) ) . '</td>';
			}
			// On a phone a row is a block: the query or page, then the numbers, each named.
			foreach ( array( number_format_i18n( $row['clicks'] ), number_format_i18n( $row['impressions'] ), self::percent( $ctr ), number_format_i18n( $row['position'], 1 ) ) as $index => $value ) {
				$html .= sprintf( '<td class="num" data-label="%1$s">%2$s</td>', esc_attr( $columns[ $index ] ), esc_html( $value ) );
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/** A share as a percent with one decimal. */
	private static function percent( float $share ): string {
		return number_format_i18n( $share * 100, 1 ) . '%';
	}

	/** A day of Search Console (Y-m-d) in the date format of the site. */
	public static function date( string $day ): string {
		$time = strtotime( $day . ' 12:00:00 UTC' );

		return false === $time ? $day : (string) wp_date( (string) get_option( 'date_format' ), $time, new DateTimeZone( 'UTC' ) );
	}

	/** An address of this site as its path, readable; other addresses whole. */
	public static function path( string $url ): string {
		$home = strtolower( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || $host !== $home ) {
			return rawurldecode( $url );
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return rawurldecode( ( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' ) );
	}

	/**
	 * The language of an address of this site with wp-loc: the one whose home it is under, the
	 * longest home first (the default language may have none of its own); '' without wp-loc.
	 */
	public static function language_of( string $url ): string {
		$found  = '';
		$length = 0;
		foreach ( array_keys( Simple_SEO_WP_Loc::languages() ) as $code ) {
			$home = trailingslashit( (string) apply_filters( 'wpml_home_url', '', $code ) );
			if ( '/' !== $home && str_starts_with( trailingslashit( $url ), $home ) && strlen( $home ) > $length ) {
				$found  = (string) $code;
				$length = strlen( $home );
			}
		}

		return $found;
	}

	/*
	 * The page and the tab.
	 */

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		$days      = self::period( isset( $_GET['days'] ) ? sanitize_text_field( wp_unslash( $_GET['days'] ) ) : self::DEFAULT_DAYS ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$connected = self::connected();
		$report    = $connected ? self::site_report( $days ) : null;

		require SIMPLE_SEO_DIR . 'includes/views/search-console.php';
	}

	/** The address of the page for a period. */
	public static function page_url( int $days = self::DEFAULT_DAYS ): string {
		$args = array( 'page' => self::PAGE );
		if ( self::DEFAULT_DAYS !== $days ) {
			$args['days'] = $days;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render_tab(): void {
		$settings  = Simple_SEO_Settings::get( self::OPTION );
		$key       = self::stored_key( $settings['key'] ?? array() );
		$sites     = $key ? self::sites() : array();
		$automatic = is_array( $sites ) ? self::match( array_keys( $sites ) ) : '';

		require SIMPLE_SEO_DIR . 'includes/views/tab-search-console.php';
	}
}
