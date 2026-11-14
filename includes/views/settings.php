<?php
/**
 * Settings page: tabs and the form of the current tab.
 *
 * @var array<string, array{label: string, options: string[], render: callable, order: int}> $tabs
 * @var string                                                                                $current
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Simple SEO', 'simple-seo' ); ?></h1>

	<?php if ( count( $tabs ) > 1 ) : ?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $simple_seo_tab => $simple_seo_tab_args ) : ?>
				<a href="<?php echo esc_url( Simple_SEO_Admin::url( $simple_seo_tab ) ); ?>" class="nav-tab<?php echo $simple_seo_tab === $current ? ' nav-tab-active' : ''; ?>"<?php echo $simple_seo_tab === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $simple_seo_tab_args['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php settings_errors(); ?>

	<?php if ( isset( $tabs[ $current ] ) ) : ?>
		<form method="post" action="options.php">
			<?php
			settings_fields( Simple_SEO_Admin::group( $current ) );
			call_user_func( $tabs[ $current ]['render'] );
			submit_button();
			?>
		</form>
	<?php endif; ?>
</div>
