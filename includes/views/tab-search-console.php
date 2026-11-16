<?php
/**
 * Search Console tab: the key of the service account (never shown again once saved), the property
 * the data comes from, and how to connect.
 *
 * @var array<string, mixed>           $settings
 * @var array<string, string>          $key       The saved key: email, project, id, secret; [] without one.
 * @var array<string, string>|WP_Error $sites     The properties the account sees: property => permission.
 * @var string                         $automatic The property of this site among them; ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option   = Simple_SEO_Search_Console::OPTION;
$simple_seo_property = (string) ( $settings['property'] ?? '' );
$simple_seo_name     = static fn( string $property ): string => str_starts_with( $property, 'sc-domain:' )
	/* translators: %s: domain. */
	? sprintf( __( '%s (domain)', 'simple-seo' ), substr( $property, strlen( 'sc-domain:' ) ) )
	: $property;
?>
<p><?php esc_html_e( 'Google Search Console tells how the site does in Google search: clicks, impressions, CTR and position, by query and by page. Simple SEO shows them on the Search Console page of the SEO menu and in the SEO box of each published post. It reads them as a service account of Google, with read access only.', 'simple-seo' ); ?></p>

<?php Simple_SEO_Admin::section( 'connection' ); ?>
<?php if ( $key ) : ?>
	<p>
		<?php
		printf(
			/* translators: 1: email of the service account, 2: Google Cloud project. */
			esc_html__( 'Connected as %1$s, project %2$s.', 'simple-seo' ),
			'<code>' . esc_html( $key['email'] ) . '</code>',
			'' !== $key['project'] ? '<code>' . esc_html( $key['project'] ) . '</code>' : '—'
		);
		?>
	</p>
	<?php if ( is_wp_error( $sites ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $sites->get_error_message() ); ?></p></div>
	<?php elseif ( ! $sites ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				/* translators: %s: email of the service account. */
				printf( esc_html__( 'The service account sees no property yet: in Search Console, add %s as a user of the property of this site (Settings, Users and permissions; Restricted is enough).', 'simple-seo' ), '<code>' . esc_html( $key['email'] ) . '</code>' );
				?>
			</p>
		</div>
	<?php endif; ?>
<?php else : ?>
	<p><?php esc_html_e( 'Not connected.', 'simple-seo' ); ?></p>
<?php endif; ?>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-gsc-json"><?php esc_html_e( 'Key of the service account', 'simple-seo' ); ?></label></th>
			<td>
				<textarea id="simple-seo-gsc-json" name="<?php echo esc_attr( $simple_seo_option . '[json]' ); ?>" rows="5" class="large-text code" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '{ "type": "service_account", "client_email": "…", "private_key": "…" }' ); ?>"></textarea>
				<p class="description">
					<?php
					echo esc_html(
						$key
							? __( 'The saved key is never shown. Paste a new one here only to replace it.', 'simple-seo' )
							: __( 'The whole content of the JSON file of the key. Simple SEO keeps the private key encrypted with the keys of the site in wp-config.php.', 'simple-seo' )
					);
					?>
				</p>
				<?php if ( $key ) : ?>
					<p>
						<input type="hidden" name="<?php echo esc_attr( $simple_seo_option . '[forget]' ); ?>" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr( $simple_seo_option . '[forget]' ); ?>" value="1"> <?php esc_html_e( 'Disconnect and remove the saved key', 'simple-seo' ); ?></label>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ( $key && is_array( $sites ) && ( $sites || '' !== $simple_seo_property ) ) : ?>
			<tr>
				<th scope="row"><label for="simple-seo-gsc-property"><?php esc_html_e( 'Property', 'simple-seo' ); ?></label></th>
				<td>
					<select id="simple-seo-gsc-property" name="<?php echo esc_attr( $simple_seo_option . '[property]' ); ?>">
						<option value=""<?php selected( '', $simple_seo_property ); ?>>
							<?php
							echo esc_html(
								'' !== $automatic
									/* translators: %s: property of Search Console. */
									? sprintf( __( 'The property of this site: %s', 'simple-seo' ), $simple_seo_name( $automatic ) )
									: __( 'The property of this site (not found)', 'simple-seo' )
							);
							?>
						</option>
						<?php foreach ( array_unique( array_merge( array_keys( $sites ), '' !== $simple_seo_property ? array( $simple_seo_property ) : array() ) ) as $simple_seo_site ) : ?>
							<option value="<?php echo esc_attr( $simple_seo_site ); ?>"<?php selected( $simple_seo_site, $simple_seo_property ); ?>><?php echo esc_html( $simple_seo_name( $simple_seo_site ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The data comes from this property. By default it is the one of this site: its domain, or the prefix of its address.', 'simple-seo' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php Simple_SEO_Admin::section_end(); ?>

<?php Simple_SEO_Admin::section( 'how' ); ?>
<ol>
	<li>
		<?php
		printf(
			/* translators: %s: link to Google Cloud Console. */
			esc_html__( 'In %s, create a project or open one, and turn on the Google Search Console API (APIs and services, Library).', 'simple-seo' ),
			'<a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>'
		);
		?>
	</li>
	<li><?php esc_html_e( 'In IAM and admin, Service accounts, create a service account: it needs no roles. Then open it, Keys, Add key, Create new key, JSON: the browser saves the file of the key.', 'simple-seo' ); ?></li>
	<li>
		<?php
		printf(
			/* translators: %s: link to Search Console. */
			esc_html__( 'In %s, open the property of this site: Settings, Users and permissions, Add user. Give the email of the service account (client_email in the file) the permission Restricted.', 'simple-seo' ),
			'<a href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a>'
		);
		?>
	</li>
	<li><?php esc_html_e( 'Paste the whole content of the file into the field above and save. Keep the file nowhere public, or delete it.', 'simple-seo' ); ?></li>
</ol>
<?php Simple_SEO_Admin::section_end(); ?>
