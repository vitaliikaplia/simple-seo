<?php
/**
 * The public functions of Yoast SEO that themes call, answered by Simple SEO. Loaded by
 * Simple_SEO_Yoast_Compat::load() only while neither Yoast nor another SEO plugin is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wpseo_replace_vars' ) ) {
	/**
	 * Replace %%variables%% in a string: wpseo_replace_vars( '%%sitename%%', array() ).
	 *
	 * @param string   $string
	 * @param mixed    $args A post or term (object or array of its fields); empty for the current page.
	 * @param string[] $omit Variables to leave as they are.
	 * @return string
	 */
	function wpseo_replace_vars( $string, $args = array(), $omit = array() ) {
		return Simple_SEO_Yoast_Compat::replace_vars( (string) $string, $args, (array) $omit );
	}
}

if ( ! function_exists( 'yoast_breadcrumb' ) ) {
	/**
	 * Print the breadcrumbs, or return them when $display is false.
	 *
	 * @param string $before HTML before the trail.
	 * @param string $after  HTML after the trail.
	 * @param bool   $display
	 * @return string The trail when not displayed; '' when printed (so Twig prints it only once).
	 */
	function yoast_breadcrumb( $before = '', $after = '', $display = true ) {
		$output = Simple_SEO_Yoast_Compat::breadcrumb( (string) $before, (string) $after );
		if ( ! $display ) {
			return $output;
		}

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built; $before and $after are the theme's own HTML.
		return '';
	}
}

if ( ! function_exists( 'yoast_get_primary_term_id' ) ) {
	/**
	 * @param string           $taxonomy
	 * @param int|WP_Post|null $post Current post when null.
	 * @return int|false
	 */
	function yoast_get_primary_term_id( $taxonomy = 'category', $post = null ) {
		$id = Simple_SEO_Yoast_Compat::primary_term_id( (string) $taxonomy, $post );

		return $id > 0 ? $id : false;
	}
}

if ( ! function_exists( 'yoast_get_primary_term' ) ) {
	/**
	 * Name of the primary term.
	 *
	 * @param string           $taxonomy
	 * @param int|WP_Post|null $post Current post when null.
	 * @return string
	 */
	function yoast_get_primary_term( $taxonomy = 'category', $post = null ) {
		$id   = Simple_SEO_Yoast_Compat::primary_term_id( (string) $taxonomy, $post );
		$term = $id ? get_term( $id, (string) $taxonomy ) : null;

		return $term instanceof WP_Term ? $term->name : '';
	}
}
