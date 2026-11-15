<?php
/**
 * The primary term of a post in a hierarchical taxonomy (the primary category): the one the
 * breadcrumbs follow and {category} names, when a post has several.
 *
 * The editor picks it in the SEO meta box (_simple_seo_primary_{taxonomy}); a pick that is not
 * among the post's terms any more is ignored. Without a pick the deepest of the post's terms is
 * the primary one (News › Sport rather than News). With wp-loc a pick copied into a translation
 * names the term of the source language: it is read as that term's translation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Primary_Term {
	public static function init(): void {
		// After themes register their taxonomies (init 10), before the meta of the fields (init 99).
		add_action( 'init', array( self::class, 'register_fields' ), 98 );
	}

	/** Field key of a taxonomy's primary term. */
	public static function field( string $taxonomy ): string {
		return 'primary_' . $taxonomy;
	}

	/**
	 * Hierarchical public taxonomies with SEO fields, each with the post types that use it.
	 *
	 * @return array<string, string[]>
	 */
	public static function taxonomies(): array {
		$taxonomies = array();
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object instanceof WP_Taxonomy || ! $object->hierarchical ) {
				continue;
			}
			$types = array_values( array_intersect( (array) $object->object_type, Simple_SEO_Fields::post_types() ) );
			if ( $types ) {
				$taxonomies[ $taxonomy ] = $types;
			}
		}

		return $taxonomies;
	}

	public static function register_fields(): void {
		foreach ( self::taxonomies() as $taxonomy => $types ) {
			$object = get_taxonomy( $taxonomy );
			Simple_SEO_Fields::register(
				self::field( $taxonomy ),
				array(
					/* translators: %s: taxonomy name, singular, e.g. Category. */
					'label'       => sprintf( __( '%s in breadcrumbs', 'simple-seo' ), $object->labels->singular_name ),
					'description' => __( 'When the post has several: the one the breadcrumbs go through. Automatically — the deepest of them.', 'simple-seo' ),
					'type'        => 'select',
					'objects'     => array( 'post' ),
					'post_types'  => $types,
					'options'     => static fn( string $type, $post ): array => self::options( $taxonomy ),
					'sanitize'    => static fn( $value ) => self::sanitize( $value, $taxonomy ),
					'order'       => 55,
					'group'       => 'breadcrumbs',
					'preview'     => static fn( string $type, $post ): string => $post instanceof WP_Post ? Simple_SEO_Breadcrumbs::text( Simple_SEO_Context::for_post( $post ) ) : '',
				)
			);
		}
	}

	/**
	 * The terms to pick from, indented by level; '' is the automatic choice.
	 *
	 * @return array<string, string>
	 */
	public static function options( string $taxonomy ): array {
		$options = array( '' => __( 'Automatically', 'simple-seo' ) );
		$terms   = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return $options;
		}

		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$options, $children ): void {
			foreach ( $children[ $parent ] ?? array() as $term ) {
				$options[ (string) $term->term_id ] = str_repeat( '— ', $depth ) . $term->name;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		// Terms whose parent is not in the list (another language, say) still get a place.
		foreach ( $terms as $term ) {
			$options[ (string) $term->term_id ] ??= $term->name;
		}

		return $options;
	}

	/**
	 * A term ID of the taxonomy, or '' (automatic).
	 *
	 * @param mixed $value
	 * @return int|string
	 */
	public static function sanitize( $value, string $taxonomy ) {
		$id   = absint( $value );
		$term = $id ? get_term( $id, $taxonomy ) : null;

		return $term instanceof WP_Term ? $id : '';
	}

	/** The primary term of a post in a taxonomy; null when the post has none there. */
	public static function get( WP_Post $post, string $taxonomy ): ?WP_Term {
		$terms = get_the_terms( $post, $taxonomy );
		$terms = is_array( $terms ) ? array_values( array_filter( $terms, static fn( $term ): bool => $term instanceof WP_Term ) ) : array();

		$primary = null;
		if ( $terms ) {
			$picked = Simple_SEO_WP_Loc::term_for_post( (int) Simple_SEO_Fields::post_value( (int) $post->ID, self::field( $taxonomy ) ), $taxonomy, $post );
			foreach ( $terms as $term ) {
				if ( $picked && (int) $term->term_id === $picked ) {
					$primary = $term;
					break;
				}
			}

			if ( null === $primary ) {
				// The deepest: News › Sport says more than News.
				$depth = -1;
				foreach ( $terms as $term ) {
					$level = count( get_ancestors( (int) $term->term_id, $taxonomy, 'taxonomy' ) );
					if ( $level > $depth ) {
						$depth   = $level;
						$primary = $term;
					}
				}
			}
		}

		/**
		 * The primary term of a post in a taxonomy.
		 *
		 * @param WP_Term|null $primary
		 * @param WP_Post      $post
		 * @param string       $taxonomy
		 */
		$primary = apply_filters( 'simple_seo_primary_term', $primary, $post, $taxonomy );

		return $primary instanceof WP_Term ? $primary : null;
	}
}
