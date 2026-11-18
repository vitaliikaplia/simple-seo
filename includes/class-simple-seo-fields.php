<?php
/**
 * Registry of the per-post and per-term SEO fields and their storage.
 *
 * Each field is its own meta key, _simple_seo_{key}, on posts and terms. An empty value is
 * never stored: the row is deleted, so only what an editor actually set exists in the database
 * and everything else falls back to the templates. Keys start with an underscore, so the core
 * Custom Fields box does not show them; REST access goes through the auth callbacks below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Fields {
	public const META_PREFIX = '_simple_seo_';

	/** Basic fields: anyone with CAP_FIELDS who can edit the object. */
	public const LEVEL_BASIC = 'basic';

	/** Fields that change indexing: CAP_ADVANCED as well. */
	public const LEVEL_ADVANCED = 'advanced';

	/** @var array<string, array<string, mixed>> */
	private static array $fields = array();

	/** @var array<string, string> Group => heading shown above its fields in the editor. */
	private static array $groups = array();

	/** @var string[]|null */
	private static ?array $post_types = null;

	/** @var string[]|null */
	private static ?array $taxonomies = null;

	/** Whether the meta of the registered fields is registered already. */
	private static bool $meta_registered = false;

	public static function init(): void {
		// Late enough for post types and taxonomies that themes register on init. The parts of the
		// plugin register their fields earlier on init (labels are translated, so not before init).
		add_action( 'init', array( self::class, 'register_all_meta' ), 99 );

		// An empty value is never stored, however it comes: the REST API, update_post_meta().
		add_filter( 'update_post_metadata', array( self::class, 'no_empty_post' ), 10, 4 );
		add_filter( 'add_post_metadata', array( self::class, 'no_empty_post' ), 10, 4 );
		add_filter( 'update_term_metadata', array( self::class, 'no_empty_term' ), 10, 4 );
		add_filter( 'add_term_metadata', array( self::class, 'no_empty_term' ), 10, 4 );
	}

	/**
	 * Register a field, on init or later (its label is translated). Once the meta of the other
	 * fields is registered, a new field registers its meta right away.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string   $label       Field label.
	 *     @type string   $description Help text under the field.
	 *     @type string   $type        text, textarea, code (a text kept as typed, monospaced), url,
	 *                                 checkbox, select or image (attachment ID).
	 *     @type string[] $objects     Where the field exists: post, term or both.
	 *     @type string[] $post_types  Only posts of these types get the field ([] — every type with fields).
	 *     @type string   $level       LEVEL_BASIC or LEVEL_ADVANCED.
	 *     @type array|callable $options Value => label, for select; or ( 'post'|'term', WP_Post|WP_Term ) → that.
	 *     @type callable $sanitize    Sanitizer of one value; the type decides when omitted.
	 *     @type int      $order       Position in the editor, lower first.
	 *     @type string   $group       Group in the editor ('' for the main fields): its heading is
	 *                                 printed above its first field the user can edit.
	 *     @type callable $mask        Optional: ( 'post'|'term', WP_Post|WP_Term ) → the template the
	 *                                 field falls back to; shown as the placeholder and inserted by
	 *                                 the "Insert structure" button.
	 *     @type callable $preview     Optional: ( 'post'|'term', WP_Post|WP_Term ) → what the page
	 *                                 shows now, under the field.
	 * }
	 */
	public static function register( string $key, array $args ): void {
		$key = sanitize_key( $key );

		self::$fields[ $key ] = array_merge(
			array(
				'label'       => $key,
				'description' => '',
				'type'        => 'text',
				'objects'     => array( 'post', 'term' ),
				'post_types'  => array(),
				'level'       => self::LEVEL_BASIC,
				'options'     => array(),
				'sanitize'    => null,
				'order'       => 50,
				'group'       => '',
				'mask'        => null,
				'preview'     => null,
				// Whether it takes variables: the SEO block shows them as chips with their values.
				'variables'   => false,
			),
			$args,
			array( 'key' => $key )
		);

		if ( self::$meta_registered ) {
			self::register_meta( $key );
		}
	}

	/** @return array<string, array<string, mixed>> Fields for one object type, in editor order. */
	public static function all( string $object_type = '' ): array {
		$fields = self::$fields;
		if ( '' !== $object_type ) {
			$fields = array_filter(
				$fields,
				static fn( array $field ): bool => in_array( $object_type, (array) $field['objects'], true )
			);
		}

		uasort( $fields, static fn( array $a, array $b ): int => (int) $a['order'] <=> (int) $b['order'] );

		return $fields;
	}

	/** Name a group of fields; call it on init (the heading is translated). */
	public static function register_group( string $group, string $heading ): void {
		self::$groups[ sanitize_key( $group ) ] = $heading;
	}

	public static function group_heading( string $group ): string {
		return self::$groups[ $group ] ?? '';
	}

	/** @return array<string, mixed>|null */
	public static function get( string $key ): ?array {
		return self::$fields[ $key ] ?? null;
	}

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	public static function register_all_meta(): void {
		if ( self::$meta_registered ) {
			return;
		}
		self::$meta_registered = true;

		foreach ( array_keys( self::$fields ) as $key ) {
			self::register_meta( $key );
		}
	}

	private static function register_meta( string $key ): void {
		$field = self::$fields[ $key ];
		$types = array(
			'checkbox' => 'boolean',
			'image'    => 'integer',
		);
		$type  = $types[ $field['type'] ] ?? 'string';

		foreach ( (array) $field['objects'] as $object_type ) {
			register_meta(
				$object_type,
				self::meta_key( $key ),
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $value ) => self::sanitize_value( $key, $value ),
					'auth_callback'     => static fn( $allowed, $meta_key, $object_id, $user_id ) => self::user_can_edit( (int) $user_id, $object_type, (int) $object_id, $key ),
				)
			);
		}
	}

	/** Public post types that get SEO fields. Filter: simple_seo_post_types. */
	public static function post_types(): array {
		if ( null === self::$post_types ) {
			$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

			/** @param string[] $types Post type names. */
			self::$post_types = array_values( array_filter( (array) apply_filters( 'simple_seo_post_types', $types ), 'post_type_exists' ) );
		}

		return self::$post_types;
	}

	/** Public taxonomies that get SEO fields. Filter: simple_seo_taxonomies. */
	public static function taxonomies(): array {
		if ( null === self::$taxonomies ) {
			$taxonomies = array_values( array_diff( get_taxonomies( array( 'public' => true ) ), array( 'post_format' ) ) );

			/** @param string[] $taxonomies Taxonomy names. */
			self::$taxonomies = array_values( array_filter( (array) apply_filters( 'simple_seo_taxonomies', $taxonomies ), 'taxonomy_exists' ) );
		}

		return self::$taxonomies;
	}

	/** Forget the computed lists, e.g. after a post type was registered late. */
	public static function flush_types(): void {
		self::$post_types = null;
		self::$taxonomies = null;
	}

	/**
	 * Whether a user may edit one field of one post or term.
	 *
	 * @param string $object_type post or term.
	 */
	public static function user_can_edit( int $user_id, string $object_type, int $object_id, string $key ): bool {
		$field = self::$fields[ $key ] ?? null;
		if ( null === $field || ! $user_id ) {
			return false;
		}

		if ( ! in_array( $object_type, (array) $field['objects'], true ) ) {
			return false;
		}
		if ( 'post' === $object_type && $field['post_types'] && ! in_array( get_post_type( $object_id ), (array) $field['post_types'], true ) ) {
			return false;
		}

		$object_cap = 'term' === $object_type ? 'edit_term' : 'edit_post';
		if ( ! user_can( $user_id, $object_cap, $object_id ) || ! user_can( $user_id, Simple_SEO_Access::CAP_FIELDS ) ) {
			return false;
		}

		return self::LEVEL_ADVANCED !== $field['level'] || user_can( $user_id, Simple_SEO_Access::CAP_ADVANCED );
	}

	/** @param mixed $value */
	public static function sanitize_value( string $key, $value ) {
		$field = self::$fields[ $key ] ?? null;
		if ( null === $field ) {
			return '';
		}

		if ( is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value );
		}

		switch ( $field['type'] ) {
			case 'checkbox':
				return (bool) $value;
			case 'textarea':
				return trim( sanitize_textarea_field( (string) $value ) );
			case 'code':
				return is_scalar( $value ) ? trim( wp_check_invalid_utf8( (string) $value ) ) : '';
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			case 'select':
				// Options that depend on the object come with a sanitizer of their own.
				$value = (string) $value;
				return is_array( $field['options'] ) && array_key_exists( $value, $field['options'] ) ? $value : '';
			case 'image':
				$id = self::image_attachment( $value );
				return $id ? $id : '';
			default:
				return trim( sanitize_text_field( (string) $value ) );
		}
	}

	/**
	 * An image from the media library, by attachment ID; 0 for anything else.
	 *
	 * @param mixed $value
	 */
	public static function image_attachment( $value ): int {
		$id = absint( $value );

		return $id && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** A stored value of a post field, '' when not set. */
	public static function post_value( int $post_id, string $key ): string {
		return (string) get_post_meta( $post_id, self::meta_key( $key ), true );
	}

	/** A stored value of a term field, '' when not set. */
	public static function term_value( int $term_id, string $key ): string {
		return (string) get_term_meta( $term_id, self::meta_key( $key ), true );
	}

	/**
	 * Save submitted values of the fields the current user may edit; empty values delete the row.
	 *
	 * @param string               $object_type post or term.
	 * @param array<string, mixed> $input       Unslashed field key => raw value.
	 * @return string[] Keys that were written or deleted.
	 */
	public static function save( string $object_type, int $object_id, array $input ): array {
		$user_id = get_current_user_id();
		$changed = array();

		foreach ( self::all( $object_type ) as $key => $field ) {
			if ( ! self::user_can_edit( $user_id, $object_type, $object_id, $key ) ) {
				continue;
			}

			// A field missing from the input is left as it is, checkboxes included: the editor form
			// sends an empty hidden value before each checkbox, so an unchecked box arrives as ''.
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value    = self::sanitize_value( $key, $input[ $key ] );
			$meta_key = self::meta_key( $key );
			$empty    = false === $value || '' === $value;

			if ( $empty ) {
				delete_metadata( $object_type, $object_id, $meta_key );
			} else {
				// update_metadata() expects slashed data and unslashes it itself.
				update_metadata( $object_type, $object_id, $meta_key, wp_slash( $value ) );
			}
			$changed[] = $key;
		}

		return $changed;
	}

	// ----------------------------------------------------------------------------------------
	// The fields of one post or term, for the REST API, WP-CLI and the abilities.

	/**
	 * A post or term that has SEO fields: of a post type or taxonomy that gets them; null otherwise.
	 *
	 * @param string $object_type post or term.
	 * @return WP_Post|WP_Term|null
	 */
	public static function object( string $object_type, int $object_id ) {
		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );

			return $term instanceof WP_Term && in_array( $term->taxonomy, self::taxonomies(), true ) ? $term : null;
		}

		// get_post( 0 ) is the post of the loop.
		$post = $object_id > 0 ? get_post( $object_id ) : null;

		return $post instanceof WP_Post && in_array( $post->post_type, self::post_types(), true ) ? $post : null;
	}

	/**
	 * The fields of terms, or of posts of a type ('' — of any type), in editor order.
	 *
	 * @param string $object_type post or term.
	 * @return array<string, array<string, mixed>>
	 */
	public static function available( string $object_type, string $post_type = '' ): array {
		return array_filter(
			self::all( $object_type ),
			static fn( array $field ): bool => '' === $post_type || ! $field['post_types'] || in_array( $post_type, (array) $field['post_types'], true )
		);
	}

	/**
	 * The values of the fields of a post or term, as their types hold them: a checkbox true or false,
	 * an image its attachment ID (0 for none), the rest text ('' for none). With a user, only the
	 * fields that user may edit; [] for a post or term without SEO fields.
	 *
	 * @param string $object_type post or term.
	 * @return array<string, string|bool|int>
	 */
	public static function values( string $object_type, int $object_id, ?int $user_id = null ): array {
		$object = self::object( $object_type, $object_id );
		if ( null === $object ) {
			return array();
		}

		$values = array();
		foreach ( self::available( $object_type, $object instanceof WP_Post ? $object->post_type : '' ) as $key => $field ) {
			if ( null !== $user_id && ! self::user_can_edit( $user_id, $object_type, $object_id, $key ) ) {
				continue;
			}

			$stored = 'term' === $object_type ? self::term_value( $object_id, $key ) : self::post_value( $object_id, $key );
			switch ( $field['type'] ) {
				case 'checkbox':
					$values[ $key ] = (bool) $stored;
					break;
				case 'image':
					$values[ $key ] = (int) $stored;
					break;
				default:
					$values[ $key ] = $stored;
			}
		}

		return $values;
	}

	/**
	 * Write the fields given of a post or term, all of them or none: a field it does not have, one
	 * the user may not edit, or a value a field cannot hold stops the write before anything is
	 * stored. An empty value ('', null, false; 0 for an image) deletes the row. Without a user only
	 * the values are checked (WP-CLI: whoever runs it runs the site).
	 *
	 * @param string               $object_type post or term.
	 * @param array<string, mixed> $input       Field key => value, unslashed.
	 * @return string[]|WP_Error The keys written or deleted.
	 */
	public static function write( string $object_type, int $object_id, array $input, ?int $user_id = null ) {
		$object = self::object( $object_type, $object_id );
		if ( null === $object ) {
			return new WP_Error(
				'simple_seo_no_object',
				'term' === $object_type ? __( 'There is no such term with SEO fields.', 'simple-seo' ) : __( 'There is no such post with SEO fields.', 'simple-seo' ),
				array( 'status' => 404 )
			);
		}

		$fields = self::available( $object_type, $object instanceof WP_Post ? $object->post_type : '' );
		$values = array();
		foreach ( $input as $key => $raw ) {
			$key = (string) $key;
			if ( ! isset( $fields[ $key ] ) ) {
				return new WP_Error(
					'simple_seo_unknown_field',
					/* translators: %s: key of a field, e.g. title. */
					sprintf( __( 'There is no SEO field %s here.', 'simple-seo' ), $key ),
					array(
						'status' => 400,
						'field'  => $key,
					)
				);
			}
			if ( null !== $user_id && ! self::user_can_edit( $user_id, $object_type, $object_id, $key ) ) {
				return new WP_Error(
					'simple_seo_cannot_edit',
					/* translators: %s: key of a field, e.g. title. */
					sprintf( __( 'You may not edit the SEO field %s.', 'simple-seo' ), $key ),
					array(
						'status' => 403,
						'field'  => $key,
					)
				);
			}

			$value = self::input_value( $key, $fields[ $key ], $raw );
			if ( null === $value ) {
				return new WP_Error(
					'simple_seo_invalid_value',
					self::invalid( $key, $fields[ $key ], $object_type, $object ),
					array(
						'status' => 400,
						'field'  => $key,
					)
				);
			}
			$values[ $key ] = $value;
		}

		foreach ( $values as $key => $value ) {
			if ( '' === $value ) {
				delete_metadata( $object_type, $object_id, self::meta_key( $key ) );
			} else {
				// update_metadata() expects slashed data and unslashes it itself.
				update_metadata( $object_type, $object_id, self::meta_key( $key ), wp_slash( $value ) );
			}
		}

		return array_keys( $values );
	}

	/**
	 * What a value given means for a field: '' to delete the row, the value to store, or null for a
	 * value the field cannot hold (one that cleaning leaves empty: a URL that is none, an unknown choice).
	 *
	 * @param array<string, mixed> $field
	 * @param mixed                $raw
	 * @return mixed
	 */
	private static function input_value( string $key, array $field, $raw ) {
		if ( 'checkbox' === $field['type'] ) {
			// true, false, 1, 0, '1', '0', 'yes', 'no', 'on', 'off', 'true', 'false' or ''.
			$on = is_scalar( $raw ) ? filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;

			return null === $on ? null : ( $on ? true : '' );
		}
		if ( null !== $raw && ! is_scalar( $raw ) ) {
			return null;
		}

		$text = trim( (string) $raw );
		if ( '' === $text || ( 'image' === $field['type'] && '0' === $text ) ) {
			return '';
		}

		$value = self::sanitize_value( $key, $raw );

		return '' === $value ? null : $value;
	}

	/**
	 * Why a field cannot hold a value, with what it takes.
	 *
	 * @param array<string, mixed> $field
	 * @param WP_Post|WP_Term      $object
	 */
	private static function invalid( string $key, array $field, string $object_type, $object ): string {
		switch ( $field['type'] ) {
			case 'checkbox':
				/* translators: %s: key of a field, e.g. nofollow. */
				return sprintf( __( 'The SEO field %s takes yes or no (1 or 0, true or false).', 'simple-seo' ), $key );
			case 'image':
				/* translators: %s: key of a field, e.g. og_image. */
				return sprintf( __( 'The SEO field %s takes the ID of an image in the media library.', 'simple-seo' ), $key );
			case 'url':
				/* translators: %s: key of a field, e.g. canonical. */
				return sprintf( __( 'The SEO field %s takes a full address that starts with https:// or http://.', 'simple-seo' ), $key );
			case 'select':
				$options = is_callable( $field['options'] ) ? call_user_func( $field['options'], $object_type, $object ) : $field['options'];
				$allowed = array_values( array_filter( array_map( 'strval', array_keys( (array) $options ) ), 'strlen' ) );
				if ( $allowed && count( $allowed ) <= 30 ) {
					/* translators: 1: key of a field, e.g. schema_page_type; 2: the values it takes. */
					return sprintf( __( 'The SEO field %1$s takes one of: %2$s.', 'simple-seo' ), $key, implode( ', ', $allowed ) );
				}
		}

		/* translators: %s: key of a field, e.g. title. */
		return sprintf( __( 'The SEO field %s cannot hold this value.', 'simple-seo' ), $key );
	}

	/**
	 * update_post_meta() or add_post_meta() of a field with an empty value: the row goes, none comes.
	 *
	 * @param mixed $check
	 * @param mixed $meta_key
	 * @param mixed $meta_value Sanitized by now.
	 * @return mixed
	 */
	public static function no_empty_post( $check, $object_id, $meta_key, $meta_value ) {
		return self::no_empty( 'post', $check, $object_id, $meta_key, $meta_value );
	}

	/**
	 * The same for terms.
	 *
	 * @param mixed $check
	 * @param mixed $meta_key
	 * @param mixed $meta_value
	 * @return mixed
	 */
	public static function no_empty_term( $check, $object_id, $meta_key, $meta_value ) {
		return self::no_empty( 'term', $check, $object_id, $meta_key, $meta_value );
	}

	/**
	 * @param mixed $check
	 * @param mixed $meta_key
	 * @param mixed $meta_value
	 * @return mixed
	 */
	private static function no_empty( string $type, $check, $object_id, $meta_key, $meta_value ) {
		if ( null !== $check || ! is_string( $meta_key ) || ! str_starts_with( $meta_key, self::META_PREFIX ) || ! isset( self::$fields[ substr( $meta_key, strlen( self::META_PREFIX ) ) ] ) ) {
			return $check;
		}
		if ( '' !== $meta_value && false !== $meta_value ) {
			return $check;
		}

		// Adding nothing adds no row; updating to nothing deletes the row there was.
		if ( str_starts_with( (string) current_filter(), 'add_' ) ) {
			return false;
		}
		delete_metadata( $type, (int) $object_id, $meta_key );

		return true;
	}
}
