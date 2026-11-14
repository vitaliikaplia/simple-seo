<?php
/**
 * Registry of the plugin's options: defaults, sanitizing, autoload and a per-request cache.
 *
 * Every concern keeps its own option row (simple_seo_access, later simple_seo_titles and so on),
 * so a row that wp-loc localizes per language never carries unrelated settings with it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Settings {
	/** @var array<string, array{defaults: array<string, mixed>, sanitize: callable, autoload: bool}> */
	private static array $options = array();

	/** @var array<string, array<string, mixed>> */
	private static array $cache = array();

	/**
	 * Register an option row.
	 *
	 * @param string               $name     Full option name, prefixed with simple_seo_.
	 * @param array<string, mixed> $defaults Values used for keys the stored row does not have.
	 * @param callable             $sanitize Receives the raw form input, returns the clean row.
	 * @param bool                 $autoload Whether the front end needs the row on every request.
	 */
	public static function register( string $name, array $defaults, callable $sanitize, bool $autoload = true ): void {
		self::$options[ $name ] = array(
			'defaults' => $defaults,
			'sanitize' => $sanitize,
			'autoload' => $autoload,
		);

		add_action( "update_option_{$name}", array( self::class, 'flush_after_update' ), 10, 3 );
		add_action( "add_option_{$name}", array( self::class, 'flush_after_add' ), 10, 1 );
		add_action( "delete_option_{$name}", array( self::class, 'flush_after_add' ), 10, 1 );
	}

	/** @return array<string, array{defaults: array<string, mixed>, sanitize: callable, autoload: bool}> */
	public static function registered(): array {
		return self::$options;
	}

	/** @return array<string, mixed> */
	public static function defaults( string $name ): array {
		return self::$options[ $name ]['defaults'] ?? array();
	}

	/**
	 * The option row merged over its defaults, read once per request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( string $name ): array {
		if ( ! isset( self::$cache[ $name ] ) ) {
			$stored               = get_option( $name, array() );
			self::$cache[ $name ] = array_merge( self::defaults( $name ), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache[ $name ];
	}

	/** @return mixed */
	public static function value( string $name, string $key ) {
		$row = self::get( $name );

		return $row[ $key ] ?? null;
	}

	/**
	 * Run the registered sanitizer: options.php calls this through register_setting().
	 *
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( string $name, $input ): array {
		if ( ! isset( self::$options[ $name ] ) ) {
			return array();
		}

		$clean = call_user_func( self::$options[ $name ]['sanitize'], is_array( $input ) ? $input : array() );

		return is_array( $clean ) ? $clean : array();
	}

	/**
	 * Create the rows that do not exist yet, empty, with the right autoload flag.
	 *
	 * A missing option costs a database query on every request that reads it; an empty
	 * autoloaded row costs nothing. Admin-only rows are created with autoload off.
	 */
	public static function install(): void {
		foreach ( self::$options as $name => $option ) {
			if ( null === get_option( $name, null ) ) {
				add_option( $name, array(), '', $option['autoload'] );
			}
		}
	}

	public static function flush( ?string $name = null ): void {
		if ( null === $name ) {
			self::$cache = array();
			return;
		}

		unset( self::$cache[ $name ] );
	}

	/**
	 * @param mixed  $old_value
	 * @param mixed  $value
	 * @param string $option
	 */
	public static function flush_after_update( $old_value, $value, string $option ): void {
		self::flush( $option );
	}

	public static function flush_after_add( string $option ): void {
		self::flush( $option );
	}
}
