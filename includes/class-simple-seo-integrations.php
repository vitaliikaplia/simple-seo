<?php
/**
 * Integrations (decisions/integrations): the codes that confirm the site to webmaster tools, and
 * the tags of Google Analytics 4 (or another Google tag), Google Tag Manager, Meta Pixel and
 * TikTok Pixel.
 *
 * The codes are meta tags of the home page, among Simple SEO's head tags. The tags are one small
 * script: their queues (dataLayer, fbq, ttq) take the events at once, and the scripts of Google,
 * Meta and TikTok come on the visitor's first move (scroll, touch, a key), when the page is
 * hidden, or after a while — or at once, as their own snippets do, by a switch. Consent is the
 * theme's: with the switch on, Google's consent mode starts denied and the pixels wait, until the
 * theme's banner calls simpleSeoConsent( true ) or sends the event simple-seo-consent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Integrations {
	public const OPTION = 'simple_seo_integrations';

	/** Webmaster tools: field => the name of their meta tag. */
	public const VERIFICATION = array(
		'google'    => 'google-site-verification',
		'bing'      => 'msvalidate.01',
		'baidu'     => 'baidu-site-verification',
		'pinterest' => 'p:domain_verify',
		'facebook'  => 'facebook-domain-verification',
		'ahrefs'    => 'ahrefs-site-verification',
	);

	/** The tags: field => the form of its ID. */
	public const TAGS = array(
		'google'  => '/^(?:G|GT|AW|DC)-[A-Z0-9]{4,20}$/',
		'gtm'     => '/^GTM-[A-Z0-9]{4,12}$/',
		'meta'    => '/^\d{6,20}$/',
		'tiktok'  => '/^[A-Z0-9]{10,30}$/',
	);

	/** Theme options where sites paste the snippets of these services (WP Base and its forks). */
	private const THEME_OPTIONS = array( 'header_custom_code', 'after_body_custom_code', 'footer_custom_code', 'ga4_measurement_id' );

	/** Milliseconds after the page has loaded when a lazy tag comes without a move of the visitor. */
	public const LAZY_TIMER = 8000;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'verification' => array(),
				'tags'         => array(),
				'lazy'         => true,
				'consent'      => false,
				'editors'      => false,
			),
			array( self::class, 'sanitize' ),
			true
		);

		Simple_SEO_Head::add_provider( 'verification', array( self::class, 'head_tags' ), 5 );
		// After Simple SEO's head tags and graph (1).
		add_action( 'wp_head', array( self::class, 'output' ), 3 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'integrations', __( 'Integrations', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 40 );
	}

	/**
	 * The codes of webmaster tools, on the home page: they look for them there.
	 *
	 * @return array<string, array{tag: string, attributes: array<string, string>}>
	 */
	public static function head_tags( Simple_SEO_Context $context ): array {
		if ( ! $context->is_front_page ) {
			return array();
		}

		$codes = (array) Simple_SEO_Settings::value( self::OPTION, 'verification' );
		$tags  = array();
		foreach ( self::VERIFICATION as $field => $name ) {
			$code = (string) ( $codes[ $field ] ?? '' );
			if ( '' !== $code ) {
				$tags[ $name ] = array(
					'tag'        => 'meta',
					'attributes' => array(
						'name'    => $name,
						'content' => $code,
					),
				);
			}
		}

		return $tags;
	}

	/**
	 * What the script of the tags gets: the IDs, how to load them, whether to wait for consent.
	 * Empty when there is nothing to load for this visitor.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$tags     = array_filter( array_map( 'strval', (array) ( $settings['tags'] ?? array() ) ) );
		$config   = array();
		if ( $tags && ! ( empty( $settings['editors'] ) && current_user_can( 'edit_posts' ) ) ) {
			$config = $tags + array(
				'lazy'    => ! empty( $settings['lazy'] ),
				'consent' => ! empty( $settings['consent'] ),
				'timer'   => self::LAZY_TIMER,
			);
		}

		/**
		 * The tags Simple SEO loads on a page: google, gtm, meta and tiktok IDs, lazy, consent and
		 * timer. An empty array loads none.
		 *
		 * @param array<string, mixed> $config
		 */
		$config = apply_filters( 'simple_seo_integrations_tags', $config );

		return is_array( $config ) ? $config : array();
	}

	public static function output(): void {
		if ( is_admin() || is_feed() || '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return;
		}

		echo self::script( self::config() ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with < and > escaped, the rest is ours.
	}

	/**
	 * The script of the tags; '' for none.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function script( array $config ): string {
		$ids = array();
		foreach ( self::TAGS as $field => $pattern ) {
			$id = is_scalar( $config[ $field ] ?? null ) ? (string) $config[ $field ] : '';
			if ( preg_match( $pattern, $id ) ) {
				$ids[ $field ] = $id;
			}
		}
		if ( ! $ids ) {
			return '';
		}

		$data = wp_json_encode(
			$ids + array(
				'lazy'    => ! empty( $config['lazy'] ),
				'consent' => ! empty( $config['consent'] ),
				'timer'   => max( 0, (int) ( $config['timer'] ?? self::LAZY_TIMER ) ),
			),
			JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
		);

		return "<script id=\"simple-seo-tags\">\n(function(c){" . self::loader() . "})({$data});\n</script>\n";
	}

	/**
	 * The loader: the queues at once, the scripts now or on the first move, consent by the theme.
	 */
	private static function loader(): string {
		return <<<'JS'
var w=window,d=document,started=false,granted=!c.consent;
var push=function(){w.dataLayer.push(arguments);};
var states=function(v){return {ad_storage:v,analytics_storage:v,ad_user_data:v,ad_personalization:v};};
if(c.google||c.gtm){w.dataLayer=w.dataLayer||[];w.gtag=w.gtag||push;if(c.consent){w.gtag('consent','default',Object.assign(states('denied'),{wait_for_update:500}));}}
if(c.google){w.gtag('js',new Date());w.gtag('config',c.google);}
if(c.gtm){w.dataLayer.push({'gtm.start':new Date().getTime(),event:'gtm.js'});}
if(c.meta&&!w.fbq){var f=w.fbq=function(){f.callMethod?f.callMethod.apply(f,arguments):f.queue.push(arguments);};w._fbq=w._fbq||f;f.push=f;f.loaded=true;f.version='2.0';f.queue=[];if(c.consent){f('consent','revoke');}f('init',c.meta);f('track','PageView');}
if(c.tiktok&&!w.ttq){w.TiktokAnalyticsObject='ttq';var t=w.ttq=[];t.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie','holdConsent','revokeConsent','grantConsent'];t.setAndDefer=function(o,m){o[m]=function(){o.push([m].concat([].slice.call(arguments)));};};t.methods.forEach(function(m){t.setAndDefer(t,m);});t.instance=function(id){var i=t._i[id]||[];t.methods.forEach(function(m){t.setAndDefer(i,m);});return i;};t._i={};t._i[c.tiktok]=[];t._i[c.tiktok]._u='https://analytics.tiktok.com/i18n/pixel/events.js';t._t={};t._t[c.tiktok]=+new Date();t._o={};t._o[c.tiktok]={};if(c.consent){t.holdConsent();}t.page();}
var add=function(src){var s=d.createElement('script');s.async=true;s.src=src;(d.head||d.documentElement).appendChild(s);};
var start=function(){if(started){return;}started=true;
if(c.google){add('https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(c.google));}
if(c.gtm){add('https://www.googletagmanager.com/gtm.js?id='+encodeURIComponent(c.gtm));}
if(c.meta){add('https://connect.facebook.net/en_US/fbevents.js');}
if(c.tiktok){add('https://analytics.tiktok.com/i18n/pixel/events.js?sdkid='+encodeURIComponent(c.tiktok)+'&lib=ttq');}};
w.simpleSeoConsent=function(yes){granted=!!yes;var v=granted?'granted':'denied';
if(w.gtag&&(c.google||c.gtm)){w.gtag('consent','update',states(v));}
if(c.meta&&w.fbq){w.fbq('consent',granted?'grant':'revoke');}
if(c.tiktok&&w.ttq){w.ttq[granted?'grantConsent':'revokeConsent']();}};
d.addEventListener('simple-seo-consent',function(e){w.simpleSeoConsent(!!(e.detail&&e.detail.granted));});
if(!c.lazy){start();return;}
var events=['scroll','pointerdown','keydown','touchstart','wheel'];
var go=function(){events.forEach(function(e){w.removeEventListener(e,go,{passive:true});});start();};
events.forEach(function(e){w.addEventListener(e,go,{passive:true,once:true});});
d.addEventListener('visibilitychange',function(){if('hidden'===d.visibilityState){go();}});
w.addEventListener('pagehide',go);
var later=function(){setTimeout(go,c.timer);};
if('complete'===d.readyState){later();}else{w.addEventListener('load',later);}
JS;
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$codes       = array();
		$input_codes = is_array( $input['verification'] ?? null ) ? $input['verification'] : array();
		foreach ( array_keys( self::VERIFICATION ) as $field ) {
			$code = self::sanitize_code( $input_codes[ $field ] ?? '' );
			if ( '' !== $code ) {
				$codes[ $field ] = $code;
			}
		}

		$tags       = array();
		$input_tags = is_array( $input['tags'] ?? null ) ? $input['tags'] : array();
		foreach ( self::TAGS as $field => $pattern ) {
			$id = self::sanitize_id( $field, $input_tags[ $field ] ?? '' );
			if ( '' !== $id ) {
				$tags[ $field ] = $id;
			}
		}

		return array(
			'verification' => $codes,
			'tags'         => $tags,
			'lazy'         => ! isset( $input['lazy'] ) || '1' === (string) $input['lazy'],
			'consent'      => isset( $input['consent'] ) && '1' === (string) $input['consent'],
			'editors'      => isset( $input['editors'] ) && '1' === (string) $input['editors'],
		);
	}

	/**
	 * A verification code as the tools give it: the code, or the whole meta tag pasted (its
	 * content is taken). Letters, digits and - _ : . = + / only; '' for anything else.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_code( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $value, $match ) ) {
			$value = trim( $match[1] );
		}

		return preg_match( '#^[A-Za-z0-9_\-:.=+/]{4,128}$#', $value ) ? $value : '';
	}

	/**
	 * A tag's ID as its service shows it (G-…, GTM-…, digits of a pixel), found in a pasted
	 * snippet too; '' for anything else.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_id( string $field, $value ): string {
		$value = strtoupper( is_scalar( $value ) ? trim( (string) $value ) : '' );
		if ( preg_match( self::TAGS[ $field ], $value ) ) {
			return $value;
		}

		$found = self::find_ids( $value );

		return $found[ $field ][0] ?? '';
	}

	/**
	 * The IDs of these services in a piece of code: a snippet pasted into the field, or what a
	 * theme prints.
	 *
	 * @return array<string, string[]> field => IDs.
	 */
	public static function find_ids( string $code ): array {
		$patterns = array(
			'google' => '/(?:gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]|[?&]id=|measurement_?id[\'"]?\s*[:=]\s*[\'"]?)((?:G|GT|AW|DC)-[A-Z0-9]{4,20})/i',
			'gtm'    => '/\b(GTM-[A-Z0-9]{4,12})\b/i',
			'meta'   => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"]?(\d{6,20})/i',
			'tiktok' => '/(?:ttq\.load\(\s*[\'"]|sdkid=)([A-Z0-9]{10,30})/i',
		);

		$found = array();
		foreach ( $patterns as $field => $pattern ) {
			if ( preg_match_all( $pattern, $code, $matches ) ) {
				$found[ $field ] = array_values( array_unique( array_map( 'strtoupper', $matches[1] ) ) );
			}
		}

		return $found;
	}

	/**
	 * What the theme prints of these services already, from its options: field => IDs. A measurement
	 * ID of its own (ga4_measurement_id) counts as Google's.
	 *
	 * @return array<string, string[]>
	 */
	public static function theme_tags(): array {
		$found = array();
		foreach ( self::THEME_OPTIONS as $option ) {
			$value = get_option( $option );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			$id  = strtoupper( trim( $value ) );
			$ids = 'ga4_measurement_id' === $option
				? ( preg_match( self::TAGS['google'], $id ) ? array( 'google' => array( $id ) ) : array() )
				: self::find_ids( $value );
			foreach ( $ids as $field => $list ) {
				$found[ $field ] = array_values( array_unique( array_merge( $found[ $field ] ?? array(), $list ) ) );
			}
		}

		return $found;
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$theme    = self::theme_tags();

		require SIMPLE_SEO_DIR . 'includes/views/tab-integrations.php';
	}
}
