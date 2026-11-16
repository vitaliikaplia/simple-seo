<?php
/**
 * AI in the SEO block: a button next to the SEO title, the meta description and the focus
 * keyphrase writes them from the page through the AI Client of WordPress 7.0 — whichever provider
 * the site connected in Settings → Connectors. Without a model that writes text there are no
 * buttons.
 *
 * Images get their alt text from what the AI sees in them: a button in the media library, the
 * empty alt texts in bulk editing, and each new image in the background after it is uploaded. An
 * alt text someone wrote is never replaced.
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

	/** WP-Cron event: the alt text of a new image, with its ID. */
	public const ALT_EVENT = 'simple_seo_ai_alt';

	/** The kinds of image the AI is shown. */
	public const IMAGE_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** The copy of an image the AI is shown, the first there is: enough to see it, cheaper to send. */
	private const IMAGE_SIZES = array( 'large', 'medium_large', 'medium' );

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

	/** Variants of an alt text asked at once; the first whole one is taken. */
	public const ALT_VARIANTS = 3;

	/** Times the AI is asked at most when every text of its answer has a broken word. */
	public const ATTEMPTS = 3;

	/** Fewer characters leave room for words that would help the page (as the analysis says). */
	private const SHORT = array(
		'title'       => 30,
		'description' => 70,
	);

	private static ?bool $ready = null;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'variants'   => 1,
				'alt_upload' => true,
			),
			array( self::class, 'sanitize' ),
			false
		);

		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		// After wp-loc (10): its copies of the image in the other languages are there by then.
		add_action( 'add_attachment', array( self::class, 'uploaded' ), 20 );
		add_action( self::ALT_EVENT, array( self::class, 'alt_event' ) );
		add_filter( 'attachment_fields_to_edit', array( self::class, 'media_field' ), 10, 2 );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_media' ) );
		// A key saved or removed in Settings → Connectors: ask again.
		add_action( 'added_option', array( self::class, 'flush_option' ) );
		add_action( 'updated_option', array( self::class, 'flush_option' ) );
		add_action( 'deleted_option', array( self::class, 'flush_option' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{variants: int, alt_upload: bool}
	 */
	public static function sanitize( array $input ): array {
		return array(
			'variants'   => max( 1, min( self::MAX_VARIANTS, (int) ( $input['variants'] ?? 1 ) ) ),
			'alt_upload' => ! isset( $input['alt_upload'] ) || '1' === (string) $input['alt_upload'],
		);
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
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/ai/alt',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_alt' ),
				'permission_callback' => static fn( WP_REST_Request $request ): bool => self::can_alt( (int) $request['id'] ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
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

	/** Whoever edits the SEO fields and may edit the image may have the AI write its alt text. */
	public static function can_alt( int $attachment_id ): bool {
		$post = get_post( $attachment_id );

		return $post instanceof WP_Post && wp_attachment_is_image( $post )
			&& current_user_can( 'edit_post', $attachment_id ) && current_user_can( Simple_SEO_Access::CAP_FIELDS );
	}

	/** @return array{alt: string}|WP_Error */
	public static function rest_alt( WP_REST_Request $request ) {
		$alt = self::alt( get_post( (int) $request['id'] ) );

		return is_wp_error( $alt ) ? $alt : array( 'alt' => $alt );
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

		$texts = self::ask( self::prompt( $field, $count, $page, $line ), self::instruction( $page['language'], $count ), $field );
		if ( is_wp_error( $texts ) ) {
			return $texts;
		}

		$limit    = 'description' === $field ? Simple_SEO_Text_Width::DESCRIPTION : Simple_SEO_Text_Width::TITLE;
		$variants = array();
		foreach ( $texts as $text ) {
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

		return array_slice( array_values( $variants ), 0, $count );
	}

	/**
	 * Ask the AI: the texts of its answer as JSON of a schema, or of the same JSON as text from a
	 * model that cannot keep to one. Models sometimes break a word with a piece of another script
	 * ("волcurrent" for «волоссям»): such texts are left out, and when none is left the AI is asked
	 * again, told it broke a word — ATTEMPTS times in all.
	 *
	 * @param string                           $field What the texts are for, as parse() reads them.
	 * @param array{0: string, 1: string}|null $file  An image to show the AI: path and type.
	 * @return string[]|WP_Error
	 */
	private static function ask( string $prompt, string $system, string $field, ?array $file = null ) {
		$build = static function ( bool $json, string $prompt ) use ( $system, $file ) {
			$builder = wp_ai_client_prompt( $prompt );
			if ( null !== $file ) {
				$builder = $builder->with_file( $file[0], $file[1] );
			}
			$builder = $builder->using_system_instruction( $system );

			return $json ? $builder->as_json_response(
				array(
					'type'                 => 'object',
					'properties'           => array(
						'variants' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'             => array( 'variants' ),
					'additionalProperties' => false,
				)
			) : $builder;
		};

		$texts = array();
		$asked = $prompt;
		try {
			$json = true === $build( true, $prompt )->is_supported_for_text_generation();
			if ( null !== $file && ! $json && true !== $build( false, $prompt )->is_supported_for_text_generation() ) {
				return new WP_Error( 'simple_seo_ai_blind', __( 'The connected AI does not read images: connect a provider whose models do.', 'simple-seo' ), array( 'status' => 500 ) );
			}
			for ( $try = 0; $try < self::ATTEMPTS; $try++ ) {
				$answer = $build( $json, $asked )->generate_text();
				if ( is_wp_error( $answer ) ) {
					break;
				}
				$all   = self::parse( (string) $answer, $field );
				$texts = array_values( array_filter( $all, static fn( string $text ): bool => ! self::broken( $text ) ) );
				// Asked again only when every text it wrote has a broken word.
				if ( $texts || ! $all ) {
					break;
				}
				$asked = $prompt . "\n\nYour last answer broke a word with letters of another script inside it. Write every word whole, in the letters of its language.";
			}
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

		return $texts ? $texts : new WP_Error( 'simple_seo_ai_empty', __( 'The AI answered with nothing to put in the field. Try again.', 'simple-seo' ), array( 'status' => 500 ) );
	}

	/** A text with a word broken by another script: a Latin letter right next to a Cyrillic one. */
	public static function broken( string $text ): bool {
		return 1 === preg_match( '/\p{Cyrillic}\p{Latin}|\p{Latin}\p{Cyrillic}/u', $text );
	}

	/**
	 * The alt text of an image, written by the AI from what it sees in it, in the language of the
	 * image (wp-loc) or of the site.
	 *
	 * @return string|WP_Error
	 */
	public static function alt( WP_Post $attachment ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'simple_seo_ai_unavailable', __( 'AI is not available on this site: connect a provider in Settings → Connectors.', 'simple-seo' ), array( 'status' => 500 ) );
		}

		$file = self::image_file( $attachment );
		if ( null === $file ) {
			return new WP_Error( 'simple_seo_ai_image', __( 'This image cannot be shown to the AI: only JPEG, PNG, GIF and WebP files of this site.', 'simple-seo' ), array( 'status' => 400 ) );
		}

		$system = implode(
			"\n",
			array(
				'You write the alt text of images for a website: what the picture shows, specific and accurate, never invented — say only what you see.',
				'Write in ' . self::language( $attachment ) . ', whatever language the instructions are in.',
				'Plain text only: no quotation marks around the text, no emoji, no hashtags, no Markdown.',
				// Several, the first whole one taken: a model that breaks a word does not break it in each.
				'Answer with JSON only, ' . self::how_many( self::ALT_VARIANTS, 'variant' ) . ': {"variants": ["…"]}',
			)
		);

		// Read as a description is: a sentence keeps its full stop.
		$texts = self::ask( self::alt_prompt( $attachment ), $system, 'description', $file );

		return is_wp_error( $texts ) ? $texts : $texts[0];
	}

	/** What the AI is told of an image besides the picture: the site, where it is, its name. */
	private static function alt_prompt( WP_Post $attachment ): string {
		$lines  = array( self::website() );
		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		if ( $parent instanceof WP_Post && '' !== trim( $parent->post_title ) ) {
			$type    = get_post_type_object( $parent->post_type );
			$lines[] = 'The image is on the ' . ( $type ? (string) $type->labels->singular_name : $parent->post_type ) . ' "' . Simple_SEO_Variables::plain( $parent->post_title ) . '"';
		}
		$name = Simple_SEO_Images::text_for( $attachment );
		if ( '' !== $name ) {
			$lines[] = 'Its file is named: ' . $name;
		}

		$lines[] = '';
		$lines[] = sprintf( 'Write the alt text of this image: what it shows, for search engines and for people who do not see it, in one short sentence of at most %d characters. Do not start with "Image of" or "Picture of".', Simple_SEO_Images::MAX_LENGTH );

		return implode( "\n", $lines );
	}

	/**
	 * The file of an image the AI is shown: a smaller copy when there is one; null for a kind of
	 * image the AI is not shown or a file that is not on this server.
	 *
	 * @return array{0: string, 1: string}|null Path and type.
	 */
	public static function image_file( WP_Post $attachment ): ?array {
		$path = (string) get_attached_file( $attachment->ID );
		$type = (string) get_post_mime_type( $attachment );
		if ( ! in_array( $type, self::IMAGE_TYPES, true ) || ! is_file( $path ) ) {
			return null;
		}

		$sizes = (array) ( wp_get_attachment_metadata( $attachment->ID )['sizes'] ?? array() );
		foreach ( self::IMAGE_SIZES as $size ) {
			$copy = isset( $sizes[ $size ]['file'] ) ? path_join( dirname( $path ), (string) $sizes[ $size ]['file'] ) : '';
			$kind = (string) ( $sizes[ $size ]['mime-type'] ?? $type );
			if ( '' !== $copy && is_file( $copy ) && in_array( $kind, self::IMAGE_TYPES, true ) ) {
				return array( $copy, $kind );
			}
		}

		return array( $path, $type );
	}

	/**
	 * A new image: its alt text is written in the background (WP-Cron), so the upload does not
	 * wait for the AI.
	 *
	 * @param mixed $attachment_id
	 */
	public static function uploaded( $attachment_id ): void {
		$post = get_post( (int) $attachment_id );
		if ( ! $post instanceof WP_Post || ! wp_attachment_is_image( $post ) || ! self::sanitize( Simple_SEO_Settings::get( self::OPTION ) )['alt_upload'] || ! self::ready() ) {
			return;
		}

		wp_schedule_single_event( time(), self::ALT_EVENT, array( (int) $post->ID ) );
	}

	/**
	 * The event of a new image: the AI's alt text, unless someone wrote one — an empty alt text and
	 * the one made from the name of the file (Simple_SEO_Images) give way. What is no image the AI
	 * refuses (alt()).
	 *
	 * @param mixed $attachment_id
	 */
	public static function alt_event( $attachment_id ): void {
		$post = get_post( (int) $attachment_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$alt = trim( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) );
		if ( '' !== $alt && Simple_SEO_Images::text_for( $post ) !== $alt ) {
			return;
		}

		$new = self::alt( $post );
		if ( is_string( $new ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', wp_slash( $new ) );
		}
	}

	/**
	 * The button of the image in the media library and on its screen: it fills the alt text field,
	 * which WordPress then saves.
	 *
	 * @param mixed $fields
	 * @param mixed $post
	 * @return mixed
	 */
	public static function media_field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof WP_Post || null === self::image_file( $post ) || ! self::can_alt( (int) $post->ID ) || ! self::ready() ) {
			return $fields;
		}

		$data = array(
			'url'     => rest_url( Simple_SEO_Analysis::NAMESPACE . '/ai/alt' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'id'      => (int) $post->ID,
			'working' => __( 'Writing…', 'simple-seo' ),
			'failed'  => __( 'The AI could not write it. Try again.', 'simple-seo' ),
		);

		$fields['simple_seo_ai_alt'] = array(
			'label'         => __( 'AI', 'simple-seo' ),
			'input'         => 'html',
			'html'          => sprintf(
				'<button type="button" class="button simple-seo-ai-alt" data-simple-seo-ai-alt="%s">%s</button> <span class="simple-seo-ai-status" role="status" aria-live="polite"></span>',
				esc_attr( (string) wp_json_encode( $data ) ),
				esc_html__( 'Write the alt text with AI', 'simple-seo' )
			),
			'helps'         => __( 'The AI looks at the picture and writes what it shows into the alt text field.', 'simple-seo' ),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $fields;
	}

	/** The screen of an image has the button too. */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( 'post.php' === $hook && $screen && 'attachment' === $screen->post_type ) {
			self::enqueue_media();
		}
	}

	/** The script of the button of images, wherever the media library opens. */
	public static function enqueue_media(): void {
		if ( current_user_can( Simple_SEO_Access::CAP_FIELDS ) ) {
			wp_enqueue_script(
				'simple-seo-media',
				SIMPLE_SEO_URL . 'assets/js/media.js',
				array(),
				SIMPLE_SEO_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
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

	/** The first line of a prompt: the site's name and tagline. */
	private static function website(): string {
		$site    = Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) );
		$tagline = Simple_SEO_Variables::plain( (string) get_option( 'blogdescription' ) );

		return 'Website: ' . $site . ( '' !== $tagline ? ' — ' . $tagline : '' );
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
		$lines   = array( self::website() );
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
