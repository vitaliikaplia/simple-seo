<?php
/**
 * What the imports from other SEO plugins share: writing what they take into Simple SEO's rows and
 * fields, and counting it for the report.
 *
 * An import only fills what Simple SEO does not have. A setting counts as Simple SEO's own once its
 * tab was saved (Simple SEO's rows start empty, so a stored key is a saved choice); a template or a
 * field, once it holds a value. Running an import again is therefore safe. The other plugin's data
 * is read straight from the database and never changed. A source reads its own keys and hands the
 * values over; text() turns its texts into Simple SEO's {variables}, value() its field values into
 * what Simple SEO's fields hold.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Simple_SEO_Import_Source {
	/** The row that keeps the state of the import from this source. */
	public const STATE = '';

	/** The plugin's name, as it calls itself. */
	public const NAME = '';

	/** The class that removes what the plugin left (a Simple_SEO_Source_Cleanup). */
	public const CLEANUP = '';

	/** Whether the site has data of the plugin: its settings, or SEO fields of posts or terms. */
	abstract public static function found(): bool;

	/**
	 * What there is to import: the plugin's settings, how many posts and terms have SEO fields.
	 *
	 * @return array{settings: bool, posts: int, terms: int}
	 */
	abstract public static function summary(): array;

	/** @param array<string, mixed> $report */
	abstract public static function settings( array &$report ): void;

	/**
	 * Take the SEO fields of the next posts after a post ID.
	 *
	 * @param array<string, mixed> $report
	 * @return int The last post ID taken; 0 when no post is left.
	 */
	abstract public static function posts( int $after, int $limit, array &$report ): int;

	/**
	 * Take the SEO fields of the next terms after a cursor.
	 *
	 * @param array<string, mixed> $report
	 * @return int Where the next batch starts; 0 when no term is left.
	 */
	abstract public static function terms( int $cursor, int $limit, array &$report ): int;

	/**
	 * How many posts have the plugin's data Simple SEO has no field for, by what the report calls it.
	 *
	 * @return array<string, int>
	 */
	abstract public static function left(): array;

	/**
	 * What the import says about the plugin's variables, for the tab.
	 */
	abstract public static function variables_note(): string;

	/** How the plugin writes a variable, for the report: %%name%%, %name%. */
	abstract public static function variable( string $name ): string;

	/**
	 * A text of the plugin's in Simple SEO's syntax. A variable Simple SEO has no counterpart for goes
	 * away and is counted in the report.
	 *
	 * @param string               $slot Where the text is used (a slot of Simple SEO's templates), '' for a field.
	 * @param array<string, mixed> $report
	 */
	abstract protected static function text( string $value, string $slot, array &$report ): string;

	/**
	 * One of the plugin's values of a field as Simple SEO's field $key takes it: '' for none, null for
	 * what the field cannot hold.
	 *
	 * @param string               $name The plugin's name of the value.
	 * @param array<string, mixed> $report
	 * @return mixed
	 */
	abstract protected static function value( string $name, string $key, string $value, array &$report );

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * The separator of titles and the templates of titles and descriptions.
	 *
	 * @param string                               $separator One of Simple SEO's separators, '' for none.
	 * @param array<string, array<string, string>> $templates Slot => title|description => the plugin's text.
	 * @param array<string, mixed>                 $report
	 */
	protected static function titles( string $separator, array $templates, array &$report ): void {
		$row    = self::row( Simple_SEO_Titles::OPTION );
		$before = Simple_SEO_Settings::sanitize( Simple_SEO_Titles::OPTION, $row );
		$clean  = $before;

		$taken = '' !== $separator && ! array_key_exists( 'separator', $row );
		if ( $taken ) {
			$clean['separator'] = $separator;
			self::add( $report, 'settings', 'separator' );
		}

		foreach ( $templates as $slot => $values ) {
			foreach ( $values as $which => $text ) {
				// A template Simple SEO has stays; an empty one of the plugin's leaves Simple SEO's default.
				$value = isset( $before['templates'][ $slot ][ $which ] ) ? '' : static::text( self::scalar( $text ), (string) $slot, $report );
				if ( '' !== $value ) {
					$clean['templates'][ $slot ][ $which ] = $value;
				}
			}
		}

		// What equals Simple SEO's default is not kept as a template of its own, nor counted.
		$clean = Simple_SEO_Settings::sanitize( Simple_SEO_Titles::OPTION, $clean );
		$count = 0;
		foreach ( $clean['templates'] as $slot => $values ) {
			$count += count( array_diff_key( $values, (array) ( $before['templates'][ $slot ] ?? array() ) ) );
		}
		if ( $count ) {
			self::add( $report, 'settings', 'templates', $count );
		}
		if ( $count || $taken ) {
			self::save( Simple_SEO_Titles::OPTION, $clean );
		}
	}

	/**
	 * Simple SEO's slots of templates with the post types and taxonomies the site has: slot =>
	 * callback( kind, name ) of the plugin's keys. The home and 404 pages are Simple SEO's special
	 * pages; attachments have no SEO fields here.
	 *
	 * @param callable $keys ( 'author'|'date'|'search'|'post'|'archive'|'term', type or taxonomy ) → array( title|description => the plugin's key ).
	 * @return array<string, array<string, string>>
	 */
	protected static function slots( callable $keys ): array {
		$slots = array(
			'author' => $keys( 'author', '' ),
			'date'   => $keys( 'date', '' ),
			'search' => $keys( 'search', '' ),
		);
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$slots[ 'post:' . $type ] = $keys( 'post', $type );
			if ( ! empty( get_post_type_object( $type )->has_archive ) ) {
				$slots[ 'archive:' . $type ] = $keys( 'archive', $type );
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$slots[ 'term:' . $taxonomy ] = $keys( 'term', $taxonomy );
		}

		return array_filter( $slots );
	}

	/**
	 * Which kinds of pages search engines should leave out, and where attachment pages go.
	 *
	 * @param string[]|null        $noindex     The slots to leave out; null when the plugin does not say.
	 * @param string               $attachments One of Simple_SEO_Indexing::ATTACHMENTS_*, '' when the plugin does not say.
	 * @param array<string, mixed> $report
	 */
	protected static function indexing( ?array $noindex, string $attachments, array &$report ): void {
		$row   = self::row( Simple_SEO_Indexing::OPTION );
		$clean = Simple_SEO_Indexing::sanitize( $row + Simple_SEO_Indexing::defaults() );
		$count = 0;

		if ( null !== $noindex && ! array_key_exists( 'noindex', $row ) ) {
			$clean['noindex'] = array_values( array_unique( $noindex ) );
			self::add( $report, 'settings', 'indexing' );
			++$count;
		}

		if ( '' !== $attachments && ! array_key_exists( 'attachments', $row ) ) {
			$clean['attachments'] = $attachments;
			self::add( $report, 'settings', 'attachments' );
			++$count;
		}

		if ( $count ) {
			self::save( Simple_SEO_Indexing::OPTION, $clean );
		}
	}

	/**
	 * The default image for social networks and the site's X account.
	 *
	 * @param mixed                $image An attachment ID.
	 * @param array<string, mixed> $report
	 */
	protected static function social( $image, string $account, array &$report ): void {
		$row   = self::row( Simple_SEO_Social::OPTION );
		$clean = Simple_SEO_Social::sanitize( $row );
		$count = 0;

		$image = Simple_SEO_Fields::image_attachment( $image );
		if ( $image && ! array_key_exists( 'default_image', $row ) ) {
			$clean['default_image'] = $image;
			self::add( $report, 'settings', 'default_image' );
			++$count;
		}
		$account = Simple_SEO_Social::sanitize_account( $account );
		if ( '' !== $account && ! array_key_exists( 'x_account', $row ) ) {
			$clean['x_account'] = $account;
			self::add( $report, 'settings', 'x_account' );
			++$count;
		}

		if ( $count ) {
			self::save( Simple_SEO_Social::OPTION, $clean );
		}
	}

	/**
	 * The home and 404 pages of one language ('' — the default one): their templates and the home
	 * page's image for social networks.
	 *
	 * @param array<string, array<string, string>> $templates home|404 => title|description => the plugin's text.
	 * @param mixed                                $image     The home page's image, an attachment ID.
	 * @param array<string, mixed>                 $report
	 */
	protected static function special( string $code, array $templates, $image, array &$report ): void {
		$name   = self::language_row_name( Simple_SEO_Special::OPTION, $code );
		$before = Simple_SEO_Special::sanitize( self::row( $name ) );
		$clean  = $before;

		foreach ( $templates as $slot => $values ) {
			// PHP turns the key '404' into a number.
			$slot = (string) $slot;
			foreach ( $values as $which => $text ) {
				$value = isset( $before[ $slot ][ $which ] ) ? '' : static::text( self::scalar( $text ), $slot, $report );
				if ( '' !== $value ) {
					$clean[ $slot ][ $which ] = $value;
				}
			}
		}

		$image = Simple_SEO_Fields::image_attachment( $image );
		if ( $image && empty( $before['home']['image'] ) ) {
			$clean['home']['image'] = $image;
		}

		// What equals Simple SEO's default is not kept, nor counted.
		$clean = Simple_SEO_Special::sanitize( $clean );
		$count = 0;
		foreach ( $clean as $slot => $values ) {
			$count += count( array_diff_key( $values, (array) ( $before[ $slot ] ?? array() ) ) );
		}
		if ( $count ) {
			self::add( $report, 'settings', 'special', $count );
			self::save( $name, $clean );
		}
	}

	/**
	 * The breadcrumbs: separator, whether the current page and the blog page are in the trail, and
	 * the taxonomy that leads each post type's trail.
	 *
	 * @param bool|null             $show_current null when the plugin does not say.
	 * @param bool|null             $show_blog    null when the plugin does not say.
	 * @param array<string, string> $taxonomies   Post type => taxonomy.
	 * @param array<string, mixed>  $report
	 */
	protected static function breadcrumbs( string $separator, ?bool $show_current, ?bool $show_blog, array $taxonomies, array &$report ): void {
		$row    = self::row( Simple_SEO_Breadcrumbs::OPTION );
		$before = Simple_SEO_Breadcrumbs::sanitize( $row );
		$input  = array(
			'separator'    => $before['separator'],
			'show_current' => $before['show_current'] ? '1' : '0',
			'show_blog'    => $before['show_blog'] ? '1' : '0',
			'taxonomies'   => $before['taxonomies'],
		);

		$separator = self::scalar( html_entity_decode( $separator, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' !== $separator && ! array_key_exists( 'separator', $row ) ) {
			$input['separator'] = $separator;
		}
		if ( null !== $show_current && ! array_key_exists( 'show_current', $row ) ) {
			$input['show_current'] = $show_current ? '1' : '0';
		}
		if ( null !== $show_blog && ! array_key_exists( 'show_blog', $row ) ) {
			$input['show_blog'] = $show_blog ? '1' : '0';
		}
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$taxonomy = self::scalar( $taxonomies[ $type ] ?? '' );
			if ( '' !== $taxonomy && ! isset( $before['taxonomies'][ $type ] ) ) {
				$input['taxonomies'][ $type ] = $taxonomy;
			}
		}

		// A taxonomy the type does not have and Simple SEO's default one are not kept, nor counted.
		$clean = Simple_SEO_Breadcrumbs::sanitize( $input );
		$count = (int) ( $clean['separator'] !== $before['separator'] ) + (int) ( $clean['show_current'] !== $before['show_current'] )
			+ (int) ( $clean['show_blog'] !== $before['show_blog'] ) + count( array_diff_assoc( $clean['taxonomies'], $before['taxonomies'] ) );
		if ( $count ) {
			self::add( $report, 'settings', 'breadcrumbs', $count );
			self::save( Simple_SEO_Breadcrumbs::OPTION, $clean );
		}
	}

	/**
	 * The breadcrumb labels of one language as the plugin had them: themes find and replace the
	 * plugin's texts ('Home' becomes an icon), so they are not translated to Simple SEO's.
	 *
	 * @param array<string, string> $labels home|prefix => the plugin's text.
	 * @param array<string, mixed>  $report
	 */
	protected static function labels( string $code, array $labels, array &$report ): void {
		$name  = self::language_row_name( Simple_SEO_Breadcrumbs::LABELS, $code );
		$clean = Simple_SEO_Breadcrumbs::sanitize_labels( self::row( $name ) );
		$count = 0;

		foreach ( array( 'home', 'prefix' ) as $label ) {
			$value = self::scalar( $labels[ $label ] ?? '' );
			if ( '' !== $value && '' === $clean[ $label ] ) {
				$clean[ $label ] = $value;
				++$count;
			}
		}

		if ( $count ) {
			self::add( $report, 'settings', 'labels', $count );
			self::save( $name, Simple_SEO_Breadcrumbs::sanitize_labels( $clean ) );
		}
	}

	/**
	 * The Schema settings: whom the site represents, the logo and the profiles elsewhere (common
	 * to all languages, taken with $code '' only), and the names of the site and of the organization
	 * of one language. Only what Simple SEO has no choice of its own for is taken; a name equal to
	 * the title of the site is not copied, an empty name falls back to it anyway.
	 *
	 * @param array<string, mixed>  $common represents (organization|person), person (user ID), logo
	 *                                      (attachment ID), profiles (addresses), page_types and
	 *                                      article_types (post type => type); what the plugin does not
	 *                                      say is left out. A post type's type is taken only where
	 *                                      Simple SEO has none of its own for that post type.
	 * @param array<string, string> $names  site_name, site_alternate, name, alternate.
	 * @param array<string, mixed>  $report
	 */
	protected static function schema( string $code, array $common, array $names, array &$report ): void {
		$count = 0;

		if ( '' === $code && $common ) {
			$row      = self::row( Simple_SEO_Schema::OPTION );
			$clean    = Simple_SEO_Schema::sanitize( $row );
			$defaults = Simple_SEO_Settings::defaults( Simple_SEO_Schema::OPTION );
			$taken    = 0;
			foreach ( array( 'represents', 'person', 'logo', 'profiles' ) as $key ) {
				if ( ! array_key_exists( $key, $common ) || array_key_exists( $key, $row ) ) {
					continue;
				}
				$value = Simple_SEO_Schema::sanitize( array( $key => $common[ $key ] ) + $clean )[ $key ];
				if ( $value !== $defaults[ $key ] ) {
					$clean[ $key ] = $value;
					++$taken;
				}
			}
			foreach ( array( 'page_types', 'article_types' ) as $key ) {
				if ( empty( $common[ $key ] ) || ! is_array( $common[ $key ] ) ) {
					continue;
				}
				$own = is_array( $row[ $key ] ?? null ) ? $row[ $key ] : array();
				$new = array_diff_key( Simple_SEO_Schema::sanitize( array( $key => array_diff_key( $common[ $key ], $own ) ) + $clean )[ $key ], $own );
				if ( $new ) {
					$clean[ $key ] += $new;
					$taken         += count( $new );
				}
			}
			if ( $taken ) {
				self::save( Simple_SEO_Schema::OPTION, $clean );
				$count += $taken;
			}
		}

		$name  = self::language_row_name( Simple_SEO_Schema::NAMES, $code );
		$clean = Simple_SEO_Schema::sanitize_names( self::row( $name ) );
		$site  = Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) );
		$taken = 0;
		foreach ( $names as $key => $value ) {
			$value = Simple_SEO_Schema::sanitize_names( array( $key => self::scalar( $value ) ) )[ $key ] ?? '';
			if ( '' === $value || '' !== $clean[ $key ] || ( $value === $site && in_array( $key, array( 'site_name', 'name' ), true ) ) ) {
				continue;
			}
			$clean[ $key ] = $value;
			++$taken;
		}
		if ( $taken ) {
			self::save( $name, $clean );
			$count += $taken;
		}

		if ( $count ) {
			self::add( $report, 'settings', 'schema', $count );
		}
	}

	/**
	 * The codes of webmaster tools for the Integrations tab: only where Simple SEO has none.
	 *
	 * @param array<string, mixed> $codes  google, bing, baidu, pinterest, facebook, ahrefs.
	 * @param array<string, mixed> $report
	 */
	protected static function verification( array $codes, array &$report ): void {
		$row   = Simple_SEO_Integrations::sanitize( self::row( Simple_SEO_Integrations::OPTION ) );
		$count = 0;
		foreach ( array_keys( Simple_SEO_Integrations::VERIFICATION ) as $field ) {
			$code = Simple_SEO_Integrations::sanitize_code( $codes[ $field ] ?? '' );
			if ( '' !== $code && empty( $row['verification'][ $field ] ) ) {
				$row['verification'][ $field ] = $code;
				++$count;
			}
		}

		if ( $count ) {
			self::save( Simple_SEO_Integrations::OPTION, $row );
			self::add( $report, 'settings', 'verification', $count );
		}
	}

	/**
	 * The details of the organization for the tab Local SEO: those common to all languages (taken
	 * with $code '' only) and the texts of one language. Only empty fields are filled.
	 *
	 * @param array<string, mixed>  $common type, phone, email, postal_code, country, geo, map, hours
	 *                                      (day Mo… => opens, closes); what the plugin does not say is left out.
	 * @param array<string, string> $names  description, street, locality, region, area_served, price_range.
	 * @param array<string, mixed>  $report
	 */
	protected static function local( string $code, array $common, array $names, array &$report ): void {
		$count  = '' === $code && $common ? Simple_SEO_Local::fill( Simple_SEO_Local::OPTION, '', $common ) : 0;
		$count += $names ? Simple_SEO_Local::fill( Simple_SEO_Local::NAMES, $code, array_map( array( self::class, 'scalar' ), $names ) ) : 0;

		if ( $count ) {
			self::add( $report, 'settings', 'local', $count );
		}
	}

	/**
	 * What the plugin removed from pages: Simple SEO's switches of the Clean-up tab go on, unless
	 * its tab has a choice of its own. What the plugin kept does not switch Simple SEO's off.
	 *
	 * @param string[]             $keys Simple SEO's switches.
	 * @param array<string, mixed> $report
	 */
	protected static function cleanup( array $keys, array &$report ): void {
		$row   = self::row( Simple_SEO_Cleanup::OPTION );
		$clean = array_replace( Simple_SEO_Cleanup::defaults(), $row );
		$count = 0;

		foreach ( array_unique( $keys ) as $key ) {
			if ( array_key_exists( $key, $clean ) && ! array_key_exists( $key, $row ) && ! $clean[ $key ] ) {
				$clean[ $key ] = true;
				++$count;
			}
		}

		if ( $count ) {
			self::add( $report, 'settings', 'cleanup', $count );
			self::save( Simple_SEO_Cleanup::OPTION, array_map( static fn( $on ): string => $on ? '1' : '0', $clean ) );
		}
	}

	/**
	 * The plugin had no XML sitemap: Simple SEO's is switched off too, unless its tab was saved.
	 *
	 * @param array<string, mixed> $report
	 */
	protected static function sitemaps_off( array &$report ): void {
		if ( ! array_key_exists( 'enabled', self::row( Simple_SEO_Sitemaps::OPTION ) ) ) {
			self::save( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => false ) );
			self::add( $report, 'settings', 'sitemaps' );
		}
	}

	// ----------------------------------------------------------------------------------------
	// Posts and terms.

	/**
	 * Fill the empty fields of a post or term from the plugin's values; a field Simple SEO has stays.
	 * A field the plugin keeps in two forms (the image as ID and as address) takes the first that fits.
	 *
	 * @param string                                                 $type   post or term.
	 * @param array<string, array<int, array{0: string, 1: string}>> $fields Field => the plugin's values (its name of the value, value).
	 * @param array<string, mixed>                                   $report
	 */
	protected static function take( string $type, int $id, array $fields, array &$report ): void {
		self::add( $report, $type, 'seen' );
		$changed = false;

		foreach ( $fields as $key => $values ) {
			$meta_key = Simple_SEO_Fields::meta_key( $key );
			if ( '' !== (string) get_metadata( $type, $id, $meta_key, true ) ) {
				self::add( $report, $type, 'kept' );
				continue;
			}

			$clean = '';
			foreach ( $values as [ $name, $value ] ) {
				$clean = static::value( $name, $key, $value, $report );
				if ( '' !== $clean && null !== $clean ) {
					break;
				}
			}

			if ( null === $clean ) {
				self::add( $report, $type, 'refused' );
			} elseif ( '' !== $clean ) {
				// update_metadata() expects slashed data and unslashes it itself.
				update_metadata( $type, $id, $meta_key, wp_slash( $clean ) );
				self::add( $report, $type, 'fields' );
				$changed = true;
			}
		}

		if ( $changed ) {
			self::add( $report, $type, 'changed' );
		}
	}

	/**
	 * A value as Simple SEO's field holds it: '' for none, null for what the field cannot hold.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	protected static function field_value( string $key, $value ) {
		if ( '' === $value || false === $value ) {
			return '';
		}
		if ( null === $value ) {
			return null;
		}

		// The sanitizer makes nothing of what the field cannot hold.
		$clean = Simple_SEO_Fields::sanitize_value( $key, $value );

		return '' === $clean || false === $clean ? null : $clean;
	}

	/**
	 * Whether Simple SEO has the field for a post of this type or for terms; '' when it has none.
	 */
	protected static function field_for( string $key, string $object_type, string $post_type = '' ): string {
		$field = Simple_SEO_Fields::get( $key );
		if ( null === $field || ! in_array( $object_type, (array) $field['objects'], true ) ) {
			return '';
		}
		if ( 'post' === $object_type && $field['post_types'] && ! in_array( $post_type, (array) $field['post_types'], true ) ) {
			return '';
		}

		return $key;
	}

	/**
	 * Another plugin's keyphrases as values to try for a post's fields: the focus keyphrase, and
	 * the more keyphrases one on a line. Empty entries and what is no text are left out.
	 *
	 * @param array<string, array<int, array{0: string, 1: string}>> $fields The values to try by field, added to.
	 * @param mixed                                                  $focus  The focus keyphrase.
	 * @param array<int, mixed>                                      $more   The other keyphrases.
	 */
	protected static function keyphrases( array &$fields, string $name, $focus, array $more, string $object_type, string $post_type ): void {
		$lists = array(
			'keyphrase'  => array( $focus ),
			'keyphrases' => $more,
		);
		foreach ( $lists as $key => $list ) {
			$phrases = array();
			foreach ( $list as $phrase ) {
				$phrase = is_scalar( $phrase ) ? trim( (string) preg_replace( '/\s+/u', ' ', (string) $phrase ) ) : '';
				if ( '' !== $phrase ) {
					$phrases[] = $phrase;
				}
			}
			if ( $phrases && '' !== self::field_for( $key, $object_type, $post_type ) ) {
				$fields[ $key ][] = array( $name, implode( "\n", $phrases ) );
			}
		}
	}

	/**
	 * An image from the media library by its address; 0 for none, null for an address that is not one.
	 *
	 * @return int|string|null
	 */
	protected static function image_by_url( string $url ) {
		if ( '' === $url ) {
			return '';
		}
		$id = attachment_url_to_postid( $url );

		return $id ? $id : null;
	}

	// ----------------------------------------------------------------------------------------
	// Helpers.

	/** @param string[] $values */
	protected static function in( array $values ): string {
		return implode( ',', array_map( static fn( string $value ): string => "'" . esc_sql( $value ) . "'", $values ) );
	}

	/**
	 * A row of options as stored, read past every filter (wp-loc's language copies included);
	 * null when there is none.
	 *
	 * @return mixed
	 */
	public static function option( string $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}

	/** The name of Simple SEO's row of a language ('' — the default one: the row itself). */
	public static function language_row_name( string $name, string $code ): string {
		return '' !== $code ? $name . '_' . $code : $name;
	}

	/**
	 * Simple SEO's row as stored: without defaults, so a key in it is a saved choice.
	 *
	 * @return array<string, mixed>
	 */
	protected static function row( string $name ): array {
		$row = self::option( $name );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Save a row of Simple SEO's under its exact name. wp-loc sends what an admin saves in another
	 * language to that language's copy; the row of the default language is written as itself.
	 *
	 * @param mixed $value
	 */
	public static function save( string $name, $value ): void {
		$cookie = $_COOKIE['admin_lang'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- set aside and put back unchanged.
		unset( $_COOKIE['admin_lang'] );
		try {
			update_option( $name, $value );
		} finally {
			if ( null !== $cookie ) {
				$_COOKIE['admin_lang'] = $cookie;
			}
		}
		Simple_SEO_Settings::flush( $name );
	}

	/** @param array<string, mixed> $report */
	protected static function add( array &$report, string $group, string $what, int $count = 1 ): void {
		$report[ $group ][ $what ] = (int) ( $report[ $group ][ $what ] ?? 0 ) + $count;
	}

	/**
	 * A scalar as trimmed text; '' for anything else.
	 *
	 * @param mixed $value
	 */
	protected static function scalar( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
