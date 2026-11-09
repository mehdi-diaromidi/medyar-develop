<?php
/**
 * @var int                              $affiliate_count
 * @var int                              $new_affiliates
 * @var int                              $today_count
 * @var int                              $month_count
 * @var int                              $total_count
 * @var int                              $pending_count
 * @var array<string,mixed>|null         $default_status
 * @var array<int,array>                 $payable        user_id => [total, count]
 * @var float                            $paid_month
 * @var array<int,array<string,mixed>>   $statuses
 * @var array<int,int>                   $status_counts
 * @var array<int,array<string,mixed>>   $waiting
 * @var array<int,array<string,mixed>>   $recent
 * @var array<int,int>                   $top_affiliates user_id => count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

cache_users(
	array_merge(
		array_column( $waiting, 'affiliate_user_id' ),
		array_column( $recent, 'affiliate_user_id' ),
		array_keys( $top_affiliates )
	)
);

$status_max  = max( 1, $status_counts ? max( $status_counts ) : 0 );
$fa_n        = static function ( $n ): string {
	return Arp_Format::fa( number_format_i18n( (float) $n ) );
};
$waiting_url = $default_status ? Arp_Admin_Ui::url( 'arp-referrals', array( 'status_id' => (int) $default_status['id'] ) ) : Arp_Admin_Ui::url( 'arp-referrals' );
$wallet_url  = Arp_Wallet_Bridge::available()
	? Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'wallet' ) )
	: Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'commissions' ) );

$kpis = array(
	array(
		'icon'  => 'dashicons-clipboard',
		'label' => 'معرفی‌های امروز',
		'value' => $fa_n( $today_count ),
		'meta'  => 'این ماه: ' . $fa_n( $month_count ) . ' — کل: ' . $fa_n( $total_count ),
		'url'   => Arp_Admin_Ui::url( 'arp-referrals' ),
		'tone'  => 'blue',
	),
	array(
		'icon'  => 'dashicons-phone',
		'label' => $default_status ? (string) $default_status['name'] : 'در انتظار تماس',
		'value' => $fa_n( $pending_count ),
		'meta'  => $pending_count ? 'نیازمند پیگیری کارشناس' : 'موردی در صف نیست',
		'url'   => $waiting_url,
		'tone'  => $pending_count ? 'orange' : 'green',
	),
	array(
		'icon'  => 'dashicons-money-alt',
		'label' => 'پورسانت پرداخت‌شده این ماه',
		'value' => Arp_Format::money( $paid_month ),
		'meta'  => 'واریز مستقیم به کیف پول همکار',
		'url'   => $wallet_url,
		'tone'  => 'teal',
		'unit'  => 'تومان',
	),
	array(
		'icon'  => 'dashicons-groups',
		'label' => 'همکاران',
		'value' => $fa_n( $affiliate_count ),
		'meta'  => 'عضو جدید این ماه: ' . $fa_n( $new_affiliates ),
		'url'   => Arp_Admin_Ui::url( 'arp-affiliates' ),
		'tone'  => 'purple',
	),
);
?>
<div class="wrap arp-wrap">
	<h1 class="wp-heading-inline">پیشخوان همکاران مدیار</h1>
	<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals' ) ); ?>" class="page-title-action">همه معرفی‌ها</a>
	<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-invited' ) ); ?>" class="page-title-action">دعوت‌کننده‌ها و پورسانت دعوت</a>
	<hr class="wp-header-end" />
	<?php Arp_Admin_Ui::notices(); ?>

	<div class="arp-kpis">
		<?php foreach ( $kpis as $kpi ) : ?>
			<a class="arp-kpi arp-kpi--<?php echo esc_attr( $kpi['tone'] ); ?>" href="<?php echo esc_url( $kpi['url'] ); ?>">
				<span class="arp-kpi__icon dashicons <?php echo esc_attr( $kpi['icon'] ); ?>" aria-hidden="true"></span>
				<span class="arp-kpi__body">
					<span class="arp-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
					<span class="arp-kpi__value"><?php echo esc_html( $kpi['value'] ); ?><?php if ( ! empty( $kpi['unit'] ) ) : ?> <small><?php echo esc_html( $kpi['unit'] ); ?></small><?php endif; ?></span>
					<span class="arp-kpi__meta"><?php echo esc_html( $kpi['meta'] ); ?></span>
				</span>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="arp-grid">
		<div class="postbox arp-box">
			<div class="postbox-header">
				<h2>صف تماس <span class="arp-muted">(قدیمی‌ترین‌ها)</span></h2>
				<a class="arp-box__link" href="<?php echo esc_url( $waiting_url ); ?>">مشاهده همه</a>
			</div>
			<div class="inside">
				<?php if ( ! $waiting ) : ?>
					<p class="arp-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> همه معرفی‌ها پیگیری شده‌اند.</p>
				<?php else : ?>
					<table class="widefat striped arp-compact">
						<thead><tr><th>بیمار</th><th>خدمت</th><th>همکار</th><th>انتظار</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $waiting as $r ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $r['patient_name'] ); ?></strong><br />
									<a href="tel:<?php echo esc_attr( $r['patient_phone'] ); ?>" dir="ltr"><?php echo esc_html( Arp_Format::fa( $r['patient_phone'] ) ); ?></a>
								</td>
								<td><?php echo esc_html( (string) ( $r['service_name'] ?? '—' ) ); ?></td>
								<td><?php echo esc_html( Arp_Admin_Ui::user_name( (int) $r['affiliate_user_id'] ) ); ?></td>
								<td><span class="arp-muted"><?php echo esc_html( Arp_Admin_Ui::ago( $r['created_at'] ) ); ?></span></td>
								<td><a class="button button-small" href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => (int) $r['id'] ) ) ); ?>">پیگیری</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox arp-box">
			<div class="postbox-header"><h2>وضعیت معرفی‌ها</h2></div>
			<div class="inside">
				<ul class="arp-bars">
					<?php foreach ( $statuses as $st ) : ?>
						<?php
						$n     = $status_counts[ (int) $st['id'] ] ?? 0;
						$color = Arp_Admin_Ui::status_colors()[ $st['slug'] ] ?? '#64748B';
						?>
						<li>
							<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'status_id' => (int) $st['id'] ) ) ); ?>">
								<span class="arp-bars__label"><?php echo esc_html( $st['name'] ); ?></span>
								<span class="arp-bars__track"><span class="arp-bars__fill" style="width:<?php echo esc_attr( (string) round( $n / $status_max * 100, 1 ) ); ?>%;background:<?php echo esc_attr( $color ); ?>"></span></span>
								<span class="arp-bars__value"><?php echo esc_html( $fa_n( $n ) ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<div class="postbox arp-box">
			<div class="postbox-header">
				<h2>آخرین معرفی‌ها</h2>
				<a class="arp-box__link" href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals' ) ); ?>">مشاهده همه</a>
			</div>
			<div class="inside">
				<?php if ( ! $recent ) : ?>
					<p class="arp-empty">هنوز معرفی‌ای ثبت نشده است.</p>
				<?php else : ?>
					<table class="widefat striped arp-compact">
						<thead><tr><th>بیمار</th><th>همکار</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
						<tbody>
						<?php foreach ( $recent as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => (int) $r['id'] ) ) ); ?>"><?php echo esc_html( $r['patient_name'] ); ?></a></td>
								<td><?php echo esc_html( Arp_Admin_Ui::user_name( (int) $r['affiliate_user_id'] ) ); ?></td>
								<td><?php echo Arp_Admin_Ui::status_badge( $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td>
								<td><?php echo esc_html( Arp_Admin_Ui::date( $r['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox arp-box">
			<div class="postbox-header"><h2>همکاران</h2></div>
			<div class="inside">
				<h3 class="arp-subhead">فعال‌ترین‌های این ماه</h3>
				<?php if ( ! $top_affiliates ) : ?>
					<p class="arp-empty">این ماه معرفی‌ای ثبت نشده است.</p>
				<?php else : ?>
					<ol class="arp-rank">
						<?php foreach ( $top_affiliates as $uid => $n ) : ?>
							<li>
								<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'affiliate_user_id' => $uid ) ) ); ?>"><?php echo esc_html( Arp_Admin_Ui::user_name( $uid ) ); ?></a>
								<span class="arp-muted"><?php echo esc_html( $fa_n( $n ) ); ?> معرفی</span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>

				<h3 class="arp-subhead">کیف پول</h3>
				<p class="description" style="margin-top:0;">
					پرداخت پورسانت فقط با واریز به کیف پول انجام می‌شود.
					<?php if ( Arp_Wallet_Bridge::available() ) : ?>
						<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'wallet' ) ) ); ?>">مرکز کیف پول همکاران</a>
						·
						<a href="<?php echo esc_url( Arp_Wallet_Bridge::withdrawals_url() ); ?>">برداشت‌ها</a>
					<?php else : ?>
						افزونه کیف پول فعال نیست.
					<?php endif; ?>
				</p>
			</div>
		</div>
	</div>
</div>
