<?php
/**
 * Readability of a post's text, by what does not depend on its language: how long the sentences
 * and the paragraphs are, how much text goes without a subheading, and whether sentences in a row
 * start with the same word. Checks that need a language — passive voice, transition words,
 * syllables — are left out: the sites are in Ukrainian, English and other languages, and the rules
 * of one language do not hold for another.
 *
 * It reads the text Simple_SEO_Content gives; Simple_SEO_Analysis runs it along with the SEO
 * checks, so the editor redoes both at once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Readability {
	/** A sentence of more words than this is long. */
	public const SENTENCE_WORDS = 20;

	/** A paragraph of more words than this is long. */
	public const PARAGRAPH_WORDS = 150;

	/** More words than this in a row want a subheading. */
	public const SECTION_WORDS = 300;

	/** This many sentences in a row starting with the same word are a repetition. */
	public const SAME_START = 3;

	/** Text blocks that are not prose: table cells and preformatted text. */
	private const NOT_PROSE = array( 'td', 'th', 'caption', 'pre' );

	/**
	 * The checks of a text and its score.
	 *
	 * @param array<string, mixed> $content Simple_SEO_Content::read().
	 * @return array{score: int, rating: string, checks: array<int, array{id: string, status: string, text: string}>}
	 */
	public static function run( array $content ): array {
		$sentences  = array();
		$paragraphs = array();
		$sections   = array( 0 );
		// The first word of each sentence in order; '' where the run of sentences breaks.
		$starts = array();

		foreach ( (array) $content['blocks'] as $block ) {
			if ( 'heading' === $block['type'] ) {
				$sections[] = 0;
				$starts[]   = '';
				continue;
			}
			if ( in_array( $block['tag'], self::NOT_PROSE, true ) ) {
				$starts[] = '';
				continue;
			}

			$sections[ count( $sections ) - 1 ] += Simple_SEO_Content::count_words( $block['text'] );
			// The items of a list are no paragraphs, and they start alike on purpose.
			$list = 'li' === $block['tag'];
			if ( $list ) {
				$starts[] = '';
			} else {
				$paragraphs[] = Simple_SEO_Content::count_words( $block['text'] );
			}
			foreach ( self::sentences( $block['text'] ) as $sentence ) {
				$sentences[] = Simple_SEO_Content::count_words( $sentence );
				if ( ! $list ) {
					$starts[] = Simple_SEO_Analysis::words( $sentence )[0] ?? '';
				}
			}
		}

		$checks = $sentences
			? array_merge(
				array( self::sentence_check( $sentences ) ),
				self::paragraph_check( $paragraphs ),
				self::section_check( $sections ),
				array( self::start_check( $starts ) )
			)
			: array( Simple_SEO_Analysis::check( 'readability', 'info', __( 'There is no text to read yet.', 'simple-seo' ) ) );

		return Simple_SEO_Analysis::score( $checks ) + array( 'checks' => $checks );
	}

	/**
	 * The sentences of a paragraph: a stop, a question or an exclamation mark (a closing quote or
	 * bracket after it too), a space, and then anything but a small letter — so "т. зв." and "e.g.
	 * this" stay whole. Pieces without a word are no sentences.
	 *
	 * @return string[]
	 */
	public static function sentences( string $text ): array {
		$pieces = preg_split( '/(?:(?<=[.!?…])|(?<=[.!?…]["\'»”’)\]]))\s+(?=[^\p{Ll}])/u', $text ) ?: array();

		return array_values( array_filter( $pieces, static fn( string $piece ): bool => Simple_SEO_Content::count_words( $piece ) > 0 ) );
	}

	/**
	 * @param int[] $sentences Words of each sentence.
	 * @return array{id: string, status: string, text: string}
	 */
	private static function sentence_check( array $sentences ): array {
		$long  = count( array_filter( $sentences, static fn( int $words ): bool => $words > self::SENTENCE_WORDS ) );
		$share = 100 * $long / count( $sentences );
		$args  = array( number_format_i18n( self::SENTENCE_WORDS ), number_format_i18n( $long ), number_format_i18n( count( $sentences ) ) );

		if ( $share <= 25 ) {
			/* translators: 1: number of words, 2: number of long sentences, 3: number of sentences. */
			return Simple_SEO_Analysis::check( 'sentence_length', 'good', vsprintf( __( 'Sentences of more than %1$s words: %2$s of %3$s. Short sentences are easy to read.', 'simple-seo' ), $args ) );
		}

		/* translators: 1: number of words, 2: number of long sentences, 3: number of sentences. */
		return Simple_SEO_Analysis::check( 'sentence_length', $share <= 35 ? 'ok' : 'bad', vsprintf( __( 'Sentences of more than %1$s words: %2$s of %3$s. Split the longest: a text reads easily when no more than a quarter of its sentences are long.', 'simple-seo' ), $args ) );
	}

	/**
	 * Only where there are paragraphs: a text of list items alone has none.
	 *
	 * @param int[] $paragraphs Words of each paragraph.
	 * @return array<int, array{id: string, status: string, text: string}>
	 */
	private static function paragraph_check( array $paragraphs ): array {
		if ( ! $paragraphs ) {
			return array();
		}

		$longest = max( $paragraphs );
		if ( $longest <= self::PARAGRAPH_WORDS ) {
			/* translators: %s: number of words. */
			return array( Simple_SEO_Analysis::check( 'paragraph_length', 'good', sprintf( __( 'The paragraphs are short enough: the longest has %s words.', 'simple-seo' ), number_format_i18n( $longest ) ) ) );
		}

		$long = count( array_filter( $paragraphs, static fn( int $words ): bool => $words > self::PARAGRAPH_WORDS ) );

		/* translators: 1: number of words, 2: number of long paragraphs, 3: words of the longest. */
		return array( Simple_SEO_Analysis::check( 'paragraph_length', $longest <= 200 ? 'ok' : 'bad', sprintf( __( 'Paragraphs of more than %1$s words: %2$s, the longest has %3$s. Break them up: a wall of text puts readers off.', 'simple-seo' ), number_format_i18n( self::PARAGRAPH_WORDS ), number_format_i18n( $long ), number_format_i18n( $longest ) ) ) );
	}

	/**
	 * Only for a text long enough to want subheadings.
	 *
	 * @param int[] $sections Words before the first subheading and after each.
	 * @return array<int, array{id: string, status: string, text: string}>
	 */
	private static function section_check( array $sections ): array {
		$words = array_sum( $sections );
		if ( $words <= self::SECTION_WORDS ) {
			return array();
		}
		if ( 1 === count( $sections ) ) {
			/* translators: %s: number of words. */
			return array( Simple_SEO_Analysis::check( 'subheadings', 'bad', sprintf( __( 'The text has %s words and no subheadings: divide it into parts with subheadings, so readers find what they look for.', 'simple-seo' ), number_format_i18n( $words ) ) ) );
		}

		$longest = max( $sections );
		if ( $longest <= self::SECTION_WORDS ) {
			/* translators: %s: number of words. */
			return array( Simple_SEO_Analysis::check( 'subheadings', 'good', sprintf( __( 'Subheadings divide the text well: no more than %s words go without one.', 'simple-seo' ), number_format_i18n( $longest ) ) ) );
		}

		/* translators: 1: number of words, 2: how many words a part should have at most. */
		return array( Simple_SEO_Analysis::check( 'subheadings', $longest <= 400 ? 'ok' : 'bad', sprintf( __( '%1$s words go without a subheading: add one, a part reads best within %2$s words.', 'simple-seo' ), number_format_i18n( $longest ), number_format_i18n( self::SECTION_WORDS ) ) ) );
	}

	/**
	 * @param string[] $starts The first word of each sentence, '' where a run breaks.
	 * @return array{id: string, status: string, text: string}
	 */
	private static function start_check( array $starts ): array {
		$runs     = array();
		$previous = '';
		$length   = 0;
		foreach ( $starts as $word ) {
			// '' breaks a run and starts none.
			$length   = '' !== $word && $word === $previous ? $length + 1 : 1;
			$previous = $word;
			// A run counts once, when it gets long enough.
			if ( self::SAME_START === $length ) {
				$runs[] = $word;
			}
		}

		if ( ! $runs ) {
			return Simple_SEO_Analysis::check( 'sentence_starts', 'good', __( 'Sentences in a row start with different words.', 'simple-seo' ) );
		}

		/* translators: 1: number of sentences, 2: the words they start with. */
		return Simple_SEO_Analysis::check( 'sentence_starts', 1 === count( $runs ) ? 'ok' : 'bad', sprintf( __( '%1$s or more sentences in a row start with the same word: %2$s. Begin them differently.', 'simple-seo' ), number_format_i18n( self::SAME_START ), implode( ', ', array_unique( $runs ) ) ) );
	}
}
