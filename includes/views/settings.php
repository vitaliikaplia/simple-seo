<?php
/**
 * Settings page: the tabs in groups that fold in a menu on the left (a list to choose from on a
 * phone), the sub-tabs of the current tab on top, and its form on a white panel. Each column has
 * its content sticky in it (assets/js/admin.js).
 *
 * @var array<string, array{label: string, options: string[], render: callable, order: int, group: string, sections: callable|null}> $tabs
 * @var string                                                                                                                        $current
 * @var array<string, string>                                                                                                         $groups   Group => label.
 * @var string[]                                                                                                                      $closed   Groups this user has folded.
 * @var array<string, string>                                                                                                         $sections Sub-tabs of the current tab, id => label.
 * @var string                                                                                                                        $section  The sub-tab shown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_grouped = array();
foreach ( $tabs as $simple_seo_tab => $simple_seo_tab_args ) {
	$simple_seo_grouped[ $simple_seo_tab_args['group'] ][ $simple_seo_tab ] = $simple_seo_tab_args['label'];
}
?>
<div class="wrap simple-seo-settings">
	<h1><?php esc_html_e( 'Simple SEO', 'simple-seo' ); ?></h1>

	<?php settings_errors(); ?>

	<div class="simple-seo-layout">
		<?php if ( count( $tabs ) > 1 ) : ?>
			<div class="simple-seo-column simple-seo-column-menu">
				<nav class="simple-seo-sidebar" aria-label="<?php esc_attr_e( 'Settings of Simple SEO', 'simple-seo' ); ?>" data-simple-seo-sticky>
					<?php foreach ( $groups as $simple_seo_group => $simple_seo_group_label ) : ?>
						<?php
						if ( empty( $simple_seo_grouped[ $simple_seo_group ] ) ) {
							continue;
						}
						// The group of the tab shown is always open.
						$simple_seo_here = isset( $simple_seo_grouped[ $simple_seo_group ][ $current ] );
						?>
						<details class="simple-seo-sidebar-section<?php echo $simple_seo_here ? ' is-current' : ''; ?>" data-simple-seo-group="<?php echo esc_attr( $simple_seo_group ); ?>"<?php echo $simple_seo_here || ! in_array( $simple_seo_group, $closed, true ) ? ' open' : ''; ?>>
							<summary><h2 class="simple-seo-sidebar-group"><span class="dashicons <?php echo esc_attr( Simple_SEO_Admin::GROUP_ICONS[ $simple_seo_group ] ?? 'dashicons-admin-generic' ); ?>" aria-hidden="true"></span><?php echo esc_html( $simple_seo_group_label ); ?></h2></summary>
							<ul>
								<?php foreach ( $simple_seo_grouped[ $simple_seo_group ] as $simple_seo_tab => $simple_seo_tab_label ) : ?>
									<li><a href="<?php echo esc_url( Simple_SEO_Admin::url( $simple_seo_tab ) ); ?>"<?php echo $simple_seo_tab === $current ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html( $simple_seo_tab_label ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endforeach; ?>
				</nav>
			</div>

			<form class="simple-seo-jump" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Simple_SEO_Admin::PAGE ); ?>">
				<label for="simple-seo-jump" class="screen-reader-text"><?php esc_html_e( 'Settings of Simple SEO', 'simple-seo' ); ?></label>
				<select id="simple-seo-jump" name="tab" data-simple-seo-jump>
					<?php foreach ( $groups as $simple_seo_group => $simple_seo_group_label ) : ?>
						<?php
						if ( empty( $simple_seo_grouped[ $simple_seo_group ] ) ) {
							continue;
						}
						?>
						<optgroup label="<?php echo esc_attr( $simple_seo_group_label ); ?>">
							<?php foreach ( $simple_seo_grouped[ $simple_seo_group ] as $simple_seo_tab => $simple_seo_tab_label ) : ?>
								<option value="<?php echo esc_attr( $simple_seo_tab ); ?>" <?php selected( $current, $simple_seo_tab ); ?>><?php echo esc_html( $simple_seo_tab_label ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button" data-simple-seo-jump-go><?php esc_html_e( 'Open', 'simple-seo' ); ?></button>
			</form>
		<?php endif; ?>

		<div class="simple-seo-column simple-seo-column-main">
			<div class="simple-seo-main" data-simple-seo-sticky>
				<?php if ( isset( $tabs[ $current ] ) ) : ?>
					<h2 class="simple-seo-main-title"><?php echo esc_html( $tabs[ $current ]['label'] ); ?></h2>

					<?php if ( $sections ) : ?>
						<nav class="nav-tab-wrapper simple-seo-subtabs" aria-label="<?php echo esc_attr( $tabs[ $current ]['label'] ); ?>">
							<?php foreach ( $sections as $simple_seo_id => $simple_seo_label ) : ?>
								<a href="<?php echo esc_url( Simple_SEO_Admin::url( $current, $simple_seo_id ) ); ?>" class="nav-tab<?php echo $simple_seo_id === $section ? ' nav-tab-active' : ''; ?>" data-simple-seo-subtab="<?php echo esc_attr( $simple_seo_id ); ?>"<?php echo $simple_seo_id === $section ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $simple_seo_label ); ?></a>
							<?php endforeach; ?>
						</nav>
					<?php endif; ?>

					<?php if ( ! $tabs[ $current ]['options'] ) : ?>
						<div class="simple-seo-panel<?php echo $sections ? ' has-subtabs' : ''; ?>">
							<?php
							// A tab without option rows prints its own forms.
							call_user_func( $tabs[ $current ]['render'] );
							?>
						</div>
					<?php else : ?>
						<form method="post" action="options.php">
							<div class="simple-seo-panel<?php echo $sections ? ' has-subtabs' : ''; ?>">
								<?php
								settings_fields( Simple_SEO_Admin::group( $current ) );
								call_user_func( $tabs[ $current ]['render'] );
								submit_button();
								?>
							</div>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
