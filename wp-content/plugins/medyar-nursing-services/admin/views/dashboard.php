<?php
/**
 * Live admin dashboard (ARP-standard).
 *
 * @package medyar-nursing-services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats = MNS_Admin_Stats::bubbles();
$status_counts = MNS_Admin_Stats::count_by_status();
$waiting = MNS_Admin_Stats::unassigned( 6 );
$recent  = MNS_Admin_Stats::recent_orders( 6 );
$pending_nurses = MNS_Admin_Stats::pending_nurses( 6 );
$status_max = max( 1, $status_counts ? max( $status_counts ) : 0 );

$kpis = array(
	array(
		'icon'  => 'dashicons-clipboard',
		'label' => 'سفارش‌های امروز',
		'value' => MNS_Admin_Ui::fa_n( $stats['today'] ),
		'meta'  => 'در حال انجام: ' . MNS_Admin_Ui::fa_n( $stats['in_progress'] ),
		'url'   => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR ),
		'tone'  => 'blue',
	),
	array(
		'icon'  => 'dashicons-warning',
		'label' => 'بدون تخصیص',
		'value' => MNS_Admin_Ui::fa_n( $stats['unassigned'] ),
		'meta'  => $stats['unassigned'] ? 'نیازمند پیگیری' : 'صف خالی است',
		'url'   => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_ASSIGN ),
		'tone'  => $stats['unassigned'] ? 'orange' : 'green',
	),
	array(
		'icon'  => 'dashicons-flag',
		'label' => 'نیازمند بررسی',
		'value' => MNS_Admin_Ui::fa_n( $stats['needs_review'] ),
		'meta'  => 'رصد مرکزی',
		'url'   => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR, array( 'status' => 'needs_review' ) ),
		'tone'  => 'teal',
	),
	array(
		'icon'  => 'dashicons-groups',
		'label' => 'عضویت در انتظار',
		'value' => MNS_Admin_Ui::fa_n( $stats['nurses_pending'] ),
		'meta'  => 'صف تأیید پرستار',
		'url'   => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_NURSES, array( 'mns_status' => 'pending' ) ),
		'tone'  => 'purple',
	),
);

MNS_Admin_Ui::header(
	'پیشخوان خدمات پرستاری',
	array(
		array( 'label' => 'رصد مرکزی', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR ) ),
		array( 'label' => 'سفارش تلفنی', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MANUAL ) ),
	)
);
?>
	<div class="mns-kpis">
		<?php foreach ( $kpis as $kpi ) : ?>
			<a class="mns-kpi mns-kpi--<?php echo esc_attr( $kpi['tone'] ); ?>" href="<?php echo esc_url( $kpi['url'] ); ?>">
				<span class="mns-kpi__icon dashicons <?php echo esc_attr( $kpi['icon'] ); ?>" aria-hidden="true"></span>
				<span class="mns-kpi__body">
					<span class="mns-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
					<span class="mns-kpi__value"><?php echo esc_html( $kpi['value'] ); ?></span>
					<span class="mns-kpi__meta"><?php echo esc_html( $kpi['meta'] ); ?></span>
				</span>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="mns-grid">
		<div class="postbox mns-box">
			<div class="postbox-header">
				<h2>صف بدون تخصیص</h2>
				<a class="mns-box__link" href="<?php echo esc_url( MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_ASSIGN ) ); ?>">مشاهده همه</a>
			</div>
			<div class="inside">
				<?php if ( ! $waiting ) : ?>
					<p class="mns-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> همه سفارش‌ها تخصیص یافته‌اند.</p>
				<?php else : ?>
					<table class="widefat striped mns-compact">
						<thead><tr><th>کد</th><th>خدمت</th><th>زمان</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $waiting as $r ) : ?>
							<?php
							$svc = MNS_Services::get( (int) $r['service_id'] );
							$detail = MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR, array( 'action' => 'view', 'id' => (int) $r['id'] ) );
							?>
							<tr>
								<td><strong dir="ltr"><?php echo esc_html( (string) $r['code'] ); ?></strong><br /><span class="mns-muted"><?php echo esc_html( MNS_Admin_Ui::ago( (string) $r['created_at'] ) ); ?></span></td>
								<td><?php echo esc_html( $svc ? (string) $svc['name'] : '—' ); ?></td>
								<td><?php echo esc_html( (string) $r['service_date'] . ' · ' . (string) $r['slot_id'] ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $detail ); ?>">پیگیری</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox mns-box">
			<div class="postbox-header">
				<h2>عضویت در انتظار</h2>
				<a class="mns-box__link" href="<?php echo esc_url( MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_NURSES, array( 'mns_status' => 'pending' ) ) ); ?>">مشاهده همه</a>
			</div>
			<div class="inside">
				<?php if ( ! $pending_nurses ) : ?>
					<p class="mns-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> درخواستی در صف نیست.</p>
				<?php else : ?>
					<table class="widefat striped mns-compact">
						<thead><tr><th>نام</th><th>زمان</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $pending_nurses as $n ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $n['display_name'] ?? ( '#' . $n['user_id'] ) ) ); ?></td>
								<td><span class="mns-muted"><?php echo esc_html( MNS_Admin_Ui::ago( (string) ( $n['applied_at'] ?? '' ) ) ); ?></span></td>
								<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . (int) $n['user_id'] ) ); ?>">بررسی</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox mns-box">
			<div class="postbox-header">
				<h2>آخرین سفارش‌ها</h2>
				<a class="mns-box__link" href="<?php echo esc_url( MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR ) ); ?>">رصد</a>
			</div>
			<div class="inside">
				<?php if ( ! $recent ) : ?>
					<p class="mns-empty">هنوز سفارشی ثبت نشده.</p>
				<?php else : ?>
					<table class="widefat striped mns-compact">
						<thead><tr><th>کد</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
						<tbody>
						<?php foreach ( $recent as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR, array( 'action' => 'view', 'id' => (int) $r['id'] ) ) ); ?>"><strong dir="ltr"><?php echo esc_html( (string) $r['code'] ); ?></strong></a></td>
								<td><?php echo MNS_Admin_Ui::status_badge( (string) $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><span class="mns-muted"><?php echo esc_html( MNS_Admin_Ui::date( (string) $r['created_at'], true ) ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox mns-box">
			<div class="postbox-header"><h2>توزیع وضعیت</h2></div>
			<div class="inside">
				<?php if ( ! $status_counts ) : ?>
					<p class="mns-empty">داده‌ای نیست.</p>
				<?php else : ?>
					<ul class="mns-bars">
						<?php foreach ( $status_counts as $st => $c ) : ?>
							<?php $pct = (int) round( ( $c / $status_max ) * 100 ); ?>
							<li>
								<a href="<?php echo esc_url( MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR, array( 'status' => $st ) ) ); ?>">
									<span><?php echo esc_html( $st ); ?></span>
									<span class="mns-bars__track"><span class="mns-bars__fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%;background:#2271b1"></span></span>
									<span class="mns-bars__value"><?php echo esc_html( MNS_Admin_Ui::fa_n( $c ) ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</div>
<?php
MNS_Admin_Ui::footer();
