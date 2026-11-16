<?php
/**
 * AI in the SEO block: a button next to the SEO title, the meta description and the focus
 * keyphrase writes them from the page through the AI Client of WordPress 7.0 — whichever provider
 * the site connected in Settings → Connectors. Without a model that writes text there are no
 * buttons.
 *
 * The title and the description keep their structure: the AI writes only the words in place of
 * {title} (or {excerpt}) of the field's template, and the other variables ({sep}, {site}, {page})
 * stay. How many variants it writes at a time (1–5) is set on the AI tab; one goes straight into
 * the field, of more the editor chooses. The language is the post's or the term's (wp-loc), else
 * the site's.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_AI {
	public const OPTION = 'simple_seo_ai';

	/** Whether a model can write text, kept until the providers or their keys change. */
	public const READY = 'simple_seo_ai_ready';

	/** The fields the AI writes. */
	public const FIELDS = array( 'title', 'description', 'keyphrase' );

	public const MAX_VARIANTS = 5;

	/** Characters of the text of the page the model reads at most. */
	public const MAX_TEXT = 8000;

	/** Where the AI's words go in the line the prompt shows. */
	private const MARK = '[TEXT]';

	/** The variable whose place the AI writes, by field, in order of preference. */
	private const CONTENT = array(
		'title'       => array( '{title}', '{excerpt}' ),
		'description' => array( '{excerpt}', '{title}' ),
	);

	/** Fewer characters leave room for words that would help the page (as the analysis says). */
	private const SHORT = array(
		'title'       => 30,
		'description' => 70,
	);

	private static ?bool $ready = null;

	public static function init(): void {
		Simple_SEO_Settings::register( self::OPTION, array( 'variants' => 1 ), array( self::class, 'sanitize' ), false );

		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		// A key saved or removed in Settings → Connectors: ask again.
		add_action( 'added_option', array( self::class, 'flush_option' ) );
		add_action( 'updated_option', array( self::class, 'flush_option' ) );
		add_action( 'deleted_option', array( self::class, 'flush_option' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{variants: int}
	 */
	public static function sanitize( array $input ): array {
		return array( 'variants' => max( 1, min( self::MAX_VARIANTS, (int) ( $input['variants'] ?? 1 ) ) ) );
	}

	/** How many variants the AI writes at a time. */
	public static function variants(): int {
		return self::sanitize( Simple_SEO_Settings::get( self::OPTION ) )['variants'];
	}

	/**
	 * Whether a model can write text, as the AI Client said last: asking it reaches the providers,
	 * so the answer is kept until a provider comes or goes or a key of Connectors changes.
	 */
	public static function ready(): bool {
		if ( ! self::allowed() ) {
			return false;
		}
		if ( null !== self::$ready ) {
			return self::$ready;
		}

		$stored = get_transient( self::READY );
		if ( is_array( $stored ) && ( $stored['providers'] ?? null ) === self::providers() ) {
			return self::$ready = ! empty( $stored['ready'] );
		}

		return self::check();
	}

	/** Ask the AI Client now whether a model can write text, and keep the answer. */
	public static function check(): bool {
		$ready = false;
		if ( self::allowed() ) {
			try {
				$ready = true === wp_ai_client_prompt( 'Hello' )->is_supported_for_text_generation();
			} catch ( Throwable $error ) {
				$ready = false;
			}
		}
		set_transient(
			self::READY,
			array(
				'providers' => self::providers(),
				'ready'     => $ready,
			),
			// No model now may be a network that failed: ask again sooner.
			$ready ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS
		);

		return self::$ready = $ready;
	}

	public static function flush(): void {
		self::$ready = null;
		delete_transient( self::READY );
	}

	/** @param mixed $option */
	public static function flush_option( $option ): void {
		if ( is_string( $option ) && 1 === preg_match( '/^connectors_ai_\w+_api_key$/', $option ) ) {
			self::flush();
		}
	}

	/** WordPress has the AI Client and lets this request use it. */
	private static function allowed(): bool {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	}

	/** The providers the AI Client knows: when they change, the answer of check() is old. */
	private static function providers(): string {
		try {
			$ids = array_map( 'strval', \WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds() );
		} catch ( Throwable $error ) {
			$ids = array();
		}
		sort( $ids );

		return implode( ',', $ids );
	}

	public static function register_route(): void {
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/ai',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => static fn( WP_REST_Request $request ): bool => self::can( (string) $request['object'], (int) $request['id'], (string) $request['field'] ),
				'args'                => array(
					'object'    => array(
						'type'     => 'string',
						'enum'     => array( 'post', 'term' ),
						'required' => true,
					),
					'id'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'field'     => array(
						'type'     => 'string',
						'enum'     => self::FIELDS,
						'required' => true,
					),
					'value'     => array( 'type' => 'string' ),
					'title'     => array( 'type' => 'string' ),
					'excerpt'   => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string' ),
					'keyphrase' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/** Whoever may edit the field of the post or term may have the AI write it. */
	public static function can( string $object_type, int $object_id, string $field ): bool {
		return in_array( $field, self::FIELDS, true )
			&& null !== Simple_SEO_Fields::object( $object_type, $object_id )
			&& Simple_SEO_Fields::user_can_edit( get_current_user_id(), $object_type, $object_id, $field );
	}

	/** @return array{variants: array<int, array{value: string, shown: string, fits: bool}>}|WP_Error */
	public static function rest( WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'value', 'title', 'excerpt', 'content', 'keyphrase' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		$variants = self::generate( Simple_SEO_Fields::object( (string) $request['object'], (int) $request['id'] ), (string) $request['field'], $input );

		return is_wp_error( $variants ) ? $variants : array( 'variants' => $variants );
	}

	/**
	 * Variants of a field written by the AI from the page: as saved, or with what the editor has now.
	 * Each has the value for the field (variables of the template kept), what the page shows with it,
	 * and whether that fits the width search results give it.
	 *
	 * @param WP_Post|WP_Term       $object
	 * @param string                $field title, description or keyphrase.
	 * @param array<string, string> $input value (of the field), title, excerpt (a term's description),
	 *                                     content, keyphrase.
	 * @return array<int, array{value: string, shown: string, fits: bool}>|WP_Error
	 */
	public static function generate( $object, string $field, array $input = array() ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'simple_seo_ai_unavailable', __( 'AI is not available on this site: connect a provider in Settings → Connectors.', 'simple-seo' ), array( 'status' => 500 ) );
		}

		$term    = $object instanceof WP_Term;
		$context = $term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );
		$page    = self::page( $object, $input );
		$value   = $input['value'] ?? ( $term ? Simple_SEO_Fields::term_value( (int) $object->term_id, $field ) : Simple_SEO_Fields::post_value( (int) $object->ID, $field ) );

		[ $mask, $variable ] = self::frame( $object, $field, $value );
		$line                = '' !== $mask ? Simple_SEO_Variables::replace( self::put( $mask, $variable, self::MARK ), $context, Simple_SEO_Titles::separator() ) : self::MARK;
		$count               = self::variants();

		$prompt = self::prompt( $field, $count, $page, $line );
		$system = self::instruction( $page['language'], $count );
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'variants' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'variants' ),
			'additionalProperties' => false,
		);

		try {
			$builder = wp_ai_client_prompt( $prompt )->using_system_instruction( $system )->as_json_response( $schema );
			// A model that cannot keep to a schema still answers: the prompt asks for the same JSON.
			if ( true !== $builder->is_supported_for_text_generation() ) {
				$builder = wp_ai_client_prompt( $prompt )->using_system_instruction( $system );
			}
			$answer = $builder->generate_text();
		} catch ( Throwable $error ) {
			$answer = new WP_Error( 'simple_seo_ai_error', $error->getMessage() );
		}
		if ( is_wp_error( $answer ) ) {
			return new WP_Error(
				'simple_seo_ai_failed',
				/* translators: %s: what the AI Client said. */
				sprintf( __( 'The AI could not write it: %s', 'simple-seo' ), $answer->get_error_message() ),
				array( 'status' => 500 )
			);
		}

		$limit    = 'description' === $field ? Simple_SEO_Text_Width::DESCRIPTION : Simple_SEO_Text_Width::TITLE;
		$variants = array();
		foreach ( self::parse( (string) $answer, $field ) as $text ) {
			$new   = '' !== $mask ? self::put( $mask, $variable, $text ) : $text;
			$shown = 'keyphrase' === $field ? $new : Simple_SEO_Variables::replace( $new, $context, Simple_SEO_Titles::separator() );
			if ( ! isset( $variants[ mb_strtolower( $new ) ] ) ) {
				$variants[ mb_strtolower( $new ) ] = array(
					'value' => $new,
					'shown' => $shown,
					'fits'  => 'keyphrase' === $field || Simple_SEO_Text_Width::fits( $shown, $limit ),
				);
			}
		}
		if ( ! $variants ) {
			return new WP_Error( 'simple_seo_ai_empty', __( 'The AI answered with nothing to put in the field. Try again.', 'simple-seo' ), array( 'status' => 500 ) );
		}

		return array_slice( array_values( $variants ), 0, $count );
	}

	/**
	 * What the prompt tells about the page: its kind and title, the focus keyphrase, the text (with
	 * its headings) and the language to write in.
	 *
	 * @param WP_Post|WP_Term       $object
	 * @param array<string, string> $input
	 * @return array{kind: string, title: string, keyphrase: string, text: string, language: string}
	 */
	private static function page( $object, array $input ): array {
		if ( $object instanceof WP_Term ) {
			$taxonomy = get_taxonomy( $object->taxonomy );

			return array(
				'kind'      => $taxonomy ? (string) $taxonomy->labels->singular_name : $object->taxonomy,
				'title'     => Simple_SEO_Variables::plain( $input['title'] ?? $object->name ),
				'keyphrase' => '',
				'text'      => mb_substr( Simple_SEO_Variables::plain( $input['excerpt'] ?? $object->description ), 0, self::MAX_TEXT ),
				'language'  => self::language( $object ),
			);
		}

		$type    = get_post_type_object( $object->post_type );
		$content = Simple_SEO_Content::read( $object, $input['content'] ?? null );
		$blocks  = array();
		foreach ( $content['blocks'] as $block ) {
			// Headings as Markdown: the model sees what the page is about part by part.
			$blocks[] = 'heading' === $block['type'] ? str_repeat( '#', max( 1, (int) ( $block['level'] ?? 2 ) ) ) . ' ' . $block['text'] : $block['text'];
		}
		$excerpt = Simple_SEO_Variables::plain( $input['excerpt'] ?? $object->post_excerpt );
		$text    = trim( ( '' !== $excerpt ? $excerpt . "\n\n" : '' ) . implode( "\n\n", $blocks ) );

		return array(
			'kind'      => $type ? (string) $type->labels->singular_name : $object->post_type,
			'title'     => Simple_SEO_Variables::plain( $input['title'] ?? $object->post_title ),
			'keyphrase' => Simple_SEO_Variables::plain( $input['keyphrase'] ?? Simple_SEO_Fields::post_value( (int) $object->ID, 'keyphrase' ) ),
			'text'      => mb_substr( $text, 0, self::MAX_TEXT ),
			'language'  => self::language( $object ),
		);
	}

	/**
	 * The language of the post or term (wp-loc), of a new one the language the admin edits in, else
	 * the site's: its English name and its locale, as "Ukrainian (uk)".
	 *
	 * @param WP_Post|WP_Term $object
	 */
	private static function language( $object ): string {
		[ $id, $type ] = $object instanceof WP_Term
			? array( (int) $object->term_taxonomy_id, 'tax_' . $object->taxonomy )
			: array( (int) $object->ID, 'post_' . $object->post_type );

		$code   = (string) ( Simple_SEO_WP_Loc::element_languages( array( $id ), $type )[ $id ] ?? '' );
		$code   = '' !== $code ? $code : Simple_SEO_WP_Loc::admin_language_code();
		$locale = ( '' !== $code ? Simple_SEO_WP_Loc::locale_of( $code ) : '' ) ?: get_locale();
		$name   = class_exists( 'Locale' ) ? (string) Locale::getDisplayLanguage( $locale, 'en' ) : '';

		return '' !== $name && $name !== $locale ? $name . ' (' . $locale . ')' : $locale;
	}

	/**
	 * The template the AI writes into and the variable whose place it takes: the field's own text
	 * when it has the variable, else the template of the post's or term's type; ['', ''] when neither
	 * has one — the AI then writes the whole field.
	 *
	 * @param WP_Post|WP_Term $object
	 * @return array{0: string, 1: string}
	 */
	private static function frame( $object, string $field, string $value ): array {
		if ( ! isset( self::CONTENT[ $field ] ) ) {
			return array( '', '' );
		}

		$definition = Simple_SEO_Fields::get( $field );
		$template   = is_callable( $definition['mask'] ?? null ) ? (string) call_user_func( $definition['mask'], $object instanceof WP_Term ? 'term' : 'post', $object ) : '';
		foreach ( array( $value, $template ) as $mask ) {
			foreach ( self::CONTENT[ $field ] as $variable ) {
				if ( str_contains( $mask, $variable ) ) {
					return array( $mask, $variable );
				}
			}
		}

		return array( '', '' );
	}

	/** The template with its first variable of that name replaced by a text. */
	private static function put( string $mask, string $variable, string $text ): string {
		return (string) preg_replace_callback( '/' . preg_quote( $variable, '/' ) . '/', static fn(): string => $text, $mask, 1 );
	}

	/**
	 * Fewest and most characters for the AI's words: what the width search results give the title or
	 * the description leaves after the rest of the line, in characters as wide as those of the page's
	 * own text (Cyrillic letters are wider than Latin ones).
	 *
	 * @param string $line The line the page shows, the AI's words as MARK.
	 * @return array{0: int, 1: int}
	 */
	public static function lengths( string $field, string $line, string $sample ): array {
		$limit  = 'description' === $field ? Simple_SEO_Text_Width::DESCRIPTION : Simple_SEO_Text_Width::TITLE;
		$rest   = trim( str_replace( self::MARK, ' ', $line ) );
		$room   = $limit[0] - ( '' !== $rest ? Simple_SEO_Text_Width::width( $rest . ' ', $limit[1] ) : 0 );
		$sample = mb_substr( Simple_SEO_Variables::plain( $sample ), 0, 2000 );
		$width  = '' !== $sample ? Simple_SEO_Text_Width::width( $sample, $limit[1] ) / mb_strlen( $sample ) : $limit[1] / 2;
		// A little under the width: models count characters loosely.
		$most = max( 10, (int) floor( $room * 0.9 / $width ) );

		return array( min( $most, max( (int) round( $most * 0.6 ), self::SHORT[ $field ] - mb_strlen( $rest ) ) ), $most );
	}

	/** What the model is and how it answers. */
	private static function instruction( string $language, int $count ): string {
		return implode(
			"\n",
			array(
				'You are an SEO copywriter. You write what search engines show for one page of a website: accurate to the page, specific, natural, never invented — say only what the page itself says.',
				'Write in ' . $language . ', whatever language the instructions are in.',
				'Plain text only: no quotation marks around the text, no emoji, no hashtags, no Markdown, no curly braces.',
				'Answer with JSON only, ' . self::how_many( $count, 'variant' ) . ': {"variants": ["…"]}',
			)
		);
	}

	/** "one variant", "3 different variants". */
	private static function how_many( int $count, string $noun ): string {
		return 1 === $count ? 'one ' . $noun : $count . ' different ' . $noun . 's';
	}

	/**
	 * The task: the page, what to write and how long.
	 *
	 * @param array{kind: string, title: string, keyphrase: string, text: string, language: string} $page
	 * @param string                                                                                  $line The line the page shows, the AI's words as MARK.
	 */
	private static function prompt( string $field, int $count, array $page, string $line ): string {
		$site    = Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) );
		$tagline = Simple_SEO_Variables::plain( (string) get_option( 'blogdescription' ) );
		$lines   = array( 'Website: ' . $site . ( '' !== $tagline ? ' — ' . $tagline : '' ) );
		$lines[] = 'Page: ' . $page['kind'] . ( '' !== $page['title'] ? ' "' . $page['title'] . '"' : '' );
		if ( 'keyphrase' !== $field && '' !== $page['keyphrase'] ) {
			$lines[] = 'Focus keyphrase: ' . $page['keyphrase'];
		}
		$lines[] = '';

		if ( 'keyphrase' === $field ) {
			$lines[] = 'Suggest ' . self::how_many( $count, 'focus keyphrase' ) . ' for this page: what people type into a search engine to find what it offers, 2 to 4 words, the way people search (lowercase except names). The page must answer that search.';
		} else {
			[ $fewest, $most ] = self::lengths( $field, $line, '' !== $page['text'] ? $page['text'] : $page['title'] );
			$lines[]           = 'title' === $field
				? sprintf( 'Write %s of the SEO title of this page: the headline search results show as its link, %d to %d characters. Say what the searcher finds on the page and make them want to open it, without clickbait.', self::how_many( $count, 'variant' ), $fewest, $most )
				: sprintf( 'Write %s of the meta description of this page: the text under its headline in search results, %d to %d characters, one or two full sentences: what the reader finds on the page and why to open it.', self::how_many( $count, 'variant' ), $fewest, $most );
			if ( self::MARK !== $line ) {
				$lines[] = sprintf( 'The site puts your words in place of %s in this line: "%s". Do not repeat what the rest of the line says.', self::MARK, $line );
			}
			if ( '' !== $page['keyphrase'] ) {
				$lines[] = 'title' === $field
					? 'Put the focus keyphrase near the start, in a natural form.'
					: 'Use the focus keyphrase once, in a natural form.';
			}
		}

		$lines[] = '';
		$lines[] = '' !== $page['text']
			? "Text of the page:\n\"\"\"\n" . $page['text'] . "\n\"\"\""
			: 'The page has no text yet: write from its title.';

		return implode( "\n", $lines );
	}

	/**
	 * The variants in the model's answer: the JSON asked for, the JSON inside other words, or one a
	 * line; each as plain text without quotes around it or curly braces.
	 *
	 * @return string[]
	 */
	public static function parse( string $answer, string $field ): array {
		$answer = trim( (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( $answer ) ) );
		$data   = json_decode( $answer, true );
		if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', $answer, $found ) ) {
			$data = json_decode( $found[0], true );
		}

		if ( is_array( $data ) ) {
			$list = isset( $data['variants'] ) && is_array( $data['variants'] ) ? $data['variants'] : ( array_is_list( $data ) ? $data : array() );
		} else {
			// Numbers or bullets in front of the lines go.
			$list = preg_replace( '/^\s*(?:[-*•]|\d+[.)])\s+/u', '', (array) preg_split( '/\R/u', $answer ) );
		}

		$texts = array();
		foreach ( (array) $list as $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}
			$text = str_replace( array( '{', '}' ), '', Simple_SEO_Variables::plain( $text ) );
			if ( preg_match( '/^["“„«]([^"“”„«»]*)["”“»]$/u', $text, $quoted ) ) {
				$text = trim( $quoted[1] );
			}
			// A title or a keyphrase is no sentence: no full stop at its end.
			if ( 'description' !== $field && preg_match( '/[^.]\.$/u', $text ) ) {
				$text = mb_substr( $text, 0, -1 );
			}
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return $texts;
	}

	/**
	 * The button next to a field in the SEO block, with the place for the variants; '' for a field the
	 * AI does not write or when no model can.
	 *
	 * @param WP_Post|WP_Term $object
	 */
	public static function button( string $field, string $object_type, $object ): string {
		if ( ! in_array( $field, self::FIELDS, true ) || ! self::ready() ) {
			return '';
		}

		$data = array(
			'url'     => rest_url( Simple_SEO_Analysis::NAMESPACE . '/ai' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'object'  => $object_type,
			'id'      => $object instanceof WP_Term ? (int) $object->term_id : (int) $object->ID,
			'field'   => $field,
			'target'  => 'simple-seo-field-' . $field,
			'working' => __( 'Writing…', 'simple-seo' ),
			'failed'  => __( 'The AI could not write it. Try again.', 'simple-seo' ),
			'choose'  => __( 'Choose one:', 'simple-seo' ),
			'long'    => __( 'longer than search results show', 'simple-seo' ),
		);

		return sprintf(
			'<span class="simple-seo-ai" data-simple-seo-ai="%s"><button type="button" class="button simple-seo-ai-button">%s</button> <span class="simple-seo-ai-status description" role="status" aria-live="polite"></span><span class="simple-seo-ai-variants" hidden></span></span>',
			esc_attr( (string) wp_json_encode( $data ) ),
			esc_html__( 'Write with AI', 'simple-seo' )
		);
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'ai', __( 'AI', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 48 );
	}

	public static function render_tab(): void {
		$settings = self::sanitize( Simple_SEO_Settings::get( self::OPTION ) );
		// The tab tells the truth of now, and the editors get it too.
		$status = ! self::allowed() ? 'off' : ( self::check() ? 'ready' : 'none' );

		require SIMPLE_SEO_DIR . 'includes/views/tab-ai.php';
	}
}
