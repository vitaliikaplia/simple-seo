<?php
/**
 * Bulk editing of SEO titles and meta descriptions: a table of the posts of a type, or of the
 * terms of a taxonomy, with both fields of each and what the page shows now, saved without
 * reloading the page through the REST route of this class. The images are listed too, with their
 * alt texts and titles, and the text their names give (Simple_SEO_Images) to fill the empty alt
 * texts with, looked over before it is saved.
 *
 * The page is under the SEO menu for whoever edits the SEO fields (not only for those who change
 * the settings). It lists only what the user may edit, and each field is saved only where the user
 * may edit it, as in the editor. With wp-loc it lists the posts and terms of the admin's language,
 * as the lists of WordPress do.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Bulk {
	public const PAGE = 'simple-seo-bulk';

	/** The fields edited here. */
	public const FIELDS = array( 'title', 'description' );

	/** The images, as the form names them; their fields: alt and title. */
	public const IMAGES = 'media:image';

	/** The fields of an image. */
	public const IMAGE_FIELDS = array( 'alt', 'title' );

	/** Screen option: rows on a page. */
	public const PER_PAGE = 'simple_seo_bulk_per_page';

	/** Items saved in one request at most. */
	public const MAX_ITEMS = 100;

	/** Post statuses listed: what can be shown or will be. */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	private static string $hook = '';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_filter( 'posts_clauses', array( self::class, 'language_clauses' ), 10, 2 );
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 20 );
		// Screen options are saved early in admin.php, before the page loads.
		add_filter( 'set_screen_option_' . self::PER_PAGE, array( self::class, 'set_per_page' ), 10, 3 );
	}

	public static function add_menu(): void {
		$hook = add_submenu_page(
			Simple_SEO_Admin::PAGE,
			__( 'Bulk SEO editing', 'simple-seo' ),
			__( 'Bulk edit', 'simple-seo' ),
			Simple_SEO_Access::CAP_FIELDS,
			self::PAGE,
			array( self::class, 'render_page' )
		);
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/** The admin page's hook suffix; '' before the menu is built. */
	public static function hook(): string {
		return self::$hook;
	}

	public static function load(): void {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => self::PER_PAGE,
			)
		);
	}

	/**
	 * @param mixed $status
	 * @param mixed $value
	 * @return mixed
	 */
	public static function set_per_page( $status, string $option, $value ) {
		return self::PER_PAGE === $option ? max( 1, min( 200, (int) $value ) ) : $status;
	}

	// ----------------------------------------------------------------------------------------
	// What is listed.

	/**
	 * What the user may edit here, by its value in the form: post types ("post:page") and
	 * taxonomies ("term:category") with SEO fields.
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function objects(): array {
		$objects = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && current_user_can( $object->cap->edit_posts ) ) {
				$objects[ 'post:' . $type ] = (string) $object->labels->name;
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( $object && current_user_can( $object->cap->edit_terms ) ) {
				$objects[ 'term:' . $taxonomy ] = (string) $object->labels->name;
			}
		}
		$attachment = get_post_type_object( 'attachment' );
		if ( $attachment && current_user_can( $attachment->cap->edit_posts ) ) {
			$objects[ self::IMAGES ] = __( 'Images', 'simple-seo' );
		}

		return $objects;
	}

	/** @return array<string, string> Value => label. */
	public static function filters( string $object = '' ): array {
		if ( self::IMAGES === $object ) {
			return array(
				''    => __( 'All', 'simple-seo' ),
				'alt' => __( 'Without alt text', 'simple-seo' ),
			);
		}

		return array(
			''            => __( 'All', 'simple-seo' ),
			'title'       => __( 'Without an SEO title of their own', 'simple-seo' ),
			'description' => __( 'Without a meta description of their own', 'simple-seo' ),
			'either'      => __( 'Without either of their own', 'simple-seo' ),
		);
	}

	/**
	 * The posts or terms of a page of the table.
	 *
	 * @param string $object  "post:{type}" or "term:{taxonomy}", one of objects().
	 * @param string $filter  One of filters().
	 * @return array{items: array<int, WP_Post|WP_Term>, total: int}
	 */
	public static function query( string $object, string $filter, string $search, int $page, int $per_page ): array {
		[ $kind, $name ] = array_pad( explode( ':', $object, 2 ), 2, '' );
		$meta            = self::meta_query( $filter );

		if ( 'term' === $kind ) {
			$args  = array(
				'taxonomy'   => $name,
				'hide_empty' => false,
				'search'     => $search,
				'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only a filter asked for.
			);
			$total = (int) wp_count_terms( $args );
			$terms = get_terms(
				// By name, as get_terms() orders them.
				$args + array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				)
			);

			return array(
				// get_terms() keeps the keys of the page it skipped to.
				'items' => is_array( $terms ) ? array_values( $terms ) : array(),
				'total' => $total,
			);
		}

		if ( 'media' === $kind ) {
			$name = 'attachment';
		}
		$type = get_post_type_object( $name );
		$args = array(
			'post_type'           => $name,
			'post_status'         => 'media' === $kind ? 'inherit' : self::STATUSES,
			's'                   => $search,
			'meta_query'          => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only a filter asked for.
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			// wp-loc copies every image to each language, as its media library lists them.
			'simple_seo_language' => 'media' === $kind || Simple_SEO_WP_Loc::translatable_post_type( $name ) ? Simple_SEO_WP_Loc::admin_language_code() : '',
		);
		if ( 'media' === $kind ) {
			$args['post_mime_type'] = 'image';
		}
		// Whoever edits only their own posts sees only those: the rest could not be saved.
		if ( $type && ! current_user_can( $type->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}
		$query = new WP_Query( $args );

		return array(
			'items' => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * With wp-loc: the posts of one language only. WordPress's own lists are filtered so by wp-loc;
	 * this query is not one of them.
	 *
	 * @param array<string, string> $clauses
	 * @return array<string, string>
	 */
	public static function language_clauses( array $clauses, WP_Query $query ): array {
		$code = (string) $query->get( 'simple_seo_language' );
		if ( '' === $code ) {
			return $clauses;
		}

		global $wpdb;
		[ $join, $where ] = Simple_SEO_WP_Loc::language_sql( "{$wpdb->posts}.ID", $wpdb->prepare( '%s', 'post_' . $query->get( 'post_type' ) ), $code );
		$clauses['join']  .= $join;
		$clauses['where'] .= $where;

		return $clauses;
	}

	/** @return array<int|string, mixed> */
	private static function meta_query( string $filter ): array {
		$none = static fn( string $key ): array => array(
			'relation' => 'OR',
			array(
				'key'     => 'alt' === $key ? '_wp_attachment_image_alt' : Simple_SEO_Fields::meta_key( $key ),
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => 'alt' === $key ? '_wp_attachment_image_alt' : Simple_SEO_Fields::meta_key( $key ),
				'value' => '',
			),
		);

		switch ( $filter ) {
			case 'alt':
			case 'title':
			case 'description':
				return array( $none( $filter ) );
			case 'either':
				return array(
					'relation' => 'OR',
					$none( 'title' ),
					$none( 'description' ),
				);
		}

		return array();
	}

	/**
	 * The title and the description a post or a term shows now, in its language.
	 *
	 * @param WP_Post|WP_Term $object
	 * @return array{title: string, description: string}
	 */
	public static function now( $object ): array {
		return (array) Simple_SEO_WP_Loc::in_object_language(
			$object,
			static function () use ( $object ): array {
				$context = $object instanceof WP_Term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );

				return array(
					'title'       => Simple_SEO_Titles::title( $context ),
					'description' => Simple_SEO_Titles::description( $context ),
				);
			}
		);
	}

	// ----------------------------------------------------------------------------------------
	// The page.

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_FIELDS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a filter of what is listed.
		$objects  = self::objects();
		$object   = isset( $_GET['object'] ) ? sanitize_text_field( wp_unslash( $_GET['object'] ) ) : '';
		$object   = isset( $objects[ $object ] ) ? $object : (string) array_key_first( $objects );
		$filter   = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '';
		$filter   = isset( self::filters( $object )[ $filter ] ) ? $filter : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page     = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$per_page = max( 1, min( 200, (int) ( get_user_option( self::PER_PAGE ) ?: 20 ) ) );

		$result = '' !== $object ? self::query( $object, $filter, $search, $page, $per_page ) : array(
			'items' => array(),
			'total' => 0,
		);
		$data   = array(
			'url'     => rest_url( Simple_SEO_Analysis::NAMESPACE . '/fields' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'saving'  => __( 'Saving…', 'simple-seo' ),
			/* translators: %s: number of posts or terms. */
			'saved'   => __( 'Saved: %s.', 'simple-seo' ),
			'failed'  => __( 'The changes could not be saved. Try again.', 'simple-seo' ),
			/* translators: %s: the title or description the page has with the saved values. */
			'now'     => __( 'Now: %s', 'simple-seo' ),
			'none'    => __( 'Now: none from Simple SEO.', 'simple-seo' ),
			'unsaved' => __( 'Changes not saved yet:', 'simple-seo' ),
		);
		$ai       = self::IMAGES === $object && Simple_SEO_AI::ready();
		if ( $ai ) {
			// The empty alt texts written by the AI, one image after another (Simple_SEO_AI).
			$data['ai'] = array(
				'url'     => rest_url( Simple_SEO_Analysis::NAMESPACE . '/ai/alt' ),
				/* translators: 1: number of the image, 2: of how many. */
				'working' => __( 'The AI writes %1$s of %2$s…', 'simple-seo' ),
				/* translators: %s: number of alt texts. */
				'done'    => __( 'The AI wrote %s: look them over and save.', 'simple-seo' ),
				'none'    => __( 'No empty alt text on this page.', 'simple-seo' ),
				'failed'  => __( 'The AI could not write it. Try again.', 'simple-seo' ),
			);
		}
		$language = 0 === strpos( $object, 'post:' ) && Simple_SEO_WP_Loc::translatable_post_type( substr( $object, 5 ) )
			|| 0 === strpos( $object, 'term:' ) && Simple_SEO_WP_Loc::translatable_taxonomy( substr( $object, 5 ) )
			|| self::IMAGES === $object
			? Simple_SEO_WP_Loc::admin_language()
			: '';
		$images   = self::IMAGES === $object;
		$columns  = $images
			? array( __( 'Image', 'simple-seo' ), __( 'Alt text', 'simple-seo' ), __( 'Title', 'simple-seo' ) )
			: array( __( 'Title', 'simple-seo' ), __( 'SEO title', 'simple-seo' ), __( 'Meta description', 'simple-seo' ) );

		require SIMPLE_SEO_DIR . 'includes/views/bulk.php';
	}

	/**
	 * A row of the table: the post or the term, and its two fields.
	 *
	 * @param WP_Post|WP_Term $object
	 */
	public static function row( $object ): string {
		if ( $object instanceof WP_Post && 'attachment' === $object->post_type ) {
			return self::image_row( $object );
		}

		$type    = $object instanceof WP_Term ? 'term' : 'post';
		$id      = $object instanceof WP_Term ? (int) $object->term_id : (int) $object->ID;
		$name    = $object instanceof WP_Term ? $object->name : get_the_title( $object );
		$name    = '' !== trim( wp_strip_all_tags( $name ) ) ? wp_strip_all_tags( $name ) : __( '(no title)', 'simple-seo' );
		$link    = $object instanceof WP_Term ? get_edit_term_link( $object ) : get_edit_post_link( $object );
		$states  = $object instanceof WP_Post ? implode( ', ', array_map( 'wp_strip_all_tags', get_post_states( $object ) ) ) : '';
		$now     = self::now( $object );
		$user_id = get_current_user_id();

		$cells = '';
		foreach ( self::FIELDS as $key ) {
			$field = Simple_SEO_Fields::get( $key );
			$value = 'term' === $type ? Simple_SEO_Fields::term_value( $id, $key ) : Simple_SEO_Fields::post_value( $id, $key );
			$mask  = is_callable( $field['mask'] ?? null ) ? (string) call_user_func( $field['mask'], $type, $object ) : '';
			$label = sprintf( '%s: %s', (string) ( $field['label'] ?? $key ), $name );
			$attrs = sprintf(
				' data-field="%1$s" aria-label="%2$s"%3$s%4$s',
				esc_attr( $key ),
				esc_attr( $label ),
				'' !== $mask ? ' placeholder="' . esc_attr( $mask ) . '"' : '',
				Simple_SEO_Fields::user_can_edit( $user_id, $type, $id, $key ) ? '' : ' disabled'
			);
			$input = 'description' === $key
				? sprintf( '<textarea rows="2" class="large-text"%s>%s</textarea>', $attrs, esc_textarea( $value ) )
				: sprintf( '<input type="text" class="large-text" value="%s"%s>', esc_attr( $value ), $attrs );
			$text  = '' !== $now[ $key ]
				/* translators: %s: the title or description the page has with the saved values. */
				? sprintf( __( 'Now: %s', 'simple-seo' ), $now[ $key ] )
				: __( 'Now: none from Simple SEO.', 'simple-seo' );

			$cells .= sprintf( '<td>%s<p class="description simple-seo-bulk-now" data-now="%s">%s</p></td>', $input, esc_attr( $key ), esc_html( $text ) );
		}

		return sprintf(
			'<tr data-object="%1$s" data-id="%2$d"><td class="column-primary"><strong>%3$s</strong>%4$s<p class="simple-seo-bulk-error" hidden></p></td>%5$s</tr>',
			esc_attr( $type ),
			$id,
			$link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $name ) ) : esc_html( $name ),
			'' !== $states ? ' — <span class="post-state">' . esc_html( $states ) . '</span>' : '',
			$cells
		);
	}

	/**
	 * A row of an image: its thumbnail and file, its alt text with the text of its name to fill an
	 * empty one with, and its title.
	 */
	public static function image_row( WP_Post $image ): string {
		$name    = '' !== trim( $image->post_title ) ? $image->post_title : __( '(no title)', 'simple-seo' );
		$file    = wp_basename( (string) get_post_meta( $image->ID, '_wp_attached_file', true ) );
		$link    = get_edit_post_link( $image );
		$suggest = Simple_SEO_Images::text_for( $image );
		$can     = current_user_can( 'edit_post', $image->ID );
		$values  = array(
			'alt'   => (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true ),
			'title' => (string) $image->post_title,
		);

		$cells = '';
		foreach ( self::IMAGE_FIELDS as $key ) {
			$label  = sprintf( '%s: %s', 'alt' === $key ? __( 'Alt text', 'simple-seo' ) : __( 'Title', 'simple-seo' ), $name );
			$attrs  = sprintf(
				' data-field="%1$s" aria-label="%2$s"%3$s%4$s',
				esc_attr( $key ),
				esc_attr( $label ),
				'alt' === $key && '' !== $suggest ? ' placeholder="' . esc_attr( $suggest ) . '" data-suggest="' . esc_attr( $suggest ) . '"' : '',
				$can ? '' : ' disabled'
			);
			$cells .= sprintf( '<td><input type="text" class="large-text" value="%s"%s>', esc_attr( $values[ $key ] ), $attrs );
			if ( 'alt' === $key ) {
				$cells .= sprintf(
					'<p class="description">%s</p>',
					esc_html(
						'' !== $suggest
							/* translators: %s: alt text made from the name of an image. */
							? sprintf( __( 'From its name: %s', 'simple-seo' ), $suggest )
							: __( 'Its name says nothing of the picture: describe what it shows.', 'simple-seo' )
					)
				);
			}
			$cells .= '</td>';
		}

		return sprintf(
			'<tr data-object="media" data-id="%1$d"><td class="column-primary simple-seo-bulk-image">%2$s<strong>%3$s</strong><br><code>%4$s</code><p class="simple-seo-bulk-error" hidden></p></td>%5$s</tr>',
			$image->ID,
			wp_get_attachment_image( $image->ID, array( 60, 60 ), false, array( 'alt' => '' ) ),
			$link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $name ) ) : esc_html( $name ),
			esc_html( $file ),
			$cells
		);
	}

	// ----------------------------------------------------------------------------------------
	// Saving.

	public static function register_route(): void {
		register_rest_route(
			Simple_SEO_Analysis::NAMESPACE,
			'/fields',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => static fn(): bool => current_user_can( Simple_SEO_Access::CAP_FIELDS ),
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => self::MAX_ITEMS,
						'items'    => array( 'type' => 'object' ),
					),
				),
			)
		);
	}

	/** @return array{items: array<int, array<string, mixed>>} */
	public static function rest( WP_REST_Request $request ): array {
		$items = array();
		foreach ( (array) $request->get_param( 'items' ) as $item ) {
			$items[] = self::save( is_array( $item ) ? $item : array() );
		}

		return array( 'items' => $items );
	}

	/**
	 * Save the title and the description of one post or term.
	 *
	 * @param array<string, mixed> $item object (post or term), id, and title and/or description.
	 * @return array<string, mixed> The item: its fields as saved and what it shows now, or an error.
	 */
	public static function save( array $item ): array {
		if ( 'media' === ( $item['object'] ?? '' ) ) {
			return self::save_image( $item );
		}

		$type   = 'term' === ( $item['object'] ?? '' ) ? 'term' : 'post';
		$id     = absint( $item['id'] ?? 0 );
		$result = array(
			'object' => $type,
			'id'     => $id,
		);

		$object = 'term' === $type ? get_term( $id ) : get_post( $id );
		$known  = 'term' === $type
			? $object instanceof WP_Term && in_array( $object->taxonomy, Simple_SEO_Fields::taxonomies(), true )
			: $object instanceof WP_Post && in_array( $object->post_type, Simple_SEO_Fields::post_types(), true );
		if ( ! $known ) {
			return $result + array( 'error' => __( 'There is no such post or term with SEO fields.', 'simple-seo' ) );
		}

		// Only text is a value: anything else leaves the field as it is, rather than clearing it.
		$input = array_map( 'strval', array_filter( array_intersect_key( $item, array_flip( self::FIELDS ) ), 'is_scalar' ) );
		$saved = Simple_SEO_Fields::save( $type, $id, $input );
		if ( $input && ! $saved ) {
			return $result + array( 'error' => __( 'You may not edit these fields.', 'simple-seo' ) );
		}

		if ( $saved && $object instanceof WP_Post ) {
			// The list of posts shows the scores of the post as saved, its SEO title included.
			Simple_SEO_Scores::remember( $object, Simple_SEO_Analysis::run( $object ) );
		}

		$fields = array();
		foreach ( self::FIELDS as $key ) {
			$fields[ $key ] = 'term' === $type ? Simple_SEO_Fields::term_value( $id, $key ) : Simple_SEO_Fields::post_value( $id, $key );
		}

		return $result + array(
			'saved'  => $saved,
			'fields' => $fields,
			'now'    => self::now( $object ),
		);
	}

	/**
	 * Save the alt text and the title of one image, for whoever may edit it.
	 *
	 * @param array<string, mixed> $item id, and alt and/or title.
	 * @return array<string, mixed>
	 */
	private static function save_image( array $item ): array {
		$id     = absint( $item['id'] ?? 0 );
		$result = array(
			'object' => 'media',
			'id'     => $id,
		);
		$image  = get_post( $id );
		if ( ! $image instanceof WP_Post || 'attachment' !== $image->post_type || ! wp_attachment_is_image( $image ) ) {
			return $result + array( 'error' => __( 'There is no such image.', 'simple-seo' ) );
		}

		$input = array_map( 'strval', array_filter( array_intersect_key( $item, array_flip( self::IMAGE_FIELDS ) ), 'is_scalar' ) );
		if ( $input && ! current_user_can( 'edit_post', $id ) ) {
			return $result + array( 'error' => __( 'You may not edit this image.', 'simple-seo' ) );
		}

		$saved = false;
		if ( isset( $input['alt'] ) ) {
			$alt   = mb_substr( trim( sanitize_text_field( $input['alt'] ) ), 0, 1000 );
			$saved = $alt !== (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( $saved ) {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}
		}
		if ( isset( $input['title'] ) ) {
			$title = trim( sanitize_text_field( $input['title'] ) );
			if ( $title !== $image->post_title ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'         => $id,
							'post_title' => $title,
						)
					)
				);
				$saved = true;
			}
		}

		return $result + array(
			'saved'  => $saved,
			'fields' => array(
				'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title' => (string) get_post_field( 'post_title', $id ),
			),
			'now'    => array(),
		);
	}
}
