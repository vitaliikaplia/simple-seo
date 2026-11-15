<?php
/**
 * The text of a page as visitors and search engines read it, for the analysis of the content:
 * headings and paragraphs in their order, links, images and the count of words.
 *
 * It does not matter which editor made the page. The content is rendered the way the site renders
 * it — blocks through their own render callbacks, so ACF blocks (whose fields live in the block
 * comment) and the attribute blocks of the Builder theme come out as the HTML their templates
 * print, and classic content gets its paragraphs — and that HTML is read. Blocks that are not the
 * page's own text (code, lists of other posts, menus, forms, maps, embeds) are left out, nested
 * ones too. The content may be the one still being edited; it is rendered in the post's language.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Content {
	/** Blocks that are not the page's own text. Filter: simple_seo_content_skip_blocks. */
	public const SKIP_BLOCKS = array(
		'core/code',
		'core/embed',
		'core/shortcode',
		'core/navigation',
		'core/navigation-link',
		'core/navigation-submenu',
		'core/page-list',
		'core/query',
		'core/latest-posts',
		'core/latest-comments',
		'core/archives',
		'core/categories',
		'core/calendar',
		'core/tag-cloud',
		'core/rss',
		'core/search',
		'core/social-links',
		'core/comments',
		'core/post-content',
		'core/post-comments-form',
		'builder/code',
		'builder/posts',
		'builder/nav-menu',
		'builder/form',
		'builder/post-stats',
		'builder/post-content',
		'builder/map',
		'builder/iframe',
	);

	/** Elements whose content is not text a visitor reads. */
	private const SKIP_TAGS = array( 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'canvas', 'form', 'select', 'textarea', 'button', 'nav', 'audio', 'video' );

	/** Elements that start a paragraph of their own. */
	private const BLOCK_TAGS = array( 'p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'blockquote', 'section', 'article', 'aside', 'header', 'footer', 'main', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'pre', 'address', 'details', 'summary', 'hr', 'caption', 'center' );

	/**
	 * Read a post: its saved content, or the content given (what the editor has, not saved yet).
	 *
	 * @return array{blocks: array<int, array{type: string, tag: string, text: string, level?: int}>, headings: array<int, array{level: int, text: string}>, links: array<int, array{url: string, text: string, internal: bool, nofollow: bool}>, images: array<int, array{src: string, alt: string}>, text: string, words: int}
	 */
	public static function read( WP_Post $post, ?string $content = null ): array {
		$content = null !== $content ? $content : (string) $post->post_content;

		return (array) Simple_SEO_WP_Loc::in_object_language( $post, static fn(): array => self::parse( self::render( $post, $content ) ) );
	}

	/** The HTML the content gives on the site, without the blocks that are not its text. */
	public static function render( WP_Post $post, string $content ): string {
		if ( '' === trim( $content ) ) {
			return '';
		}

		/**
		 * Blocks left out of the text of a page: code, lists of other posts, menus, forms and the like.
		 *
		 * @param string[] $blocks Block names.
		 */
		$skip = (array) apply_filters( 'simple_seo_content_skip_blocks', self::SKIP_BLOCKS );
		$gate = static fn( $pre, array $block ) => in_array( $block['blockName'] ?? '', $skip, true ) ? '' : $pre;

		// Render callbacks read the post being shown: get_the_ID(), get_field() of ACF.
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		add_filter( 'pre_render_block', $gate, 10, 2 );

		try {
			if ( ! has_blocks( $content ) ) {
				// Classic content: paragraphs from its blank lines, as the_content makes them.
				return wpautop( strip_shortcodes( $content ) );
			}

			$html = '';
			foreach ( parse_blocks( $content ) as $block ) {
				// A block whose template fails leaves the rest of the page readable.
				try {
					$html .= render_block( $block );
				} catch ( Throwable $error ) {
					continue;
				}
			}

			return strip_shortcodes( $html );
		} finally {
			remove_filter( 'pre_render_block', $gate, 10 );
			$GLOBALS['post'] = $previous;
			if ( $previous instanceof WP_Post ) {
				setup_postdata( $previous );
			}
		}
	}

	/**
	 * Headings and paragraphs in their order, links, images and words of an HTML fragment.
	 *
	 * @return array{blocks: array<int, array{type: string, tag: string, text: string, level?: int}>, headings: array<int, array{level: int, text: string}>, links: array<int, array{url: string, text: string, internal: bool, nofollow: bool}>, images: array<int, array{src: string, alt: string}>, text: string, words: int}
	 */
	public static function parse( string $html ): array {
		$result = array(
			'blocks'   => array(),
			'headings' => array(),
			'links'    => array(),
			'images'   => array(),
			'text'     => '',
			'words'    => 0,
		);
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $result;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return $result;
		}

		$buffer = '';
		self::walk( $body, $result, $buffer );
		self::flush( $result, $buffer, 'p' );

		$texts            = array_column( $result['blocks'], 'text' );
		$result['text']   = implode( "\n\n", $texts );
		$result['words']  = self::count_words( $result['text'] );

		return $result;
	}

	/** Words of a text: runs of letters and digits, with apostrophes and hyphens inside them. */
	public static function count_words( string $text ): int {
		return (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\'’ʼ\-][\p{L}\p{N}]+)*/u', $text );
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function walk( DOMNode $node, array &$result, string &$buffer ): void {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$buffer .= $child->data;
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, self::SKIP_TAGS, true ) || $child->hasAttribute( 'hidden' ) ) {
				continue;
			}

			if ( preg_match( '/^h([1-6])$/', $tag, $match ) ) {
				self::flush( $result, $buffer, 'p' );
				$inner = '';
				self::walk( $child, $result, $inner );
				$text = self::clean( $inner );
				if ( '' !== $text ) {
					$result['blocks'][]   = array(
						'type'  => 'heading',
						'tag'   => $tag,
						'text'  => $text,
						'level' => (int) $match[1],
					);
					$result['headings'][] = array(
						'level' => (int) $match[1],
						'text'  => $text,
					);
				}
				continue;
			}

			if ( 'img' === $tag ) {
				$result['images'][] = array(
					'src' => (string) $child->getAttribute( 'src' ),
					'alt' => self::clean( (string) $child->getAttribute( 'alt' ) ),
				);
				continue;
			}

			if ( 'br' === $tag ) {
				$buffer .= ' ';
				continue;
			}

			if ( 'a' === $tag ) {
				$start = strlen( $buffer );
				self::walk( $child, $result, $buffer );
				self::link( $result, (string) $child->getAttribute( 'href' ), self::clean( substr( $buffer, $start ) ), (string) $child->getAttribute( 'rel' ) );
				continue;
			}

			if ( in_array( $tag, self::BLOCK_TAGS, true ) ) {
				self::flush( $result, $buffer, 'p' );
				$inner = '';
				self::walk( $child, $result, $inner );
				self::flush( $result, $inner, $tag );
				continue;
			}

			// Inline elements: their text runs on in the paragraph around them.
			self::walk( $child, $result, $buffer );
		}
	}

	/**
	 * The text gathered so far becomes a paragraph.
	 *
	 * @param array<string, mixed> $result
	 */
	private static function flush( array &$result, string &$buffer, string $tag ): void {
		$text   = self::clean( $buffer );
		$buffer = '';
		if ( '' !== $text ) {
			$result['blocks'][] = array(
				'type' => 'text',
				'tag'  => $tag,
				'text' => $text,
			);
		}
	}

	/**
	 * A link of the page: to the site itself or elsewhere. Anchors on the page, mail and phone
	 * links are not links to pages.
	 *
	 * @param array<string, mixed> $result
	 */
	private static function link( array &$result, string $href, string $text, string $rel ): void {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(?:mailto|tel|sms|javascript|data):/i', $href ) ) {
			return;
		}

		$host     = wp_parse_url( $href, PHP_URL_HOST );
		$scheme   = wp_parse_url( $href, PHP_URL_SCHEME );
		$internal = null === $host || false === $host
			? ( null === $scheme || false === $scheme )
			: strtolower( (string) $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$result['links'][] = array(
			'url'      => $href,
			'text'     => $text,
			'internal' => $internal,
			'nofollow' => in_array( 'nofollow', preg_split( '/\s+/', strtolower( $rel ) ) ?: array(), true ),
		);
	}

	/** Text on one line; with /u, \s takes the no-break spaces too. */
	private static function clean( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
