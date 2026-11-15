<?php
/**
 * Local SEO tab: the type of the organization, its contacts, address and place on the map, the
 * texts kept per language with wp-loc, the opening hours, the button that takes the theme's
 * details, and the organization node as saved now.
 *
 * @var array<string, mixed>      $settings
 * @var array<string, string>     $names
 * @var string                    $language Name of the language being edited, '' without wp-loc.
 * @var string                    $type     The type of the organization.
 * @var bool                      $theme    Whether the theme keeps details of the organization.
 * @var string                    $nonce    Action of the nonce of the theme button.
 * @var array<string, mixed>|null $example  The organization node; null when the site represents a person.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$simple_seo_option = Simple_SEO_Local::OPTION;
$simple_seo_names  = Simple_SEO_Local::NAMES;
$simple_seo_types  = Simple_SEO_Local::types();
$simple_seo_local  = Simple_SEO_Local::is_local( $type ) ? '' : ' hidden';
// The weekdays of WordPress count from Sunday.
$simple_seo_day_numbers = array_flip( array_keys( Simple_SEO_Local::DAYS ) );
?>
<p><?php esc_html_e( 'What kind of organization the site represents and how to find it: the address, phone and opening hours go into the organization of the Schema graph, so search engines and maps can show them.', 'simple-seo' ); ?></p>

<?php if ( 'person' === Simple_SEO_Schema::represents() ) : ?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'The site represents a person (the Schema tab). These details go into the graph once it represents an organization.', 'simple-seo' ); ?></p>
	</div>
<?php elseif ( Simple_SEO_Local::is_local( $type ) && '' === trim( ( $names['street'] ?? '' ) . ( $names['locality'] ?? '' ) ) ) : ?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'A local business needs its address: search engines show local businesses with one.', 'simple-seo' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $theme ) : ?>
	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'The theme keeps details of the organization (its options of Schema.org and the opening hours). Simple SEO can take them into the empty fields of this tab and of the names on the Schema tab, in every language; what is filled in stays.', 'simple-seo' ); ?>
		</p>
		<p>
			<?php wp_nonce_field( $nonce, 'simple_seo_local_nonce', false ); ?>
			<button type="submit" class="button" name="simple_seo_local_theme" value="1" formaction="<?php echo esc_url( Simple_SEO_Admin::url( 'local' ) ); ?>"><?php esc_html_e( 'Take from the theme', 'simple-seo' ); ?></button>
		</p>
	</div>
<?php endif; ?>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-local-type"><?php esc_html_e( 'Type of the organization', 'simple-seo' ); ?></label></th>
			<td>
				<select id="simple-seo-local-type" name="<?php echo esc_attr( $simple_seo_option ); ?>[type]" data-simple-seo-local-type>
					<?php
					foreach ( array(
						__( 'Organizations', 'simple-seo' )    => false,
						__( 'Local businesses', 'simple-seo' ) => true,
					) as $simple_seo_group => $simple_seo_is_local ) :
						?>
						<optgroup label="<?php echo esc_attr( $simple_seo_group ); ?>">
							<?php
							foreach ( Simple_SEO_Local::type_labels() as $simple_seo_value => $simple_seo_label ) :
								if ( $simple_seo_types[ $simple_seo_value ] !== $simple_seo_is_local ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $simple_seo_value ); ?>" data-local="<?php echo $simple_seo_is_local ? '1' : '0'; ?>"<?php selected( $type, $simple_seo_value ); ?>><?php echo esc_html( $simple_seo_label === $simple_seo_value ? $simple_seo_label : $simple_seo_label . ' (' . $simple_seo_value . ')' ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'The narrowest type that fits. A local business — a place people come to, or an area it serves — also gets its coordinates, map, opening hours and price range.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-phone"><?php esc_html_e( 'Phone', 'simple-seo' ); ?></label></th>
			<td>
				<input type="tel" class="regular-text" id="simple-seo-local-phone" name="<?php echo esc_attr( $simple_seo_option ); ?>[phone]" value="<?php echo esc_attr( (string) ( $settings['phone'] ?? '' ) ); ?>" placeholder="+380 44 123 45 67">
				<p class="description"><?php esc_html_e( 'With the country code.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-email"><?php esc_html_e( 'E-mail', 'simple-seo' ); ?></label></th>
			<td>
				<input type="email" class="regular-text" id="simple-seo-local-email" name="<?php echo esc_attr( $simple_seo_option ); ?>[email]" value="<?php echo esc_attr( (string) ( $settings['email'] ?? '' ) ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-postal-code"><?php esc_html_e( 'Postal code', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="small-text" style="width:8em" id="simple-seo-local-postal-code" name="<?php echo esc_attr( $simple_seo_option ); ?>[postal_code]" value="<?php echo esc_attr( (string) ( $settings['postal_code'] ?? '' ) ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-country"><?php esc_html_e( 'Country', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="small-text" style="width:8em" id="simple-seo-local-country" name="<?php echo esc_attr( $simple_seo_option ); ?>[country]" value="<?php echo esc_attr( (string) ( $settings['country'] ?? '' ) ); ?>" placeholder="UA">
				<p class="description"><?php esc_html_e( 'A code of two letters, as search engines prefer it: UA, PL, US.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr class="simple-seo-local-only"<?php echo esc_attr( $simple_seo_local ); ?>>
			<th scope="row"><label for="simple-seo-local-geo"><?php esc_html_e( 'Coordinates', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-local-geo" name="<?php echo esc_attr( $simple_seo_option ); ?>[geo]" value="<?php echo esc_attr( str_replace( ',', ', ', (string) ( $settings['geo'] ?? '' ) ) ); ?>" placeholder="50.4501, 30.5234">
				<p class="description"><?php esc_html_e( 'Latitude and longitude, as a map copies them (in Google Maps: right-click the place and click the numbers).', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr class="simple-seo-local-only"<?php echo esc_attr( $simple_seo_local ); ?>>
			<th scope="row"><label for="simple-seo-local-map"><?php esc_html_e( 'Link to the map', 'simple-seo' ); ?></label></th>
			<td>
				<input type="url" class="large-text" style="max-width:860px" id="simple-seo-local-map" name="<?php echo esc_attr( $simple_seo_option ); ?>[map]" value="<?php echo esc_attr( (string) ( $settings['map'] ?? '' ) ); ?>" placeholder="https://maps.app.goo.gl/…">
				<p class="description"><?php esc_html_e( 'The address of the place on a map, such as its share link in Google Maps.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Address and description', 'simple-seo' ); ?></h2>
<?php
/* translators: %s: language name, e.g. English. */
$simple_seo_notice = __( 'These texts are kept for each language; you are editing them for: %s. The other settings on this tab are common to all languages.', 'simple-seo' );
require __DIR__ . '/language-notice.php';
?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-local-description"><?php esc_html_e( 'Description', 'simple-seo' ); ?></label></th>
			<td>
				<textarea id="simple-seo-local-description" name="<?php echo esc_attr( $simple_seo_names ); ?>[description]" class="large-text" rows="3" style="max-width:860px" maxlength="500"><?php echo esc_textarea( (string) ( $names['description'] ?? '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'What the organization does, in a sentence or two.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-street"><?php esc_html_e( 'Street address', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-local-street" name="<?php echo esc_attr( $simple_seo_names ); ?>[street]" value="<?php echo esc_attr( (string) ( $names['street'] ?? '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'The street, the building and the office.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-locality"><?php esc_html_e( 'City', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-local-locality" name="<?php echo esc_attr( $simple_seo_names ); ?>[locality]" value="<?php echo esc_attr( (string) ( $names['locality'] ?? '' ) ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-region"><?php esc_html_e( 'Region', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-local-region" name="<?php echo esc_attr( $simple_seo_names ); ?>[region]" value="<?php echo esc_attr( (string) ( $names['region'] ?? '' ) ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-local-area"><?php esc_html_e( 'Area served', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-local-area" name="<?php echo esc_attr( $simple_seo_names ); ?>[area_served]" value="<?php echo esc_attr( (string) ( $names['area_served'] ?? '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Where it works: a city, a region, a country; several with commas between them.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr class="simple-seo-local-only"<?php echo esc_attr( $simple_seo_local ); ?>>
			<th scope="row"><label for="simple-seo-local-price"><?php esc_html_e( 'Price range', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-local-price" name="<?php echo esc_attr( $simple_seo_names ); ?>[price_range]" value="<?php echo esc_attr( (string) ( $names['price_range'] ?? '' ) ); ?>" placeholder="$$">
				<p class="description"><?php esc_html_e( 'How dear it is: $ to $$$$, or the prices, such as 500–2000 UAH.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<div class="simple-seo-local-only"<?php echo esc_attr( $simple_seo_local ); ?>>
	<h2><?php esc_html_e( 'Opening hours', 'simple-seo' ); ?></h2>
	<p><?php esc_html_e( 'The days it is open, with the time it opens and closes. Without the times a day is open round the clock; a day that closes after midnight closes earlier than it opens.', 'simple-seo' ); ?></p>
	<table class="widefat striped simple-seo-local-hours" style="max-width:520px">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Day', 'simple-seo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Opens', 'simple-seo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Closes', 'simple-seo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( array_keys( Simple_SEO_Local::DAYS ) as $simple_seo_day ) :
				$simple_seo_hours = is_array( $settings['hours'][ $simple_seo_day ] ?? null ) ? $settings['hours'][ $simple_seo_day ] : null;
				$simple_seo_field = $simple_seo_option . '[hours][' . $simple_seo_day . ']';
				$simple_seo_name  = $wp_locale instanceof WP_Locale ? $wp_locale->get_weekday( ( $simple_seo_day_numbers[ $simple_seo_day ] + 1 ) % 7 ) : Simple_SEO_Local::DAYS[ $simple_seo_day ];
				?>
				<tr>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $simple_seo_field ); ?>[open]" value="0">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $simple_seo_field ); ?>[open]" value="1" <?php checked( null !== $simple_seo_hours ); ?>>
							<?php echo esc_html( $simple_seo_name ); ?>
						</label>
					</td>
					<td>
						<label class="screen-reader-text" for="simple-seo-local-opens-<?php echo esc_attr( $simple_seo_day ); ?>"><?php echo esc_html( $simple_seo_name . ': ' . __( 'Opens', 'simple-seo' ) ); ?></label>
						<input type="time" id="simple-seo-local-opens-<?php echo esc_attr( $simple_seo_day ); ?>" name="<?php echo esc_attr( $simple_seo_field ); ?>[opens]" value="<?php echo esc_attr( (string) ( $simple_seo_hours['opens'] ?? '' ) ); ?>">
					</td>
					<td>
						<label class="screen-reader-text" for="simple-seo-local-closes-<?php echo esc_attr( $simple_seo_day ); ?>"><?php echo esc_html( $simple_seo_name . ': ' . __( 'Closes', 'simple-seo' ) ); ?></label>
						<input type="time" id="simple-seo-local-closes-<?php echo esc_attr( $simple_seo_day ); ?>" name="<?php echo esc_attr( $simple_seo_field ); ?>[closes]" value="<?php echo esc_attr( (string) ( $simple_seo_hours['closes'] ?? '' ) ); ?>">
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php if ( $example ) : ?>
	<details style="margin-top:1.5em">
		<summary><?php esc_html_e( 'The organization in the graph as saved now', 'simple-seo' ); ?></summary>
		<pre class="simple-seo-schema-example" style="max-width:860px;max-height:400px;overflow:auto;white-space:pre-wrap;background:#fff;border:1px solid #c3c4c7;padding:8px"><?php echo esc_html( (string) wp_json_encode( $example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
	</details>
<?php endif; ?>
