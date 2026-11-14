<?php
/**
 * The single place where Simple SEO prints its tags into <head>.
 *
 * Parts of the plugin register providers; each returns tags keyed by a stable id ('description',
 * 'og:title', 'canonical', …). The keys are what the public filter and the Yoast compatibility
 * layer hook into, so a tag can be changed or removed without knowing which part produced it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Head {
	/** @var array<string, array{callback: callable, priority: int}> */
	private static array $providers = array();

	public static function init(): void {
		// Priority 1: next to core's wp_robots, before themes and other plugins print their tags.
		add_action( 'wp_head', array( self::class, 'output' ), 1 );
	}

	/**
	 * @param callable $callback Receives Simple_SEO_Context, returns tags keyed by id. A tag is
	 *                           array( 'tag' => 'meta'|'link', 'attributes' => array( name => value ) ).
	 */
	public static function add_provider( string $id, callable $callback, int $priority = 10 ): void {
		self::$providers[ $id ] = array(
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	public static function remove_provider( string $id ): void {
		unset( self::$providers[ $id ] );
	}

	/**
	 * All tags for a context, after the public filter.
	 *
	 * @return array<string, array{tag: string, attributes: array<string, string>}>
	 */
	public static function tags( Simple_SEO_Context $context ): array {
		$providers = self::$providers;
		uasort( $providers, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		$tags = array();
		foreach ( $providers as $provider ) {
			$provided = call_user_func( $provider['callback'], $context );
			if ( is_array( $provided ) ) {
				$tags = array_merge( $tags, $provided );
			}
		}

		/**
		 * Change, add or remove Simple SEO's head tags.
		 *
		 * @param array<string, array{tag: string, attributes: array<string, string>}> $tags    Tags keyed by id.
		 * @param Simple_SEO_Context                                                    $context What the page shows.
		 */
		$tags = apply_filters( 'simple_seo_head_tags', $tags, $context );

		return is_array( $tags ) ? $tags : array();
	}

	public static function output(): void {
		if ( is_admin() || is_feed() || '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return;
		}

		echo self::render( self::tags( Simple_SEO_Context::current() ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- render() escapes.
	}

	/** The attribute without which a tag means nothing and is left out. */
	private const REQUIRED = array(
		'meta' => 'content',
		'link' => 'href',
	);

	/** @param array<string, mixed> $tags */
	public static function render( array $tags ): string {
		$html = '';

		foreach ( $tags as $tag ) {
			$name = is_array( $tag ) ? (string) ( $tag['tag'] ?? '' ) : '';
			if ( ! isset( self::REQUIRED[ $name ] ) || empty( $tag['attributes'] ) || ! is_array( $tag['attributes'] ) ) {
				continue;
			}

			$attributes = array();
			foreach ( $tag['attributes'] as $attribute => $value ) {
				$attribute = strtolower( (string) $attribute );
				if ( ! preg_match( '/^[a-z][a-z0-9:_-]*$/', $attribute ) || ! is_scalar( $value ) ) {
					continue;
				}
				$value = 'href' === $attribute ? esc_url( (string) $value ) : esc_attr( (string) $value );
				if ( '' !== $value ) {
					$attributes[ $attribute ] = $value;
				}
			}

			// A link whose address was unsafe (javascript:, …) or a meta without content is dropped whole.
			if ( ! isset( $attributes[ self::REQUIRED[ $name ] ] ) ) {
				continue;
			}

			$html .= '<' . $name;
			foreach ( $attributes as $attribute => $value ) {
				$html .= sprintf( ' %s="%s"', $attribute, $value );
			}
			$html .= ' />' . "\n";
		}

		return $html;
	}
}
