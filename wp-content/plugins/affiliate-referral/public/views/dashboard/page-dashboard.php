<?php
/**
 * Dashboard body. Site header + sidebar come from layout; this file is main column only.
 *
 * @var WP_User $user
 * @var string  $display_name  From layout.
 * @var string  $phone_display From layout.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats  = Arp_Affiliate_Stats::dashboard( (int) $user->ID );
$latest = Arp_Referrals_Repo::query(
	array(
		'affiliate_user_id' => (int) $user->ID,
		'limit'             => 5,
	)
);
$invite_code = Arp_Inviter::ensure_code( (int) $user->ID );
$invite_url  = Arp_Inviter::share_url( (int) $user->ID );
?>
<section class="arp-dash-body" dir="rtl">

	<!-- 1. Profile card -->
	<div class="arp-card arp-profile-card">
		<div class="arp-profile-card__avatar">
			<?php echo get_avatar( (int) $user->ID, 56 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="arp-profile-card__text">
			<strong class="arp-profile-card__name"><?php echo esc_html( $display_name ); ?></strong>
			<?php if ( $phone_display ) : ?>
				<span class="arp-profile-card__phone"><?php echo esc_html( $phone_display ); ?></span>
			<?php endif; ?>
		</div>
		<a class="arp-profile-card__cta" href="<?php echo esc_url( Arp_Pages::page_url( 'submit' ) ); ?>">ثبت معرفی بیمار</a>
	</div>

	<?php if ( '' !== $invite_url ) : ?>
		<div class="arp-card arp-invite-card">
			<strong class="arp-invite-card__title">لینک دعوت همکاران</strong>
			<p class="arp-invite-card__desc">این لینک را برای دعوت همکاران جدید به اشتراک بگذارید. کد معرف: <code dir="ltr"><?php echo esc_html( $invite_code ); ?></code></p>
			<?php include __DIR__ . '/partials/invite-url.php'; ?>
		</div>
	<?php endif; ?>

	<!-- 2. Metrics board -->
	<div class="arp-metrics" role="group" aria-label="شاخص‌های کلیدی">
		<div class="arp-metrics__finance">
			<div class="arp-metric arp-metric--hero">
				<span class="arp-metric__label"><?php echo Arp_Wallet_Bridge::available() ? 'موجودی قابل برداشت' : 'مبلغ قابل پرداخت'; ?></span>
				<div class="arp-metric__figure">
					<strong class="arp-metric__num"><?php echo esc_html( Arp_Format::money( $stats['payable'] ) ); ?></strong>
					<span class="arp-metric__unit">تومان</span>
				</div>
			</div>
			<div class="arp-metrics__vdiv" aria-hidden="true"></div>
			<div class="arp-metric arp-metric--money">
				<span class="arp-metric__label">پورسانت ماه جاری</span>
				<div class="arp-metric__figure">
					<strong class="arp-metric__num"><?php echo esc_html( Arp_Format::money( $stats['month_earned'] ) ); ?></strong>
					<span class="arp-metric__unit">تومان</span>
				</div>
			</div>
		</div>

		<div class="arp-metrics__hdiv" aria-hidden="true"></div>

		<div class="arp-metrics__refs">
			<div class="arp-metric arp-metric--stat">
				<span class="arp-metric__label">تعداد کل معرفی‌ها</span>
				<strong class="arp-metric__num"><?php echo esc_html( Arp_Format::fa( $stats['total'] ) ); ?></strong>
			</div>
			<div class="arp-metrics__vdiv arp-metrics__vdiv--soft" aria-hidden="true"></div>
			<div class="arp-metric arp-metric--stat arp-metric--progress">
				<span class="arp-metric__label">
					<span class="arp-metric__dot" aria-hidden="true"></span>
					در حال پیگیری
				</span>
				<strong class="arp-metric__num"><?php echo esc_html( Arp_Format::fa( $stats['open'] ) ); ?></strong>
			</div>
			<div class="arp-metrics__vdiv arp-metrics__vdiv--soft" aria-hidden="true"></div>
			<div class="arp-metric arp-metric--stat arp-metric--success">
				<span class="arp-metric__label">
					<span class="arp-metric__dot" aria-hidden="true"></span>
					موفق
				</span>
				<strong class="arp-metric__num"><?php echo esc_html( Arp_Format::fa( $stats['success'] ) ); ?></strong>
			</div>
		</div>
	</div>

	<!-- 3. Charts: status on the right, trend on the left (RTL) -->
	<div class="arp-charts-row">
		<div class="arp-card arp-chart-status">
			<div class="arp-card__head">
				<h3 class="arp-card__title">وضعیت معرفی‌ها</h3>
			</div>
			<div class="arp-donut-wrap">
				<ul class="arp-donut-legend">
					<?php foreach ( $stats['statuses'] as $item ) : ?>
						<li style="--arp-legend-color: <?php echo esc_attr( $item['color'] ); ?>">
							<span class="arp-legend-label"><?php echo esc_html( $item['label'] ); ?></span>
							<span class="arp-legend-count"><?php echo esc_html( Arp_Format::fa( $item['count'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="arp-donut-canvas">
					<canvas id="arp-chart-status" width="155" height="155" aria-label="نمودار وضعیت"></canvas>
					<div class="arp-donut-center">
						<strong><?php echo esc_html( Arp_Format::fa( $stats['total'] ) ); ?></strong>
						<span>کل موارد</span>
					</div>
				</div>
			</div>
		</div>

		<div class="arp-card arp-chart-trend">
			<div class="arp-card__head">
				<h3 class="arp-card__title">روند معرفی‌ها</h3>
			</div>
			<div class="arp-trend-canvas">
				<canvas id="arp-chart-trend" aria-label="نمودار روند معرفی‌ها"></canvas>
			</div>
		</div>
	</div>

	<!-- 4. Table -->
	<div class="arp-card arp-card--table">
		<div class="arp-card__head">
			<h3 class="arp-card__title">آخرین معرفی‌های ثبت‌شده</h3>
			<a class="arp-btn-outline" href="<?php echo esc_url( Arp_Pages::page_url( 'referrals' ) ); ?>">
				<span>مشاهده همه</span>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
			</a>
		</div>
		<div class="arp-table-wrap">
			<table class="arp-table">
				<thead>
					<tr>
						<th class="arp-table__num">ردیف</th>
						<th>نام بیمار</th>
						<th>تلفن بیمار</th>
						<th>خدمت موردنظر</th>
						<th>تاریخ ثبت</th>
						<th>وضعیت</th>
						<th class="arp-table__amount">پورسانت</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $latest ) : ?>
						<tr>
							<td colspan="7" class="arp-table__empty">هنوز معرفی‌ای ثبت نکرده‌اید.</td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $latest as $i => $row ) : ?>
						<tr>
							<td class="arp-table__num"><?php echo esc_html( Arp_Format::fa( $i + 1 ) ); ?></td>
							<td><?php echo esc_html( $row['patient_name'] ); ?></td>
							<td><?php echo esc_html( Arp_Format::fa( $row['patient_phone'] ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['service_name'] ?? '—' ) ); ?></td>
							<td><?php echo esc_html( Arp_Format::fa( Arp_Format::to_jalali( (string) $row['created_at'] ) ) ); ?></td>
							<td><span class="arp-status arp-status--dynamic" style="<?php echo esc_attr( Arp_Ui::status_style( $row ) ); ?>"><?php echo esc_html( (string) ( $row['status_name'] ?? '—' ) ); ?></span></td>
							<td class="arp-table__amount">
								<?php if ( null !== $row['commission_amount'] ) : ?>
									<strong><?php echo esc_html( Arp_Format::money( $row['commission_amount'] ) ); ?></strong><span>تومان</span>
								<?php else : ?>
									<span>—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

</section>

<script type="application/json" id="arp-dash-chart-data">
<?php
echo wp_json_encode(
	array(
		'trend'  => $stats['trend'],
		'status' => array(
			'labels' => array_column( $stats['statuses'], 'label' ),
			'values' => array_column( $stats['statuses'], 'count' ),
			'colors' => array_column( $stats['statuses'], 'color' ),
		),
	)
);
?>
</script>
