<?php
/**
 * Wallet hub for affiliate finance (replaces batch settlements).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wallet_ready = Arp_Wallet_Bridge::available();
$legacy       = Arp_Commissions_Repo::payable_affiliates();
$legacy_count = array_sum( array_column( $legacy, 'count' ) );
$legacy_total = array_sum( array_column( $legacy, 'total' ) );
?>
<div class="arp-wallet-hub">
	<?php if ( ! $wallet_ready ) : ?>
		<div class="notice notice-error inline">
			<p>افزونه <strong>کیف پول تومان مدیار</strong> فعال نیست. پرداخت پورسانت فقط از طریق این افزونه انجام می‌شود؛ لطفاً آن را نصب و فعال کنید.</p>
		</div>
	<?php else : ?>
		<div class="notice notice-success inline">
			<p>
				<strong>پرداخت پورسانت فقط از طریق کیف پول است.</strong>
				با ورود معرفی به وضعیت پورسانت‌دار، مبلغ همان لحظه به کیف پول همکار واریز و «پرداخت‌شده» ثبت می‌شود.
				برداشت نقدی همکار از مسیر برداشت کیف پول انجام می‌شود — تسویه دسته‌ای بانکی در این پلاگین وجود ندارد.
			</p>
		</div>

		<div class="arp-summary" style="margin-bottom:16px;">
			<div class="arp-summary__item">
				<span class="arp-muted">تراکنش‌های پورسانت</span>
				<strong><a href="<?php echo esc_url( Arp_Wallet_Bridge::transactions_url( 'ARP-C-' ) ); ?>">مشاهده واریزها</a></strong>
			</div>
			<div class="arp-summary__item">
				<span class="arp-muted">برداشت همکاران</span>
				<strong><a href="<?php echo esc_url( Arp_Wallet_Bridge::withdrawals_url() ); ?>">صفحه برداشت‌ها</a></strong>
			</div>
			<div class="arp-summary__item">
				<span class="arp-muted">لیست پورسانت‌ها</span>
				<strong><a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'commissions' ) ) ); ?>">پورسانت‌ها</a></strong>
			</div>
		</div>

		<?php if ( $legacy_count > 0 ) : ?>
			<div class="postbox">
				<div class="postbox-header"><h2>مانده‌های قدیمی (قبل از کیف پول)</h2></div>
				<div class="inside">
					<p>
						<?php
						echo esc_html(
							sprintf(
								'%s پورسانت با وضعیت «در انتظار پرداخت» و جمع %s هنوز به کیف پول منتقل نشده‌اند.',
								Arp_Format::fa( number_format_i18n( $legacy_count ) ),
								Arp_Admin_Ui::money( $legacy_total )
							)
						);
						?>
					</p>
					<form method="post">
						<?php wp_nonce_field( 'arp_migrate_approved_wallet' ); ?>
						<?php
						submit_button(
							'واریز همه به کیف پول و علامت پرداخت‌شده',
							'primary',
							'arp_migrate_approved_wallet',
							false,
							array(
								'data-confirm' => 'همه پورسانت‌های در انتظار پرداخت به کیف پول واریز و پرداخت‌شده می‌شوند. ادامه می‌دهید؟',
							)
						);
						?>
					</form>
					<table class="widefat striped" style="margin-top:12px;">
						<thead>
							<tr><th>همکار</th><th>تعداد</th><th>مبلغ</th><th></th></tr>
						</thead>
						<tbody>
						<?php foreach ( $legacy as $uid => $row ) : ?>
							<tr>
								<td><?php echo esc_html( Arp_Admin_Ui::user_name( (int) $uid ) ); ?></td>
								<td><?php echo esc_html( Arp_Format::fa( (int) $row['count'] ) ); ?></td>
								<td><?php echo esc_html( Arp_Admin_Ui::money( $row['total'] ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( Arp_Wallet_Bridge::user_insights_url( (int) $uid ) ); ?>">پنل کیف پول</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php else : ?>
			<p class="arp-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ماندهٔ تسویه دسته‌ای وجود ندارد؛ همه پورسانت‌های جدید از مسیر کیف پول پرداخت می‌شوند.</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
