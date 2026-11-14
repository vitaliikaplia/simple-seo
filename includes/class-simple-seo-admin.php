<?php
/**
 * The SEO menu and settings page. Parts of the plugin add their own tabs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Admin {
	public const PAGE = 'simple-seo';

	/** @var array<string, array{label: string, options: string[], render: callable, order: int}> */
	private static array $tabs = array();

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . SIMPLE_SEO_BASENAME, array( self::class, 'action_links' ) );

		// Tab labels are translated: tabs are added on init, which runs before admin_init and admin_menu.
		if ( did_action( 'init' ) ) {
			self::add_core_tabs();
		} else {
			add_action( 'init', array( self::class, 'add_core_tabs' ), 5 );
		}
	}

	public static function add_core_tabs(): void {
		self::add_tab( 'access', __( 'Access', 'simple-seo' ), array( Simple_SEO_Access::OPTION ), array( Simple_SEO_Access::class, 'render_tab' ), 90 );
	}

	/**
	 * Add a settings tab. Call it on init (or later, before admin_init): the label is translated.
	 *
	 * @param string[] $options Option rows saved by the tab's form, registered with Simple_SEO_Settings.
	 * @param callable $render  Prints the tab's fields; the page prints the form around them.
	 */
	public static function add_tab( string $id, string $label, array $options, callable $render, int $order = 50 ): void {
		self::$tabs[ $id ] = array(
			'label'   => $label,
			'options' => $options,
			'render'  => $render,
			'order'   => $order,
		);
	}

	/** @return array<string, array{label: string, options: string[], render: callable, order: int}> */
	public static function tabs(): array {
		$tabs = self::$tabs;
		uasort( $tabs, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		return $tabs;
	}

	public static function add_menu(): void {
		add_menu_page(
			__( 'Simple SEO', 'simple-seo' ),
			__( 'SEO', 'simple-seo' ),
			Simple_SEO_Access::CAP_SETTINGS,
			self::PAGE,
			array( self::class, 'render_page' ),
			'dashicons-search',
			99
		);
	}

	/** One settings group per tab, saved through options.php with the plugin's own capability. */
	public static function register_settings(): void {
		foreach ( self::$tabs as $id => $tab ) {
			$group = self::group( $id );

			foreach ( $tab['options'] as $option ) {
				register_setting(
					$group,
					$option,
					array(
						'type'              => 'object',
						'sanitize_callback' => static fn( $input ) => Simple_SEO_Settings::sanitize( $option, $input ),
						'default'           => array(),
						'show_in_rest'      => false,
					)
				);
			}

			add_filter( "option_page_capability_{$group}", array( self::class, 'capability' ) );
		}
	}

	public static function capability(): string {
		return Simple_SEO_Access::CAP_SETTINGS;
	}

	public static function group( string $tab ): string {
		return 'simple-seo-' . $tab;
	}

	public static function current_tab(): string {
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.

		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	public static function url( string $tab = '' ): string {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		$tabs    = self::tabs();
		$current = self::current_tab();

		require SIMPLE_SEO_DIR . 'includes/views/settings.php';
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'simple-seo' ) ) );
		}

		return $links;
	}
}
