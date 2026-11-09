<?php
/**
 * Referrals table + pager (full page and AJAX refresh).
 *
 * @var array<int,array<string,mixed>> $visible
 * @var bool                           $has_filters
 * @var int                            $total
 * @var int                            $per
 * @var int                            $page_num
 * @var int                            $pages
 * @var callable                       $page_url
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="arp-table-wrap">
	<table class="arp-table arp-table--expandable">
		<thead>
			<tr>
				<th>نام و نام خانوادگی</th>
				<th>شماره تماس</th>
				<th>تاریخ ثبت</th>
				<th>نوع خدمت</th>
				<th>وضعیت</th>
				<th class="arp-table__amount">پورسانت</th>
				<th class="arp-table__toggle"><span class="screen-reader-text">جزئیات</span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $visible ) ) : ?>
				<tr><td colspan="7" class="arp-table__empty"><?php echo esc_html( $has_filters ? 'موردی با این فیلترها پیدا نشد.' : 'هنوز معرفی‌ای ثبت نکرده‌اید.' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $visible as $row ) : ?>
					<?php $arp_details_id = 'arp-ref-details-' . (int) $row['id']; ?>
					<tr class="arp-table__row">
						<td class="arp-table__name"><?php echo esc_html( (string) $row['patient_name'] ); ?></td>
						<td><span dir="ltr"><?php echo esc_html( Arp_Format::fa( (string) $row['patient_phone'] ) ); ?></span></td>
						<td><?php echo esc_html( Arp_Format::fa( Arp_Format::to_jalali( (string) $row['created_at'] ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['service_name'] ?? '—' ) ); ?></td>
						<td><span class="arp-status arp-status--dynamic" style="<?php echo esc_attr( Arp_Ui::status_style( $row ) ); ?>"><?php echo esc_html( (string) ( $row['status_name'] ?? '—' ) ); ?></span></td>
						<td class="arp-table__amount">
							<?php if ( null !== $row['commission_amount'] ) : ?>
								<strong><?php echo esc_html( Arp_Format::money( $row['commission_amount'] ) ); ?></strong>
								<span>تومان</span>
							<?php else : ?>
								<span class="arp-table__dash">–</span>
							<?php endif; ?>
						</td>
						<td class="arp-table__toggle">
							<button type="button" class="arp-row-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $arp_details_id ); ?>" aria-label="نمایش توضیحات">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
							</button>
						</td>
					</tr>
					<tr class="arp-table__details" id="<?php echo esc_attr( $arp_details_id ); ?>" hidden>
						<td colspan="7">
							<span class="arp-table__details-label">توضیحات معرفی:</span>
							<?php if ( '' !== trim( (string) $row['description'] ) ) : ?>
								<p class="arp-table__details-text"><?php echo esc_html( (string) $row['description'] ); ?></p>
							<?php else : ?>
								<p class="arp-table__details-text is-empty">توضیحی ثبت نشده است.</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<?php include __DIR__ . '/pager.php'; ?>
