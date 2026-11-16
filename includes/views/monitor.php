<?php
/**
 * The page "Pages not found" under the SEO menu: the addresses of the site that answered 404, how
 * many times and when, where the visitors came from, and what to do with each.
 *
 * @var array{s: string, hidden: bool, order: string, paged: int}             $view
 * @var array{rows: array<int, array<string, mixed>>, total: int}             $found
 * @var int                                                                   $hidden  How many addresses are hidden.
 * @var bool                                                                  $enabled Whether 404s are counted.
 * @var array<string, int>                                                    $rules   The redirect rules of WP Base of the addresses shown: address => ID.
 * @var bool                                                                  $theme   Whether the theme has the redirect rules of WP Base.
 * @var string                                                                $nonce   Nonce action of the forms.
 * @var string                                                                $done    What the last form did.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_when  = static fn( string $gmt ): string => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $gmt . ' UTC' ) );
$simple_seo_form  = static function ( string $do, int $id, string $label, string $class = 'button-link' ) use ( $nonce, $view ): string {
	$fields = '';
	foreach ( array_filter( array( 's' => $view['s'], 'hidden' => $view['hidden'] ? '1' : '', 'order' => 'last' === $view['order'] ? 'last' : '', 'paged' => $view['paged'] > 1 ? (string) $view['paged'] : '' ) ) as $name => $value ) {
		$fields .= sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( (string) $value ) );
	}

	return sprintf(
		'<form method="post" action="%1$s" class="simple-seo-404-action">%2$s<input type="hidden" name="action" value="%3$s"><input type="hidden" name="do" value="%4$s"><input type="hidden" name="id" value="%5$d">%6$s<button type="submit" class="%7$s">%8$s</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( $nonce, '_wpnonce', true, false ),
		esc_attr( $nonce ),
		esc_attr( $do ),
		$id,
		$fields,
		esc_attr( $class ),
		esc_html( $label )
	);
};
$simple_seo_notes = array(
	'hidden'      => __( 'The address is hidden: it is still counted, but listed under Hidden only.', 'simple-seo' ),
	'shown'       => __( 'The address is listed again.', 'simple-seo' ),
	'deleted'     => __( 'The address is deleted from the list. Asked for again, it comes back.', 'simple-seo' ),
	'cleared'     => __( 'The list is empty.', 'simple-seo' ),
	'rule-failed' => __( 'The redirect rule could not be made.', 'simple-seo' ),
);
$simple_seo_pages = (int) ceil( $found['total'] / Simple_SEO_Monitor::PER_PAGE );
?>
<div class="wrap simple-seo-404-page">
	<h1><?php esc_html_e( 'Pages not found', 'simple-seo' ); ?></h1>
	<p>
		<?php
		/* translators: %s: number of days. */
		echo esc_html( sprintf( __( 'The addresses of this site that answered "Page not found" (404): how many times they were asked for, when, and where the last visitor came from. An address is kept for %s days after it was last asked for; IP addresses are not kept.', 'simple-seo' ), number_format_i18n( Simple_SEO_Monitor::DAYS ) ) );
		?>
	</p>

	<?php if ( ! $enabled ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'The monitor is off: new 404s are not counted.', 'simple-seo' ); ?>
				<?php if ( current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) : ?>
					<a href="<?php echo esc_url( Simple_SEO_Admin::url( 'monitor' ) ); ?>"><?php esc_html_e( 'Switch it on on the 404 tab of the settings', 'simple-seo' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $simple_seo_notes[ $done ] ) ) : ?>
		<div class="notice <?php echo 'rule-failed' === $done ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $simple_seo_notes[ $done ] ); ?></p></div>
	<?php endif; ?>

	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( Simple_SEO_Monitor::page_url( array( 'order' => $view['order'] ) ) ); ?>"<?php echo $view['hidden'] ? '' : ' class="current" aria-current="page"'; ?>><?php esc_html_e( 'Listed', 'simple-seo' ); ?></a> |</li>
		<li>
			<a href="<?php echo esc_url( Simple_SEO_Monitor::page_url( array( 'hidden' => true, 'order' => $view['order'] ) ) ); ?>"<?php echo $view['hidden'] ? ' class="current" aria-current="page"' : ''; ?>>
				<?php esc_html_e( 'Hidden', 'simple-seo' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $hidden ) ); ?>)</span>
			</a>
		</li>
	</ul>
	<form method="get" class="search-form simple-seo-404-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( Simple_SEO_Monitor::PAGE ); ?>">
		<?php if ( $view['hidden'] ) : ?>
			<input type="hidden" name="hidden" value="1">
		<?php endif; ?>
		<?php if ( 'last' === $view['order'] ) : ?>
			<input type="hidden" name="order" value="last">
		<?php endif; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="simple-seo-404-s"><?php esc_html_e( 'Search the addresses', 'simple-seo' ); ?></label>
			<input type="search" id="simple-seo-404-s" name="s" value="<?php echo esc_attr( $view['s'] ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search the addresses', 'simple-seo' ); ?></button>
		</p>
	</form>

	<p class="simple-seo-404-order">
		<?php esc_html_e( 'First:', 'simple-seo' ); ?>
		<?php if ( 'last' === $view['order'] ) : ?>
			<a href="<?php echo esc_url( Simple_SEO_Monitor::page_url( array( 's' => $view['s'], 'hidden' => $view['hidden'] ) ) ); ?>"><?php esc_html_e( 'asked for most', 'simple-seo' ); ?></a> · <strong><?php esc_html_e( 'asked for last', 'simple-seo' ); ?></strong>
		<?php else : ?>
			<strong><?php esc_html_e( 'asked for most', 'simple-seo' ); ?></strong> · <a href="<?php echo esc_url( Simple_SEO_Monitor::page_url( array( 's' => $view['s'], 'hidden' => $view['hidden'], 'order' => 'last' ) ) ); ?>"><?php esc_html_e( 'asked for last', 'simple-seo' ); ?></a>
		<?php endif; ?>
	</p>

	<?php if ( ! $found['rows'] ) : ?>
		<p><?php echo esc_html( '' !== $view['s'] ? __( 'No address has these words.', 'simple-seo' ) : ( $view['hidden'] ? __( 'No address is hidden.', 'simple-seo' ) : ( $hidden ? __( 'Every address is hidden.', 'simple-seo' ) : __( 'No page of this site has answered 404 yet.', 'simple-seo' ) ) ) ); ?></p>
	<?php else : ?>
		<?php if ( ! $theme ) : ?>
			<p class="description"><?php esc_html_e( 'Simple SEO makes no redirects: send an address elsewhere with the redirects of your theme or of a redirection plugin.', 'simple-seo' ); ?></p>
		<?php endif; ?>
		<table class="widefat striped simple-seo-404-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Address', 'simple-seo' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Times', 'simple-seo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'First', 'simple-seo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last', 'simple-seo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Came from', 'simple-seo' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'simple-seo' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $found['rows'] as $simple_seo_row ) :
					$simple_seo_rule = $rules[ Simple_SEO_Monitor::rule_url( $simple_seo_row['url'] ) ] ?? 0;
					?>
					<tr>
						<td><a href="<?php echo esc_url( home_url( $simple_seo_row['url'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( $simple_seo_row['url'] ) ); ?></a></td>
						<td class="num" data-label="<?php esc_attr_e( 'Times', 'simple-seo' ); ?>"><?php echo esc_html( number_format_i18n( $simple_seo_row['hits'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'First', 'simple-seo' ); ?>"><?php echo esc_html( $simple_seo_when( $simple_seo_row['first_seen'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Last', 'simple-seo' ); ?>"><?php echo esc_html( $simple_seo_when( $simple_seo_row['last_seen'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Came from', 'simple-seo' ); ?>">
							<?php if ( '' !== $simple_seo_row['referrer'] ) : ?>
								<a href="<?php echo esc_url( $simple_seo_row['referrer'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( rawurldecode( $simple_seo_row['referrer'] ) ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td class="simple-seo-404-actions">
							<?php
							if ( $simple_seo_rule ) {
								printf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $simple_seo_rule ) ), esc_html( 'publish' === get_post_status( $simple_seo_rule ) ? __( 'Open its redirect', 'simple-seo' ) : __( 'Finish its redirect', 'simple-seo' ) ) );
							} elseif ( Simple_SEO_Monitor::rules_available() ) {
								echo $simple_seo_form( 'redirect', $simple_seo_row['id'], __( 'Make a redirect', 'simple-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
							}
							echo $simple_seo_form( $simple_seo_row['hidden'] ? 'show' : 'hide', $simple_seo_row['id'], $simple_seo_row['hidden'] ? __( 'Show', 'simple-seo' ) : __( 'Hide', 'simple-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
							echo $simple_seo_form( 'delete', $simple_seo_row['id'], __( 'Delete', 'simple-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $simple_seo_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => str_replace( '999999999', '%#%', Simple_SEO_Monitor::page_url( array( 's' => $view['s'], 'hidden' => $view['hidden'], 'order' => $view['order'], 'paged' => 999999999 ) ) ),
								'format'  => '',
								'current' => $view['paged'],
								'total'   => $simple_seo_pages,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $found['rows'] || $hidden ) : ?>
		<?php
		$simple_seo_clear = $simple_seo_form( 'clear', 0, __( 'Clear the list', 'simple-seo' ), 'button' );
		echo str_replace( '<button ', '<button onclick="' . esc_attr( 'return window.confirm(' . wp_json_encode( __( 'Delete every address of the list, the hidden ones too?', 'simple-seo' ) ) . ')' ) . '" ', $simple_seo_clear ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		?>
	<?php endif; ?>
</div>
