<?php
/**
 * Keyword research: the searches around a phrase, for choosing keyphrases and planning articles.
 *
 * Sources, each turned on in the settings:
 *   - the suggestions of Google search (its autocomplete): the phrase, then the phrase with each
 *     letter of the alphabet, with question words before it and prepositions after it — what
 *     people type, free and without a key;
 *   - Search Console, when it is connected: the queries this site was shown for with the words of
 *     the phrase, and its impressions, clicks and position for each idea;
 *   - the AI connected in Settings → Connectors: topics of the ideas and the structure of an
 *     article. It groups and plans only: no number comes from it.
 *
 * The page is under the SEO menu; the SEO block of a post has a button by the focus keyphrase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Keywords {
	public const OPTION = 'simple_seo_keywords';

	public const PAGE = 'simple-seo-keywords';

	/** Prefix of the transients of suggestions. */
	public const CACHE = 'simple_seo_kw_';

	/** Suggestions change slowly: a phrase is asked of Google once a week. */
	public const TTL = WEEK_IN_SECONDS;

	public const SUGGEST_URL = 'https://suggestqueries.google.com/complete/search';

	/** Characters of a phrase at most. */
	public const MAX_SEED = 80;

	/** Requests to Google at once. */
	public const BATCH = 8;

	/** Ideas the AI is given at most. */
	public const MAX_AI = 300;

	/** Ideas the SEO block shows. */
	public const EDITOR_IDEAS = 30;

	/** Searches a topic of the words has at least. */
	public const MIN_TOPIC = 3;

	/** Days of Search Console read. */
	public const GSC_DAYS = 90;

	/**
	 * The languages of the suggestions: the name in English (for the AI), the letters a word
	 * starts with, question words (asked before the phrase; a question has one of them), auxiliary
	 * words that make a question only at its start, and prepositions (asked after the phrase).
	 */
	private const LANGUAGES = array(
		'uk' => array(
			'name'      => 'Ukrainian',
			'letters'   => 'а б в г д е є ж з і к л м н о п р с т у ф х ц ч ш щ ю я',
			'questions' => 'як що чи чому де коли скільки який яка яке які навіщо хто чим куди звідки',
			'starts'    => 'можна',
			'after'     => 'для від при з без або в на',
		),
		'ru' => array(
			'name'      => 'Russian',
			'letters'   => 'а б в г д е ж з и к л м н о п р с т у ф х ц ч ш щ э ю я',
			'questions' => 'как что почему где когда сколько какой какая какое какие зачем кто чем куда откуда ли',
			'starts'    => 'можно',
			'after'     => 'для от при с без или в на',
		),
		'en' => array(
			'name'      => 'English',
			'letters'   => 'a b c d e f g h i j k l m n o p q r s t u v w x y z',
			'questions' => 'how what why where when which who whom whose',
			'starts'    => 'can does do is are should will could would',
			'after'     => 'for with without vs near or to',
		),
		'pl' => array(
			'name'      => 'Polish',
			'letters'   => 'a b c d e f g h i j k l m n o p r s t u w z',
			'questions' => 'jak co czy dlaczego gdzie kiedy ile jaki jaka jakie kto',
			'starts'    => 'można',
			'after'     => 'dla na z bez czy w do',
		),
		'de' => array(
			'name'      => 'German',
			'letters'   => 'a b c d e f g h i j k l m n o p r s t u v w z',
			'questions' => 'wie was warum wo wann welche welcher welches wer wieso weshalb',
			'starts'    => 'kann ist sind',
			'after'     => 'für mit ohne oder gegen bei in',
		),
		'fr' => array(
			'name'      => 'French',
			'letters'   => 'a b c d e f g h i j l m n o p q r s t u v',
			'questions' => 'comment pourquoi où quand quel quelle quels quelles qui combien',
			'starts'    => 'est peut',
			'after'     => 'pour avec sans ou en à',
		),
		'es' => array(
			'name'      => 'Spanish',
			'letters'   => 'a b c d e f g h i j l m n o p q r s t u v',
			'questions' => 'cómo como qué que por quién dónde cuándo cuál cuánto',
			'starts'    => 'es puede',
			'after'     => 'para con sin o en de',
		),
		'it' => array(
			'name'      => 'Italian',
			'letters'   => 'a b c d e f g i l m n o p q r s t u v z',
			'questions' => 'come cosa perché dove quando quale quali chi quanto',
			'starts'    => 'è può',
			'after'     => 'per con senza o in da',
		),
	);

	/** Short words that name no topic, beside the question words and prepositions of a language. */
	private const STOP = 'і й та а це не ні так його її їх чого таке такий такі можна треба буде є чего такое это нет and the of in on at by an a or it is to de la le el il di und der die das';

	/** The country a language is searched in when the site does not say. */
	private const COUNTRY_OF = array(
		'uk' => 'UA',
		'ru' => 'UA',
		'en' => 'US',
		'pl' => 'PL',
		'de' => 'DE',
		'fr' => 'FR',
		'es' => 'ES',
		'it' => 'IT',
	);

	public const COUNTRIES = array( 'UA', 'US', 'GB', 'CA', 'AU', 'IE', 'PL', 'DE', 'AT', 'CH', 'FR', 'ES', 'IT', 'CZ', 'MD', 'LT', 'LV', 'EE', 'KZ' );

	private static string $hook = '';

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'suggest'        => true,
				'search_console' => true,
				'ai'             => true,
				'language'       => '',
				'country'        => '',
				'volumes'        => '',
				'dfs_login'      => '',
				'dfs_secret'     => '',
				'dfs_sandbox'    => false,
				'trends'         => true,
				'gads_customer'  => '',
				'gads_login'     => '',
				'gads_key'       => array(),
				'gads_use_gsc'   => true,
			),
			array( self::class, 'sanitize' ),
			false
		);

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		// A login saved: its balance asked at once, so the tab shows whether it works.
		add_action( 'add_option_' . self::OPTION, array( Simple_SEO_DataForSEO::class, 'check' ) );
		add_action( 'update_option_' . self::OPTION, array( Simple_SEO_DataForSEO::class, 'check' ) );
		add_action( 'add_option_' . self::OPTION, array( Simple_SEO_Google_Ads::class, 'check' ) );
		add_action( 'update_option_' . self::OPTION, array( Simple_SEO_Google_Ads::class, 'check' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
			// After Search Console (21).
			add_action( 'admin_menu', array( self::class, 'add_menu' ), 22 );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		}
	}

	/*
	 * Settings.
	 */

	/**
	 * The settings as the form sends them or as they are kept. The password of DataForSEO comes
	 * from the form as dfs_password and is kept encrypted as dfs_secret: an empty field keeps the
	 * saved one, the box dfs_forget removes it. The key of a service account for Google Ads comes
	 * as gads_json and is kept as gads_key, as Search Console keeps its own (or that one is used).
	 *
	 * @param array<string, mixed> $input
	 * @return array{suggest: bool, search_console: bool, ai: bool, language: string, country: string, volumes: string, dfs_login: string, dfs_secret: string, dfs_sandbox: bool, trends: bool, gads_customer: string, gads_login: string, gads_key: array<string, string>, gads_use_gsc: bool}
	 */
	public static function sanitize( array $input ): array {
		$language = is_string( $input['language'] ?? null ) ? $input['language'] : '';
		$country  = is_string( $input['country'] ?? null ) ? strtoupper( $input['country'] ) : '';
		$volumes  = is_string( $input['volumes'] ?? null ) ? $input['volumes'] : '';
		$password = is_string( $input['dfs_password'] ?? null ) ? trim( $input['dfs_password'] ) : '';
		$secret   = $input['dfs_secret'] ?? ( Simple_SEO_Settings::get( self::OPTION )['dfs_secret'] ?? '' );
		if ( '' !== $password ) {
			$secret = Simple_SEO_Search_Console::encrypt( $password );
		} elseif ( ! empty( $input['dfs_forget'] ) ) {
			$secret = '';
		}

		$key  = $input['gads_key'] ?? ( Simple_SEO_Settings::get( self::OPTION )['gads_key'] ?? array() );
		$json = is_string( $input['gads_json'] ?? null ) ? trim( $input['gads_json'] ) : '';
		if ( '' !== $json ) {
			$read = Simple_SEO_Search_Console::read_key( $json );
			if ( is_wp_error( $read ) ) {
				add_settings_error( self::OPTION, $read->get_error_code(), $read->get_error_message() );
			} else {
				$key = $read;
			}
		} elseif ( ! empty( $input['gads_forget'] ) ) {
			$key = array();
		}

		return array(
			'suggest'        => self::ticked( $input['suggest'] ?? true ),
			'search_console' => self::ticked( $input['search_console'] ?? true ),
			'ai'             => self::ticked( $input['ai'] ?? true ),
			'language'       => isset( self::LANGUAGES[ $language ] ) ? $language : '',
			'country'        => in_array( $country, self::COUNTRIES, true ) ? $country : '',
			'volumes'        => in_array( $volumes, array( '', 'dataforseo', 'google_ads' ), true ) ? $volumes : '',
			'dfs_login'      => is_string( $input['dfs_login'] ?? null ) ? sanitize_text_field( trim( $input['dfs_login'] ) ) : '',
			'dfs_secret'     => is_string( $secret ) ? $secret : '',
			'dfs_sandbox'    => self::ticked( $input['dfs_sandbox'] ?? false ),
			'trends'         => self::ticked( $input['trends'] ?? true ),
			'gads_customer'  => self::customer( $input['gads_customer'] ?? '' ),
			'gads_login'     => self::customer( $input['gads_login'] ?? '' ),
			'gads_key'       => is_array( $key ) && is_string( $key['email'] ?? null ) && is_string( $key['secret'] ?? null ) ? array_map( 'strval', array_intersect_key( $key, array_flip( array( 'email', 'project', 'id', 'secret' ) ) ) ) : array(),
			'gads_use_gsc'   => self::ticked( $input['gads_use_gsc'] ?? true ),
		);
	}

	/** The ID of a Google Ads account: its ten digits, without the hyphens; '' for anything else. */
	private static function customer( $value ): string {
		$digits = is_string( $value ) ? (string) preg_replace( '/[\s-]/', '', $value ) : '';

		return 1 === preg_match( '/^\d{10}$/', $digits ) ? $digits : '';
	}

	/** A box of the form (hidden 0, box 1) or a value already clean. */
	private static function ticked( $value ): bool {
		return true === $value || '1' === $value || 1 === $value;
	}

	/** @return array{suggest: bool, search_console: bool, ai: bool, language: string, country: string, volumes: string, dfs_login: string, dfs_secret: string, dfs_sandbox: bool, trends: bool, gads_customer: string, gads_login: string, gads_key: array<string, string>, gads_use_gsc: bool} */
	public static function settings(): array {
		return self::sanitize( Simple_SEO_Settings::get( self::OPTION ) );
	}

	/** The languages there are suggestions in, code => name in the language of the admin. */
	public static function languages(): array {
		return array(
			'uk' => __( 'Ukrainian', 'simple-seo' ),
			'en' => __( 'English', 'simple-seo' ),
			'pl' => __( 'Polish', 'simple-seo' ),
			'de' => __( 'German', 'simple-seo' ),
			'fr' => __( 'French', 'simple-seo' ),
			'es' => __( 'Spanish', 'simple-seo' ),
			'it' => __( 'Italian', 'simple-seo' ),
			'ru' => __( 'Russian', 'simple-seo' ),
		);
	}

	/** The countries searched in, code => name. */
	public static function countries(): array {
		return array(
			'UA' => __( 'Ukraine', 'simple-seo' ),
			'US' => __( 'United States', 'simple-seo' ),
			'GB' => __( 'United Kingdom', 'simple-seo' ),
			'CA' => __( 'Canada', 'simple-seo' ),
			'AU' => __( 'Australia', 'simple-seo' ),
			'IE' => __( 'Ireland', 'simple-seo' ),
			'PL' => __( 'Poland', 'simple-seo' ),
			'DE' => __( 'Germany', 'simple-seo' ),
			'AT' => __( 'Austria', 'simple-seo' ),
			'CH' => __( 'Switzerland', 'simple-seo' ),
			'FR' => __( 'France', 'simple-seo' ),
			'ES' => __( 'Spain', 'simple-seo' ),
			'IT' => __( 'Italy', 'simple-seo' ),
			'CZ' => __( 'Czechia', 'simple-seo' ),
			'MD' => __( 'Moldova', 'simple-seo' ),
			'LT' => __( 'Lithuania', 'simple-seo' ),
			'LV' => __( 'Latvia', 'simple-seo' ),
			'EE' => __( 'Estonia', 'simple-seo' ),
			'KZ' => __( 'Kazakhstan', 'simple-seo' ),
		);
	}

	/** The language of the site among the languages there are: its locale, else English. */
	public static function site_language(): string {
		$code = strtolower( substr( get_locale(), 0, 2 ) );

		return isset( self::LANGUAGES[ $code ] ) ? $code : 'en';
	}

	/** The country of the site: the region of its locale, else that of its language. */
	public static function site_country(): string {
		$region = preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', get_locale(), $found ) ? $found[1] : '';

		return in_array( $region, self::COUNTRIES, true ) ? $region : self::COUNTRY_OF[ self::site_language() ];
	}

	/**
	 * The language searched in: the one asked for, else that of the settings, else that of the
	 * phrase by its letters, else that of the site.
	 */
	public static function language( string $asked = '', string $seed = '' ): string {
		if ( isset( self::LANGUAGES[ $asked ] ) ) {
			return $asked;
		}
		$saved = self::settings()['language'];

		return '' !== $saved ? $saved : self::guess( $seed, self::site_language() );
	}

	/**
	 * The language of a phrase by its letters: Cyrillic with ы, э, ъ or ё is Russian, other Cyrillic
	 * Ukrainian (or Russian on a Russian site); Latin letters keep a Latin language of the site, else
	 * English; a phrase without letters, the language of the site.
	 */
	public static function guess( string $seed, string $site ): string {
		if ( preg_match( '/\p{Cyrillic}/u', $seed ) ) {
			if ( preg_match( '/[ыэъё]/u', mb_strtolower( $seed ) ) ) {
				return 'ru';
			}

			return preg_match( '/[іїєґ]/u', mb_strtolower( $seed ) ) || 'ru' !== $site ? 'uk' : 'ru';
		}
		if ( preg_match( '/\p{Latin}/u', $seed ) ) {
			return in_array( $site, array( 'uk', 'ru' ), true ) ? 'en' : $site;
		}

		return $site;
	}

	/**
	 * The country searched in: the one asked for, else that of the settings, else that of the site
	 * when the language is its own, else the country of the language.
	 */
	public static function country( string $asked = '', string $language = '' ): string {
		$asked = strtoupper( $asked );
		if ( in_array( $asked, self::COUNTRIES, true ) ) {
			return $asked;
		}
		$saved = self::settings()['country'];
		if ( '' !== $saved ) {
			return $saved;
		}

		return '' === $language || self::site_language() === $language ? self::site_country() : ( self::COUNTRY_OF[ $language ] ?? self::site_country() );
	}

	/*
	 * Words.
	 */

	/** A phrase as it is searched: lower case, single spaces, no more than MAX_SEED characters. */
	public static function clean( string $text ): string {
		$text = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ) );

		return trim( mb_substr( $text, 0, self::MAX_SEED ) );
	}

	/** @return string[] */
	private static function list_of( string $language, string $key ): array {
		$words = (string) ( self::LANGUAGES[ $language ][ $key ] ?? '' );

		return '' !== $words ? explode( ' ', $words ) : array();
	}

	/** Whether a search is a question: a question word in it, an auxiliary word at its start, or "?". */
	public static function is_question( string $keyword, string $language ): bool {
		$words = Simple_SEO_Analysis::words( $keyword );
		if ( ! $words ) {
			return false;
		}

		return str_contains( $keyword, '?' )
			|| array_intersect( $words, self::list_of( $language, 'questions' ) )
			|| in_array( $words[0], self::list_of( $language, 'starts' ), true );
	}

	/**
	 * The start a word of the phrase is known by in its forms ("чай", "чаю" and "чаї" alike): the
	 * stem of the analysis, or of a short word all but its last letter.
	 */
	private static function root( string $word ): string {
		$length = mb_strlen( $word );

		return $length >= 4 ? Simple_SEO_Analysis::stem( $word ) : mb_substr( $word, 0, max( 2, $length - 1 ) );
	}

	/** Whether a word is a form of a word of the phrase. */
	private static function of_seed( string $word, array $roots ): bool {
		foreach ( $roots as $root ) {
			if ( str_starts_with( $word, $root ) && mb_strlen( $word ) <= mb_strlen( $root ) + 4 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The words of a phrase, those with a hyphen apart too ("іван-чай").
	 *
	 * @return string[]
	 */
	private static function words( string $text ): array {
		$words = array();
		foreach ( Simple_SEO_Analysis::words( $text ) as $word ) {
			foreach ( explode( '-', $word ) as $part ) {
				if ( '' !== $part ) {
					$words[] = $part;
				}
			}
		}

		return $words;
	}

	/** @return string[] The roots of the words of a phrase. */
	private static function roots( string $seed ): array {
		return array_values( array_unique( array_map( array( self::class, 'root' ), self::words( $seed ) ) ) );
	}

	/** Whether a search has every word of the phrase, in some form. */
	public static function matches( string $keyword, string $seed ): bool {
		$words = self::words( $keyword );
		foreach ( self::roots( $seed ) as $root ) {
			if ( ! self::of_seed_any( $words, $root ) ) {
				return false;
			}
		}

		return true;
	}

	private static function of_seed_any( array $words, string $root ): bool {
		foreach ( $words as $word ) {
			if ( self::of_seed( $word, array( $root ) ) ) {
				return true;
			}
		}

		return false;
	}

	/*
	 * Google's suggestions.
	 */

	/**
	 * The searches asked of Google for a phrase: the phrase, the phrase with each letter, each
	 * question word before it and each preposition after it.
	 *
	 * @return string[]
	 */
	public static function suggest_queries( string $seed, string $language ): array {
		$queries = array( $seed );
		foreach ( self::list_of( $language, 'questions' ) as $word ) {
			$queries[] = $word . ' ' . $seed;
		}
		foreach ( self::list_of( $language, 'after' ) as $word ) {
			$queries[] = $seed . ' ' . $word;
		}
		foreach ( self::list_of( $language, 'letters' ) as $letter ) {
			$queries[] = $seed . ' ' . $letter;
		}

		return array_values( array_unique( $queries ) );
	}

	/** The address of the suggestions of one search. */
	public static function suggest_url( string $query, string $language, string $country ): string {
		return add_query_arg(
			array(
				'client' => 'firefox',
				'ie'     => 'utf-8',
				'oe'     => 'utf-8',
				'hl'     => $language,
				'gl'     => strtolower( $country ),
				'q'      => rawurlencode( $query ),
			),
			self::SUGGEST_URL
		);
	}

	/**
	 * The suggestions of Google for a phrase, in their order, kept a week.
	 *
	 * @return string[]|WP_Error
	 */
	public static function suggestions( string $seed, string $language, string $country, bool $refresh = false ) {
		$cache = self::CACHE . md5( 'suggest|' . $seed . '|' . $language . '|' . $country );
		$saved = $refresh ? false : get_transient( $cache );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		$urls = array();
		foreach ( self::suggest_queries( $seed, $language ) as $query ) {
			$urls[] = self::suggest_url( $query, $language, $country );
		}

		$bodies = self::fetch( $urls );
		$found  = array();
		$failed = 0;
		foreach ( $urls as $url ) {
			$body = $bodies[ $url ] ?? null;
			if ( ! is_string( $body ) ) {
				++$failed;
				continue;
			}
			foreach ( self::parse_suggestions( $body ) as $keyword ) {
				$found[ $keyword ] = true;
			}
		}

		if ( count( $urls ) === $failed ) {
			return new WP_Error( 'simple_seo_kw_suggest', __( 'Google did not answer the requests for suggestions from this server. Try again later.', 'simple-seo' ) );
		}

		// Keys of digits only became numbers.
		$keywords = array_map( 'strval', array_keys( $found ) );
		// A few failed requests: what came is shown, but asked again next time.
		set_transient( $cache, $keywords, $failed ? HOUR_IN_SECONDS : self::TTL );

		return $keywords;
	}

	/**
	 * The searches of one answer of Google: ["query", ["suggestion", …], …].
	 *
	 * @return string[]
	 */
	public static function parse_suggestions( string $body ): array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! is_array( $data[1] ?? null ) ) {
			return array();
		}

		$keywords = array();
		foreach ( $data[1] as $suggestion ) {
			$keyword = is_string( $suggestion ) ? self::clean( $suggestion ) : '';
			if ( '' !== $keyword ) {
				$keywords[] = $keyword;
			}
		}

		return $keywords;
	}

	/**
	 * The bodies of some addresses, BATCH at a time, null for one that did not answer 200. The
	 * filter simple_seo_keywords_fetch answers instead (tests, another way to the network).
	 *
	 * @param string[] $urls
	 * @return array<string, string|null>
	 */
	public static function fetch( array $urls ): array {
		$bodies = apply_filters( 'simple_seo_keywords_fetch', null, $urls );
		if ( is_array( $bodies ) ) {
			return $bodies;
		}

		$bodies = array();
		foreach ( array_chunk( $urls, self::BATCH ) as $chunk ) {
			$requests = array();
			foreach ( $chunk as $url ) {
				$requests[ $url ] = array(
					'url'     => $url,
					'headers' => array( 'Accept' => 'application/json' ),
				);
			}
			try {
				$responses = \WpOrg\Requests\Requests::request_multiple(
					$requests,
					array(
						'timeout'   => 10,
						'useragent' => 'Mozilla/5.0 (compatible; Simple SEO; WordPress)',
						'verify'    => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
					)
				);
			} catch ( Throwable $error ) {
				$responses = array();
			}
			foreach ( $chunk as $url ) {
				$response       = $responses[ $url ] ?? null;
				$bodies[ $url ] = $response instanceof \WpOrg\Requests\Response && 200 === (int) $response->status_code ? (string) $response->body : null;
			}
		}

		return $bodies;
	}

	/*
	 * Search Console.
	 */

	/** Whether the ideas get the data of Search Console: it is on and connected. */
	public static function gsc_on(): bool {
		return self::settings()['search_console'] && Simple_SEO_Search_Console::connected();
	}

	/**
	 * The queries the site was shown for in the last GSC_DAYS days that have the words of the
	 * phrase, with their clicks, impressions and position.
	 *
	 * @return array<string, array{clicks: int, impressions: int, position: float}>|WP_Error
	 */
	public static function gsc( string $seed ) {
		$property = Simple_SEO_Search_Console::property();
		if ( is_wp_error( $property ) ) {
			return $property;
		}

		// Google filters by the longest word; the forms of all of them are matched here.
		$roots = self::roots( $seed );
		usort( $roots, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );
		$today = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$rows  = Simple_SEO_Search_Console::query(
			$property,
			array(
				'startDate'             => $today->modify( '-' . self::GSC_DAYS . ' days' )->format( 'Y-m-d' ),
				'endDate'               => $today->format( 'Y-m-d' ),
				'dimensions'            => array( 'query' ),
				'rowLimit'              => 1000,
				'dimensionFilterGroups' => array(
					array(
						'filters' => array(
							array(
								'dimension'  => 'query',
								'operator'   => 'contains',
								'expression' => (string) ( $roots[0] ?? $seed ),
							),
						),
					),
				),
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$queries = array();
		foreach ( $rows as $row ) {
			$keyword = self::clean( (string) ( $row['keys'][0] ?? '' ) );
			if ( '' !== $keyword && self::matches( $keyword, $seed ) ) {
				$queries[ $keyword ] = array(
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
					'position'    => round( (float) $row['position'], 1 ),
				);
			}
		}

		return $queries;
	}

	/*
	 * Research.
	 */

	/**
	 * The ideas around a phrase from the sources that are on: each with whether it is a question,
	 * where it came from, its topic and the data of Search Console; the topics with their counts;
	 * what a source could not give.
	 *
	 * @return array{seed: string, language: string, country: string, items: array<int, array<string, mixed>>, topics: array<int, array{name: string, count: int}>, errors: array<string, string>}|WP_Error
	 */
	public static function research( string $seed, string $language = '', string $country = '', bool $refresh = false ) {
		$seed = self::clean( $seed );
		if ( '' === $seed ) {
			return new WP_Error( 'simple_seo_kw_empty', __( 'Type a phrase to find the searches around it.', 'simple-seo' ), array( 'status' => 400 ) );
		}

		$language = self::language( $language, $seed );
		$country  = self::country( $country, $language );
		$settings = self::settings();
		$errors   = array();
		$items    = array();

		if ( $settings['suggest'] ) {
			$suggestions = self::suggestions( $seed, $language, $country, $refresh );
			if ( is_wp_error( $suggestions ) ) {
				$errors['suggest'] = $suggestions->get_error_message();
			} else {
				foreach ( $suggestions as $keyword ) {
					$items[ $keyword ] = self::item( $keyword, 'suggest' );
				}
			}
		}

		if ( self::gsc_on() ) {
			$queries = self::gsc( $seed );
			if ( is_wp_error( $queries ) ) {
				$errors['search_console'] = $queries->get_error_message();
			} else {
				// The queries the site is seen for that Google does not suggest: most seen first.
				uasort( $queries, static fn( array $a, array $b ): int => $b['impressions'] <=> $a['impressions'] );
				foreach ( $queries as $keyword => $data ) {
					$keyword = (string) $keyword;
					if ( ! isset( $items[ $keyword ] ) ) {
						$items[ $keyword ] = self::item( $keyword, 'search_console' );
					} else {
						$items[ $keyword ]['sources'][] = 'search_console';
					}
					$items[ $keyword ]['gsc'] = $data;
				}
			}
		}

		if ( Simple_SEO_DataForSEO::on() ) {
			[ $items, $error ] = self::with_metrics( $items, $seed, $language, $country );
			if ( '' !== $error ) {
				$errors['dataforseo'] = $error;
			}
		} elseif ( Simple_SEO_Google_Ads::on() ) {
			[ $items, $error ] = self::with_planner( $items, $seed, $language, $country );
			if ( '' !== $error ) {
				$errors['google_ads'] = $error;
			}
		}

		$items = array_values( $items );
		foreach ( $items as $index => $item ) {
			$items[ $index ]['question'] = self::is_question( $item['keyword'], $language );
		}
		if ( self::volumes_on() ) {
			// The most searched first; those without a volume keep their order after them.
			usort( $items, static fn( array $a, array $b ): int => ( $b['metrics']['volume'] ?? -1 ) <=> ( $a['metrics']['volume'] ?? -1 ) );
		}
		[ $items, $topics ] = self::topics( $items, $seed, $language );

		return array(
			'seed'     => $seed,
			'language' => $language,
			'country'  => $country,
			'items'    => $items,
			'topics'   => $topics,
			'errors'   => $errors,
		);
	}

	/** @return array{keyword: string, question: bool, sources: string[], topic: string, gsc: array<string, mixed>|null, metrics: array<string, mixed>|null} */
	private static function item( string $keyword, string $source ): array {
		return array(
			'keyword'  => $keyword,
			'question' => false,
			'sources'  => array( $source ),
			'topic'    => '',
			'gsc'      => null,
			'metrics'  => null,
		);
	}

	/**
	 * The ideas with the metrics of DataForSEO: more searches of the phrase from its Labs with
	 * theirs, then the volumes of the others from Google Ads and their difficulty from Labs. What
	 * one request could not give stops those after it.
	 *
	 * @param array<string, array<string, mixed>> $items keyword => item.
	 * @return array{0: array<string, array<string, mixed>>, 1: string} The items and why some have none.
	 */
	private static function with_metrics( array $items, string $seed, string $language, string $country ): array {
		$more = Simple_SEO_DataForSEO::suggestions( $seed, $language, $country );
		if ( is_wp_error( $more ) ) {
			return array( $items, $more->get_error_message() );
		}
		foreach ( $more as $keyword => $metrics ) {
			$keyword = (string) $keyword;
			if ( ! isset( $items[ $keyword ] ) ) {
				$items[ $keyword ] = self::item( $keyword, 'dataforseo' );
			} else {
				$items[ $keyword ]['sources'][] = 'dataforseo';
			}
			$items[ $keyword ]['metrics'] = $metrics;
		}

		$missing = array();
		foreach ( $items as $keyword => $item ) {
			if ( null === $item['metrics'] ) {
				$missing[] = (string) $keyword;
			}
		}
		$volumes = $missing ? Simple_SEO_DataForSEO::volumes( $missing, $language, $country ) : array();
		if ( is_wp_error( $volumes ) ) {
			return array( $items, $volumes->get_error_message() );
		}
		foreach ( $volumes as $keyword => $metrics ) {
			if ( isset( $items[ $keyword ] ) ) {
				$items[ $keyword ]['metrics'] = $metrics;
			}
		}

		$hard = array();
		foreach ( $items as $keyword => $item ) {
			if ( null !== $item['metrics'] && null === $item['metrics']['kd'] ) {
				$hard[] = (string) $keyword;
			}
		}
		$difficulty = $hard ? Simple_SEO_DataForSEO::difficulty( $hard, $language, $country ) : array();
		if ( is_wp_error( $difficulty ) ) {
			return array( $items, $difficulty->get_error_message() );
		}
		foreach ( $difficulty as $keyword => $kd ) {
			if ( isset( $items[ $keyword ] ) ) {
				$items[ $keyword ]['metrics']['kd'] = $kd;
			}
		}

		return array( $items, '' );
	}

	/**
	 * The ideas with the metrics of the Keyword Planner of Google Ads: more searches of the phrase
	 * with theirs, then the volumes of the others. No difficulty: Google Ads has none.
	 *
	 * @param array<string, array<string, mixed>> $items keyword => item.
	 * @return array{0: array<string, array<string, mixed>>, 1: string} The items and why some have none.
	 */
	private static function with_planner( array $items, string $seed, string $language, string $country ): array {
		$more = Simple_SEO_Google_Ads::ideas( $seed, $language, $country );
		if ( is_wp_error( $more ) ) {
			return array( $items, $more->get_error_message() );
		}
		foreach ( $more as $keyword => $metrics ) {
			$keyword = (string) $keyword;
			if ( ! isset( $items[ $keyword ] ) ) {
				$items[ $keyword ] = self::item( $keyword, 'google_ads' );
			} else {
				$items[ $keyword ]['sources'][] = 'google_ads';
			}
			$items[ $keyword ]['metrics'] = $metrics;
		}

		$missing = array();
		foreach ( $items as $keyword => $item ) {
			if ( null === $item['metrics'] ) {
				$missing[] = (string) $keyword;
			}
		}
		$volumes = $missing ? Simple_SEO_Google_Ads::volumes( $missing, $language, $country ) : array();
		if ( is_wp_error( $volumes ) ) {
			return array( $items, $volumes->get_error_message() );
		}
		foreach ( $volumes as $keyword => $metrics ) {
			if ( isset( $items[ $keyword ] ) && null === $items[ $keyword ]['metrics'] ) {
				$items[ $keyword ]['metrics'] = $metrics;
			}
		}

		return array( $items, '' );
	}

	/** Whether the searches get volumes: from DataForSEO or the Keyword Planner of Google Ads. */
	public static function volumes_on(): bool {
		return Simple_SEO_DataForSEO::on() || Simple_SEO_Google_Ads::on();
	}

	/**
	 * The interest over time of a phrase in Google Trends, from DataForSEO while it is on with
	 * Trends.
	 *
	 * @return array{seed: string, points: array<int, array{date: string, value: int}>}|WP_Error
	 */
	public static function trends( string $seed, string $language = '', string $country = '' ) {
		$seed = self::clean( $seed );
		if ( '' === $seed ) {
			return new WP_Error( 'simple_seo_kw_empty', __( 'Type a phrase to find the searches around it.', 'simple-seo' ), array( 'status' => 400 ) );
		}
		if ( ! Simple_SEO_DataForSEO::on() || ! self::settings()['trends'] ) {
			return new WP_Error( 'simple_seo_kw_no_trends', __( 'Google Trends comes from DataForSEO: connect it and turn Trends on on the Keyword research tab.', 'simple-seo' ), array( 'status' => 400 ) );
		}
		$language = self::language( $language, $seed );
		$points   = Simple_SEO_DataForSEO::trends( $seed, $language, self::country( $country, $language ) );

		return is_wp_error( $points ) ? $points : array(
			'seed'   => $seed,
			'points' => $points,
		);
	}

	/**
	 * Topics without the AI: the words the ideas have beyond the phrase, by their stems. An idea
	 * goes under its word that most ideas share, named by the form most of them use; a topic of
	 * fewer than MIN_TOPIC ideas is none, and those ideas go under no topic ('').
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{name: string, count: int}>}
	 */
	public static function topics( array $items, string $seed, string $language ): array {
		$roots = self::roots( $seed );
		$skip  = array_merge( self::list_of( $language, 'questions' ), self::list_of( $language, 'starts' ), self::list_of( $language, 'after' ), explode( ' ', self::STOP ) );

		$stems = array();
		$forms = array();
		$count = array();
		foreach ( $items as $index => $item ) {
			$stems[ $index ] = array();
			foreach ( self::words( (string) $item['keyword'] ) as $word ) {
				if ( mb_strlen( $word ) < 3 || in_array( $word, $skip, true ) || self::of_seed( $word, $roots ) ) {
					continue;
				}
				$stem = Simple_SEO_Analysis::stem( $word );
				if ( ! in_array( $stem, $stems[ $index ], true ) ) {
					$stems[ $index ][]  = $stem;
					$count[ $stem ]     = ( $count[ $stem ] ?? 0 ) + 1;
					$forms[ $stem ][ $word ] = ( $forms[ $stem ][ $word ] ?? 0 ) + 1;
				}
			}
		}

		// Forms whose stems differ by a letter or two at the end ("тиск", "тиску") are one word.
		$same = array();
		foreach ( array_keys( $count ) as $stem ) {
			foreach ( array_keys( $count ) as $shorter ) {
				$stem    = (string) $stem;
				$shorter = (string) $shorter;
				if ( $shorter !== $stem && mb_strlen( $shorter ) >= 3 && mb_strlen( $stem ) - mb_strlen( $shorter ) <= 2 && str_starts_with( $stem, $shorter ) && ( ! isset( $same[ $stem ] ) || mb_strlen( $shorter ) < mb_strlen( $same[ $stem ] ) ) ) {
					$same[ $stem ] = $shorter;
				}
			}
		}
		foreach ( $same as $stem => $shorter ) {
			foreach ( $forms[ $stem ] as $word => $times ) {
				$forms[ $shorter ][ $word ] = ( $forms[ $shorter ][ $word ] ?? 0 ) + $times;
			}
		}
		$count = array();
		foreach ( $stems as $index => $list ) {
			$stems[ $index ] = array_values( array_unique( array_map( static fn( string $one ): string => $same[ $one ] ?? $one, $list ) ) );
			foreach ( $stems[ $index ] as $stem ) {
				$count[ $stem ] = ( $count[ $stem ] ?? 0 ) + 1;
			}
		}

		$sizes = array();
		foreach ( $items as $index => $item ) {
			$best = '';
			foreach ( $stems[ $index ] as $stem ) {
				if ( $count[ $stem ] >= self::MIN_TOPIC && ( '' === $best || $count[ $stem ] > $count[ $best ] ) ) {
					$best = $stem;
				}
			}
			if ( '' !== $best ) {
				arsort( $forms[ $best ] );
				$name                     = (string) array_key_first( $forms[ $best ] );
				$items[ $index ]['topic'] = $name;
				$sizes[ $name ]           = ( $sizes[ $name ] ?? 0 ) + 1;
			}
		}

		// A word most of whose ideas went under bigger topics is no topic.
		foreach ( $sizes as $name => $size ) {
			if ( $size < self::MIN_TOPIC ) {
				foreach ( $items as $index => $item ) {
					if ( $item['topic'] === (string) $name ) {
						$items[ $index ]['topic'] = '';
					}
				}
				unset( $sizes[ $name ] );
			}
		}
		arsort( $sizes );
		$topics = array();
		foreach ( $sizes as $name => $size ) {
			$topics[] = array(
				'name'  => (string) $name,
				'count' => $size,
			);
		}

		return array( $items, $topics );
	}

	/*
	 * The AI.
	 */

	/** Whether the AI groups and plans: it is on and a model can write. */
	public static function ai_on(): bool {
		return self::settings()['ai'] && Simple_SEO_AI::ready();
	}

	private static function ai_off(): WP_Error {
		return new WP_Error( 'simple_seo_kw_ai_off', __( 'The AI is turned off for the keyword research in the settings.', 'simple-seo' ), array( 'status' => 403 ) );
	}

	/**
	 * Keywords as the AI may get them: clean, once each, MAX_AI at most.
	 *
	 * @param mixed $keywords
	 * @return string[]
	 */
	private static function keyword_list( $keywords ): array {
		$list = array();
		foreach ( is_array( $keywords ) ? $keywords : array() as $keyword ) {
			$keyword = is_string( $keyword ) ? self::clean( $keyword ) : '';
			if ( '' !== $keyword ) {
				$list[ $keyword ] = true;
			}
		}

		return array_slice( array_map( 'strval', array_keys( $list ) ), 0, self::MAX_AI );
	}

	/**
	 * The topics of the ideas grouped by the AI: the parent topic of the phrase and topics, each
	 * with its ideas — only ideas it was given, each once; those it left out under no topic.
	 *
	 * @param string[] $keywords
	 * @return array{parent: string, topics: array<int, array{name: string, keywords: string[]}>}|WP_Error
	 */
	public static function ai_topics( string $seed, array $keywords, string $language ) {
		if ( ! self::settings()['ai'] ) {
			return self::ai_off();
		}
		$seed     = self::clean( $seed );
		$keywords = self::keyword_list( $keywords );
		if ( '' === $seed || ! $keywords ) {
			return new WP_Error( 'simple_seo_kw_empty', __( 'There are no searches to group.', 'simple-seo' ), array( 'status' => 400 ) );
		}

		$system = implode(
			"\n",
			array(
				'You are an SEO strategist. You group the searches people make around a subject into topics, the way a keyword tool finds the parent topic of each search: searches answered by one page or one section of a page go together.',
				'Name the topics in ' . self::LANGUAGES[ self::language( $language ) ]['name'] . ', in two to four words, whatever language the instructions are in.',
				'Use only the searches given, word for word, each in one topic. Never invent searches or numbers.',
				'Answer with JSON only: {"parent_topic": "…", "topics": [{"name": "…", "keywords": ["…"]}]}',
			)
		);
		$prompt = 'The subject: ' . $seed . "\n\nThe searches, one a line:\n" . implode( "\n", $keywords );

		$data = Simple_SEO_AI::structured(
			$prompt,
			$system,
			array(
				'type'                 => 'object',
				'properties'           => array(
					'parent_topic' => array( 'type' => 'string' ),
					'topics'       => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'name'     => array( 'type' => 'string' ),
								'keywords' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
							'required'             => array( 'name', 'keywords' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'parent_topic', 'topics' ),
				'additionalProperties' => false,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$left   = array_fill_keys( $keywords, true );
		$topics = array();
		foreach ( is_array( $data['topics'] ?? null ) ? $data['topics'] : array() as $topic ) {
			$name = is_array( $topic ) && is_string( $topic['name'] ?? null ) ? self::line( $topic['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$own = array();
			foreach ( self::keyword_list( $topic['keywords'] ?? array() ) as $keyword ) {
				if ( isset( $left[ $keyword ] ) ) {
					$own[] = $keyword;
					unset( $left[ $keyword ] );
				}
			}
			if ( $own ) {
				$topics[] = array(
					'name'     => $name,
					'keywords' => $own,
				);
			}
		}
		if ( ! $topics ) {
			return new WP_Error( 'simple_seo_ai_empty', __( 'The AI answered with nothing that could be read. Try again.', 'simple-seo' ), array( 'status' => 500 ) );
		}

		return array(
			'parent' => is_string( $data['parent_topic'] ?? null ) ? self::line( $data['parent_topic'] ) : '',
			'topics' => $topics,
		);
	}

	/** A short line of the AI: plain, one line, 120 characters at most. */
	private static function line( string $text ): string {
		return trim( mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ), 0, 120 ) );
	}

	/**
	 * The structure of an article on a phrase or a topic by the AI: its title, its sections with
	 * their subheadings and the searches each covers, and the questions for a FAQ — of the
	 * searches given.
	 *
	 * @param string[] $keywords
	 * @return array{title: string, sections: array<int, array{heading: string, subheadings: string[], keywords: string[]}>, faq: string[]}|WP_Error
	 */
	public static function outline( string $seed, string $topic, array $keywords, string $language ) {
		if ( ! self::settings()['ai'] ) {
			return self::ai_off();
		}
		$seed     = self::clean( $seed );
		$keywords = self::keyword_list( $keywords );
		if ( '' === $seed || ! $keywords ) {
			return new WP_Error( 'simple_seo_kw_empty', __( 'There are no searches to plan an article on.', 'simple-seo' ), array( 'status' => 400 ) );
		}
		$language  = self::language( $language );
		$questions = array_values( array_filter( $keywords, static fn( string $keyword ): bool => self::is_question( $keyword, $language ) ) );

		$system = implode(
			"\n",
			array(
				'You are an SEO editor. You plan one article that answers the searches given better than the pages already found for them: a title, sections with subheadings in a natural order, and a FAQ.',
				'Write in ' . self::LANGUAGES[ $language ]['name'] . ', whatever language the instructions are in. Plain text, no Markdown, no numbering.',
				'Each section lists the searches it answers, word for word from those given. The FAQ takes questions from the questions given, word for word, those that need a short answer of their own.',
				'Plan only what the searches ask; never invent facts, numbers or searches.',
				'Answer with JSON only: {"title": "…", "sections": [{"heading": "…", "subheadings": ["…"], "keywords": ["…"]}], "faq": ["…"]}',
			)
		);
		$prompt = 'The subject: ' . $seed . ( '' !== $topic ? "\nThe topic of the article: " . self::line( $topic ) : '' )
			. "\n\nThe searches, one a line:\n" . implode( "\n", $keywords )
			. ( $questions ? "\n\nThe questions among them:\n" . implode( "\n", $questions ) : '' );

		$data = Simple_SEO_AI::structured(
			$prompt,
			$system,
			array(
				'type'                 => 'object',
				'properties'           => array(
					'title'    => array( 'type' => 'string' ),
					'sections' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'heading'     => array( 'type' => 'string' ),
								'subheadings' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'keywords'    => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
							'required'             => array( 'heading', 'subheadings', 'keywords' ),
							'additionalProperties' => false,
						),
					),
					'faq'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'             => array( 'title', 'sections', 'faq' ),
				'additionalProperties' => false,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$outline = self::clean_outline( $data, $keywords, $questions );

		return '' !== $outline['title'] && $outline['sections']
			? $outline
			: new WP_Error( 'simple_seo_ai_empty', __( 'The AI answered with nothing that could be read. Try again.', 'simple-seo' ), array( 'status' => 500 ) );
	}

	/**
	 * An outline as it may be shown and written: lines of plain text, sections and subheadings
	 * within bounds, searches and questions only of those given (any when none are given).
	 *
	 * @param array<string, mixed> $data
	 * @param string[]|null        $keywords
	 * @param string[]|null        $questions
	 * @return array{title: string, sections: array<int, array{heading: string, subheadings: string[], keywords: string[]}>, faq: string[]}
	 */
	public static function clean_outline( array $data, ?array $keywords = null, ?array $questions = null ): array {
		$sections = array();
		foreach ( array_slice( is_array( $data['sections'] ?? null ) ? $data['sections'] : array(), 0, 15 ) as $section ) {
			$heading = is_array( $section ) && is_string( $section['heading'] ?? null ) ? self::line( $section['heading'] ) : '';
			if ( '' === $heading ) {
				continue;
			}
			$subheadings = array();
			foreach ( array_slice( is_array( $section['subheadings'] ?? null ) ? $section['subheadings'] : array(), 0, 8 ) as $subheading ) {
				$subheading = is_string( $subheading ) ? self::line( $subheading ) : '';
				if ( '' !== $subheading ) {
					$subheadings[] = $subheading;
				}
			}
			$own = self::keyword_list( $section['keywords'] ?? array() );
			if ( null !== $keywords ) {
				$own = array_values( array_intersect( $own, $keywords ) );
			}
			$sections[] = array(
				'heading'     => $heading,
				'subheadings' => $subheadings,
				'keywords'    => $own,
			);
		}

		$faq = array();
		foreach ( array_slice( is_array( $data['faq'] ?? null ) ? $data['faq'] : array(), 0, 12 ) as $question ) {
			$question = is_string( $question ) ? self::line( $question ) : '';
			if ( '' !== $question && ( null === $questions || in_array( self::clean( $question ), $questions, true ) ) ) {
				$faq[] = $question;
			}
		}

		return array(
			'title'    => is_string( $data['title'] ?? null ) ? self::line( $data['title'] ) : '',
			'sections' => $sections,
			'faq'      => array_values( array_unique( $faq ) ),
		);
	}

	/**
	 * A draft post of an outline: its title, a heading for each section and subheading with an
	 * empty paragraph to write in, the FAQ as the block Questions and answers with the answers to
	 * write, and the phrase as its focus keyphrase.
	 *
	 * @param array<string, mixed> $outline
	 * @return array{id: int, edit: string}|WP_Error
	 */
	public static function draft( array $outline, string $keyphrase ) {
		$outline = self::clean_outline( $outline );
		if ( '' === $outline['title'] || ! $outline['sections'] ) {
			return new WP_Error( 'simple_seo_kw_outline', __( 'The structure of the article is empty.', 'simple-seo' ), array( 'status' => 400 ) );
		}

		$blocks = array();
		foreach ( $outline['sections'] as $section ) {
			$blocks[] = self::heading_block( $section['heading'], 2 );
			$blocks[] = self::paragraph_block();
			foreach ( $section['subheadings'] as $subheading ) {
				$blocks[] = self::heading_block( $subheading, 3 );
				$blocks[] = self::paragraph_block();
			}
		}
		if ( $outline['faq'] ) {
			$blocks[] = array(
				'blockName'    => Simple_SEO_FAQ::BLOCK,
				'attrs'        => array(
					'items' => array_map(
						static fn( string $question ): array => array(
							'question' => $question,
							'answer'   => '',
						),
						$outline['faq']
					),
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $outline['title'],
				'post_content' => implode( "\n\n", array_map( 'serialize_block', $blocks ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$keyphrase = self::clean( $keyphrase );
		if ( '' !== $keyphrase ) {
			// Whoever may not edit the keyphrase still gets the draft.
			Simple_SEO_Fields::write( 'post', (int) $id, array( 'keyphrase' => $keyphrase ), get_current_user_id() );
		}

		return array(
			'id'   => (int) $id,
			'edit' => (string) get_edit_post_link( (int) $id, 'raw' ),
		);
	}

	/** @return array<string, mixed> */
	private static function heading_block( string $text, int $level ): array {
		$html = sprintf( '<h%1$d class="wp-block-heading">%2$s</h%1$d>', $level, esc_html( $text ) );

		return array(
			'blockName'    => 'core/heading',
			'attrs'        => 2 === $level ? array() : array( 'level' => $level ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/** @return array<string, mixed> */
	private static function paragraph_block(): array {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p></p>',
			'innerContent' => array( '<p></p>' ),
		);
	}

	/*
	 * REST.
	 */

	public static function register_routes(): void {
		$can  = static fn(): bool => current_user_can( Simple_SEO_Access::CAP_FIELDS );
		$text = array( 'type' => 'string' );
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/keywords',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ) {
					return self::research( (string) $request['seed'], (string) $request['language'], (string) $request['country'], (bool) $request['refresh'] );
				},
				'permission_callback' => $can,
				'args'                => array(
					'seed'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'language' => $text,
					'country'  => $text,
					'refresh'  => array( 'type' => 'boolean' ),
				),
			)
		);
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/keywords/trends',
			array(
				'methods'             => 'POST',
				'callback'            => static fn( WP_REST_Request $request ) => self::trends( (string) $request['seed'], (string) $request['language'], (string) $request['country'] ),
				'permission_callback' => $can,
				'args'                => array(
					'seed'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'language' => $text,
					'country'  => $text,
				),
			)
		);
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/keywords/topics',
			array(
				'methods'             => 'POST',
				'callback'            => static fn( WP_REST_Request $request ) => self::ai_topics( (string) $request['seed'], (array) $request['keywords'], (string) $request['language'] ),
				'permission_callback' => $can,
				'args'                => array(
					'seed'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'keywords' => $list + array( 'required' => true ),
					'language' => $text,
				),
			)
		);
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/keywords/outline',
			array(
				'methods'             => 'POST',
				'callback'            => static fn( WP_REST_Request $request ) => self::outline( (string) $request['seed'], (string) $request['topic'], (array) $request['keywords'], (string) $request['language'] ),
				'permission_callback' => $can,
				'args'                => array(
					'seed'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'topic'    => $text,
					'keywords' => $list + array( 'required' => true ),
					'language' => $text,
				),
			)
		);
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/keywords/draft',
			array(
				'methods'             => 'POST',
				'callback'            => static fn( WP_REST_Request $request ) => self::draft( (array) $request['outline'], (string) $request['keyphrase'] ),
				'permission_callback' => static fn(): bool => current_user_can( Simple_SEO_Access::CAP_FIELDS ) && current_user_can( 'edit_posts' ),
				'args'                => array(
					'outline'   => array(
						'type'     => 'object',
						'required' => true,
					),
					'keyphrase' => $text,
				),
			)
		);
	}

	/*
	 * The admin.
	 */

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab(
			'keywords',
			__( 'Keyword research', 'simple-seo' ),
			array( self::OPTION ),
			array( self::class, 'render_tab' ),
			30,
			'services',
			static fn(): array => array(
				'sources' => __( 'Sources', 'simple-seo' ),
				'volumes' => __( 'Volumes and difficulty', 'simple-seo' ),
			)
		);
	}

	public static function render_tab(): void {
		$settings  = self::settings();
		$languages = self::languages();
		$countries = self::countries();
		$site      = array(
			'language' => $languages[ self::site_language() ],
			'country'  => $countries[ self::site_country() ] ?? self::site_country(),
		);
		$balance   = Simple_SEO_DataForSEO::on() ? Simple_SEO_DataForSEO::balance() : null;
		$planner   = Simple_SEO_Google_Ads::on() ? Simple_SEO_Google_Ads::status() : null;
		$gsc_key   = Simple_SEO_Search_Console::connected();

		require SIMPLE_SEO_DIR . 'includes/views/tab-keywords.php';
	}

	public static function add_menu(): void {
		$hook = add_submenu_page(
			Simple_SEO_Admin::PAGE,
			__( 'Keyword research', 'simple-seo' ),
			__( 'Keywords', 'simple-seo' ),
			Simple_SEO_Access::CAP_FIELDS,
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

	/** Whether any source finds ideas. */
	public static function available(): bool {
		return self::settings()['suggest'] || self::gsc_on();
	}

	/** The address of the page, for a phrase. */
	public static function page_url( string $seed = '', string $language = '', string $country = '' ): string {
		return add_query_arg(
			array_filter(
				array(
					'page'    => self::PAGE,
					'q'       => '' !== $seed ? rawurlencode( $seed ) : '',
					'lang'    => $language,
					'country' => $country,
				)
			),
			admin_url( 'admin.php' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_FIELDS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a search: it reads, it changes nothing.
		$seed     = self::clean( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' );
		$language = self::language( isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '', $seed );
		$country  = self::country( isset( $_GET['country'] ) ? sanitize_key( wp_unslash( $_GET['country'] ) ) : '', $language );
		$refresh  = ! empty( $_GET['refresh'] );
		// phpcs:enable

		$result    = '' !== $seed && self::available() ? self::research( $seed, $language, $country, $refresh ) : null;
		$languages = self::languages();
		$countries = self::countries();
		$ai        = self::ai_on();
		$gsc       = self::gsc_on();
		$draft     = current_user_can( 'edit_posts' );
		$metrics   = self::volumes_on();
		$kd        = Simple_SEO_DataForSEO::on();
		$trends    = $kd && self::settings()['trends'];

		require SIMPLE_SEO_DIR . 'includes/views/keywords.php';
	}

	/** The script of the page and of the button of the SEO block. */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		$editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && in_array( $screen->post_type, Simple_SEO_Fields::post_types(), true );
		if ( ( '' === self::$hook || self::$hook !== $hook ) && ! $editor ) {
			return;
		}

		wp_enqueue_script(
			'simple-seo-keywords',
			SIMPLE_SEO_URL . 'assets/js/keywords.js',
			array(),
			SIMPLE_SEO_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script( 'simple-seo-keywords', 'simpleSeoKeywords', self::script_data() );
	}

	/** @return array<string, mixed> What the script needs: the addresses, the nonce and its words. */
	public static function script_data(): array {
		$base = rest_url( Simple_SEO_Analysis::NAMESPACE . '/keywords' );

		return array(
			'url'     => $base,
			'topics'  => $base . '/topics',
			'outline' => $base . '/outline',
			'draft'   => $base . '/draft',
			'trends'  => $base . '/trends',
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'max'     => self::EDITOR_IDEAS,
			'text'    => array(
				'finding'   => __( 'Finding the searches…', 'simple-seo' ),
				'grouping'  => __( 'The AI groups the searches…', 'simple-seo' ),
				'planning'  => __( 'The AI plans the article…', 'simple-seo' ),
				'creating'  => __( 'Creating the draft…', 'simple-seo' ),
				'none'      => __( 'No searches found around this phrase.', 'simple-seo' ),
				'failed'    => __( 'Something went wrong. Try again.', 'simple-seo' ),
				'type'      => __( 'Type a focus keyphrase or a title first.', 'simple-seo' ),
				'focus'     => __( 'Use as the focus keyphrase', 'simple-seo' ),
				'add'       => __( 'Add to the other keyphrases', 'simple-seo' ),
				'question'  => __( 'question', 'simple-seo' ),
				'other'     => __( 'Other', 'simple-seo' ),
				'parent'    => __( 'Parent topic:', 'simple-seo' ),
				'copy'      => __( 'Copy', 'simple-seo' ),
				'copied'    => __( 'Copied.', 'simple-seo' ),
				'create'    => __( 'Create a draft', 'simple-seo' ),
				'open'      => __( 'Open the draft', 'simple-seo' ),
				'faq'       => __( 'Questions and answers', 'simple-seo' ),
				'covers'    => __( 'Covers:', 'simple-seo' ),
				'all'       => __( 'All', 'simple-seo' ),
				'questions' => __( 'Questions', 'simple-seo' ),
				'more'      => __( 'Open the keyword research', 'simple-seo' ),
				'volume'    => __( 'a month', 'simple-seo' ),
			),
			'page'    => self::page_url(),
		);
	}

	/**
	 * The button of the SEO block by the focus keyphrase of a post: the searches around the
	 * keyphrase or the title, to pick from.
	 *
	 * @param WP_Post|WP_Term $object
	 */
	public static function button( string $field, string $object_type, $object ): string {
		if ( 'keyphrase' !== $field || 'post' !== $object_type || ! current_user_can( Simple_SEO_Access::CAP_FIELDS ) || ! self::available() ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="button simple-seo-kw-button" data-simple-seo-kw="%s">%s</button>',
			// The language of the post under wp-loc; else the phrase tells (language()).
			esc_attr( $object instanceof WP_Post ? (string) ( Simple_SEO_WP_Loc::element_languages( array( (int) $object->ID ), 'post_' . $object->post_type )[ (int) $object->ID ] ?? '' ) : '' ),
			esc_html__( 'Find keyphrases', 'simple-seo' )
		);
	}
}
