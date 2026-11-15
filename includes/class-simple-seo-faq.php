<?php
/**
 * Questions and answers on a post, for the FAQPage of its Schema graph, and the block
 * "Questions and answers" for posts whose theme has no FAQ of its own (decisions/schema).
 *
 * Where they come from, in the order of the post's blocks:
 *   - ACF blocks with "faq" in their name (acf/main-faq of WP Base and its forks, acf/main-accordion-faq):
 *     the rows {repeater}_{n}_question and {repeater}_{n}_answer of the block's data;
 *   - Simple SEO's own block simple-seo/faq;
 *   - accordions — builder/accordion of the Builder theme (an item's label is the question, its
 *     blocks the answer) and core/details (the summary, then the rest) — only where the page is
 *     a FAQ page by its type: an accordion is not always questions and answers;
 *   - the filter simple_seo_faq, for any other theme.
 * Questions are plain text, answers HTML with the few tags search engines take.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_FAQ {
	public const BLOCK = 'simple-seo/faq';

	public const SCRIPT = 'simple-seo-faq-block';

	public const EDITOR_STYLE = 'simple-seo-faq-block-editor';

	/** The tags an answer keeps: those search engines show in a FAQ answer. */
	private const ANSWER_TAGS = array(
		'h1'     => array(),
		'h2'     => array(),
		'h3'     => array(),
		'h4'     => array(),
		'h5'     => array(),
		'h6'     => array(),
		'br'     => array(),
		'ol'     => array(),
		'ul'     => array(),
		'li'     => array(),
		'a'      => array( 'href' => true ),
		'p'      => array(),
		'div'    => array(),
		'b'      => array(),
		'strong' => array(),
		'i'      => array(),
		'em'     => array(),
	);

	/** @var array<string, array{faq: array<int, array{question: string, answer: string, source: string}>, accordion: array<int, array{question: string, answer: string, source: string}>}> */
	private static array $scans = array();

	public static function init(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	public static function register_block(): void {
		wp_register_script( self::SCRIPT, SIMPLE_SEO_URL . 'assets/js/faq-block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), SIMPLE_SEO_VERSION, true );
		wp_add_inline_script(
			self::SCRIPT,
			'window.simpleSeoFaq = ' . wp_json_encode(
				array(
					'title'       => __( 'Questions and answers', 'simple-seo' ),
					'description' => __( 'Questions with their answers, shown as an accordion and marked up for search engines as a FAQ.', 'simple-seo' ),
					'question'    => __( 'Question', 'simple-seo' ),
					'answer'      => __( 'Answer', 'simple-seo' ),
					'add'         => __( 'Add a question', 'simple-seo' ),
					'remove'      => __( 'Remove', 'simple-seo' ),
				)
			) . ';',
			'before'
		);

		// The editor only: on the site the theme styles the block.
		wp_register_style( self::EDITOR_STYLE, false, array(), SIMPLE_SEO_VERSION );
		wp_add_inline_style( self::EDITOR_STYLE, '.simple-seo-faq .simple-seo-faq-item{padding:.5em 0;border-bottom:1px solid #dcdcde}.simple-seo-faq .simple-seo-faq-question{margin:0 0 .25em;font-weight:600}.simple-seo-faq .simple-seo-faq-answer{margin:0 0 .25em}' );

		register_block_type(
			self::BLOCK,
			array(
				'api_version'           => 3,
				'title'                 => __( 'Questions and answers', 'simple-seo' ),
				'description'           => __( 'Questions with their answers, shown as an accordion and marked up for search engines as a FAQ.', 'simple-seo' ),
				'category'              => 'text',
				'icon'                  => 'editor-help',
				'keywords'              => array( 'faq', __( 'questions', 'simple-seo' ) ),
				'attributes'            => array(
					// No schema of the entries: one broken entry would empty the whole list on the
					// site while the graph still had its questions. block_items() takes what is whole.
					'items' => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
				'supports'              => array(
					'html'      => false,
					'anchor'    => true,
					'className' => true,
				),
				'editor_script_handles' => array( self::SCRIPT ),
				'editor_style_handles'  => array( self::EDITOR_STYLE ),
				'render_callback'       => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * The block on the site: each question a <details> that opens to its answer, as an accordion
	 * without a script. The theme styles it as it likes (.simple-seo-faq).
	 *
	 * @param array<string, mixed> $attributes
	 */
	public static function render( $attributes ): string {
		$items = self::block_items( is_array( $attributes ) ? $attributes : array() );
		if ( ! $items ) {
			return '';
		}

		$html = '';
		foreach ( $items as $item ) {
			$html .= sprintf(
				'<details class="simple-seo-faq-item"><summary class="simple-seo-faq-question">%s</summary><div class="simple-seo-faq-answer">%s</div></details>',
				esc_html( $item['question'] ),
				wp_kses_post( $item['answer'] )
			);
		}

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'simple-seo-faq' ) ) . '>' . $html . '</div>';
	}

	/**
	 * What a post holds: the questions of FAQ blocks (always FAQ) and of accordions (FAQ only for a
	 * FAQ page), each with the block it came from. Worked out once per post and version.
	 *
	 * @return array{faq: array<int, array{question: string, answer: string, source: string}>, accordion: array<int, array{question: string, answer: string, source: string}>}
	 */
	public static function scan( WP_Post $post ): array {
		$key = $post->ID . ':' . md5( (string) $post->post_content );
		if ( ! isset( self::$scans[ $key ] ) ) {
			$found = array(
				'faq'       => array(),
				'accordion' => array(),
			);
			if ( has_blocks( $post->post_content ) ) {
				self::walk( parse_blocks( $post->post_content ), $found );
			}
			self::$scans[ $key ] = $found;
		}

		return self::$scans[ $key ];
	}

	/**
	 * The questions and answers of a post: those of its FAQ blocks, and of its accordions too when
	 * $accordions (the page is a FAQ page). Filter: simple_seo_faq.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function questions( WP_Post $post, bool $accordions ): array {
		$scan  = self::scan( $post );
		$items = $accordions ? array_merge( $scan['faq'], $scan['accordion'] ) : $scan['faq'];
		$items = array_map(
			static fn( array $item ): array => array(
				'question' => $item['question'],
				'answer'   => $item['answer'],
			),
			$items
		);

		/**
		 * The questions and answers of a post, for its FAQPage: question as plain text, answer as HTML.
		 *
		 * @param array<int, array{question: string, answer: string}> $items
		 * @param WP_Post                                              $post
		 * @param bool                                                 $accordions Whether the page is a FAQ page, so its accordions count.
		 */
		$items = apply_filters( 'simple_seo_faq', $items, $post, $accordions );

		$clean = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item = self::item( is_array( $item ) ? ( $item['question'] ?? '' ) : '', is_array( $item ) ? ( $item['answer'] ?? '' ) : '', '' );
			if ( $item ) {
				$clean[] = array(
					'question' => $item['question'],
					'answer'   => $item['answer'],
				);
			}
		}

		return $clean;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, array>             $found
	 */
	private static function walk( array $blocks, array &$found ): void {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			if ( self::BLOCK === $name ) {
				foreach ( self::block_items( $attrs ) as $item ) {
					$found['faq'][] = $item + array( 'source' => $name );
				}
				continue;
			}
			if ( str_starts_with( $name, 'acf/' ) && str_contains( $name, 'faq' ) ) {
				foreach ( self::acf_items( is_array( $attrs['data'] ?? null ) ? $attrs['data'] : array(), $name ) as $item ) {
					$found['faq'][] = $item;
				}
				continue;
			}
			if ( 'builder/accordion-item' === $name ) {
				$item = self::item( $attrs['label'] ?? '', self::inner( $block ), $name );
				if ( $item ) {
					$found['accordion'][] = $item;
				}
				continue;
			}
			if ( 'core/details' === $name ) {
				$summary = preg_match( '#<summary[^>]*>(.*?)</summary>#is', (string) ( $block['innerHTML'] ?? '' ), $match ) ? $match[1] : '';
				$item    = self::item( $summary, self::inner( $block ), $name );
				if ( $item ) {
					$found['accordion'][] = $item;
				}
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $found );
			}
		}
	}

	/**
	 * The rows of an ACF block's repeater: {repeater}_{n}_question with {repeater}_{n}_answer, in
	 * the order of the repeaters and their rows.
	 *
	 * @param array<string, mixed> $data
	 * @return array<int, array{question: string, answer: string, source: string}>
	 */
	private static function acf_items( array $data, string $source ): array {
		$rows = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || str_starts_with( $key, '_' ) || ! preg_match( '/^(.+)_(\d+)_question$/', $key, $match ) ) {
				continue;
			}
			$rows[] = array( $match[1], (int) $match[2], $value, $data[ $match[1] . '_' . $match[2] . '_answer' ] ?? '' );
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );

		$items = array();
		foreach ( $rows as $row ) {
			$item = self::item( $row[2], $row[3], $source );
			if ( $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * The questions of Simple SEO's block.
	 *
	 * @param array<string, mixed> $attributes
	 * @return array<int, array{question: string, answer: string}>
	 */
	private static function block_items( array $attributes ): array {
		$items = array();
		foreach ( is_array( $attributes['items'] ?? null ) ? $attributes['items'] : array() as $entry ) {
			$item = is_array( $entry ) ? self::item( $entry['question'] ?? '', $entry['answer'] ?? '', '' ) : null;
			if ( $item ) {
				$items[] = array(
					'question' => $item['question'],
					'answer'   => $item['answer'],
				);
			}
		}

		return $items;
	}

	/** The answer of an accordion item: its blocks as the site shows them. */
	private static function inner( array $block ): string {
		$html = '';
		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $inner ) {
			$html .= render_block( $inner );
		}

		return $html;
	}

	/**
	 * A question as plain text and its answer with the tags search engines take; null when either
	 * is empty.
	 *
	 * @param mixed $question
	 * @param mixed $answer
	 * @return array{question: string, answer: string, source: string}|null
	 */
	private static function item( $question, $answer, string $source ): ?array {
		$question = is_scalar( $question ) ? Simple_SEO_Variables::plain( (string) $question ) : '';
		// A script or a style goes with its text; other tags only lose themselves.
		$answer = is_scalar( $answer ) ? (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $answer ) : '';
		$answer = trim( (string) preg_replace( '/\s+/u', ' ', wp_kses( $answer, self::ANSWER_TAGS ) ) );
		if ( '' === $question || '' === trim( wp_strip_all_tags( $answer ) ) ) {
			return null;
		}

		return array(
			'question' => $question,
			'answer'   => $answer,
			'source'   => $source,
		);
	}
}
