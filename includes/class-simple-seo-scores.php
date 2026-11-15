<?php
/**
 * The SEO and readability scores of posts, kept for the lists of posts: the list of each post type
 * with SEO fields gets a column SEO with both.
 *
 * The scores are those of the post as saved. They are taken when a person saves the post — in the
 * editor, by quick edit, through the REST API — or opens it in the editor, and they show only while
 * the post has not changed since: a post changed in another way (an import, WP-CLI, a translation
 * that copied another post's meta) shows none until it is saved or opened again. A list never
 * analyses posts itself: that would render the content of every row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Scores {
	/** Post meta: seo, readability (null for a post without text) and modified (post_modified: a new draft has no GMT time). */
	public const META = '_simple_seo_scores';

	public const COLUMN = 'simple_seo';

	public static function init(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'store' ), 20, 2 );
		add_action( 'admin_init', array( self::class, 'hook_lists' ) );
	}

	/**
	 * Analyse a post a person has just saved. In the block editor this comes twice, for the post and
	 * then for its meta boxes: the second one, with the SEO fields, is what stays.
	 */
	public static function store( int $post_id, WP_Post $post ): void {
		// Imports, WP-CLI and bulk edit save many posts at once: their scores wait for the editor.
		$person = ( is_admin() || wp_is_serving_rest_request() ) && empty( $_REQUEST['bulk_edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tells a bulk edit apart; WordPress checks its nonce.

		/**
		 * Whether to analyse a post just saved and keep its scores for the list of posts.
		 *
		 * @param bool    $person Whether a person saved it: in the admin or through the REST API, not by bulk edit.
		 * @param WP_Post $post   The post.
		 */
		if ( self::scored( $post ) && apply_filters( 'simple_seo_store_scores', $person, $post ) ) {
			self::remember( $post, Simple_SEO_Analysis::run( $post ) );
		}
	}

	/**
	 * Keep the scores of a post as saved.
	 *
	 * @param array{score: int, readability: array{score: int, rating: string}} $result Simple_SEO_Analysis::run() of the saved post.
	 */
	public static function remember( WP_Post $post, array $result ): void {
		if ( ! self::scored( $post ) ) {
			return;
		}

		// The same scores again are no write: update_post_meta() compares them itself.
		update_post_meta(
			(int) $post->ID,
			self::META,
			array(
				'seo'         => (int) $result['score'],
				'readability' => 'none' === $result['readability']['rating'] ? null : (int) $result['readability']['score'],
				'modified'    => (string) $post->post_modified,
			)
		);
	}

	/**
	 * The scores of a post if they are those of the post as it is; null if there are none.
	 *
	 * @return array{seo: int, readability: ?int, modified: string}|null
	 */
	public static function get( WP_Post $post ): ?array {
		$scores = get_post_meta( (int) $post->ID, self::META, true );

		return is_array( $scores ) && isset( $scores['seo'], $scores['modified'] ) && $scores['modified'] === $post->post_modified ? $scores : null;
	}

	/** A post that has an analysis: of a type with SEO fields, neither a new draft nor in the trash. */
	private static function scored( WP_Post $post ): bool {
		return in_array( $post->post_type, Simple_SEO_Fields::post_types(), true )
			&& ! in_array( $post->post_status, array( 'auto-draft', 'trash' ), true );
	}

	// ----------------------------------------------------------------------------------------
	// The column of the lists.

	/**
	 * The column on the lists of the post types with SEO fields, for whoever edits the fields. Quick
	 * edit redraws its row in an AJAX request that has no screen of its own: hence admin_init, and
	 * these hooks are only ever run by the lists.
	 */
	public static function hook_lists(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_FIELDS ) ) {
			return;
		}

		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( self::class, 'cell' ), 10, 2 );
		}
	}

	/**
	 * The column goes before the date.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$column = array( self::COLUMN => __( 'SEO', 'simple-seo' ) );
		$date   = array_search( 'date', array_keys( $columns ), true );

		return false === $date
			? $columns + $column
			: array_slice( $columns, 0, $date, true ) + $column + array_slice( $columns, $date, null, true );
	}

	public static function cell( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post   = get_post( $post_id );
		$scores = $post instanceof WP_Post ? self::get( $post ) : null;
		if ( null === $scores ) {
			$note = __( 'Not analysed since the last change: open or save the post.', 'simple-seo' );
			printf( '<span aria-hidden="true" title="%1$s">—</span><span class="screen-reader-text">%2$s</span>', esc_attr( $note ), esc_html( $note ) );
			return;
		}

		$readability = null === $scores['readability'] ? null : (int) $scores['readability'];
		printf(
			'<span class="simple-seo-score is-%1$s">%2$s</span><span class="simple-seo-score is-%3$s">%4$s</span>',
			esc_attr( Simple_SEO_Analysis::rating( (int) $scores['seo'] ) ),
			/* translators: %s: score out of 100. */
			esc_html( sprintf( __( 'SEO: %s', 'simple-seo' ), number_format_i18n( (int) $scores['seo'] ) ) ),
			esc_attr( null === $readability ? 'none' : Simple_SEO_Analysis::rating( $readability ) ),
			/* translators: %s: score out of 100, or a dash for a post without text. */
			esc_html( sprintf( __( 'Readability: %s', 'simple-seo' ), null === $readability ? '—' : number_format_i18n( $readability ) ) )
		);
	}
}
