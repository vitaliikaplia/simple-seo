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
		add_action( 'admin_notices', array( self::class, 'conflict_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );

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
	 * @param string[] $options Option rows saved by the tab's form, registered with Simple_SEO_Settings;
	 *                          none: the tab prints its own forms.
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

	/** On the plugin's page and the plugins list: say why the site shows nothing of Simple SEO. */
	public static function conflict_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'toplevel_page_' . self::PAGE, 'plugins' ), true ) || ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return;
		}

		$plugin = Simple_SEO_Plugin::conflicting_plugin();
		if ( '' === $plugin ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: name of another SEO plugin, e.g. Yoast SEO. */
					__( '%s is active, so Simple SEO prints nothing on the site — no titles, descriptions or other tags — and pages never get them twice. Deactivate it when you switch to Simple SEO; the settings and fields here can be prepared in the meantime.', 'simple-seo' ),
					$plugin
				)
			)
		);
	}

	/** The script and styles of the SEO fields and the settings page, only where those are. */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		$ours   = 'toplevel_page_' . self::PAGE === $hook
			|| ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && in_array( $screen->post_type, Simple_SEO_Fields::post_types(), true ) )
			|| ( in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) && $screen && in_array( $screen->taxonomy, Simple_SEO_Fields::taxonomies(), true ) );

		if ( $ours ) {
			// The search result preview of the editors.
			wp_enqueue_style( 'simple-seo-admin', SIMPLE_SEO_URL . 'assets/css/admin.css', array(), SIMPLE_SEO_VERSION );
			// The image fields pick from the media library.
			wp_enqueue_media();
			wp_enqueue_script(
				'simple-seo-admin',
				SIMPLE_SEO_URL . 'assets/js/admin.js',
				array(),
				SIMPLE_SEO_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/**
	 * An image chosen from the media library: a hidden attachment ID, a thumbnail and two buttons.
	 * assets/js/admin.js opens the library; without JavaScript the saved image simply stays.
	 */
	public static function image_picker( string $name, string $id, int $attachment_id ): string {
		$thumbnail = $attachment_id ? (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		$hidden    = '' === $thumbnail ? ' hidden' : '';

		return sprintf(
			'<span class="simple-seo-image" data-target="%1$s" data-title="%5$s" data-button="%6$s">'
			. '<input type="hidden" id="%1$s" name="%2$s" value="%3$s">'
			// No display style on the wrappers: it would override their hidden attribute.
			. '<span class="simple-seo-image-preview"%4$s><img src="%7$s" alt="" style="max-width:150px;height:auto;display:block;margin-bottom:6px"></span>'
			. '<button type="button" class="button simple-seo-image-choose">%8$s</button> '
			. '<button type="button" class="button-link simple-seo-image-remove"%4$s>%9$s</button>'
			. '</span>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $attachment_id ? (string) $attachment_id : '' ),
			$hidden,
			esc_attr__( 'Choose an image', 'simple-seo' ),
			esc_attr__( 'Use this image', 'simple-seo' ),
			esc_url( $thumbnail ),
			esc_html__( 'Choose image', 'simple-seo' ),
			esc_html__( 'Remove image', 'simple-seo' )
		);
	}

	/** The "Insert structure" button for a field with the given id. */
	public static function mask_button( string $target_id, string $mask ): string {
		return sprintf(
			'<button type="button" class="button simple-seo-mask" data-target="%s" data-mask="%s" title="%s">%s</button>',
			esc_attr( $target_id ),
			esc_attr( $mask ),
			esc_attr( $mask ),
			esc_html__( 'Insert structure', 'simple-seo' )
		);
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
