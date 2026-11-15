<?php
/**
 * SEO analysis of a post: the focus keyphrase and the other keyphrases in the title, the
 * description, the address, the first paragraph, the subheadings, the text and the alt text of
 * images; the length of the text, its links and images; the length of the title and the
 * description. Each check says what to do, and together they give a score out of 100. The
 * readability of the text (Simple_SEO_Readability) comes with them, with a score of its own.
 *
 * The text comes from Simple_SEO_Content, so the analysis reads any editor's content, the one
 * still being edited included: the editor sends it through the REST route of this class.
 *
 * Words match by their stems — the start of a word without its last letters — so "ключова фраза"
 * is found in "ключової фрази" and "analysis" in "analyses": no dictionary of any language.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Analysis {
	public const NAMESPACE = 'simple-seo/v1';

	/** Additional keyphrases checked; lines after these are ignored. */
	public const MORE = 4;

	/** Points of a check: good, ok, bad. A note (info) gives none and takes none. */
	private const POINTS = array(
		'good' => 2,
		'ok'   => 1,
		'bad'  => 0,
	);

	public static function init(): void {
		add_action( 'init', array( self::class, 'register_fields' ), 5 );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	public static function register_fields(): void {
		Simple_SEO_Fields::register_group( 'analysis', __( 'Content analysis', 'simple-seo' ) );
		Simple_SEO_Fields::register(
			'keyphrase',
			array(
				'label'       => __( 'Focus keyphrase', 'simple-seo' ),
				'description' => __( 'The words people search for to find this page. The analysis below checks the page for them.', 'simple-seo' ),
				'objects'     => array( 'post' ),
				'order'       => 90,
				'group'       => 'analysis',
			)
		);
		Simple_SEO_Fields::register(
			'keyphrases',
			array(
				/* translators: %d: how many. */
				'label'       => sprintf( __( 'More keyphrases, up to %d', 'simple-seo' ), self::MORE ),
				'description' => __( 'One on a line: other searches this page answers. The analysis counts them in the text.', 'simple-seo' ),
				'type'        => 'textarea',
				'objects'     => array( 'post' ),
				'order'       => 91,
				'group'       => 'analysis',
			)
		);
	}

	public static function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			'/analysis/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => static fn( WP_REST_Request $request ): bool => self::can_analyse( (int) $request['id'] ),
				'args'                => array(
					'content'     => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'url'         => array( 'type' => 'string' ),
					'keyphrase'   => array( 'type' => 'string' ),
					'keyphrases'  => array( 'type' => 'string' ),
				),
			)
		);
	}

	/** Whoever edits the post and its SEO fields may have it analysed. */
	public static function can_analyse( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof WP_Post
			&& in_array( $post->post_type, Simple_SEO_Fields::post_types(), true )
			&& current_user_can( 'edit_post', $post_id )
			&& current_user_can( Simple_SEO_Access::CAP_FIELDS );
	}

	/** @return array<string, mixed> */
	public static function rest( WP_REST_Request $request ): array {
		$input = array();
		foreach ( array( 'content', 'title', 'description', 'url', 'keyphrase', 'keyphrases' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		return self::run( get_post( (int) $request['id'] ), $input );
	}

	/**
	 * Analyse a post: as saved, or with what the editor has now.
	 *
	 * @param array<string, string> $input content, title and description (as the page shows them,
	 *                                     variables filled), url, keyphrase, keyphrases.
	 * @return array{score: int, rating: string, words: int, checks: array<int, array{id: string, status: string, text: string}>, readability: array{score: int, rating: string, checks: array<int, array{id: string, status: string, text: string}>}}
	 */
	public static function run( WP_Post $post, array $input = array() ): array {
		$context = Simple_SEO_Context::for_post( $post );
		$title   = isset( $input['title'] ) ? Simple_SEO_Variables::plain( $input['title'] ) : Simple_SEO_Titles::title( $context );
		$summary = isset( $input['description'] ) ? Simple_SEO_Variables::plain( $input['description'] ) : Simple_SEO_Titles::description( $context );
		$url     = isset( $input['url'] ) ? $input['url'] : (string) get_permalink( $post );
		$focus   = self::clean_phrase( $input['keyphrase'] ?? Simple_SEO_Fields::post_value( (int) $post->ID, 'keyphrase' ) );
		$more    = self::more( $input['keyphrases'] ?? Simple_SEO_Fields::post_value( (int) $post->ID, 'keyphrases' ) );
		$content = Simple_SEO_Content::read( $post, $input['content'] ?? null );

		$checks = array();
		if ( '' === $focus ) {
			$checks[] = self::check( 'keyphrase', 'info', __( 'Set a focus keyphrase: the checks below then look for it on the page.', 'simple-seo' ) );
		} else {
			$checks = array_merge( $checks, self::keyphrase_checks( $post, $focus, $title, $summary, $url, $content ) );
		}
		foreach ( $more as $phrase ) {
			$checks[] = self::more_check( $phrase, $content );
		}
		$checks = array_values( array_merge( $checks, self::page_checks( $title, $summary, $content ) ) );

		return self::score( $checks ) + array(
			'words'       => (int) $content['words'],
			'checks'      => $checks,
			'readability' => Simple_SEO_Readability::run( $content ),
		);
	}

	/**
	 * The score of checks out of 100 and its rating; rating none where no check counts.
	 *
	 * @param array<int, array{status: string}> $checks
	 * @return array{score: int, rating: string}
	 */
	public static function score( array $checks ): array {
		$scored = array_filter( $checks, static fn( array $check ): bool => isset( self::POINTS[ $check['status'] ] ) );
		if ( ! $scored ) {
			return array(
				'score'  => 0,
				'rating' => 'none',
			);
		}

		$points = array_sum( array_map( static fn( array $check ): int => self::POINTS[ $check['status'] ], $scored ) );
		$score  = (int) round( 100 * $points / ( 2 * count( $scored ) ) );

		return array(
			'score'  => $score,
			'rating' => self::rating( $score ),
		);
	}

	public static function rating( int $score ): string {
		return $score >= 75 ? 'good' : ( $score >= 45 ? 'ok' : 'bad' );
	}

	/**
	 * The panel of the meta box: the score and the checks of the post as saved; assets/js/admin.js
	 * redoes them through the REST route while the post is edited.
	 */
	public static function render( WP_Post $post ): string {
		$result = self::run( $post );
		// The list of posts shows the scores of the post as saved: these are they.
		Simple_SEO_Scores::remember( $post, $result );

		$data = array(
			'url'         => rest_url( self::NAMESPACE . '/analysis/' . (int) $post->ID ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			/* translators: %s: score out of 100. */
			'score'       => __( 'SEO score: %s of 100', 'simple-seo' ),
			/* translators: %s: score out of 100. */
			'readability' => __( 'Readability: %s of 100', 'simple-seo' ),
			'unscored'    => __( 'Readability', 'simple-seo' ),
			'failed'      => __( 'The analysis could not be updated; it shows the page as it was.', 'simple-seo' ),
		);

		return sprintf(
			'<div class="simple-seo-analysis" data-simple-seo-analysis="%1$s">%2$s%3$s<p class="simple-seo-analysis-error description" hidden>%4$s</p></div>',
			esc_attr( (string) wp_json_encode( $data ) ),
			self::part( 'seo', $result, sprintf( $data['score'], number_format_i18n( $result['score'] ) ) ),
			self::part( 'readability', $result['readability'], 'none' === $result['readability']['rating'] ? $data['unscored'] : sprintf( $data['readability'], number_format_i18n( $result['readability']['score'] ) ) ),
			esc_html( $data['failed'] )
		);
	}

	/**
	 * One part of the panel: its score and its checks.
	 *
	 * @param array{rating: string, checks: array<int, array{status: string, text: string}>} $result
	 */
	private static function part( string $name, array $result, string $heading ): string {
		$items = '';
		foreach ( $result['checks'] as $check ) {
			$items .= sprintf( '<li class="is-%s">%s</li>', esc_attr( $check['status'] ), esc_html( $check['text'] ) );
		}

		return sprintf(
			'<div class="simple-seo-analysis-part" data-part="%1$s"><p class="simple-seo-analysis-score is-%2$s"><strong>%3$s</strong></p><ul class="simple-seo-analysis-checks">%4$s</ul></div>',
			esc_attr( $name ),
			esc_attr( $result['rating'] ),
			esc_html( $heading ),
			$items
		);
	}

	// ----------------------------------------------------------------------------------------
	// Checks.

	/**
	 * @param array<string, mixed> $content Simple_SEO_Content::read().
	 * @return array<int, array{id: string, status: string, text: string}>
	 */
	private static function keyphrase_checks( WP_Post $post, string $focus, string $title, string $summary, string $url, array $content ): array {
		$stems  = array_map( array( self::class, 'stem' ), self::words( $focus ) );
		$checks = array();

		$checks[] = count( $stems ) <= 4
			? self::check( 'keyphrase_length', 'good', __( 'The keyphrase is short enough to be searched for as it is.', 'simple-seo' ) )
			: self::check( 'keyphrase_length', 'ok', __( 'The keyphrase is long: few people search for more than four words at once.', 'simple-seo' ) );

		$checks[] = self::contains( self::words( $title ), $stems )
			? self::check( 'keyphrase_title', 'good', __( 'The SEO title has the keyphrase.', 'simple-seo' ) )
			: self::check( 'keyphrase_title', 'bad', __( 'The SEO title does not have the keyphrase: put it in, nearer the start the better.', 'simple-seo' ) );

		$checks[] = self::contains( self::words( $summary ), $stems )
			? self::check( 'keyphrase_description', 'good', __( 'The meta description has the keyphrase.', 'simple-seo' ) )
			: self::check( 'keyphrase_description', 'bad', __( 'The meta description does not have the keyphrase: search engines highlight it there.', 'simple-seo' ) );

		$checks[] = self::in_address( $url, $focus, $stems )
			? self::check( 'keyphrase_slug', 'good', __( 'The address of the page has the keyphrase.', 'simple-seo' ) )
			: self::check( 'keyphrase_slug', 'ok', __( 'The address of the page does not have the keyphrase: put its words into the slug.', 'simple-seo' ) );

		$first    = '';
		foreach ( $content['blocks'] as $block ) {
			if ( 'text' === $block['type'] ) {
				$first = $block['text'];
				break;
			}
		}
		$checks[] = self::contains( self::words( $first ), $stems )
			? self::check( 'keyphrase_intro', 'good', __( 'The first paragraph has the keyphrase.', 'simple-seo' ) )
			: self::check( 'keyphrase_intro', 'bad', __( 'The first paragraph does not have the keyphrase: say early what the page is about.', 'simple-seo' ) );

		if ( $content['headings'] ) {
			$in_headings = array_filter( $content['headings'], static fn( array $heading ): bool => self::contains( self::words( $heading['text'] ), $stems ) );
			$checks[]    = $in_headings
				? self::check( 'keyphrase_headings', 'good', __( 'A subheading has the keyphrase.', 'simple-seo' ) )
				: self::check( 'keyphrase_headings', 'ok', __( 'No subheading has the keyphrase: use it, or its words, in one of them.', 'simple-seo' ) );
		}

		$words   = self::words( $content['text'] );
		$count   = self::occurrences( $words, $stems );
		$density = $words ? 100 * $count * count( $stems ) / count( $words ) : 0;
		if ( 0 === $count ) {
			$checks[] = self::check( 'keyphrase_density', 'bad', __( 'The text does not have the keyphrase.', 'simple-seo' ) );
		} elseif ( $density > 3 && $count > 2 ) {
			// Once or twice in a short text is not stuffing, however large its share.
			/* translators: 1: how many times, 2: share of the words, in per cent. */
			$checks[] = self::check( 'keyphrase_density', 'bad', sprintf( __( 'The keyphrase is in the text %1$s times, %2$s%% of the words: too often, it reads as stuffing.', 'simple-seo' ), number_format_i18n( $count ), number_format_i18n( $density, 1 ) ) );
		} elseif ( $density < 0.5 ) {
			// Below half a per cent: only a long text gets there with the keyphrase in it.
			/* translators: 1: how many times, 2: share of the words, in per cent. */
			$checks[] = self::check( 'keyphrase_density', 'ok', sprintf( __( 'The keyphrase is in the text %1$s times, %2$s%% of the words: it could be there more often.', 'simple-seo' ), number_format_i18n( $count ), number_format_i18n( $density, 1 ) ) );
		} else {
			/* translators: %s: how many times. */
			$checks[] = self::check( 'keyphrase_density', 'good', sprintf( __( 'The keyphrase is in the text %s times.', 'simple-seo' ), number_format_i18n( $count ) ) );
		}

		if ( $content['images'] ) {
			$in_alt   = array_filter( $content['images'], static fn( array $image ): bool => self::contains( self::words( $image['alt'] ), $stems ) );
			$checks[] = $in_alt
				? self::check( 'keyphrase_images', 'good', __( 'The alt text of an image has the keyphrase.', 'simple-seo' ) )
				: self::check( 'keyphrase_images', 'ok', __( 'No image has the keyphrase in its alt text.', 'simple-seo' ) );
		}

		$others   = self::used_elsewhere( $post, $focus );
		$checks[] = $others
			/* translators: %s: titles of other posts. */
			? self::check( 'keyphrase_used', 'ok', sprintf( __( 'The same keyphrase is the focus of other pages too: %s. Pages on one search compete with each other.', 'simple-seo' ), implode( ', ', $others ) ) )
			: self::check( 'keyphrase_used', 'good', __( 'No other page has this focus keyphrase.', 'simple-seo' ) );

		return $checks;
	}

	/**
	 * @param array<string, mixed> $content
	 * @return array{id: string, status: string, text: string}
	 */
	private static function more_check( string $phrase, array $content ): array {
		$count = self::occurrences( self::words( $content['text'] ), array_map( array( self::class, 'stem' ), self::words( $phrase ) ) );

		return $count
			/* translators: 1: a keyphrase, 2: how many times. */
			? self::check( 'more_keyphrases', 'good', sprintf( __( '"%1$s" is in the text %2$s times.', 'simple-seo' ), $phrase, number_format_i18n( $count ) ) )
			/* translators: %s: a keyphrase. */
			: self::check( 'more_keyphrases', 'bad', sprintf( __( '"%s" is not in the text.', 'simple-seo' ), $phrase ) );
	}

	/**
	 * @param array<string, mixed> $content
	 * @return array<int, array{id: string, status: string, text: string}>
	 */
	private static function page_checks( string $title, string $summary, array $content ): array {
		$checks = array();
		$words  = (int) $content['words'];

		$checks[] = $words >= 300
			/* translators: %s: number of words. */
			? self::check( 'length', 'good', sprintf( __( 'The text has %s words.', 'simple-seo' ), number_format_i18n( $words ) ) )
			/* translators: %s: number of words. */
			: self::check( 'length', $words >= 150 ? 'ok' : 'bad', sprintf( __( 'The text has %s words: search engines favour pages that say more; 300 is a good start.', 'simple-seo' ), number_format_i18n( $words ) ) );

		$internal = count( array_filter( $content['links'], static fn( array $link ): bool => $link['internal'] ) );
		$external = count( $content['links'] ) - $internal;
		$checks[] = $internal
			/* translators: %s: number of links. */
			? self::check( 'links_internal', 'good', sprintf( __( 'Links to other pages of the site: %s.', 'simple-seo' ), number_format_i18n( $internal ) ) )
			: self::check( 'links_internal', 'ok', __( 'No links to other pages of the site: they help visitors and search engines find them.', 'simple-seo' ) );
		$checks[] = $external
			/* translators: %s: number of links. */
			? self::check( 'links_external', 'good', sprintf( __( 'Links to other sites: %s.', 'simple-seo' ), number_format_i18n( $external ) ) )
			: self::check( 'links_external', 'ok', __( 'No links to other sites: a link to a good source makes the text more trustworthy.', 'simple-seo' ) );

		$images   = count( $content['images'] );
		$no_alt   = count( array_filter( $content['images'], static fn( array $image ): bool => '' === $image['alt'] ) );
		$checks[] = $images
			/* translators: %s: number of images. */
			? self::check( 'images', 'good', sprintf( __( 'Images: %s.', 'simple-seo' ), number_format_i18n( $images ) ) )
			: self::check( 'images', 'ok', __( 'No images: a picture makes a page easier to read and can come up in image search.', 'simple-seo' ) );
		if ( $no_alt ) {
			/* translators: %s: number of images. */
			$checks[] = self::check( 'alt', 'bad', sprintf( __( 'Images without alt text: %s. Describe what they show, for search engines and for people who do not see them.', 'simple-seo' ), number_format_i18n( $no_alt ) ) );
		}

		$checks[] = self::length_check( 'title_length', $title, 30, Simple_SEO_Text_Width::TITLE, __( 'The page has no SEO title of Simple SEO: the theme\'s title stays.', 'simple-seo' ) );
		$checks[] = self::length_check( 'description_length', $summary, 70, Simple_SEO_Text_Width::DESCRIPTION, __( 'The page has no meta description: search engines will show a piece of its text.', 'simple-seo' ) );

		return $checks;
	}

	/** @return array{id: string, status: string, text: string} */
	/**
	 * The length of the title or the description: short below a number of characters (it says too
	 * little), too long when wider than search results show it on a computer (its end is cut) — a
	 * width, as the search result preview measures it, not a number of characters.
	 *
	 * @param array{0: int, 1: int} $limit Width and font size of search results, in pixels.
	 */
	private static function length_check( string $id, string $text, int $short, array $limit, string $none ): array {
		$length = mb_strlen( $text );
		if ( 0 === $length ) {
			return self::check( $id, 'bad', $none );
		}

		$what  = 'title_length' === $id ? __( 'SEO title', 'simple-seo' ) : __( 'Meta description', 'simple-seo' );
		$width = (int) round( Simple_SEO_Text_Width::width( $text, $limit[1] ) );
		if ( $width > $limit[0] ) {
			/* translators: 1: SEO title or Meta description, 2: number of characters, 3: its width in pixels, 4: the width search results give it. */
			return self::check( $id, 'ok', sprintf( __( '%1$s: %2$s characters, %3$s pixels wide of %4$s: search engines will cut its end.', 'simple-seo' ), $what, number_format_i18n( $length ), number_format_i18n( $width ), number_format_i18n( $limit[0] ) ) );
		}
		if ( $length < $short ) {
			/* translators: 1: SEO title or Meta description, 2: number of characters. */
			return self::check( $id, 'ok', sprintf( __( '%1$s: %2$s characters, short: a search result has room for more.', 'simple-seo' ), $what, number_format_i18n( $length ) ) );
		}

		/* translators: 1: SEO title or Meta description, 2: number of characters. */
		return self::check( $id, 'good', sprintf( __( '%1$s: %2$s characters, a good length.', 'simple-seo' ), $what, number_format_i18n( $length ) ) );
	}

	/** @return array{id: string, status: string, text: string} */
	public static function check( string $id, string $status, string $text ): array {
		return array(
			'id'     => $id,
			'status' => $status,
			'text'   => $text,
		);
	}

	// ----------------------------------------------------------------------------------------
	// Words.

	/**
	 * The words of a text, in lower case, apostrophes alike.
	 *
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$text = mb_strtolower( str_replace( array( '’', 'ʼ', '`' ), "'", $text ) );
		preg_match_all( "/[\\p{L}\\p{N}]+(?:['\\-][\\p{L}\\p{N}]+)*/u", $text, $matches );

		return $matches[0];
	}

	/** The start of a word that its forms share: without its last two letters, or one for a short word. */
	public static function stem( string $word ): string {
		$length = mb_strlen( $word );
		if ( $length >= 6 ) {
			return mb_substr( $word, 0, $length - 2 );
		}

		return $length >= 4 ? mb_substr( $word, 0, $length - 1 ) : $word;
	}

	/**
	 * Whether every stem starts one of the words, in any order.
	 *
	 * @param string[] $words
	 * @param string[] $stems
	 */
	public static function contains( array $words, array $stems ): bool {
		if ( ! $stems ) {
			return false;
		}
		foreach ( $stems as $stem ) {
			$found = false;
			foreach ( $words as $word ) {
				if ( str_starts_with( $word, $stem ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How many times the stems start words one after another, in their order.
	 *
	 * @param string[] $words
	 * @param string[] $stems
	 */
	public static function occurrences( array $words, array $stems ): int {
		$size  = count( $stems );
		$count = 0;
		if ( ! $size ) {
			return 0;
		}

		for ( $i = 0, $last = count( $words ) - $size; $i <= $last; $i++ ) {
			$match = true;
			for ( $j = 0; $j < $size; $j++ ) {
				if ( ! str_starts_with( $words[ $i + $j ], $stems[ $j ] ) ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				++$count;
				$i += $size - 1;
			}
		}

		return $count;
	}

	/**
	 * Whether the address of the page has the keyphrase: its words in the slug, as they are or
	 * written in Latin letters (a Cyrillic title often gets a transliterated slug).
	 *
	 * @param string[] $stems
	 */
	private static function in_address( string $url, string $phrase, array $stems ): bool {
		$path  = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$words = self::words( str_replace( array( '-', '_', '/' ), ' ', $path ) );

		return self::contains( $words, $stems )
			|| self::contains( $words, array_map( array( self::class, 'stem' ), self::words( self::latin( $phrase ) ) ) );
	}

	/** Ukrainian (and Russian) letters in Latin ones, as slugs commonly spell them. */
	public static function latin( string $text ): string {
		return strtr(
			mb_strtolower( $text ),
			array(
				'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
				'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
				'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
				'ь' => '', 'ю' => 'iu', 'я' => 'ia', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => '', "'" => '',
			)
		);
	}

	/**
	 * Titles of other posts, in the same language, whose focus keyphrase is the same; three at most.
	 *
	 * @return string[]
	 */
	private static function used_elsewhere( WP_Post $post, string $focus ): array {
		global $wpdb;

		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = %s AND LOWER( m.meta_value ) = %s AND m.post_id <> %d AND p.post_status IN ( 'publish', 'future', 'draft', 'pending', 'private' )
					ORDER BY m.post_id LIMIT 20",
					Simple_SEO_Fields::meta_key( 'keyphrase' ),
					mb_strtolower( $focus ),
					(int) $post->ID
				)
			)
		);
		if ( $ids && Simple_SEO_WP_Loc::languages() ) {
			$language  = Simple_SEO_WP_Loc::element_languages( array( (int) $post->ID ), 'post_' . $post->post_type )[ (int) $post->ID ] ?? '';
			$languages = array();
			foreach ( $ids as $id ) {
				$languages += Simple_SEO_WP_Loc::element_languages( array( $id ), 'post_' . get_post_type( $id ) );
			}
			// A page whose language is not known may be in this one.
			$ids = array_values( array_filter( $ids, static fn( int $id ): bool => '' === $language || in_array( $languages[ $id ] ?? '', array( '', $language ), true ) ) );
		}

		return array_map( static fn( int $id ): string => '"' . Simple_SEO_Variables::plain( get_the_title( $id ) ) . '"', array_slice( $ids, 0, 3 ) );
	}

	/** A keyphrase as typed, on one line. */
	private static function clean_phrase( string $phrase ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $phrase ) );
	}

	/**
	 * The additional keyphrases: one on a line, the first MORE of them.
	 *
	 * @return string[]
	 */
	private static function more( string $lines ): array {
		$phrases = array_filter( array_map( array( self::class, 'clean_phrase' ), preg_split( '/\R/u', $lines ) ?: array() ), 'strlen' );

		return array_slice( array_values( array_unique( $phrases ) ), 0, self::MORE );
	}
}
