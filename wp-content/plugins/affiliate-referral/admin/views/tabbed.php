<?php
/**
 * Tabbed screen shell; the tab body lives in views/{$tab_view}.php.
 *
 * @var string               $page_slug
 * @var string               $title
 * @var array<string,string> $tabs
 * @var string               $tab
 * @var string               $tab_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap arp-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
	<hr class="wp-header-end" />
	<?php Arp_Admin_Ui::notices(); ?>
	<?php Arp_Admin_Ui::tabs( $page_slug, $tabs, $tab ); ?>
	<div class="arp-tab-body">
		<?php include ARP_PLUGIN_DIR . 'admin/views/' . $tab_view . '.php'; ?>
	</div>
</div>
