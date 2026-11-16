<?php
/**
 * Suggestions of internal links: the other published pages of this site whose focus keyphrase,
 * more keyphrases or title (two words or more) the text of a post mentions — the same starts of
 * words the content analysis matches — and that the text does not link to yet. Shown in the SEO
 * block with the analysis; the text is never changed.
 *
 * The phrases of the pages are kept in a transient, made again after a post or its keyphrases
 * change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Suggestions {
	/** The phrases of the pages, in a transient. */
	public const CACHE = 'simple_seo_link_phrases';

	/** Pages whose phrases are kept at most: the newest. */
	public const MAX_POSTS = 3000;

	/** Suggestions shown at most. */
	public const MAX = 10;

	/** Words a title needs to be a phrase: a title of one word matches too much. */
	public const TITLE_WORDS = 2;

	public static function init(): void {
		add_action( 'save_post', array( self::class, 'flush' ) );
		add_action( 'deleted_post', array( self::class, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_meta' ), 10, 3 );
		}
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * The keyphrases of a post changed: the phrases are made again.
	 *
	 * @param mixed $meta_id
	 * @param mixed $object_id
	 * @param mixed $meta_key
	 */
	public static function flush_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, array( Simple_SEO_Fields::meta_key( 'keyphrase' ), Simple_SEO_Fields::meta_key( 'keyphrases' ) ), true ) ) {
			self::flush();
		}
	}

	/**
	 * The phrases of the published pages of the post types with SEO fields that visitors see, the
	 * newest MAX_POSTS: ID => language ('' without languages) and the phrases, each with the starts
	 * of its words.
	 *
	 * @return array<int, array{title: string, language: string, phrases: array<int, array{text: string, stems: string[]}>}>
	 */
	public static function phrases(): array {
		global $wpdb;

		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$types = array_values( array_filter( Simple_SEO_Fields::post_types(), static fn( string $type ): bool => 'attachment' !== $type && is_post_type_viewable( $type ) ) );
		$found = array();
		if ( $types ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders made for the list.
			$posts = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( " . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' ) ORDER BY post_date DESC, ID DESC LIMIT %d',
					array_merge( $types, array( self::MAX_POSTS ) )
				),
				ARRAY_A
			);
			$ids   = array_map( 'intval', array_column( $posts, 'ID' ) );
			$meta  = array();
			if ( $ids ) {
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) AND post_id IN ( " . implode( ', ', $ids ) . ' )',
						Simple_SEO_Fields::meta_key( 'keyphrase' ),
						Simple_SEO_Fields::meta_key( 'keyphrases' )
					),
					ARRAY_A
				);
				foreach ( $rows as $row ) {
					$meta[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
				}
			}
			// phpcs:enable

			$languages = array();
			foreach ( array_unique( array_column( $posts, 'post_type' ) ) as $type ) {
				$languages += Simple_SEO_WP_Loc::element_languages( array_map( 'intval', array_column( wp_list_filter( $posts, array( 'post_type' => $type ) ), 'ID' ) ), 'post_' . $type );
			}

			foreach ( $posts as $post ) {
				$id    = (int) $post['ID'];
				$texts = array_merge(
					array( (string) ( $meta[ $id ][ Simple_SEO_Fields::meta_key( 'keyphrase' ) ] ?? '' ) ),
					preg_split( '/\R/', (string) ( $meta[ $id ][ Simple_SEO_Fields::meta_key( 'keyphrases' ) ] ?? '' ) ) ?: array()
				);
				$title = trim( wp_strip_all_tags( (string) $post['post_title'] ) );
				if ( count( Simple_SEO_Analysis::words( $title ) ) >= self::TITLE_WORDS ) {
					$texts[] = $title;
				}

				$phrases = array();
				foreach ( $texts as $text ) {
					$text  = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
					$stems = array_map( array( Simple_SEO_Analysis::class, 'stem' ), Simple_SEO_Analysis::words( $text ) );
					if ( $stems && ! isset( $phrases[ implode( ' ', $stems ) ] ) ) {
						$phrases[ implode( ' ', $stems ) ] = array(
							'text'  => $text,
							'stems' => $stems,
						);
					}
				}
				if ( $phrases ) {
					$found[ $id ] = array(
						'title'    => $title,
						'language' => (string) ( $languages[ $id ] ?? '' ),
						'phrases'  => array_values( $phrases ),
					);
				}
			}
		}

		set_transient( self::CACHE, $found, DAY_IN_SECONDS );

		return $found;
	}

	/**
	 * The suggestions for a post and its text: the pages it mentions most first, then by title, at
	 * most MAX; not itself, nor a page it links to already, nor one in another language (a post or a
	 * page without a language yet, as wp-loc leaves one made before it, goes with any).
	 *
	 * @param array<string, mixed> $content Simple_SEO_Content::read().
	 * @return array<int, array{id: int, title: string, url: string, phrase: string, count: int}>
	 */
	public static function for_post( WP_Post $post, array $content ): array {
		$words = Simple_SEO_Analysis::words( (string) ( $content['text'] ?? '' ) );
		if ( ! $words ) {
			return array();
		}

		// Where each word starts: its first three letters, to find a phrase without reading all the words for each.
		$index = array();
		foreach ( $words as $position => $word ) {
			$index[ mb_substr( $word, 0, 3 ) ][] = $position;
		}

		$linked = array();
		foreach ( (array) ( $content['links'] ?? array() ) as $link ) {
			if ( ! empty( $link['internal'] ) ) {
				$linked[ url_to_postid( WP_Http::make_absolute_url( (string) $link['url'], home_url( '/' ) ) ) ] = true;
			}
		}

		$language = (string) ( Simple_SEO_WP_Loc::element_languages( array( (int) $post->ID ), 'post_' . $post->post_type )[ (int) $post->ID ] ?? '' );
		$found    = array();
		foreach ( self::phrases() as $id => $page ) {
			if ( (int) $post->ID === $id || isset( $linked[ $id ] ) || ( '' !== $language && '' !== $page['language'] && $page['language'] !== $language ) ) {
				continue;
			}
			$best = null;
			foreach ( $page['phrases'] as $phrase ) {
				$count = self::count( $words, $index, $phrase['stems'] );
				if ( $count > 0 && ( null === $best || $count > $best['count'] ) ) {
					$best = array(
						'phrase' => $phrase['text'],
						'count'  => $count,
					);
				}
			}
			if ( null !== $best ) {
				$found[] = array(
					'id'     => (int) $id,
					'title'  => $page['title'],
					'url'    => (string) get_permalink( $id ),
				) + $best;
			}
		}

		usort(
			$found,
			static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] ?: strcasecmp( $a['title'], $b['title'] )
		);

		return array_slice( $found, 0, self::MAX );
	}

	/**
	 * How many times the starts of words of a phrase start words of the text one after another.
	 *
	 * @param string[]             $words
	 * @param array<string, int[]> $index The positions of the words by their first three letters.
	 * @param string[]             $stems
	 */
	private static function count( array $words, array $index, array $stems ): int {
		$first = $stems[0];
		if ( mb_strlen( $first ) < 3 ) {
			// Too short a start for the index: every word.
			return Simple_SEO_Analysis::occurrences( $words, $stems );
		}

		$count = 0;
		$size  = count( $stems );
		foreach ( $index[ mb_substr( $first, 0, 3 ) ] ?? array() as $position ) {
			for ( $i = 0; $i < $size; $i++ ) {
				if ( ! isset( $words[ $position + $i ] ) || ! str_starts_with( $words[ $position + $i ], $stems[ $i ] ) ) {
					continue 2;
				}
			}
			++$count;
		}

		return $count;
	}
}
