<?php
/**
 * The search result preview in the SEO block of the post editor and of the term form: the card a
 * search engine shows for the page, on a computer and on a phone. PHP prints it as the page is now
 * and hands assets/js/admin.js what it needs to redo it while the fields, the title and the address
 * change: the templates, the values of the variables and the separator, all in the language of the
 * post or term. Admin only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Snippet {
	/**
	 * The card with its switch and notes.
	 *
	 * @param WP_Post|WP_Term $object
	 */
	public static function render( string $object_type, $object ): string {
		$data         = self::data( $object_type, $object );
		$data['l10n'] = self::labels();

		$icon = '' !== $data['icon']
			? sprintf( '<img src="%s" alt="">', esc_url( $data['icon'] ) )
			: '<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>';

		return sprintf(
			'<div class="simple-seo-snippet%1$s" data-simple-seo-snippet="%2$s">'
				. '<div class="simple-seo-snippet-bar"><strong>%3$s</strong>'
				. '<span class="simple-seo-snippet-views" role="group" aria-label="%3$s" hidden>'
				. '<button type="button" class="button" data-simple-seo-view="desktop" aria-pressed="true">%4$s</button>'
				. '<button type="button" class="button" data-simple-seo-view="mobile" aria-pressed="false">%5$s</button>'
				. '</span></div>'
				. '<div class="simple-seo-snippet-card">'
				. '<div class="simple-seo-snippet-site"><span class="simple-seo-snippet-icon">%6$s</span>'
				. '<span><span class="simple-seo-snippet-name">%7$s</span><br><span class="simple-seo-snippet-url">%8$s</span></span></div>'
				. '<div class="simple-seo-snippet-title">%9$s</div>'
				. '<div class="simple-seo-snippet-description">%10$s</div>'
				. '</div>'
				. '<p class="simple-seo-snippet-noindex description"%11$s>%12$s</p>'
				. '<p class="simple-seo-snippet-notes description"></p>'
				. '</div>',
			$data['noindex'] ? ' is-noindex' : '',
			esc_attr( (string) wp_json_encode( $data ) ),
			esc_html( $data['l10n']['heading'] ),
			esc_html( $data['l10n']['desktop'] ),
			esc_html( $data['l10n']['mobile'] ),
			$icon,
			esc_html( $data['site'] ),
			esc_html( self::display_url( '' !== $data['url'] ? $data['url'] : $data['home'] ) ),
			esc_html( '' !== $data['title'] ? $data['title'] : $data['raw']['title'] ),
			esc_html( $data['description'] ),
			$data['noindex'] ? '' : ' hidden',
			esc_html( $data['l10n']['noindex'] )
		);
	}

	/**
	 * What the card shows and what the browser needs to redo it, worked out in the language of the
	 * post or term: its templates, variables, site name and address are that language's.
	 *
	 * @param WP_Post|WP_Term $object
	 * @return array<string, mixed>
	 */
	public static function data( string $object_type, $object ): array {
		// Words a variable brings (a date, "Page 2 of 5") in the language of the page, not the admin's.
		return (array) Simple_SEO_WP_Loc::in_object_language( $object, static fn(): array => self::build( $object_type, $object ) );
	}

	/**
	 * "https://example.com › blog › post", as search engines show an address.
	 */
	public static function display_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$crumbs = array( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		foreach ( explode( '/', (string) ( $parts['path'] ?? '' ) ) as $segment ) {
			if ( '' !== $segment ) {
				$crumbs[] = rawurldecode( $segment );
			}
		}

		return implode( ' › ', $crumbs );
	}

	/**
	 * @param WP_Post|WP_Term $object
	 * @return array<string, mixed>
	 */
	private static function build( string $object_type, $object ): array {
		$is_term = 'term' === $object_type && $object instanceof WP_Term;
		$context = $is_term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );
		$slot    = Simple_SEO_Titles::template_slot( $context );

		$values = array();
		foreach ( array_keys( Simple_SEO_Variables::labels() ) as $name ) {
			$values[ $name ] = (string) Simple_SEO_Variables::value( $name, $context );
		}

		$excerpt_field     = false;
		$excerpt_from_text = '';
		if ( ! $is_term ) {
			$excerpt_field = post_type_supports( $object->post_type, 'excerpt' );
			// What {excerpt} becomes once the excerpt box is emptied: the start of the text.
			$copy               = clone $object;
			$copy->post_excerpt = '';
			$excerpt_from_text  = (string) Simple_SEO_Variables::value( 'excerpt', Simple_SEO_Context::for_post( $copy ) );
		}

		return array(
			'kind'            => $is_term ? 'term' : 'post',
			'title'           => Simple_SEO_Titles::title( $context ),
			'description'     => Simple_SEO_Titles::description( $context ),
			'templates'       => array(
				'title'       => Simple_SEO_Titles::template( $slot, 'title' ),
				'description' => Simple_SEO_Titles::template( $slot, 'description' ),
			),
			'separator'       => Simple_SEO_Titles::separator(),
			'values'          => $values,
			// The saved fields, for a user the field itself is hidden from.
			'own'             => array(
				'title'       => $context->field( 'title' ),
				'description' => $context->field( 'description' ),
			),
			'excerptField'    => $excerpt_field,
			'excerptFromText' => $excerpt_from_text,
			// A protected post shows nothing of its text: {excerpt} stays empty whatever is typed.
			'protected'       => $context->password_required,
			// What the form had when the page was printed: the card is redone only once something changes.
			'raw'             => array(
				'title'   => $is_term ? (string) $object->name : (string) $object->post_title,
				'excerpt' => $is_term ? (string) $object->description : (string) $object->post_excerpt,
				// As the slug field shows it: decoded.
				'slug'    => (string) apply_filters( 'editable_slug', $is_term ? $object->slug : $object->post_name, $object ),
			),
			'site'            => Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) ),
			'icon'            => (string) get_site_icon_url( 64 ),
			'url'             => $is_term ? self::term_url( $object ) : self::post_url( $object ),
			'home'            => home_url( '/' ),
			'noindex'         => Simple_SEO_Indexing::is_noindex( $context ),
			// Without a choice of its own the page is hidden as its kind is (or as the whole site is).
			'typeNoindex'     => ! get_option( 'blog_public' ) || Simple_SEO_Indexing::slot_is_noindex( Simple_SEO_Titles::slot_for( $context ) ),
			'public'          => (bool) get_option( 'blog_public' ),
		);
	}

	/** The address a post has, or will have once published; '' for a new post without one yet. */
	private static function post_url( WP_Post $post ): string {
		if ( 'auto-draft' === $post->post_status ) {
			return '';
		}
		if ( in_array( $post->post_status, array( 'publish', 'private', 'inherit' ), true ) || ! function_exists( 'get_sample_permalink' ) ) {
			return (string) get_permalink( $post );
		}

		// A draft has the plain ?p= address until it is published: show the one it will get.
		list( $template, $name ) = get_sample_permalink( $post );

		return '' !== (string) $name ? str_replace( array( '%pagename%', '%postname%' ), (string) $name, (string) $template ) : (string) get_permalink( $post );
	}

	private static function term_url( WP_Term $term ): string {
		$url = get_term_link( $term );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Texts of the card, in the admin's language; %s stands for a number of characters.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'heading'          => __( 'Search result preview', 'simple-seo' ),
			'desktop'          => __( 'Desktop', 'simple-seo' ),
			'mobile'           => __( 'Mobile', 'simple-seo' ),
			'noindex'          => __( 'Hidden from search results (noindex): search engines are asked not to show this page.', 'simple-seo' ),
			/* translators: %s: number of characters. */
			'titleFits'        => __( 'SEO title: %s characters, fits.', 'simple-seo' ),
			/* translators: %s: number of characters. */
			'titleCut'         => __( 'SEO title: %s characters, search engines will cut it.', 'simple-seo' ),
			/* translators: %s: number of characters. */
			'titleShort'       => __( 'SEO title: %s characters, short: there is room for more words.', 'simple-seo' ),
			'titleNone'        => __( 'SEO title: none from Simple SEO, the theme\'s title stays.', 'simple-seo' ),
			/* translators: %s: number of characters. */
			'descriptionFits'  => __( 'Meta description: %s characters, fits.', 'simple-seo' ),
			/* translators: %s: number of characters. */
			'descriptionCut'   => __( 'Meta description: %s characters, search engines will cut it.', 'simple-seo' ),
			/* translators: %s: number of characters. */
			'descriptionShort' => __( 'Meta description: %s characters, short: there is room for more words.', 'simple-seo' ),
			'descriptionNone'  => __( 'Meta description: none, search engines will show a piece of the page\'s text.', 'simple-seo' ),
		);
	}
}
