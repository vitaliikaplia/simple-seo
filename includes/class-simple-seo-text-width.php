<?php
/**
 * The width of a text in Arial, as search engines measure a title and a description in their
 * results: they cut what is wider, not what has more characters, and Cyrillic letters are wider
 * than Latin ones (decisions/snippet-preview). The search result preview measures in the browser
 * (assets/js/admin.js, canvas without kerning); this class gives the server the same numbers for
 * the content analysis and for the description made from a post's text.
 *
 * The widths of the characters are Arial's advance widths, in thousandths of the font size,
 * measured in a browser with canvas (kerning off). A character not in the table counts as a letter
 * of average width, or as a square for Chinese, Japanese, Korean and emoji.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Text_Width {
	/** A title in the results on a computer: width in pixels, font size in pixels. */
	public const TITLE = array( 600, 20 );

	/** A description in the results on a computer: width in pixels, font size in pixels. */
	public const DESCRIPTION = array( 920, 14 );

	/** The widths of characters, by the first code point of a range of them. */
	private const RANGES = array(
		0x0020 => '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584',
		0x00A0 => '278,333,556,556,556,556,260,556,333,737,370,556,584,0,737,552,400,549,333,333,333,576,537,333,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,549,611,556,556,556,556,500,556,500',
		0x0100 => '667,556,667,556,667,556,722,500,722,500,722,500,722,500,722,615,722,556,667,556,667,556,667,556,667,556,667,556,778,556,778,556,778,556,778,556,722,556,722,556,278,278,278,278,278,278,278,222,278,278,735,444,500,222,667,500,500,556,222,556,222,556,292,556,334,556,222,722,556,722,556,722,556,604,723,556,778,556,778,556,778,556,1000,944,722,333,722,333,722,333,667,500,667,500,667,500,667,500,611,278,611,375,611,278,722,556,722,556,722,556,722,556,722,556,722,556,944,722,667,500,667,611,500,611,500,611,500,222',
		0x0400 => '667,667,865,542,719,667,278,278,500,1057,1010,854,583,719,635,719,667,656,667,542,677,667,923,604,719,719,583,656,833,722,778,719,667,722,611,635,760,667,740,667,917,938,792,885,656,719,1010,722,556,573,531,365,583,556,669,458,559,559,438,583,688,552,556,542,556,500,458,500,823,500,573,521,802,823,625,719,521,510,750,542,556,556,556,365,510,500,222,278,222,906,813,556,438,559,500,552',
		0x0490 => '489,411',
		0x2010 => '333,333,556,556,1000,1000,413,552,222,222,222,222,333,333,333,333,556,556,350,354,323,667,1000',
		0x2030 => '1000',
		0x2039 => '333,333',
		0x20AC => '556',
		0x20B4 => '556',
		0x2116 => '1073',
		0x2122 => '1000',
	);

	/** A character not in the table: a letter of average width. */
	private const OTHER = 556;

	/** Chinese, Japanese, Korean and emoji: a square. */
	private const SQUARE = 1000;

	/** @var array<int, int>|null Code point => width. */
	private static ?array $widths = null;

	/**
	 * The width of a text in pixels at a font size.
	 */
	public static function width( string $text, int $size ): float {
		$units = 0;
		foreach ( self::characters( $text ) as $character ) {
			$units += self::units( $character );
		}

		return $units * $size / 1000;
	}

	/**
	 * Whether a text fits a width.
	 *
	 * @param array{0: int, 1: int} $limit Width and font size, in pixels.
	 */
	public static function fits( string $text, array $limit ): bool {
		return self::width( $text, $limit[1] ) <= $limit[0];
	}

	/**
	 * A text cut to fit a width with an ellipsis: after the last word that fits (no punctuation
	 * before the ellipsis), or after the last character for a single long word. The text as it is
	 * when it fits.
	 *
	 * @param array{0: int, 1: int} $limit Width and font size, in pixels.
	 */
	public static function cut( string $text, array $limit ): string {
		if ( self::fits( $text, $limit ) ) {
			return $text;
		}

		$room  = $limit[0] * 1000 / $limit[1] - self::units( '…' );
		$units = 0;
		$kept  = '';
		$word  = '';
		foreach ( self::characters( $text ) as $character ) {
			$units += self::units( $character );
			if ( $units > $room ) {
				break;
			}
			$kept .= $character;
			if ( ' ' === $character ) {
				$word = $kept;
			}
		}
		$kept = '' !== trim( $word ) ? $word : $kept;

		// Not rtrim(): its character list is bytes, and the dashes would eat bytes of Cyrillic letters.
		return (string) preg_replace( '/[\s,.;:–—-]+$/u', '', $kept ) . '…';
	}

	/**
	 * The characters of a text; a byte that is no UTF-8 becomes the replacement character, as a
	 * browser shows it.
	 *
	 * @return string[]
	 */
	private static function characters( string $text ): array {
		$characters = preg_split( '//u', wp_check_invalid_utf8( $text, true ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $characters ) ? $characters : array();
	}

	/** The width of a character in thousandths of the font size. */
	private static function units( string $character ): int {
		if ( null === self::$widths ) {
			self::$widths = array();
			foreach ( self::RANGES as $start => $widths ) {
				foreach ( explode( ',', $widths ) as $offset => $width ) {
					self::$widths[ $start + $offset ] = (int) $width;
				}
			}
		}

		$code = mb_ord( $character );
		if ( false === $code ) {
			return self::OTHER;
		}
		if ( isset( self::$widths[ $code ] ) ) {
			return self::$widths[ $code ];
		}

		return preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $character ) || $code >= 0x1F000 ? self::SQUARE : self::OTHER;
	}
}
