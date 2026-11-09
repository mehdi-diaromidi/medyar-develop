<?php
/**
 * Invite URL field with copy + share actions.
 *
 * @var string $invite_url
 * @var string $invite_code Optional.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$invite_url  = isset( $invite_url ) ? (string) $invite_url : '';
$invite_code = isset( $invite_code ) ? (string) $invite_code : '';
if ( '' === $invite_url ) {
	return;
}
$share_title = 'دعوت به طرح همکاران مدیار';
$share_text  = '' !== $invite_code
	? sprintf( 'با کد معرف %s به طرح همکاران مدیار بپیوندید:', $invite_code )
	: 'به طرح همکاران مدیار بپیوندید:';
?>
<div class="arp-invite-card__url-row">
	<input type="text"
		class="arp-invite-card__url"
		dir="ltr"
		readonly
		value="<?php echo esc_attr( $invite_url ); ?>"
		onclick="this.select();"
		aria-label="<?php echo esc_attr( 'لینک دعوت' ); ?>"
	/>
	<div class="arp-invite-card__actions">
		<div class="arp-invite-card__action">
			<button type="button"
				class="arp-invite-card__btn arp-copy"
				data-copy="<?php echo esc_attr( $invite_url ); ?>"
				aria-label="<?php echo esc_attr( 'کپی لینک' ); ?>"
				title="<?php echo esc_attr( 'کپی لینک' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
			</button>
			<span class="arp-invite-card__tip" hidden aria-live="polite"></span>
		</div>
		<div class="arp-invite-card__action">
			<button type="button"
				class="arp-invite-card__btn arp-share"
				data-share-url="<?php echo esc_attr( $invite_url ); ?>"
				data-share-title="<?php echo esc_attr( $share_title ); ?>"
				data-share-text="<?php echo esc_attr( $share_text ); ?>"
				aria-label="<?php echo esc_attr( 'اشتراک‌گذاری' ); ?>"
				title="<?php echo esc_attr( 'اشتراک‌گذاری' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>
			</button>
			<span class="arp-invite-card__tip" hidden aria-live="polite"></span>
		</div>
	</div>
</div>
