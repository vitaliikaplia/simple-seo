<?php
/**
 * Tools for an AI agent: abilities of the WordPress Abilities API, marked for MCP, so that Simple
 * MCP (2.7+), the official WordPress MCP Adapter or another client of the API offers them as
 * tools. Without such a client they only sit in the core registry. The plugin depends on none.
 *
 * The abilities are thin: they run what the editor, the bulk editing, the analysis, the Search
 * Console page, IndexNow and WP-CLI run, with the rights of the person behind the MCP key or
 * connection — the same rights as on the screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Abilities {
	public const CATEGORY = 'simple-seo';

	/** Posts or terms in one call of find, by default and at most. */
	private const PER_PAGE = 20;
	private const PER_PAGE_MAX = 100;

	public static function init(): void {
		// Both fire the first time something reads the registry, after init: the order in which
		// plugins load does not matter, and the labels are translated by then.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Simple SEO',
				'description' => __( 'The SEO of the site: the SEO fields of posts and terms, the analysis of posts, the settings, Google Search Console and IndexNow.', 'simple-seo' ),
			)
		);
	}

	public static function register(): void {
		foreach ( self::definitions() as $name => $args ) {
			wp_register_ability( $name, $args + array( 'category' => self::CATEGORY ) );
		}
	}

	/**
	 * Every ability with its arguments for wp_register_ability().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$meta   = static fn( bool $readonly, bool $destructive, bool $idempotent ): array => array(
			'mcp'         => array( 'public' => true ),
			'annotations' => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
		$schema = static function ( array $properties, array $required = array() ): array {
			$schema = array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			);
			if ( $required ) {
				$schema['required'] = $required;
			} else {
				// A call without arguments is valid too (WP_Ability::normalize_input()).
				$schema['default'] = array();
			}

			return $schema;
		};
		$object = array(
			'type'        => 'string',
			'enum'        => array( 'post', 'term' ),
			'default'     => 'post',
			'description' => __( 'post (the ID is a post ID) or term (the ID is a term_id). Default post.', 'simple-seo' ),
		);
		$id     = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the post, or term_id of the term.', 'simple-seo' ),
		);
		$none   = $schema( array() );
		$fields = implode( ', ', array_map( array( self::class, 'field_note' ), array_keys( Simple_SEO_Fields::all() ), Simple_SEO_Fields::all() ) );
		$vars   = implode( ', ', array_map( static fn( string $name ): string => '{' . $name . '}', array_keys( Simple_SEO_Variables::labels() ) ) );

		return array(
			'simple-seo/get-fields'      => array(
				'label'               => __( 'Read SEO fields', 'simple-seo' ),
				'description'         => __( 'The SEO fields of one post or term as stored — an empty one falls back to the templates of its type — and what its page shows now: the title and the meta description with the templates filled in, the canonical address and whether search engines are asked not to index it. Lists only the fields the user may edit. Read-only.', 'simple-seo' ),
				'input_schema'        => $schema(
					array(
						'object' => $object,
						'id'     => $id,
					),
					array( 'id' )
				),
				'execute_callback'    => array( self::class, 'get_fields' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'meta'                => $meta( true, false, true ),
			),
			'simple-seo/update-fields'   => array(
				'label'               => __( 'Change SEO fields', 'simple-seo' ),
				/* translators: 1: the fields with their types, 2: the variables of templates. */
				'description'         => sprintf( __( 'Overwrites SEO fields of one post or term: all the values given, or none if one cannot be stored (the error says what that field takes). An empty value ("", false, 0 for an image) clears a field, so the page falls back to the templates; fields not given stay as they are. A text may use the variables of the templates, %2$s. Returns the fields and what the page shows afterwards. The fields: %1$s.', 'simple-seo' ), $fields, $vars ),
				'input_schema'        => $schema(
					array(
						'object' => $object,
						'id'     => $id,
						'fields' => array(
							'type'          => 'object',
							'minProperties' => 1,
							'description'   => __( 'Field key => new value, e.g. {"title": "Prices {sep} {site}", "description": ""}.', 'simple-seo' ),
						),
					),
					array( 'id', 'fields' )
				),
				'execute_callback'    => array( self::class, 'update_fields' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'meta'                => $meta( false, true, true ),
			),
			'simple-seo/find'            => array(
				'label'               => __( 'Find posts by their SEO', 'simple-seo' ),
				'description'         => __( 'Lists the posts of a post type, or the terms of a taxonomy, with their own SEO title and meta description and what their pages show: to find the pages without a meta description of their own (missing "description"), for example, and then change them with simple-seo/update-fields. Posts newest first, terms by name; with wp-loc, those of the default language. Paged. Read-only.', 'simple-seo' ),
				'input_schema'        => $schema(
					array(
						'object'   => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'term' ),
							'default'     => 'post',
							'description' => __( 'post or term. Default post.', 'simple-seo' ),
						),
						'type'     => array(
							'type'        => 'string',
							'description' => __( 'The post type (default post) or the taxonomy (default category).', 'simple-seo' ),
						),
						'missing'  => array(
							'type'        => 'string',
							'enum'        => array( '', 'title', 'description', 'either' ),
							'default'     => '',
							'description' => __( 'Only those without an SEO title of their own (title), a meta description of their own (description), or either. Default: all.', 'simple-seo' ),
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Words the title or the text has (posts), or the name (terms).', 'simple-seo' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the list, from 1.', 'simple-seo' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::PER_PAGE_MAX,
							'default'     => self::PER_PAGE,
							/* translators: 1: default number, 2: the largest. */
							'description' => sprintf( __( 'How many on a page: %1$d by default, %2$d at most.', 'simple-seo' ), self::PER_PAGE, self::PER_PAGE_MAX ),
						),
					)
				),
				'execute_callback'    => array( self::class, 'find' ),
				'permission_callback' => array( self::class, 'can_find' ),
				'meta'                => $meta( true, false, true ),
			),
			'simple-seo/analyze'         => array(
				'label'               => __( 'Analyse a post', 'simple-seo' ),
				'description'         => __( 'Analyses a post as saved: the SEO checks of its focus keyphrase (the field keyphrase) and its page, and the readability of its text, each check with its status (good, ok, bad or info) and what to do, and both scores out of 100. Also the title and the description its page shows. Read-only.', 'simple-seo' ),
				'input_schema'        => $schema(
					array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'ID of the post.', 'simple-seo' ),
						),
					),
					array( 'id' )
				),
				'execute_callback'    => array( self::class, 'analyze' ),
				'permission_callback' => array( self::class, 'can_analyze' ),
				'meta'                => $meta( true, false, true ),
			),
			'simple-seo/search-console'  => array(
				'label'               => __( 'Google Search Console data', 'simple-seo' ),
				/* translators: %d: the default number of days. */
				'description'         => sprintf( __( 'Google Search Console data. Without an ID, of the whole site for the last 7, 28 or 90 days that have data: clicks, impressions, CTR and average position, beside those of the period before, and the top queries and pages. With the ID of a published post, its own for %d days with its top queries. Search Console must be connected on its tab of the settings. Read-only; the answers of Google are kept for six hours.', 'simple-seo' ), Simple_SEO_Search_Console::DEFAULT_DAYS ),
				'input_schema'        => $schema(
					array(
						'id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'ID of a published post; without it, the whole site.', 'simple-seo' ),
						),
						'days' => array(
							'type'        => 'integer',
							'enum'        => Simple_SEO_Search_Console::PERIODS,
							'default'     => Simple_SEO_Search_Console::DEFAULT_DAYS,
							/* translators: %d: the default number of days. */
							'description' => sprintf( __( 'The period of the site: 7, 28 or 90 days (default %d).', 'simple-seo' ), Simple_SEO_Search_Console::DEFAULT_DAYS ),
						),
					)
				),
				'execute_callback'    => array( self::class, 'search_console' ),
				'permission_callback' => array( self::class, 'can_see_reports' ),
				'meta'                => $meta( true, false, true ),
			),
			'simple-seo/settings'        => array(
				'label'               => __( 'Read the settings', 'simple-seo' ),
				'description'         => __( 'The settings of Simple SEO, row by row, with the defaults filled in: the templates of titles and descriptions, indexing, social networks, the special pages, the sitemaps, breadcrumbs, Schema, local SEO, images, integrations, IndexNow, the access of roles and the rest. With wp-loc, the rows of texts are those of the current language. The key of the Search Console service account is not given, only its account. Read-only.', 'simple-seo' ),
				'input_schema'        => $none,
				'execute_callback'    => array( self::class, 'settings' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => $meta( true, false, true ),
			),
			'simple-seo/indexnow-status' => array(
				'label'               => __( 'IndexNow status', 'simple-seo' ),
				'description'         => __( 'Whether IndexNow sends the changes of pages to search engines, and why not if it does not; its key and key file; the addresses waiting and when they go; the last answers of Bing. Read-only.', 'simple-seo' ),
				'input_schema'        => $none,
				'execute_callback'    => array( self::class, 'indexnow_status' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => $meta( true, false, true ),
			),
			'simple-seo/indexnow-submit' => array(
				'label'               => __( 'Send addresses through IndexNow', 'simple-seo' ),
				'description'         => __( 'Sends addresses to search engines through IndexNow at once (to Bing; the search engines that take part share them): of posts by their IDs — only pages search engines may show, as the sitemap lists them — and addresses of this site. The addresses waiting go with them. A page published, changed or taken down is sent by itself a minute later: use this for pages changed in another way. Returns the answers of Bing and what was left out, with why.', 'simple-seo' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'  => array(
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'description' => __( 'IDs of posts.', 'simple-seo' ),
						),
						'urls' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Addresses of this site.', 'simple-seo' ),
						),
					),
					'additionalProperties' => false,
					'minProperties'        => 1,
				),
				'execute_callback'    => array( self::class, 'indexnow_submit' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => $meta( false, false, false ),
			),
		);
	}

	/**
	 * A field as update-fields describes it: "key (type, advanced; one of: …)".
	 *
	 * @param array<string, mixed> $field
	 */
	public static function field_note( string $key, array $field ): string {
		$notes = array( (string) $field['type'] );
		if ( Simple_SEO_Fields::LEVEL_ADVANCED === $field['level'] ) {
			$notes[] = 'advanced';
		}
		if ( $field['post_types'] ) {
			$notes[] = 'post types: ' . implode( ' ', (array) $field['post_types'] );
		} elseif ( array( 'post' ) === (array) $field['objects'] ) {
			$notes[] = 'posts only';
		}
		if ( 'select' === $field['type'] && is_array( $field['options'] ) ) {
			$notes[] = 'one of: ' . implode( ' ', array_filter( array_map( 'strval', array_keys( $field['options'] ) ), 'strlen' ) );
		}

		return $key . ' (' . implode( '; ', $notes ) . ')';
	}

	// ----------------------------------------------------------------------------------------
	// Who may.

	/**
	 * Whoever edits SEO fields and the post or term may read and change them; a post or term that
	 * does not exist (or has no SEO fields) is said so by the call itself.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function can_edit( $input = array() ): bool {
		$type   = self::object_type( $input );
		$id     = (int) ( $input['id'] ?? 0 );
		$object = Simple_SEO_Fields::object( $type, $id );

		return current_user_can( Simple_SEO_Access::CAP_FIELDS )
			&& ( null === $object || current_user_can( 'term' === $type ? 'edit_term' : 'edit_post', $id ) );
	}

	/** @param array<string, mixed> $input */
	public static function can_analyze( $input = array() ): bool {
		return self::can_edit( array( 'id' => $input['id'] ?? 0 ) );
	}

	/**
	 * Whoever edits SEO fields and the posts of the type (or the terms of the taxonomy).
	 *
	 * @param array<string, mixed> $input
	 */
	public static function can_find( $input = array() ): bool {
		$type = self::object_type( $input );
		$name = self::list_type( $type, $input );
		$cap  = 'term' === $type ? ( get_taxonomy( $name )->cap->edit_terms ?? '' ) : ( get_post_type_object( $name )->cap->edit_posts ?? '' );

		return current_user_can( Simple_SEO_Access::CAP_FIELDS ) && ( '' === $cap || current_user_can( $cap ) );
	}

	/**
	 * Whoever sees the data of Search Console; for a post, who edits it too.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function can_see_reports( $input = array() ): bool {
		$id = (int) ( $input['id'] ?? 0 );

		return current_user_can( Simple_SEO_Access::CAP_REPORTS ) && ( ! $id || ! get_post( $id ) || current_user_can( 'edit_post', $id ) );
	}

	public static function can_manage(): bool {
		return current_user_can( Simple_SEO_Access::CAP_SETTINGS );
	}

	// ----------------------------------------------------------------------------------------
	// What they do.

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_fields( $input = array() ) {
		$type   = self::object_type( $input );
		$object = Simple_SEO_Fields::object( $type, (int) ( $input['id'] ?? 0 ) );

		return null === $object ? self::no_object( $type ) : self::describe( $type, $object );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_fields( $input = array() ) {
		$type  = self::object_type( $input );
		$id    = (int) ( $input['id'] ?? 0 );
		$saved = Simple_SEO_Fields::write( $type, $id, (array) ( $input['fields'] ?? array() ), get_current_user_id() );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$object = Simple_SEO_Fields::object( $type, $id );
		if ( $object instanceof WP_Post ) {
			// The list of posts shows the scores of the post as saved, its SEO fields included.
			Simple_SEO_Scores::remember( $object, Simple_SEO_Analysis::run( $object ) );
		}

		return array( 'saved' => $saved ) + self::describe( $type, $object );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( $input = array() ) {
		$type  = self::object_type( $input );
		$name  = self::list_type( $type, $input );
		$known = 'term' === $type ? Simple_SEO_Fields::taxonomies() : Simple_SEO_Fields::post_types();
		if ( ! in_array( $name, $known, true ) ) {
			return new WP_Error(
				'simple_seo_no_type',
				/* translators: 1: a name given, 2: the names there are. */
				sprintf( __( 'No SEO fields for %1$s. There are: %2$s.', 'simple-seo' ), $name, implode( ', ', $known ) )
			);
		}

		// The schema of the input keeps both within their bounds.
		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? self::PER_PAGE );
		$found    = Simple_SEO_Bulk::query( $type . ':' . $name, (string) ( $input['missing'] ?? '' ), (string) ( $input['search'] ?? '' ), $page, $per_page );
		$items    = array();
		foreach ( $found['items'] as $object ) {
			$id      = $object instanceof WP_Term ? (int) $object->term_id : (int) $object->ID;
			$shown   = Simple_SEO_Bulk::now( $object );
			$items[] = array(
				'id'    => $id,
				'name'  => $object instanceof WP_Term ? $object->name : $object->post_title,
			) + ( $object instanceof WP_Post ? array( 'status' => $object->post_status ) : array() ) + array(
				'url'   => self::url( $object ),
				'own'   => array(
					'title'       => 'term' === $type ? Simple_SEO_Fields::term_value( $id, 'title' ) : Simple_SEO_Fields::post_value( $id, 'title' ),
					'description' => 'term' === $type ? Simple_SEO_Fields::term_value( $id, 'description' ) : Simple_SEO_Fields::post_value( $id, 'description' ),
				),
				'shown' => array(
					'title'       => (string) $shown['title'],
					'description' => (string) $shown['description'],
				),
			);
		}

		return array(
			'object' => $type,
			'type'   => $name,
			'total'  => $found['total'],
			'page'   => $page,
			'pages'  => (int) ceil( $found['total'] / $per_page ),
			'items'  => $items,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function analyze( $input = array() ) {
		$post = Simple_SEO_Fields::object( 'post', (int) ( $input['id'] ?? 0 ) );

		return $post instanceof WP_Post ? array( 'id' => (int) $post->ID ) + Simple_SEO_Analysis::report( $post ) : self::no_object( 'post' );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function search_console( $input = array() ) {
		if ( ! Simple_SEO_Search_Console::connected() ) {
			return new WP_Error( 'simple_seo_gsc_not_connected', __( 'Search Console is not connected: paste the key of a service account on the Search Console tab of the settings.', 'simple-seo' ) );
		}
		if ( empty( $input['id'] ) ) {
			return Simple_SEO_Search_Console::site_report( (int) ( $input['days'] ?? Simple_SEO_Search_Console::DEFAULT_DAYS ) );
		}

		$post = Simple_SEO_Fields::object( 'post', (int) $input['id'] );

		return $post instanceof WP_Post ? Simple_SEO_Search_Console::post_report( $post ) : self::no_object( 'post' );
	}

	/** @return array<string, array<string, mixed>> Row => its settings. */
	public static function settings(): array {
		$rows = array();
		foreach ( array_keys( Simple_SEO_Settings::registered() ) as $name ) {
			$rows[ $name ] = Simple_SEO_Settings::get( $name );
		}

		// The service account's key is a secret: whose it is is enough.
		$key = (array) $rows[ Simple_SEO_Search_Console::OPTION ]['key'];

		$rows[ Simple_SEO_Search_Console::OPTION ]['key'] = $key ? array(
			'email'   => (string) ( $key['email'] ?? '' ),
			'project' => (string) ( $key['project'] ?? '' ),
		) : array();

		return $rows;
	}

	/** @return array<string, mixed> */
	public static function indexnow_status(): array {
		return Simple_SEO_IndexNow::status();
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function indexnow_submit( $input = array() ) {
		$blockers = Simple_SEO_IndexNow::blockers();
		if ( $blockers ) {
			return new WP_Error( 'simple_seo_indexnow_blocked', __( 'Nothing is sent:', 'simple-seo' ) . ' ' . implode( ' ', $blockers ) );
		}

		$found = Simple_SEO_IndexNow::resolve( array_merge( (array) ( $input['ids'] ?? array() ), (array) ( $input['urls'] ?? array() ) ) );
		if ( ! $found['urls'] ) {
			return new WP_Error( 'simple_seo_indexnow_nothing', trim( __( 'Nothing to send.', 'simple-seo' ) . ' ' . implode( ' ', $found['left'] ) ) );
		}

		Simple_SEO_IndexNow::add( $found['urls'] );
		$answers = Simple_SEO_IndexNow::send_now();

		return array(
			'ok'       => count( $answers ) === count( array_filter( array_column( $answers, 'ok' ) ) ),
			'answers'  => array_map(
				static fn( array $answer ): array => array(
					'url'     => rawurldecode( $answer['url'] ),
					'count'   => $answer['count'],
					'code'    => $answer['code'],
					'ok'      => $answer['ok'],
					'message' => $answer['message'],
				),
				$answers
			),
			'left_out' => $found['left'] ? $found['left'] : new stdClass(),
		);
	}

	// ----------------------------------------------------------------------------------------
	// Helpers.

	/**
	 * A post or term with its fields the user may edit and what its page shows, worked out in its
	 * language with wp-loc.
	 *
	 * @param WP_Post|WP_Term $object
	 * @return array<string, mixed>
	 */
	private static function describe( string $type, $object ): array {
		$id     = $object instanceof WP_Term ? (int) $object->term_id : (int) $object->ID;
		$shown  = (array) Simple_SEO_WP_Loc::in_object_language(
			$object,
			static function () use ( $object ): array {
				$context = $object instanceof WP_Term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );

				return array(
					'title'       => Simple_SEO_Titles::title( $context ),
					'description' => Simple_SEO_Titles::description( $context ),
					'canonical'   => Simple_SEO_Indexing::canonical( $context ),
					'noindex'     => Simple_SEO_Indexing::is_noindex( $context ),
				);
			}
		);

		return array(
			'object' => $type,
			'id'     => $id,
			'type'   => $object instanceof WP_Term ? $object->taxonomy : $object->post_type,
			'name'   => $object instanceof WP_Term ? $object->name : $object->post_title,
		) + ( $object instanceof WP_Post ? array( 'status' => $object->post_status ) : array() ) + array(
			'url'    => self::url( $object ),
			'fields' => Simple_SEO_Fields::values( $type, $id, get_current_user_id() ),
			'shown'  => $shown,
		);
	}

	/** @param WP_Post|WP_Term $object */
	private static function url( $object ): string {
		$url = $object instanceof WP_Term ? get_term_link( $object ) : get_permalink( $object );

		return is_string( $url ) ? $url : '';
	}

	/** @param array<string, mixed> $input */
	private static function object_type( $input ): string {
		return 'term' === ( $input['object'] ?? 'post' ) ? 'term' : 'post';
	}

	/**
	 * The post type or taxonomy find lists.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function list_type( string $type, $input ): string {
		$name = (string) ( $input['type'] ?? '' );

		return '' !== $name ? $name : ( 'term' === $type ? 'category' : 'post' );
	}

	private static function no_object( string $type ): WP_Error {
		return new WP_Error(
			'simple_seo_no_object',
			'term' === $type ? __( 'There is no such term with SEO fields.', 'simple-seo' ) : __( 'There is no such post with SEO fields.', 'simple-seo' )
		);
	}
}
