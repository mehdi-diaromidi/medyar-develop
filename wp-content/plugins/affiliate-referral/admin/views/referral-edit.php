<?php
/**
 * @var array<string,mixed>|null       $referral    Joined referral row.
 * @var array<int,array<string,mixed>> $statuses
 * @var array{price:?float,percentage:?float,amount:?float}|null $suggestion
 * @var array<int,array<string,mixed>> $commissions Newest first.
 * @var array<int,array<string,mixed>> $logs        Newest first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$back = Arp_Admin_Ui::url( 'arp-referrals' );

if ( ! $referral ) : ?>
	<div class="wrap">
		<h1>معرفی یافت نشد</h1>
		<p><a href="<?php echo esc_url( $back ); ?>">بازگشت به فهرست معرفی‌ها</a></p>
	</div>
	<?php
	return;
endif;

$aff_id     = (int) $referral['affiliate_user_id'];
$aff        = get_userdata( $aff_id );
$aff_mobile = $aff ? (string) get_user_meta( $aff_id, 'mobile', true ) : '';
$has_active = false;
foreach ( $commissions as $c ) {
	if ( 'reversed' !== $c['state'] ) {
		$has_active = true;
		break;
	}
}
$status_names = array_column( $statuses, 'name', 'id' );

$hint = 'برای این خدمت پورسانتی تعریف نشده؛ مبلغ نهایی را وارد کنید.';
if ( $suggestion && null !== $suggestion['amount'] ) {
	if ( null !== $suggestion['price'] && $suggestion['percentage'] ) {
		$hint = sprintf(
			'پیش‌فرض: %s٪ از مبلغ خدمت (%s) — قابل تغییر است.',
			Arp_Format::fa( $suggestion['percentage'] ),
			Arp_Admin_Ui::money( $suggestion['price'] )
		);
	} else {
		$hint = 'مبلغ پیش‌فرض پورسانت این خدمت — قابل تغییر است.';
	}
}
$default_amount = ( $suggestion && null !== $suggestion['amount'] ) ? number_format( (float) $suggestion['amount'] ) : '';
?>
<div class="wrap arp-wrap">
	<h1 class="wp-heading-inline">
		<?php echo esc_html( sprintf( 'معرفی #%s — %s', Arp_Format::fa( (int) $referral['id'] ), $referral['patient_name'] ) ); ?>
	</h1>
	<a href="<?php echo esc_url( $back ); ?>" class="page-title-action">بازگشت به فهرست</a>
	<hr class="wp-header-end" />
	<?php Arp_Admin_Ui::notices(); ?>

	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-2">
			<div id="post-body-content">
				<div class="postbox">
					<div class="postbox-header"><h2>اطلاعات معرفی</h2></div>
					<div class="inside">
						<table class="form-table arp-details" role="presentation">
							<tr><th>بیمار</th><td><strong><?php echo esc_html( $referral['patient_name'] ); ?></strong></td></tr>
							<tr>
								<th>تلفن</th>
								<td>
									<a href="tel:<?php echo esc_attr( $referral['patient_phone'] ); ?>" dir="ltr"><?php echo esc_html( Arp_Format::fa( $referral['patient_phone'] ) ); ?></a>
									<button type="button" class="button button-small arp-copy" data-copy="<?php echo esc_attr( $referral['patient_phone'] ); ?>">کپی</button>
								</td>
							</tr>
							<tr><th>خدمت</th><td><?php echo esc_html( (string) ( $referral['service_name'] ?? '—' ) ); ?></td></tr>
							<tr><th>وضعیت فعلی</th><td><?php echo Arp_Admin_Ui::status_badge( $referral ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td></tr>
							<tr>
								<th>تاریخ ثبت</th>
								<td><?php echo esc_html( Arp_Admin_Ui::date( $referral['created_at'], true ) ); ?> <span class="arp-muted">(<?php echo esc_html( Arp_Admin_Ui::ago( $referral['created_at'] ) ); ?>)</span></td>
							</tr>
							<tr><th>توضیحات همکار</th><td><?php echo '' !== (string) $referral['description'] ? nl2br( esc_html( $referral['description'] ) ) : '<span class="arp-muted">—</span>'; ?></td></tr>
						</table>
					</div>
				</div>

				<div class="postbox">
					<div class="postbox-header"><h2>تاریخچه پیگیری</h2></div>
					<div class="inside">
						<ol class="arp-timeline">
							<?php foreach ( $logs as $log ) : ?>
								<?php $moved = (int) $log['from_status_id'] !== (int) $log['to_status_id']; ?>
								<li class="<?php echo $moved ? 'is-move' : 'is-note'; ?>">
									<div class="arp-timeline__head">
										<strong><?php echo esc_html( Arp_Admin_Ui::user_name( (int) $log['user_id'] ) ); ?></strong>
										<?php if ( $moved ) : ?>
											وضعیت را از «<?php echo esc_html( $status_names[ $log['from_status_id'] ] ?? 'وضعیت حذف‌شده' ); ?>» به «<?php echo esc_html( $status_names[ $log['to_status_id'] ] ?? 'وضعیت حذف‌شده' ); ?>» تغییر داد
										<?php else : ?>
											یادداشت گذاشت
										<?php endif; ?>
										<span class="arp-muted"><?php echo esc_html( Arp_Admin_Ui::date( $log['created_at'], true ) ); ?></span>
									</div>
									<?php if ( '' !== (string) $log['note'] ) : ?>
										<div class="arp-timeline__note"><?php echo nl2br( esc_html( $log['note'] ) ); ?></div>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
							<li class="is-start">
								<div class="arp-timeline__head">
									<strong><?php echo esc_html( Arp_Admin_Ui::user_name( $aff_id ) ); ?></strong> معرفی را ثبت کرد
									<span class="arp-muted"><?php echo esc_html( Arp_Admin_Ui::date( $referral['created_at'], true ) ); ?></span>
								</div>
							</li>
						</ol>
					</div>
				</div>
			</div>

			<div id="postbox-container-1" class="postbox-container">
				<div class="postbox">
					<div class="postbox-header"><h2>پیگیری و تغییر وضعیت</h2></div>
					<div class="inside">
						<form method="post" class="arp-status-form">
							<?php wp_nonce_field( 'arp_update_referral' ); ?>
							<input type="hidden" name="referral_id" value="<?php echo esc_attr( (string) $referral['id'] ); ?>" />
							<p>
								<label for="status_id" class="arp-label">وضعیت</label>
								<select name="status_id" id="status_id" class="widefat" required>
									<?php foreach ( $statuses as $st ) : ?>
										<option value="<?php echo esc_attr( (string) $st['id'] ); ?>" <?php selected( (int) $referral['status_id'], (int) $st['id'] ); ?>
											data-commission="<?php echo esc_attr( ! empty( $st['triggers_commission'] ) && ! $has_active && (int) $referral['status_id'] !== (int) $st['id'] ? '1' : '0' ); ?>">
											<?php echo esc_html( $st['name'] . ( ! empty( $st['triggers_commission'] ) ? ' (پورسانت‌دار)' : '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="arp-amount-field" hidden>
								<label for="commission_amount" class="arp-label">مبلغ پورسانت (تومان)</label>
								<input type="text" name="commission_amount" id="commission_amount" value="<?php echo esc_attr( $default_amount ); ?>" class="widefat arp-money" inputmode="numeric" dir="ltr" autocomplete="off" />
								<span class="description"><?php echo esc_html( $hint ); ?></span>
							</p>
							<p>
								<label for="arp-note" class="arp-label">یادداشت پیگیری <span class="arp-muted">(اختیاری)</span></label>
								<textarea name="note" id="arp-note" rows="3" class="widefat" placeholder="مثلاً: تماس گرفته شد، بیمار فردا مراجعه می‌کند."></textarea>
							</p>
							<div class="arp-form-actions">
								<?php submit_button( 'ذخیره', 'primary', 'arp_update_referral', false ); ?>
							</div>
						</form>
					</div>
				</div>

				<div class="postbox">
					<div class="postbox-header"><h2>همکار معرف</h2></div>
					<div class="inside">
						<?php if ( $aff ) : ?>
							<div class="arp-user-cell">
								<?php echo get_avatar( $aff_id, 40 ); ?>
								<div>
									<strong><?php echo esc_html( $aff->display_name ); ?></strong>
									<span class="arp-muted">کد: <code><?php echo esc_html( Arp_Referral_Code::get_for_user( $aff_id ) ); ?></code></span>
								</div>
							</div>
							<ul class="arp-links">
								<?php if ( '' !== $aff_mobile ) : ?>
									<li><span class="dashicons dashicons-phone" aria-hidden="true"></span> <a href="tel:<?php echo esc_attr( $aff_mobile ); ?>" dir="ltr"><?php echo esc_html( Arp_Format::fa( $aff_mobile ) ); ?></a></li>
								<?php endif; ?>
								<li><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'affiliate_user_id' => $aff_id ) ) ); ?>">همه معرفی‌های این همکار</a></li>
								<li><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <a href="<?php echo esc_url( get_edit_user_link( $aff_id ) ); ?>">پروفایل کاربری</a></li>
							</ul>
						<?php else : ?>
							<p class="arp-muted">کاربر حذف شده است.</p>
						<?php endif; ?>
					</div>
				</div>

				<div class="postbox">
					<div class="postbox-header"><h2>پورسانت</h2></div>
					<div class="inside">
						<?php if ( ! $commissions ) : ?>
							<p class="arp-muted">هنوز پورسانتی برای این معرفی ثبت نشده است.</p>
						<?php else : ?>
							<ul class="arp-commission-list">
								<?php foreach ( $commissions as $c ) : ?>
									<li>
										<strong><?php echo esc_html( Arp_Admin_Ui::money( $c['amount'] ) ); ?></strong>
										<?php echo Arp_Admin_Ui::commission_badge( (string) $c['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
										<span class="arp-muted">
											<?php echo esc_html( Arp_Admin_Ui::date( $c['created_at'] ) ); ?>
											<?php if ( ! empty( $c['tracking_code'] ) ) : ?>
												— کد پیگیری <code><?php echo esc_html( $c['tracking_code'] ); ?></code>
											<?php endif; ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<br class="clear" />
	</div>
</div>
