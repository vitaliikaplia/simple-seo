<?php
/**
 * SEO fields in the post editor (a meta box that works in the classic editor and in the block
 * editor) and on the term edit screen. Admin only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Editor {
	private const NONCE_ACTION = 'simple_seo_save_fields';
	private const NONCE_FIELD  = 'simple_seo_fields_nonce';
	private const INPUT        = 'simple_seo';

	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'save_post' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'hook_taxonomies' ) );
	}

	/** @param mixed $post */
	public static function add_meta_box( string $post_type, $post = null ): void {
		if ( ! in_array( $post_type, Simple_SEO_Fields::post_types(), true ) || ! current_user_can( Simple_SEO_Access::CAP_FIELDS ) ) {
			return;
		}

		add_meta_box( 'simple-seo', __( 'SEO', 'simple-seo' ), array( self::class, 'render_meta_box' ), $post_type, 'normal', 'default' );
	}

	public static function render_meta_box( WP_Post $post ): void {
		$fields = self::editable_fields( 'post', (int) $post->ID );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// A new post has no title yet: what it would show now says nothing.
		$preview = 'auto-draft' !== $post->post_status;

		$group = '';
		echo '<div class="simple-seo-fields">';
		echo Simple_SEO_Snippet::render( 'post', $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		foreach ( $fields as $key => $field ) {
			$value = Simple_SEO_Fields::post_value( (int) $post->ID, $key );
			if ( $group !== $field['group'] ) {
				$group   = (string) $field['group'];
				$heading = Simple_SEO_Fields::group_heading( $group );
				if ( '' !== $heading ) {
					printf( '<h4 style="margin:1.5em 0 .5em;padding-top:1em;border-top:1px solid #dcdcde">%s</h4>', esc_html( $heading ) );
				}
			}
			echo '<p>';
			if ( 'checkbox' === $field['type'] ) {
				echo self::input( $field, $value ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				printf( '<label for="%s">%s</label>', esc_attr( self::input_id( $key ) ), esc_html( (string) $field['label'] ) );
			} else {
				printf( '<label for="%s"><strong>%s</strong></label><br>', esc_attr( self::input_id( $key ) ), esc_html( (string) $field['label'] ) );
				echo self::input_with_mask( $field, $value, 'post', $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			}
			if ( '' !== (string) $field['description'] ) {
				printf( '<br><span class="description">%s</span>', esc_html( (string) $field['description'] ) );
			}
			if ( $preview ) {
				echo self::preview( $field, 'post', $post, '<br>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			}
			echo '</p>';
		}
		// The checks of the content, under the keyphrases they look for.
		echo Simple_SEO_Analysis::render( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		// How the page does in Google search, loaded after the editor.
		echo Simple_SEO_Search_Console::meta_box_part( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '</div>';
	}

	/**
	 * Save the meta box. In the block editor the meta boxes are posted separately to post.php,
	 * which runs save_post as well.
	 */
	public static function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		// Saving one post can save others in the same request (wp-loc syncs status and the featured
		// image across a translation group): the submitted fields belong to the edited post only.
		if ( ! isset( $_POST['post_ID'] ) || (int) $_POST['post_ID'] !== $post_id ) {
			return;
		}
		if ( ! in_array( $post->post_type, Simple_SEO_Fields::post_types(), true ) ) {
			return;
		}

		Simple_SEO_Fields::save( 'post', $post_id, self::submitted() );
	}

	/** Term screens: taxonomies exist by admin_init. */
	public static function hook_taxonomies(): void {
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( self::class, 'render_term_fields' ), 10, 1 );
			add_action( "edited_{$taxonomy}", array( self::class, 'save_term' ), 10, 1 );
		}
	}

	public static function render_term_fields( WP_Term $term ): void {
		$fields = self::editable_fields( 'term', (int) $term->term_id );
		if ( ! $fields ) {
			return;
		}

		echo '<tr class="form-field simple-seo-heading"><th scope="row" colspan="2"><h2 style="margin:1em 0 0">' . esc_html__( 'SEO', 'simple-seo' ) . '</h2>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '</th></tr>';
		echo '<tr class="form-field simple-seo-snippet-row"><td colspan="2" style="padding-left:0">' . Simple_SEO_Snippet::render( 'term', $term ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

		$group = '';
		foreach ( $fields as $key => $field ) {
			$value = Simple_SEO_Fields::term_value( (int) $term->term_id, $key );
			if ( $group !== $field['group'] ) {
				$group   = (string) $field['group'];
				$heading = Simple_SEO_Fields::group_heading( $group );
				if ( '' !== $heading ) {
					printf( '<tr class="form-field"><th scope="row" colspan="2"><h3 style="margin:.5em 0 0">%s</h3></th></tr>', esc_html( $heading ) );
				}
			}
			printf( '<tr class="form-field"><th scope="row"><label for="%s">%s</label></th><td>', esc_attr( self::input_id( $key ) ), esc_html( (string) $field['label'] ) );
			echo self::input_with_mask( $field, $value, 'term', $term ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			if ( '' !== (string) $field['description'] ) {
				printf( '<p class="description">%s</p>', esc_html( (string) $field['description'] ) );
			}
			echo self::preview( $field, 'term', $term, '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			echo '</td></tr>';
		}
	}

	/** Quick edit and other term updates carry no nonce of ours and change nothing. */
	public static function save_term( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		// As with posts: only the term whose form was submitted.
		if ( ! isset( $_POST['tag_ID'] ) || (int) $_POST['tag_ID'] !== $term_id ) {
			return;
		}

		Simple_SEO_Fields::save( 'term', $term_id, self::submitted() );
	}

	/** @return array<string, array<string, mixed>> */
	private static function editable_fields( string $object_type, int $object_id ): array {
		$user_id = get_current_user_id();

		return array_filter(
			Simple_SEO_Fields::all( $object_type ),
			static fn( array $field ): bool => Simple_SEO_Fields::user_can_edit( $user_id, $object_type, $object_id, (string) $field['key'] )
		);
	}

	/** @return array<string, mixed> */
	private static function submitted(): array {
		// Each value is sanitized by its field in Simple_SEO_Fields::save().
		$input = isset( $_POST[ self::INPUT ] ) && is_array( $_POST[ self::INPUT ] ) ? wp_unslash( $_POST[ self::INPUT ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_array( $input ) ? $input : array();
	}

	/** Not "simple-seo-{key}": WordPress gives the meta box heading the id "simple-seo-title". */
	private static function input_id( string $key ): string {
		return 'simple-seo-field-' . $key;
	}

	/**
	 * A field with a template: the template as the placeholder and the "Insert structure" button.
	 * The fields the AI writes get its button (Simple_SEO_AI) as well, and the focus keyphrase the
	 * one of the keyword research (Simple_SEO_Keywords).
	 *
	 * @param array<string, mixed> $field
	 * @param WP_Post|WP_Term      $object
	 */
	private static function input_with_mask( array $field, string $value, string $object_type, $object ): string {
		$mask    = is_callable( $field['mask'] ?? null ) ? (string) call_user_func( $field['mask'], $object_type, $object ) : '';
		$html    = self::input( $field, $value, $mask, $object );
		$buttons = array_filter(
			array(
				'' !== $mask ? Simple_SEO_Admin::mask_button( self::input_id( (string) $field['key'] ), $mask ) : '',
				Simple_SEO_AI::button( (string) $field['key'], $object_type, $object ),
				Simple_SEO_Keywords::button( (string) $field['key'], $object_type, $object ),
			)
		);

		if ( $buttons ) {
			$html .= '<span class="simple-seo-field-buttons">' . implode( ' ', $buttons ) . '</span>';
		}

		return $html;
	}

	/**
	 * What the page shows now with the saved values, under the field.
	 *
	 * @param array<string, mixed> $field
	 * @param WP_Post|WP_Term      $object
	 */
	private static function preview( array $field, string $object_type, $object, string $before ): string {
		if ( ! is_callable( $field['preview'] ?? null ) ) {
			return '';
		}

		$text = (string) call_user_func( $field['preview'], $object_type, $object );

		return $before . sprintf(
			'<span class="description simple-seo-preview">%s</span>',
			esc_html(
				'' !== $text
					/* translators: %s: the title or description the page has with the saved values. */
					? sprintf( __( 'Now: %s', 'simple-seo' ), $text )
					: __( 'Now: none from Simple SEO.', 'simple-seo' )
			)
		);
	}

	/**
	 * @param array<string, mixed> $field
	 * @param WP_Post|WP_Term|null $object For options that depend on the object.
	 */
	private static function input( array $field, string $value, string $placeholder = '', $object = null ): string {
		$key         = (string) $field['key'];
		$id          = esc_attr( self::input_id( $key ) );
		$name        = esc_attr( self::INPUT . '[' . $key . ']' );
		$placeholder = '' !== $placeholder ? sprintf( ' placeholder="%s"', esc_attr( $placeholder ) ) : '';

		switch ( $field['type'] ) {
			case 'textarea':
				return sprintf( '<textarea id="%s" name="%s" rows="3" class="large-text"%s>%s</textarea>', $id, $name, $placeholder, esc_textarea( $value ) );
			case 'code':
				return sprintf( '<textarea id="%s" name="%s" rows="8" class="large-text code" spellcheck="false"%s>%s</textarea>', $id, $name, $placeholder, esc_textarea( $value ) );
			case 'checkbox':
				// The hidden empty value is what arrives when the box is unchecked.
				return sprintf( '<input type="hidden" name="%2$s" value=""><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s>', $id, $name, checked( '' !== $value && '0' !== $value, true, false ) );
			case 'select':
				$options = '';
				$choices = is_callable( $field['options'] ) ? (array) call_user_func( $field['options'], $object instanceof WP_Term ? 'term' : 'post', $object ) : (array) $field['options'];
				foreach ( $choices as $option_value => $option_label ) {
					$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $option_value ), selected( $value, (string) $option_value, false ), esc_html( (string) $option_label ) );
				}
				return sprintf( '<select id="%s" name="%s">%s</select>', $id, $name, $options );
			case 'url':
				return sprintf( '<input type="url" id="%s" name="%s" value="%s" class="large-text"%s>', $id, $name, esc_attr( $value ), $placeholder );
			case 'image':
				return Simple_SEO_Admin::image_picker( self::INPUT . '[' . $key . ']', self::input_id( $key ), (int) $value );
			default:
				return sprintf( '<input type="text" id="%s" name="%s" value="%s" class="large-text"%s>', $id, $name, esc_attr( $value ), $placeholder );
		}
	}
}
