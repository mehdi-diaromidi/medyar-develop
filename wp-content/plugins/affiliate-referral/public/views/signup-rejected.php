<?php
/**
 * Shown when an affiliate application was rejected; form below allows re-apply.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$note = (string) get_user_meta( get_current_user_id(), Arp_Affiliate_Applications::META_REJECT_NOTE, true );
?>
<section class="arp-submit arp-signup arp-signup--rejected" dir="rtl" style="margin-bottom:1.5rem">
	<div class="arp-notice arp-notice--error" role="alert">
		درخواست قبلی طرح همکاران شما رد شده است.
		<?php if ( '' !== trim( $note ) ) : ?>
			<br /><strong>توضیح:</strong> <?php echo esc_html( $note ); ?>
		<?php endif; ?>
		<br />در صورت تمایل می‌توانید دوباره فرم زیر را تکمیل و ارسال کنید.
	</div>
</section>
