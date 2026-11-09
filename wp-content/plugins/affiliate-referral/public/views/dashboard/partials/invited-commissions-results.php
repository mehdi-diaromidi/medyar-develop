<?php
/**
 * Special inviter: commissions earned by invited affiliates (KPI + table + pager).
 *
 * @var array<int,array<string,mixed>> $visible
 * @var bool                           $has_filters
 * @var int                            $offset
 * @var int                            $total
 * @var int                            $per
 * @var int                            $page_num
 * @var int                            $pages
 * @var callable                       $page_url
 * @var int                            $invitee_count
 * @var float                          $amount_total
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$invitee_count = isset( $invitee_count ) ? (int) $invitee_count : 0;
$amount_total  = isset( $amount_total ) ? (float) $amount_total : 0.0;
?>
<div class="arp-kpis arp-invited-gain-kpis" aria-label="خلاصه پورسانت همکاران دعوت‌شده">
	<div class="arp-kpi">
		<div class="arp-kpi__label">تعداد دعوت‌شده</div>
		<div class="arp-kpi__value"><?php echo esc_html( Arp_Format::fa( number_format_i18n( $invitee_count ) ) ); ?></div>
	</div>
	<div class="arp-kpi">
		<div class="arp-kpi__label">ردیف‌های پورسانت</div>
		<div class="arp-kpi__value"><?php echo esc_html( Arp_Format::fa( number_format_i18n( (int) $total ) ) ); ?></div>
	</div>
	<div class="arp-kpi">
		<div class="arp-kpi__label">جمع مبلغ</div>
		<div class="arp-kpi__value"><?php echo esc_html( Arp_Format::money( $amount_total ) ); ?> <small>تومان</small></div>
	</div>
</div>

<div class="arp-table-wrap">
	<table class="arp-table">
		<thead>
			<tr>
				<th class="arp-table__num">#</th>
				<th>همکار</th>
				<th>خدمت</th>
				<th>مبلغ پورسانت</th>
				<th>وضعیت</th>
				<th>تاریخ</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $visible ) ) : ?>
				<tr>
					<td colspan="6" class="arp-table__empty">
						<?php echo esc_html( $has_filters ? 'موردی با این فیلترها پیدا نشد.' : 'هنوز پورسانت‌داری برای همکاران دعوت‌شده ثبت نشده است.' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $visible as $i => $row ) : ?>
					<?php
					$aff_id = (int) ( $row['affiliate_user_id'] ?? 0 );
					$aff    = $aff_id ? get_userdata( $aff_id ) : null;
					?>
					<tr>
						<td class="arp-table__num"><?php echo esc_html( Arp_Format::fa( $offset + $i + 1 ) ); ?></td>
						<td>
							<?php echo esc_html( $aff ? $aff->display_name : ( '#' . $aff_id ) ); ?>
						</td>
						<td><?php echo esc_html( (string) ( $row['service_name'] ?? '—' ) ); ?></td>
						<td class="arp-comm__amount">
							<strong><?php echo esc_html( Arp_Format::money( $row['amount'] ?? 0 ) ); ?></strong>
							<span>تومان</span>
						</td>
						<td>
							<span class="arp-status arp-status--<?php echo esc_attr( Arp_Ui::commission_tone( (string) ( $row['state'] ?? '' ) ) ); ?>">
								<?php echo esc_html( Arp_Ui::commission_label( (string) ( $row['state'] ?? '' ) ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( Arp_Format::fa( Arp_Format::to_jalali( (string) ( $row['created_at'] ?? '' ) ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<?php include __DIR__ . '/pager.php'; ?>
