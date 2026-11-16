<?php
/**
 * WP-CLI: wp simple-seo post and wp simple-seo term — the SEO fields of one post or term.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and change the SEO fields of a post (wp simple-seo post) or a term (wp simple-seo term).
 *
 * ## EXAMPLES
 *
 *     $ wp simple-seo post get 12
 *     $ wp simple-seo term update 5 --title="Shoes {sep} {site}"
 */
final class Simple_SEO_CLI_Fields {
	/** post or term. */
	private string $object_type;

	public function __construct( string $object_type = 'post' ) {
		$this->object_type = 'term' === $object_type ? 'term' : 'post';
	}

	/**
	 * Show the SEO fields of a post or term.
	 *
	 * An empty field is one not set: the page falls back to the templates. A checkbox is 1 or empty,
	 * an image the ID of its attachment (0 for none).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the post or term.
	 *
	 * [--field=<field>]
	 * : Show only the value of this field.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo post get 12 --format=json
	 *     $ wp simple-seo term get 5 --field=title
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function get( array $args, array $assoc_args ): void {
		$object_id = $this->object_id( (string) ( $args[0] ?? '' ) );
		$values    = Simple_SEO_Fields::values( $this->object_type, $object_id );

		$formatter = new WP_CLI\Formatter( $assoc_args, array_keys( $values ) );
		$formatter->display_item( $values );
	}

	/**
	 * Change SEO fields of a post or term: all the values given, or none if one cannot be stored.
	 *
	 * An empty value clears a field, so the page falls back to the templates. A checkbox takes 1 or 0,
	 * an image the ID of an attachment. The fields there are: wp simple-seo fields.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the post or term.
	 *
	 * [--<field>=<value>]
	 * : A field and its new value.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo post update 12 --title="Prices {sep} {site}" --description=""
	 *     $ wp simple-seo post update 12 --index=noindex --nofollow=1
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function update( array $args, array $assoc_args ): void {
		$object_id = $this->object_id( (string) ( $args[0] ?? '' ) );
		if ( ! $assoc_args ) {
			WP_CLI::error( __( 'Name the fields to change, for example --title="…".', 'simple-seo' ) );
		}

		$saved = Simple_SEO_Fields::write( $this->object_type, $object_id, $assoc_args );
		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( $saved->get_error_message() );
		}

		$post = 'post' === $this->object_type ? get_post( $object_id ) : null;
		if ( $post instanceof WP_Post ) {
			// The list of posts shows the scores of the post as saved, its SEO fields included.
			Simple_SEO_Scores::remember( $post, Simple_SEO_Analysis::run( $post ) );
		}

		/* translators: %s: keys of fields, e.g. title, description. */
		WP_CLI::success( sprintf( __( 'Fields saved: %s.', 'simple-seo' ), implode( ', ', $saved ) ) );
	}

	/** The ID of a post or term with SEO fields; an error for anything else. */
	private function object_id( string $id ): int {
		if ( null === Simple_SEO_Fields::object( $this->object_type, absint( $id ) ) ) {
			WP_CLI::error( 'term' === $this->object_type ? __( 'There is no such term with SEO fields.', 'simple-seo' ) : __( 'There is no such post with SEO fields.', 'simple-seo' ) );
		}

		return absint( $id );
	}
}
