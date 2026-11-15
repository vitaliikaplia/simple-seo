<?php
/**
 * WPSEO_Primary_Term as themes use it: new WPSEO_Primary_Term( 'category', $post_id ) and
 * ->get_primary_term(). Answered with Simple SEO's primary term (the one picked in the SEO box,
 * otherwise the deepest assigned). Loaded by Simple_SEO_Yoast_Compat::load() only without Yoast.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSEO_Primary_Term {
	/** @var string */
	protected $taxonomy_name;

	/** @var int */
	protected $post_ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- the name themes know.

	/**
	 * @param string $taxonomy_name
	 * @param int    $post_id
	 */
	public function __construct( $taxonomy_name, $post_id ) {
		$this->taxonomy_name = (string) $taxonomy_name;
		$this->post_ID       = (int) $post_id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	/**
	 * The ID of the primary term, false when the post has no terms in the taxonomy.
	 *
	 * @return int|false
	 */
	public function get_primary_term() {
		$id = Simple_SEO_Yoast_Compat::primary_term_id( $this->taxonomy_name, $this->post_ID ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		return $id > 0 ? $id : false;
	}

	/**
	 * @param int $new_primary_term Term ID.
	 * @return void
	 */
	public function set_primary_term( $new_primary_term ) {
		Simple_SEO_Yoast_Compat::set_primary_term( $this->taxonomy_name, $this->post_ID, (int) $new_primary_term ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}
}
