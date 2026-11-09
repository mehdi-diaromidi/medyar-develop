<?php
/**
 * Inviters with invitee gain + per-inviter commission-bearing referral detail.
 *
 * @var Arp_Invited_Affiliates_Table $table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$inviter_id = isset( $_GET['inviter_user_id'] ) ? absint( $_GET['inviter_user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$inviter    = $inviter_id > 0 ? get_userdata( $inviter_id ) : false;
?>
<div class="wrap arp-wrap">
	<?php if ( $inviter ) : ?>
		<?php
		$breakdown = Arp_Inviter::commission_breakdown( $inviter_id, 500 );
		$invitees  = Arp_Inviter::referred_user_ids( $inviter_id );
		cache_users( $invitees );
		$paid_sum    = 0.0;
		$payable_sum = 0.0;
		foreach ( $breakdown['by_affiliate'] as $t ) {
			$paid_sum    += (float) $t['paid'];
			$payable_sum += (float) $t['payable'];
		}
		$own_code = Arp_Inviter::code_for_user( $inviter_id );
		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( sprintf( 'پورسانت دعوت — %s', $inviter->display_name ) ); ?></h1>
		<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-invited' ) ); ?>" class="page-title-action">همه دعوت‌کننده‌ها</a>
		<a href="<?php echo esc_url( get_edit_user_link( $inviter_id ) ); ?>" class="page-title-action">ویرایش کاربر</a>
		<hr class="wp-header-end" />
		<?php Arp_Admin_Ui::notices(); ?>

		<?php if ( '' !== $own_code ) : ?>
			<p class="description">کد دعوت: <code dir="ltr"><?php echo esc_html( $own_code ); ?></code></p>
		<?php endif; ?>

		<div class="arp-summary">
			<div class="arp-summary__item">
				<span class="arp-muted">همکاران دعوت‌شده</span>
				<strong><?php echo esc_html( Arp_Format::fa( number_format_i18n( $breakdown['invitee_count'] ) ) ); ?></strong>
				<a class="arp-muted" href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-affiliates', array( 'inviter_user_id' => $inviter_id ) ) ); ?>">مشاهده در لیست همکاران</a>
			</div>
			<div class="arp-summary__item">
				<span class="arp-muted">جمع پورسانت (gain)</span>
				<strong><?php echo esc_html( Arp_Admin_Ui::money( $breakdown['amount_total'] ) ); ?></strong>
				<span class="arp-muted"><?php echo esc_html( Arp_Format::fa( number_format_i18n( $breakdown['commission_count'] ) ) ); ?> ردیف پورسانت‌دار</span>
			</div>
			<div class="arp-summary__item">
				<?php echo Arp_Admin_Ui::commission_badge( 'approved' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
				<strong><?php echo esc_html( Arp_Admin_Ui::money( $payable_sum ) ); ?></strong>
			</div>
			<div class="arp-summary__item">
				<?php echo Arp_Admin_Ui::commission_badge( 'paid' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
				<strong><?php echo esc_html( Arp_Admin_Ui::money( $paid_sum ) ); ?></strong>
			</div>
		</div>

		<h2>معرفی‌های پورسانت‌دار همکاران دعوت‌شده</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>همکار دعوت‌شده</th>
					<th>معرفی / بیمار</th>
					<th>خدمت</th>
					<th>مبلغ پورسانت</th>
					<th>وضعیت پورسانت</th>
					<th>تاریخ</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $breakdown['rows'] ) ) : ?>
					<tr><td colspan="6">هنوز پورسانت تأیید/پرداخت‌شده‌ای برای همکاران دعوت‌شده این کاربر ثبت نشده است.</td></tr>
				<?php else : ?>
					<?php foreach ( $breakdown['rows'] as $row ) : ?>
						<?php
						$aff_id = (int) ( $row['affiliate_user_id'] ?? 0 );
						$aff    = $aff_id ? get_userdata( $aff_id ) : false;
						$ref_id = (int) ( $row['referral_id'] ?? 0 );
						?>
						<tr>
							<td>
								<?php if ( $aff ) : ?>
									<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'affiliate_user_id' => $aff_id ) ) ); ?>"><?php echo esc_html( $aff->display_name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( '#' . $aff_id ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $ref_id > 0 ) : ?>
									<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => $ref_id ) ) ); ?>"><?php echo esc_html( (string) ( $row['patient_name'] ?? '—' ) ); ?></a>
									<span class="arp-muted">#<?php echo esc_html( Arp_Format::fa( (string) $ref_id ) ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $row['service_name'] ?? '—' ) ); ?></td>
							<td><?php echo esc_html( Arp_Admin_Ui::money( (float) ( $row['amount'] ?? 0 ) ) ); ?></td>
							<td><?php echo Arp_Admin_Ui::commission_badge( (string) ( $row['state'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td>
							<td><?php echo esc_html( Arp_Admin_Ui::date( (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<h2>همکاران دعوت‌شده</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>همکار</th>
					<th>تأییدشده (پرداخت‌نشده)</th>
					<th>پرداخت‌شده</th>
					<th>جمع</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $invitees ) ) : ?>
					<tr><td colspan="4">همکاری با کد این کاربر ثبت‌نام نکرده است.</td></tr>
				<?php else : ?>
					<?php foreach ( $invitees as $aid ) : ?>
						<?php
						$aid = (int) $aid;
						$u   = get_userdata( $aid );
						$t   = $breakdown['by_affiliate'][ $aid ] ?? array( 'payable' => 0.0, 'paid' => 0.0, 'earned' => 0.0 );
						?>
						<tr>
							<td>
								<?php if ( $u ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( $aid ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( '#' . $aid ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Arp_Admin_Ui::money( $t['payable'] ) ); ?></td>
							<td><?php echo esc_html( Arp_Admin_Ui::money( $t['paid'] ) ); ?></td>
							<td><strong><?php echo esc_html( Arp_Admin_Ui::money( $t['earned'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	<?php else : ?>
		<?php $table->prepare_items(); ?>
		<h1 class="wp-heading-inline">دعوت‌کننده‌ها و پورسانت دعوت</h1>
		<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-affiliates' ) ); ?>" class="page-title-action">لیست همکاران</a>
		<hr class="wp-header-end" />
		<?php Arp_Admin_Ui::notices(); ?>
		<p class="description">
			هر ردیف یک دعوت‌کننده است با جمع پورسانت تأیید/پرداخت‌شدهٔ همکاران دعوت‌شده‌اش. برای جزئیات هر معرفی، روی نام کلیک کنید.
			همکاران دعوت‌شده با ستون «معرف» در لیست همکاران مشخص‌اند.
		</p>
		<form method="get">
			<input type="hidden" name="page" value="arp-invited" />
			<?php $table->display(); ?>
		</form>
	<?php endif; ?>
</div>
