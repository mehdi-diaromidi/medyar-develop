<?php
/**
 * @var Arp_Referrals_Table $table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table->prepare_items();
$search = Arp_Referrals_Table::filters_from_request()['search'];
?>
<div class="wrap arp-wrap">
	<h1 class="wp-heading-inline">معرفی‌ها</h1>
	<?php if ( '' !== $search ) : ?>
		<span class="subtitle">نتایج جستجو برای «<?php echo esc_html( $search ); ?>»</span>
	<?php endif; ?>
	<hr class="wp-header-end" />
	<?php Arp_Admin_Ui::notices(); ?>

	<?php $table->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="arp-referrals" />
		<?php
		$table->search_box( 'جستجوی بیمار', 'arp-referral' );
		$table->display();
		?>
	</form>
</div>
