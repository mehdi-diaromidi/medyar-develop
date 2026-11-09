<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var array                $settings
 * @var array                $vars
 * @var string               $tab
 * @var array<string,string> $tabs
 * @var array|null           $income_report
 */
?>
<div class="wrap arp-wrap mmad-admin-reserve-sms">
	<h1 class="wp-heading-inline"><?php echo esc_html( $tabs[ $tab ] ?? 'تنظیمات سفارشی رزرو' ); ?></h1>
	<hr class="wp-header-end" />
	<?php settings_errors(); ?>

	<div class="arp-tab-body">
		<?php
		if ( $tab === 'medyar-income' ) {
			include MMAD_PLUGIN_DIR . 'includes/admin-page-reserve-income.php';
		} else {
			include MMAD_PLUGIN_DIR . 'includes/admin-page-reserve-sms-tab.php';
		}
		?>
	</div>
</div>
