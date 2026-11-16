<?php
/**
 * The SEO menu and settings page. Parts of the plugin add their own tabs; the tabs stand in groups
 * in a menu on the left, and a tab of several parts has sub-tabs of its own on top.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Admin {
	public const PAGE = 'simple-seo';

	/** @var array<string, array{label: string, options: string[], render: callable, order: int, group: string, sections: callable|null}> */
	private static array $tabs = array();

	/** @var array<string, string> The sub-tabs of the tab being shown, id => label. */
	private static array $sections = array();

	/** The sub-tab being shown. */
	private static string $section = '';

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
		self::add_tab( 'access', __( 'Access', 'simple-seo' ), array( Simple_SEO_Access::OPTION ), array( Simple_SEO_Access::class, 'render_tab' ), 10, 'plugin' );
	}

	/** The icons of the groups in the menu on the left (Dashicons). */
	public const GROUP_ICONS = array(
		'search'   => 'dashicons-search',
		'markup'   => 'dashicons-share',
		'crawl'    => 'dashicons-admin-site-alt3',
		'ai'       => 'dashicons-lightbulb',
		'services' => 'dashicons-admin-links',
		'plugin'   => 'dashicons-admin-plugins',
	);

	/**
	 * The groups of the tabs in the menu on the left, in their order.
	 *
	 * @return array<string, string> Group => label.
	 */
	public static function groups(): array {
		return array(
			'search'   => __( 'Search appearance', 'simple-seo' ),
			'markup'   => __( 'Social networks and Schema', 'simple-seo' ),
			'crawl'    => __( 'For search engines', 'simple-seo' ),
			'ai'       => __( 'AI and agents', 'simple-seo' ),
			'services' => __( 'Services', 'simple-seo' ),
			'plugin'   => __( 'Plugin', 'simple-seo' ),
		);
	}

	/**
	 * Add a settings tab. Call it on init (or later, before admin_init): the label is translated.
	 *
	 * @param string[]      $options  Option rows saved by the tab's form, registered with Simple_SEO_Settings;
	 *                                none: the tab prints its own forms.
	 * @param callable      $render   Prints the tab's fields; the page prints the form around them.
	 * @param int           $order    Place in its group, lower first.
	 * @param string        $group    One of groups(); another goes into the last one.
	 * @param callable|null $sections Returns the tab's sub-tabs, id => label; the render marks their
	 *                                parts with section() and section_end().
	 */
	public static function add_tab( string $id, string $label, array $options, callable $render, int $order = 50, string $group = 'plugin', ?callable $sections = null ): void {
		self::$tabs[ $id ] = array(
			'label'    => $label,
			'options'  => $options,
			'render'   => $render,
			'order'    => $order,
			'group'    => $group,
			'sections' => $sections,
		);
	}

	/**
	 * The tabs in the order of the menu: by group, then by their order in it.
	 *
	 * @return array<string, array{label: string, options: string[], render: callable, order: int, group: string, sections: callable|null}>
	 */
	public static function tabs(): array {
		$groups = array_keys( self::groups() );
		$tabs   = self::$tabs;
		foreach ( $tabs as $id => $tab ) {
			if ( ! in_array( $tab['group'], $groups, true ) ) {
				$tabs[ $id ]['group'] = (string) end( $groups );
			}
		}
		uasort( $tabs, static fn( array $a, array $b ): int => array( array_search( $a['group'], $groups, true ), $a['order'] ) <=> array( array_search( $b['group'], $groups, true ), $b['order'] ) );

		return $tabs;
	}

	/**
	 * The sub-tabs of a tab, id => label; none for a tab of one part.
	 *
	 * @return array<string, string>
	 */
	public static function sections( string $tab ): array {
		$tabs     = self::tabs();
		$sections = isset( $tabs[ $tab ] ) && is_callable( $tabs[ $tab ]['sections'] ) ? (array) call_user_func( $tabs[ $tab ]['sections'] ) : array();

		return count( $sections ) > 1 ? array_map( 'strval', $sections ) : array();
	}

	/**
	 * The sub-tab shown: the one the address names, else the first.
	 *
	 * @param array<string, string> $sections
	 */
	public static function current_section( array $sections ): string {
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.

		return isset( $sections[ $section ] ) ? $section : (string) array_key_first( $sections );
	}

	/**
	 * Open the part of a sub-tab. Every part is in the form, so saving loses no field of another;
	 * only the current one is shown (assets/js/admin.js switches them without a reload).
	 */
	public static function section( string $id ): void {
		printf(
			'<div class="simple-seo-section" id="simple-seo-section-%1$s" data-simple-seo-section="%1$s"%2$s>',
			esc_attr( $id ),
			self::$sections && $id !== self::$section ? ' hidden' : ''
		);
	}

	public static function section_end(): void {
		echo '</div>';
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
		// The first item of the menu: the settings, beside the pages others add (bulk editing).
		add_submenu_page( self::PAGE, __( 'Simple SEO', 'simple-seo' ), __( 'Settings', 'simple-seo' ), Simple_SEO_Access::CAP_SETTINGS, self::PAGE, array( self::class, 'render_page' ) );
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

	public static function url( string $tab = '', string $section = '' ): string {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		if ( '' !== $section ) {
			$args['section'] = $section;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		$tabs          = self::tabs();
		$current       = self::current_tab();
		$groups        = self::groups();
		self::$sections = self::sections( $current );
		self::$section  = self::current_section( self::$sections );
		$sections      = self::$sections;
		$section       = self::$section;

		try {
			require SIMPLE_SEO_DIR . 'includes/views/settings.php';
		} finally {
			self::$sections = array();
			self::$section  = '';
		}
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

	/** The script and styles of the SEO fields, the settings and bulk editing, only where those are. */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		$ours   = 'toplevel_page_' . self::PAGE === $hook
			|| ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && in_array( $screen->post_type, Simple_SEO_Fields::post_types(), true ) )
			|| ( in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) && $screen && in_array( $screen->taxonomy, Simple_SEO_Fields::taxonomies(), true ) );
		$bulk   = '' !== Simple_SEO_Bulk::hook() && Simple_SEO_Bulk::hook() === $hook;
		$report = in_array( $hook, array_filter( array( Simple_SEO_Search_Console::hook(), Simple_SEO_Monitor::hook(), Simple_SEO_Audit::hook() ) ), true );
		// The list of posts has the column of scores, and nothing else of Simple SEO's.
		$list = 'edit.php' === $hook && $screen && in_array( $screen->post_type, Simple_SEO_Fields::post_types(), true );

		if ( $ours || $bulk || $report || $list ) {
			wp_enqueue_style( 'simple-seo-admin', SIMPLE_SEO_URL . 'assets/css/admin.css', array(), SIMPLE_SEO_VERSION );
		}
		if ( $ours ) {
			// The image fields pick from the media library.
			wp_enqueue_media();
		}
		if ( $ours || $bulk ) {
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
