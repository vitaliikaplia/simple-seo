<?php
/**
 * Template functions for themes. Each checks nothing but itself, so a theme can call it behind
 * function_exists() and keep working without Simple SEO.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'simple_seo_breadcrumbs' ) ) {
	/**
	 * The breadcrumbs of the current page as HTML; '' on the home page.
	 *
	 * @param array<string, string> $args before, after (HTML around the trail), class (extra class).
	 */
	function simple_seo_breadcrumbs( array $args = array() ): string {
		return Simple_SEO_Breadcrumbs::render( $args );
	}
}

if ( ! function_exists( 'simple_seo_the_breadcrumbs' ) ) {
	/**
	 * Print the breadcrumbs of the current page.
	 *
	 * @param array<string, string> $args See simple_seo_breadcrumbs().
	 */
	function simple_seo_the_breadcrumbs( array $args = array() ): void {
		echo simple_seo_breadcrumbs( $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}
}

if ( ! function_exists( 'simple_seo_primary_term' ) ) {
	/**
	 * The primary term of a post in a hierarchical taxonomy (the primary category by default).
	 *
	 * @param int|WP_Post|null $post Post, its ID, or null for the current post.
	 */
	function simple_seo_primary_term( $post = null, string $taxonomy = 'category' ): ?WP_Term {
		$post = get_post( $post );

		return $post instanceof WP_Post ? Simple_SEO_Primary_Term::get( $post, $taxonomy ) : null;
	}
}
