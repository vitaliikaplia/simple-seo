<?php
/**
 * Alt text and titles of images from their names (decisions/images): a new image gets an alt text
 * when it has none, and a title that reads as words instead of a file name; the bulk editing
 * screen offers the same text for the images that have no alt text yet, to look over before it is
 * saved.
 *
 * The name is the image's title first — WordPress makes it from the file's name as it was on the
 * computer (Cyrillic and spaces kept), while the file itself is often renamed (transliterated, or
 * a timestamp) — then the file's name. Names that say nothing of the picture (IMG_1234, a
 * screenshot, a hash, a stock photo's code, "Untitled design") give no text: an alt text that
 * says nothing is worse than none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Images {
	public const OPTION = 'simple_seo_images';

	/** The longest text made from a name, in characters. */
	public const MAX_LENGTH = 125;

	/** Starts of the names of cameras, phones, screenshots and messengers. */
	private const DEVICE = '(?:img|dsc[nf]?|pxl|mvimg|vid|pano|gopr|dji|vlcsnap|screenshot|screen shot|снимок экрана|знімок екрана|скриншот|скріншот|whats ?app|telegram)';

	/** Names that say only that it is a picture. */
	private const GENERIC = '(?:img|image|images|photo|photos|foto|фото|pic|picture|picture copy|image copy|file|unnamed|download|untitled|untitled design|без назви|без названия|дизайн без названия|dizajn bez nazvaniya|dyzajn bez nazvy|lorem ipsum|cover|logo|icon|banner|copy|new|test|background|bg|thumbnail|thumb|placeholder)';

	/** Stock photo sites and downloaders: their names are a photographer and a code. */
	private const STOCK = '(?:unsplash|pexels|pixabay|depositphotos|shutterstock|freepik|adobestock|istock|istockphoto|gettyimages|dreamstime|123rf|iconfinder|flaticon|saveinta|snapinsta|savefrom|instasave)';

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'alt'   => true,
				'title' => true,
			),
			array( self::class, 'sanitize' ),
			false
		);

		// Before wp-loc (10), which copies the new image's title and alt text to the other languages.
		add_action( 'add_attachment', array( self::class, 'uploaded' ), 5 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'images', __( 'Images', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 19 );
	}

	/**
	 * A new image: its alt text from its name when it has none, its title as words when it is the
	 * file's name.
	 */
	public static function uploaded( $attachment_id ): void {
		$post = get_post( (int) $attachment_id );
		if ( ! $post instanceof WP_Post || ! wp_attachment_is_image( $post ) ) {
			return;
		}

		$text = self::text_for( $post );
		if ( '' === $text ) {
			return;
		}

		if ( Simple_SEO_Settings::value( self::OPTION, 'alt' ) && '' === trim( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', $text );
		}
		if ( Simple_SEO_Settings::value( self::OPTION, 'title' ) && self::is_file_name( (string) $post->post_title ) && $text !== $post->post_title ) {
			wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $text,
				)
			);
		}
	}

	/**
	 * The text an image's name gives: of its title, else of its file's name; '' when neither says
	 * anything of the picture.
	 */
	public static function text_for( WP_Post $attachment ): string {
		foreach ( array( (string) $attachment->post_title, wp_basename( (string) get_post_meta( $attachment->ID, '_wp_attached_file', true ) ) ) as $name ) {
			$text = self::text_from_name( $name );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * Whether a title is a file's name rather than words: no spaces, and dashes, underscores or
	 * dots between its parts.
	 */
	public static function is_file_name( string $title ): bool {
		return '' !== $title && ! preg_match( '/\s/u', $title ) && (bool) preg_match( '/[-_.+]/', $title );
	}

	/**
	 * Words of a name: without the extension, what WordPress adds to its copies (-scaled, -1024x768,
	 * -e1700000000000, -2), numbers at the ends, codes and hashes; dashes and underscores between
	 * words become spaces, the first letter a capital. '' for a name that says nothing of the
	 * picture.
	 */
	public static function text_from_name( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( preg_match( '/%[0-9a-f]{2}/i', $name ) ) {
			$name = rawurldecode( $name );
		}
		$name = (string) preg_replace( '/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic|heif|ico)$/i', '', trim( $name ) );

		// A hash or a stock photo says nothing of what is in the picture.
		if ( '' === $name || preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $name ) || preg_match( '/' . self::STOCK . '/i', $name ) ) {
			return '';
		}

		// What WordPress adds to the names of copies it makes, and a counter in brackets. Sizes
		// (-1024x768), edits (-e1700000000000) and counters (-2, (3)) go below with the numbers.
		$name = (string) preg_replace( '/(?:[-_](?:scaled|rotated)|\[\d{1,3}\])$/i', '', $name );

		// A name without spaces is a file's: its dashes, underscores and dots are spaces (a dot
		// between digits stays). A name with spaces keeps its dashes.
		$name = preg_match( '/\s/u', $name )
			? str_replace( '_', ' ', $name )
			: (string) preg_replace( '/[-_+]+|(?<!\d)\.|\.(?!\d)/', ' ', $name );

		$words = array();
		foreach ( (array) preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$letters = preg_match_all( '/\p{L}/u', $word );
			$digits  = preg_match_all( '/\d/', $word );
			// A code (2EHRBs1gefY, PE8A7927), a number with a letter or none (2024-01-01, 24T145525),
			// punctuation other than a dash or an ampersand between words.
			if ( ( $letters && $digits && mb_strlen( $word ) >= 8 && preg_match( '/^[\p{L}\d]+$/u', $word ) )
				|| ( $letters <= 1 && $digits >= 4 )
				|| ( ! $letters && ! $digits && ! preg_match( '/^[-–—&\/]+$/u', $word ) ) ) {
				continue;
			}
			$words[] = $word;
		}
		// Numbers and dashes at the ends: counters, dates, sizes.
		while ( $words && ! preg_match( '/\p{L}/u', (string) end( $words ) ) ) {
			array_pop( $words );
		}
		while ( $words && ! preg_match( '/\p{L}/u', (string) reset( $words ) ) ) {
			array_shift( $words );
		}

		$text = implode( ' ', $words );
		if ( preg_match_all( '/\p{L}/u', $text ) < 3
			|| preg_match( '/^' . self::DEVICE . '(?:\s|$)/iu', $text )
			|| preg_match( '/^' . self::GENERIC . '$/iu', $text ) ) {
			return '';
		}
		// camelCase: words.
		$text = (string) preg_replace( '/(\p{Ll})(\p{Lu})/u', '$1 $2', $text );

		// SHOUTED NAMES are written as a sentence.
		if ( ! preg_match( '/\p{Ll}/u', $text ) ) {
			$text = mb_strtolower( $text );
		}
		$text = mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );

		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_LENGTH );
			$text = (string) preg_replace( '/\s+\S*$/u', '', $text );
		}

		return $text;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, bool>
	 */
	public static function sanitize( array $input ): array {
		return array(
			'alt'   => ! isset( $input['alt'] ) || '1' === (string) $input['alt'],
			'title' => ! isset( $input['title'] ) || '1' === (string) $input['title'],
		);
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$bulk     = add_query_arg(
			array(
				'page'   => Simple_SEO_Bulk::PAGE,
				'object' => Simple_SEO_Bulk::IMAGES,
				'filter' => 'alt',
			),
			admin_url( 'admin.php' )
		);

		require SIMPLE_SEO_DIR . 'includes/views/tab-images.php';
	}
}
