<?php
/**
 * HTML to Markdown, for the Markdown versions of pages: what a reader takes from the page —
 * headings, paragraphs, emphasis, links, images, lists, quotes, code, tables — in CommonMark with
 * the tables and strikethrough of GitHub. Scripts, styles, forms, menus, frames and media players
 * are left out, and so is whatever is hidden from people (hidden, aria-hidden="true").
 *
 * Text is escaped only where Markdown would read it otherwise: the characters that start emphasis,
 * code or links, a backslash before punctuation, and what would start a heading, a quote, a list or
 * a rule at the beginning of a line. Underscores inside words stay as they are.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_HTML_Markdown {
	/** Elements whose content is not text a reader takes from the page. */
	private const SKIP = array( 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'embed', 'canvas', 'form', 'input', 'select', 'textarea', 'button', 'nav', 'audio', 'video', 'map', 'head', 'title', 'meta', 'link' );

	/** Elements that stand as blocks of their own; the rest run on in the text around them. */
	private const BLOCKS = array( 'p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'figure', 'figcaption', 'center', 'address', 'details', 'summary', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre', 'table', 'caption', 'hr', 'body' );

	/** Address relative links are read against. */
	private string $base;

	private function __construct( string $base ) {
		$this->base = $base;
	}

	/**
	 * Markdown of an HTML fragment.
	 *
	 * @param string $base Address of the page, for relative links and images ('' — the home page).
	 */
	public static function convert( string $html, string $base = '' ): string {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}

		$converter = new self( '' !== $base ? $base : home_url( '/' ) );

		return self::join( $converter->blocks( $body ) );
	}

	/**
	 * Escape plain text so that Markdown reads it as text. Line starts are left to line().
	 */
	public static function escape( string $text ): string {
		// A backslash escapes the punctuation after it: double it there.
		$text = (string) preg_replace( '/\\\\(?=[!-\/:-@\[-`{-~])/', '\\\\\\\\', $text );
		$text = str_replace( array( '`', '*', '[', ']' ), array( '\`', '\*', '\[', '\]' ), $text );
		// Underscores start emphasis at the edge of a word only.
		$text = (string) preg_replace( '/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\_', $text );

		// What would start an HTML tag or an autolink.
		return (string) preg_replace( '/<(?=[A-Za-z\/!?])/', '\<', $text );
	}

	/**
	 * A line of text that must not start a heading, a quote, a list or a rule.
	 */
	public static function line( string $line ): string {
		if ( preg_match( '/^(?:#{1,6}(?:\s|$)|>|[-+](?:\s|$)|={2,}\s*$|-{2,}\s*$)/', $line ) ) {
			return '\\' . $line;
		}

		return (string) preg_replace( '/^(\d{1,9})([.)])(\s|$)/', '$1\\\\$2$3', $line );
	}

	/** An address inside (…): spaces, brackets and parentheses would end it. */
	public static function url( string $url ): string {
		return str_replace( array( ' ', '(', ')', '<', '>' ), array( '%20', '%28', '%29', '%3C', '%3E' ), $url );
	}

	/** Blocks joined by a blank line; the blank lines inside a block of code stay. */
	private static function join( array $blocks ): string {
		return implode( "\n\n", array_filter( array_map( 'trim', $blocks ), static fn( string $block ): bool => '' !== $block ) );
	}

	/**
	 * The blocks of an element's content: runs of inline content become paragraphs.
	 *
	 * @return string[]
	 */
	private function blocks( DOMNode $parent ): array {
		$blocks = array();
		$buffer = '';

		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$buffer .= self::text( $child->data );
				continue;
			}
			if ( ! $child instanceof DOMElement || self::skipped( $child ) ) {
				continue;
			}

			if ( in_array( strtolower( $child->tagName ), self::BLOCKS, true ) ) {
				$blocks[] = self::paragraph( $buffer );
				$buffer   = '';
				array_push( $blocks, ...$this->block( $child ) );
				continue;
			}

			$buffer .= $this->inline( $child );
		}
		$blocks[] = self::paragraph( $buffer );

		return array_values( array_filter( $blocks, static fn( string $block ): bool => '' !== trim( $block ) ) );
	}

	/**
	 * A block element.
	 *
	 * @return string[]
	 */
	private function block( DOMElement $element ): array {
		$tag = strtolower( $element->tagName );

		if ( preg_match( '/^h([1-6])$/', $tag, $match ) ) {
			// A run of # at the end would close the heading.
			$text = (string) preg_replace( '/(\s)(#+)$/', '$1\\\\$2', self::single( $this->inline_content( $element ) ) );

			return '' !== $text ? array( str_repeat( '#', (int) $match[1] ) . ' ' . $text ) : array();
		}

		switch ( $tag ) {
			case 'p':
				return array( self::paragraph( $this->inline_content( $element ) ) );
			case 'ul':
			case 'ol':
				return array( $this->list( $element ) );
			case 'blockquote':
				$inner = self::join( $this->blocks( $element ) );

				return '' !== $inner ? array( self::prefix( $inner, '> ', '>' ) ) : array();
			case 'pre':
				return array( self::fence( $element ) );
			case 'table':
				return $this->table( $element );
			case 'hr':
				return array( '---' );
			case 'figcaption':
			case 'caption':
				$text = self::single( $this->inline_content( $element ) );

				return '' !== $text ? array( '*' . $text . '*' ) : array();
			case 'dt':
			case 'summary':
				$text = self::single( $this->inline_content( $element ) );

				return '' !== $text ? array( '**' . $text . '**' ) : array();
		}

		// Containers: their content in its order.
		return $this->blocks( $element );
	}

	/** Inline markup of an element that runs on in the text around it. */
	private function inline( DOMElement $element ): string {
		switch ( strtolower( $element->tagName ) ) {
			case 'br':
				return "\n";
			case 'strong':
			case 'b':
				return self::wrap( $this->inline_content( $element ), '**' );
			case 'em':
			case 'i':
				return self::wrap( $this->inline_content( $element ), '*' );
			case 'del':
			case 's':
			case 'strike':
				return self::wrap( $this->inline_content( $element ), '~~' );
			case 'code':
			case 'kbd':
			case 'samp':
				return self::code( $element->textContent );
			case 'a':
				return $this->link( $element );
			case 'img':
				return $this->image( $element );
		}

		return $this->inline_content( $element );
	}

	/** The content of an element as inline text; blocks inside it run on, set apart by spaces. */
	private function inline_content( DOMNode $parent ): string {
		$text = '';
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$text .= self::text( $child->data );
				continue;
			}
			if ( ! $child instanceof DOMElement || self::skipped( $child ) ) {
				continue;
			}

			$text .= in_array( strtolower( $child->tagName ), self::BLOCKS, true )
				? ' ' . $this->inline_content( $child ) . ' '
				: $this->inline( $child );
		}

		return $text;
	}

	private function link( DOMElement $element ): string {
		$text = self::single( $this->inline_content( $element ) );
		$href = trim( (string) $element->getAttribute( 'href' ) );

		// Anchors on the page and scripts are no addresses to follow.
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(?:javascript|data|vbscript):/i', $href ) ) {
			return $text;
		}
		if ( '' === $text ) {
			return '';
		}

		return '[' . $text . '](' . self::url( $this->absolute( $href ) ) . ')';
	}

	private function image( DOMElement $element ): string {
		$src = trim( (string) $element->getAttribute( 'src' ) );
		// Lazy loading puts the image elsewhere and a placeholder in src.
		if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
			$src = '';
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attribute ) {
				$candidate = trim( (string) $element->getAttribute( $attribute ) );
				if ( '' !== $candidate && ! str_starts_with( $candidate, 'data:' ) ) {
					$src = $candidate;
					break;
				}
			}
		}
		if ( '' === $src ) {
			return '';
		}

		return '![' . self::single( self::escape( (string) $element->getAttribute( 'alt' ) ) ) . '](' . self::url( $this->absolute( $src ) ) . ')';
	}

	/**
	 * A list, its items indented under their markers; lists inside items stay tight. What stands in
	 * the list outside an item is shown as one too, rather than lost.
	 */
	private function list( DOMElement $list ): string {
		$ordered = 'ol' === strtolower( $list->tagName );
		$number  = $ordered && '' !== $list->getAttribute( 'start' ) ? (int) $list->getAttribute( 'start' ) : 1;
		$items   = array();

		foreach ( $list->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || self::skipped( $child ) ) {
				continue;
			}

			$content = '';
			foreach ( $this->blocks( $child ) as $block ) {
				$nested   = (bool) preg_match( '/^(?:- |\d+\. )/', $block );
				$content .= '' === $content ? $block : ( $nested ? "\n" : "\n\n" ) . $block;
			}
			if ( '' === trim( $content ) ) {
				continue;
			}

			$marker  = $ordered ? $number++ . '. ' : '- ';
			$items[] = $marker . self::prefix( $content, str_repeat( ' ', strlen( $marker ) ), '', true );
		}

		return implode( "\n", $items );
	}

	/**
	 * A table of GitHub Markdown: the first row heads it.
	 *
	 * @return string[]
	 */
	private function table( DOMElement $table ): array {
		$caption = '';
		$rows    = array();
		foreach ( $table->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( 'caption' === $tag ) {
				$caption = self::single( $this->inline_content( $child ) );
			} elseif ( 'tr' === $tag ) {
				$rows[] = $this->row( $child );
			} elseif ( in_array( $tag, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				foreach ( $child->childNodes as $row ) {
					if ( $row instanceof DOMElement && 'tr' === strtolower( $row->tagName ) ) {
						$rows[] = $this->row( $row );
					}
				}
			}
		}

		$rows = array_values( array_filter( $rows, static fn( array $cells ): bool => array() !== $cells ) );
		if ( ! $rows ) {
			return '' !== $caption ? array( '*' . $caption . '*' ) : array();
		}

		$columns = max( array_map( 'count', $rows ) );
		$lines   = array();
		foreach ( $rows as $index => $cells ) {
			$cells   = array_pad( $cells, $columns, '' );
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';
			if ( 0 === $index ) {
				$lines[] = '| ' . implode( ' | ', array_fill( 0, $columns, '---' ) ) . ' |';
			}
		}

		$blocks = array();
		if ( '' !== $caption ) {
			$blocks[] = '*' . $caption . '*';
		}
		$blocks[] = implode( "\n", $lines );

		return $blocks;
	}

	/** @return string[] The cells of a row, each on one line. */
	private function row( DOMElement $row ): array {
		$cells = array();
		foreach ( $row->childNodes as $cell ) {
			if ( $cell instanceof DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
				$cells[] = str_replace( '|', '\|', self::single( $this->inline_content( $cell ) ) );
			}
		}

		return $cells;
	}

	/** A block of code, fenced longer than any run of backticks inside it, with its language. */
	private static function fence( DOMElement $pre ): string {
		$code = rtrim( str_replace( array( "\r\n", "\r" ), "\n", $pre->textContent ) );
		if ( '' === trim( $code ) ) {
			return '';
		}

		$language = '';
		$classes  = (string) $pre->getAttribute( 'class' );
		foreach ( $pre->getElementsByTagName( 'code' ) as $inner ) {
			$classes .= ' ' . $inner->getAttribute( 'class' );
		}
		if ( preg_match( '/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#-]+)/', $classes, $match ) ) {
			$language = $match[1];
		}

		preg_match_all( '/`+/', $code, $runs );
		$longest = $runs[0] ? max( array_map( 'strlen', $runs[0] ) ) : 0;
		$fence   = str_repeat( '`', max( 3, $longest + 1 ) );

		return $fence . $language . "\n" . $code . "\n" . $fence;
	}

	/** Code inside text, between more backticks than it has in a row. */
	private static function code( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}

		preg_match_all( '/`+/', $text, $runs );
		$ticks = str_repeat( '`', ( $runs[0] ? max( array_map( 'strlen', $runs[0] ) ) : 0 ) + 1 );
		$pad   = str_starts_with( $text, '`' ) || str_ends_with( $text, '`' ) ? ' ' : '';

		return $ticks . $pad . $text . $pad . $ticks;
	}

	/** Emphasis around text; the spaces at its edges stay outside the marks. */
	private static function wrap( string $text, string $mark ): string {
		$inner = trim( $text );
		if ( '' === $inner ) {
			return '' !== $text ? ' ' : '';
		}

		$lead  = preg_match( '/^\s/u', $text ) ? ' ' : '';
		$trail = preg_match( '/\s$/u', $text ) ? ' ' : '';

		return $lead . $mark . self::single( $inner ) . $mark . $trail;
	}

	/** A paragraph: its lines (from <br>) trimmed, none of them starting a block of Markdown. */
	private static function paragraph( string $text ): string {
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = self::line( $line );
			}
		}

		return implode( "\n", $lines );
	}

	/** A text node: the line breaks of the source are spaces (only <br> breaks a line). */
	private static function text( string $data ): string {
		return self::escape( (string) preg_replace( '/\s+/u', ' ', $data ) );
	}

	/** Text on one line. */
	private static function single( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Every line of a text with a prefix: the quote mark, or the indent of a list item.
	 *
	 * @param bool $skip_first The first line follows the list marker.
	 */
	private static function prefix( string $text, string $prefix, string $empty, bool $skip_first = false ): string {
		$lines = explode( "\n", $text );
		foreach ( $lines as $index => $line ) {
			if ( $skip_first && 0 === $index ) {
				continue;
			}
			$lines[ $index ] = '' === $line ? $empty : $prefix . $line;
		}

		return implode( "\n", $lines );
	}

	private static function skipped( DOMElement $element ): bool {
		return in_array( strtolower( $element->tagName ), self::SKIP, true )
			|| $element->hasAttribute( 'hidden' )
			|| 'true' === strtolower( trim( (string) $element->getAttribute( 'aria-hidden' ) ) );
	}

	/** An address of a link or an image, absolute. */
	private function absolute( string $url ): string {
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
			return $url;
		}

		$scheme = (string) wp_parse_url( $this->base, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $this->base, PHP_URL_HOST );
		$port   = wp_parse_url( $this->base, PHP_URL_PORT );
		$origin = $scheme . '://' . $host . ( $port ? ':' . $port : '' );

		if ( str_starts_with( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . $url;
		}

		$path = (string) wp_parse_url( $this->base, PHP_URL_PATH );
		if ( str_starts_with( $url, '?' ) ) {
			return $origin . ( '' !== $path ? $path : '/' ) . $url;
		}

		// Relative to the folder of the page.
		$folder = '' !== $path && str_ends_with( $path, '/' ) ? $path : trailingslashit( dirname( '' !== $path ? $path : '/' ) );

		return $origin . ( '/' === $folder[0] ? $folder : '/' . $folder ) . $url;
	}
}
