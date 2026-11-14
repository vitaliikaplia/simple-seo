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
	}

	/**
	 * Register a field, on init or later (its label is translated). Once the meta of the other
	 * fields is registered, a new field registers its meta right away.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string   $label       Field label.
	 *     @type string   $description Help text under the field.
	 *     @type string   $type        text, textarea, url, checkbox, select or image (attachment ID).
	 *     @type string[] $objects     Where the field exists: post, term or both.
	 *     @type string   $level       LEVEL_BASIC or LEVEL_ADVANCED.
	 *     @type array    $options     Value => label, for select.
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
				'level'       => self::LEVEL_BASIC,
				'options'     => array(),
				'sanitize'    => null,
				'order'       => 50,
				'group'       => '',
				'mask'        => null,
				'preview'     => null,
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
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			case 'select':
				$value = (string) $value;
				return array_key_exists( $value, (array) $field['options'] ) ? $value : '';
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
}
