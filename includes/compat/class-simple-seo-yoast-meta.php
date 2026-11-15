<?php
/**
 * Yoast's post meta keys answered with Simple SEO's fields: get_post_meta( $id, '_yoast_wpseo_metadesc', true )
 * reads the post's meta description from Simple SEO and update_post_meta() with that key writes it
 * there, so themes that read and write Yoast's meta (their own Schema, AI translation of titles)
 * keep working once Yoast is gone.
 *
 * Only the keys Simple SEO has a field for are aliases; Yoast's other rows (focus keyphrase, X,
 * advanced robots, scores) stay what they are. The rows Yoast left under an alias are neither read
 * nor written nor deleted through it: what the page shows is Simple SEO's data, Yoast's rows wait
 * for the import, and Yoast finds them as they were if it comes back. Only a delete of a key from
 * every post (delete_post_meta_by_key()) reaches them. Terms have no aliases: Yoast keeps their SEO
 * in an option, not in term meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Yoast_Meta {
	private const PREFIX = '_yoast_wpseo_';

	/** Yoast's key without the prefix => Simple SEO's field. primary_{taxonomy} keeps its name. */
	private const FIELDS = array(
		'title'                 => 'title',
		'metadesc'              => 'description',
		'opengraph-title'       => 'og_title',
		'opengraph-description' => 'og_description',
		'opengraph-image-id'    => 'og_image',
		'opengraph-image'       => 'og_image',
		'meta-robots-noindex'   => 'index',
		'meta-robots-nofollow'  => 'nofollow',
		'canonical'             => 'canonical',
	);

	/** Keys whose text may hold variables: %%title%% for Yoast, {title} for Simple SEO. */
	private const TEXTS = array( 'title', 'metadesc', 'opengraph-title', 'opengraph-description' );

	/** Yoast's values of meta-robots-noindex => Simple SEO's; '0' or nothing is the default. */
	private const NOINDEX = array(
		'1' => 'noindex',
		'2' => 'index',
	);

	public static function init(): void {
		add_filter( 'get_post_metadata', array( self::class, 'get' ), 5, 4 );
		add_filter( 'update_post_metadata', array( self::class, 'update' ), 5, 5 );
		add_filter( 'add_post_metadata', array( self::class, 'add' ), 5, 5 );
		add_filter( 'delete_post_metadata', array( self::class, 'delete' ), 5, 5 );
	}

	/**
	 * get_post_meta(): an alias reads Simple SEO's field. The list of all of a post's meta has no
	 * aliases: code that copies every row of a post (wp-loc's new translation, Duplicate Post)
	 * would otherwise write Yoast's old values over the Simple SEO fields it copies too.
	 *
	 * @param mixed $value
	 * @param mixed $meta_key
	 * @return mixed
	 */
	public static function get( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || ! is_string( $meta_key ) ) {
			return $value;
		}
		if ( '' === $meta_key ) {
			return self::listing( (int) $object_id, (bool) $single );
		}

		$key = self::field( $meta_key, (int) $object_id );
		if ( '' === $key ) {
			return $value;
		}

		$stored = self::read( self::name( $meta_key ), $key, (int) $object_id );
		if ( '' === $stored ) {
			return $single ? '' : array();
		}

		return array( $stored );
	}

	/**
	 * update_post_meta(): an alias writes Simple SEO's field ('' clears it). A value the field
	 * cannot hold is not written: false, as for a failed write.
	 *
	 * @param mixed $check
	 * @param mixed $meta_key
	 * @param mixed $meta_value
	 * @param mixed $prev_value
	 * @return mixed
	 */
	public static function update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		$key = null === $check && is_string( $meta_key ) ? self::field( $meta_key, (int) $object_id ) : '';
		if ( '' === $key ) {
			return $check;
		}

		if ( ! self::matches( $prev_value, self::name( $meta_key ), $key, (int) $object_id ) ) {
			return false;
		}

		return self::write( self::name( $meta_key ), $key, (int) $object_id, $meta_value );
	}

	/**
	 * add_post_meta(): an alias has one value, so adding sets it; with $unique, only when unset.
	 *
	 * @param mixed $check
	 * @param mixed $meta_key
	 * @param mixed $meta_value
	 * @return mixed
	 */
	public static function add( $check, $object_id, $meta_key, $meta_value, $unique ) {
		$key = null === $check && is_string( $meta_key ) ? self::field( $meta_key, (int) $object_id ) : '';
		if ( '' === $key ) {
			return $check;
		}

		if ( $unique && '' !== self::read( self::name( $meta_key ), $key, (int) $object_id ) ) {
			return false;
		}

		return self::write( self::name( $meta_key ), $key, (int) $object_id, $meta_value );
	}

	/**
	 * delete_post_meta(): an alias clears Simple SEO's field. A delete from every post
	 * (delete_post_meta_by_key()) is about the rows Yoast left, so it goes to them.
	 *
	 * @param mixed $check
	 * @param mixed $meta_key
	 * @param mixed $meta_value
	 * @return mixed
	 */
	public static function delete( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		$key = null === $check && ! $delete_all && is_string( $meta_key ) ? self::field( $meta_key, (int) $object_id ) : '';
		if ( '' === $key ) {
			return $check;
		}

		if ( ! self::matches( $meta_value, self::name( $meta_key ), $key, (int) $object_id ) ) {
			return false;
		}

		return delete_metadata( 'post', (int) $object_id, Simple_SEO_Fields::meta_key( $key ) );
	}

	/** Yoast's post meta keys that are aliases on every post (the primary terms come on top). */
	public static function keys(): array {
		return array_map( static fn( string $name ): string => self::PREFIX . $name, array_keys( self::FIELDS ) );
	}

	/** The Simple SEO field of a Yoast key without its prefix ('metadesc' → description); '' for none. */
	public static function field_of( string $name ): string {
		return self::FIELDS[ $name ] ?? ( str_starts_with( $name, 'primary_' ) ? $name : '' );
	}

	/** The Simple SEO field a Yoast key of a post stands for; '' where the key is no alias. */
	public static function field( string $meta_key, int $post_id ): string {
		if ( ! str_starts_with( $meta_key, self::PREFIX ) ) {
			return '';
		}

		$key   = self::field_of( self::name( $meta_key ) );
		$field = '' !== $key ? Simple_SEO_Fields::get( $key ) : null;
		if ( null === $field || ! in_array( 'post', (array) $field['objects'], true ) ) {
			return '';
		}

		// A primary term field exists only for the post types of its taxonomy.
		$types = (array) $field['post_types'];

		return ! $types || in_array( get_post_type( $post_id ), $types, true ) ? $key : '';
	}

	private static function name( string $meta_key ): string {
		return substr( $meta_key, strlen( self::PREFIX ) );
	}

	/** Simple SEO's value of a field in the form Yoast kept it; '' when the field is not set. */
	private static function read( string $name, string $key, int $post_id ): string {
		$value = Simple_SEO_Fields::post_value( $post_id, $key );
		if ( '' === $value ) {
			return '';
		}

		switch ( $name ) {
			case 'meta-robots-noindex':
				return (string) array_search( $value, self::NOINDEX, true );
			case 'meta-robots-nofollow':
				return '0' !== $value ? '1' : '';
			case 'opengraph-image':
				return (string) wp_get_attachment_url( (int) $value );
		}

		if ( str_starts_with( $name, 'primary_' ) ) {
			// With wp-loc, the term in the post's language, as wp-loc answered for Yoast.
			$post = get_post( $post_id );
			$term = $post instanceof WP_Post ? Simple_SEO_WP_Loc::term_for_post( (int) $value, substr( $name, 8 ), $post ) : (int) $value;

			return $term ? (string) $term : '';
		}

		return in_array( $name, self::TEXTS, true ) ? Simple_SEO_Yoast_Compat::to_yoast( $value ) : $value;
	}

	/**
	 * Write a value given in Yoast's form into Simple SEO's field: '' (or Yoast's default) deletes
	 * the row, a value the field cannot hold is not written.
	 *
	 * @param mixed $value
	 * @return bool|int What update_metadata() or delete_metadata() returned; false when not written.
	 */
	private static function write( string $name, string $key, int $post_id, $value ) {
		$clean    = self::clean( $name, $key, $value );
		$meta_key = Simple_SEO_Fields::meta_key( $key );
		if ( '' === $clean ) {
			return delete_metadata( 'post', $post_id, $meta_key );
		}
		if ( null === $clean ) {
			return false;
		}

		// update_metadata() expects slashed data and unslashes it itself.
		return update_metadata( 'post', $post_id, $meta_key, wp_slash( $clean ) );
	}

	/**
	 * A value in Yoast's form (the key's name without the prefix) as Simple SEO's field keeps it:
	 * '' for none (Yoast's default too), null for what the field cannot hold. The import uses it too.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function clean( string $name, string $key, $value ) {
		$value = self::value( $name, $value );
		if ( '' === $value || false === $value ) {
			return '';
		}

		// The sanitizer makes nothing of what value() could not take (null) either.
		$clean = Simple_SEO_Fields::sanitize_value( $key, $value );

		return '' === $clean || false === $clean ? null : $clean;
	}

	/**
	 * A value in Yoast's form as Simple SEO's field takes it: '' or false for none; null for
	 * what no field can hold (not text, an image address that is not in the media library).
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function value( string $name, $value ) {
		if ( null !== $value && ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		switch ( $name ) {
			case 'meta-robots-noindex':
				return self::NOINDEX[ $value ] ?? '';
			case 'meta-robots-nofollow':
				return '1' === $value;
			case 'opengraph-image':
				$id = '' !== $value ? attachment_url_to_postid( $value ) : 0;

				return $id ? $id : ( '' !== $value ? null : '' );
		}

		if ( 'opengraph-image-id' === $name || str_starts_with( $name, 'primary_' ) ) {
			// An ID of 0 is no image, no term.
			return '0' !== $value ? $value : '';
		}

		return in_array( $name, self::TEXTS, true ) ? Simple_SEO_Yoast_Compat::to_simple( $value ) : $value;
	}

	/**
	 * Whether a value given to narrow an update or a delete is the alias's value. Nothing given
	 * ('', null or false) means any value, as for update_post_meta() and delete_post_meta().
	 *
	 * @param mixed $given
	 */
	private static function matches( $given, string $name, string $key, int $post_id ): bool {
		if ( '' === $given || null === $given || false === $given ) {
			return true;
		}

		return is_scalar( $given ) && (string) $given === self::read( $name, $key, $post_id );
	}

	/**
	 * Every meta row of a post without the aliases (the rows Yoast left under them); null, so
	 * that core answers itself, when the post has none of them.
	 *
	 * @return array<string, array<int, mixed>>|array<int, array<string, array<int, mixed>>>|null
	 */
	private static function listing( int $post_id, bool $single ): ?array {
		$meta = wp_cache_get( $post_id, 'post_meta' );
		if ( ! is_array( $meta ) ) {
			$cache = update_meta_cache( 'post', array( $post_id ) );
			$meta  = is_array( $cache ) ? (array) ( $cache[ $post_id ] ?? array() ) : array();
		}

		$hidden = false;
		foreach ( array_keys( $meta ) as $meta_key ) {
			if ( '' !== self::field( (string) $meta_key, $post_id ) ) {
				unset( $meta[ $meta_key ] );
				$hidden = true;
			}
		}
		if ( ! $hidden ) {
			return null;
		}

		// Core hands out the first element of what a filter returns when asked for a single value.
		return $single ? array( $meta ) : $meta;
	}
}
