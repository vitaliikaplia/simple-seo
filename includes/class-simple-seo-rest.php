<?php
/**
 * The SEO fields in the REST API: a field simple_seo on the posts of every post type and the terms
 * of every taxonomy that have SEO fields and are in the REST API, in the edit context.
 *
 * Core lists the meta of posts (meta._simple_seo_*) only for post types that support custom fields;
 * this field is there for all of them, holds the values as their types do (a checkbox true or
 * false, an image its ID) and writes all the values sent or none, with the rules of the editor:
 * only the fields the user may edit, an empty value deletes the row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_REST {
	public const FIELD = 'simple_seo';

	/** The types of the REST schema by field type; the rest are text. */
	private const TYPES = array(
		'checkbox' => 'boolean',
		'image'    => 'integer',
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/** A type the REST API does not show keeps the field to itself: nothing asks for it. */
	public static function register(): void {
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			register_rest_field( $type, self::FIELD, self::args( 'post', $type ) );
		}

		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			// The REST API names the objects of tags "tag", after the title of their schema.
			register_rest_field( 'post_tag' === $taxonomy ? 'tag' : $taxonomy, self::FIELD, self::args( 'term', '' ) );
		}
	}

	/**
	 * The field on the objects of a post type or of a taxonomy.
	 *
	 * @param string $object_type post or term.
	 * @return array<string, mixed>
	 */
	private static function args( string $object_type, string $post_type ): array {
		$properties = array();
		foreach ( Simple_SEO_Fields::available( $object_type, $post_type ) as $key => $field ) {
			$properties[ $key ] = array(
				'description' => (string) $field['label'],
				'type'        => self::TYPES[ $field['type'] ] ?? 'string',
				'context'     => array( 'edit' ),
			);
		}

		return array(
			'get_callback'    => static fn( array $data ) => self::get( $object_type, (int) ( $data['id'] ?? 0 ) ),
			'update_callback' => static fn( $value, $object ) => self::update( $object_type, $object, $value ),
			'schema'          => array(
				'description'          => __( 'The SEO fields of Simple SEO the user may edit. An empty value clears a field: an empty text, false, 0 for an image.', 'simple-seo' ),
				'type'                 => 'object',
				'context'              => array( 'edit' ),
				'properties'           => $properties,
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * The fields of a post or term the user may edit; an object even when there are none.
	 *
	 * @return array<string, string|bool|int>|stdClass
	 */
	public static function get( string $object_type, int $object_id ) {
		$values = Simple_SEO_Fields::values( $object_type, $object_id, get_current_user_id() );

		return $values ? $values : new stdClass();
	}

	/**
	 * Write the fields sent.
	 *
	 * @param WP_Post|WP_Term $object
	 * @param mixed           $value
	 * @return true|WP_Error
	 */
	public static function update( string $object_type, $object, $value ) {
		$id     = $object instanceof WP_Term ? (int) $object->term_id : (int) ( $object->ID ?? 0 );
		$result = Simple_SEO_Fields::write( $object_type, $id, (array) $value, get_current_user_id() );

		return is_wp_error( $result ) ? $result : true;
	}
}
