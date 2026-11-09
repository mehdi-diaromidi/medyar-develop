<?php
/**
 * @var Arp_Applications_Table $table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table->prepare_items();
$flash = isset( $_GET['arp_notice'] ) ? sanitize_key( wp_unslash( $_GET['arp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$flash_map = array(
	'approved'     => array( 'updated', 'درخواست تأیید شد و پیامک برای متقاضی ارسال شد.' ),
	'rejected'     => array( 'updated', 'درخواست رد شد و پیامک برای متقاضی ارسال شد.' ),
	'approve_fail' => array( 'error', 'تأیید انجام نشد. وضعیت درخواست را بررسی کنید.' ),
	'reject_fail'  => array( 'error', 'رد انجام نشد. وضعیت درخواست را بررسی کنید.' ),
);
?>
<div class="wrap arp-wrap">
	<h1 class="wp-heading-inline">درخواست‌های طرح همکاران</h1>
	<hr class="wp-header-end" />
	<?php if ( isset( $flash_map[ $flash ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash_map[ $flash ][0] ); ?> is-dismissible"><p><?php echo esc_html( $flash_map[ $flash ][1] ); ?></p></div>
	<?php endif; ?>
	<?php Arp_Admin_Ui::notices(); ?>

	<p class="description">متقاضیانی که فرم عضویت را تکمیل کرده‌اند و منتظر تأیید دسترسی به پنل هستند.</p>

	<form method="get">
		<input type="hidden" name="page" value="arp-applications" />
		<?php $table->display(); ?>
	</form>
</div>
