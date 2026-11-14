<?php
/**
 * Access tab: which roles get each of the plugin's capabilities.
 *
 * @var array<string, string[]>                         $access
 * @var array<string, string>                           $roles
 * @var array<string, array{label: string, help: string}> $rows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p><?php esc_html_e( 'Choose which roles can do what. Editing SEO fields of a post or term also needs the right to edit that post or term itself.', 'simple-seo' ); ?></p>

<table class="form-table" role="presentation">
	<tbody>
	<?php foreach ( $rows as $simple_seo_key => $simple_seo_row ) : ?>
		<tr>
			<th scope="row"><?php echo esc_html( $simple_seo_row['label'] ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php echo esc_html( $simple_seo_row['label'] ); ?></legend>
					<?php
					foreach ( $roles as $simple_seo_role => $simple_seo_role_name ) :
						$simple_seo_locked  = 'settings' === $simple_seo_key && 'administrator' === $simple_seo_role;
						$simple_seo_checked = $simple_seo_locked || in_array( $simple_seo_role, (array) ( $access[ $simple_seo_key ] ?? array() ), true );
						?>
						<label style="display:inline-block;margin:0 1.5em .5em 0">
							<input type="checkbox"
								name="<?php echo esc_attr( Simple_SEO_Access::OPTION . '[' . $simple_seo_key . '][]' ); ?>"
								value="<?php echo esc_attr( $simple_seo_role ); ?>"
								<?php checked( $simple_seo_checked ); ?>
								<?php disabled( $simple_seo_locked ); ?>>
							<?php echo esc_html( translate_user_role( $simple_seo_role_name ) ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php echo esc_html( $simple_seo_row['help'] ); ?></p>
				</fieldset>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
