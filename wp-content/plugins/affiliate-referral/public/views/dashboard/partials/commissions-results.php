<?php
/**
 * Commissions table + pager (full page and AJAX refresh).
 *
 * @var array<int,array<string,mixed>> $visible
 * @var bool                           $has_filters
 * @var int                            $offset
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
	<table class="arp-table">
		<thead>
			<tr>
				<th class="arp-table__num">#</th>
				<th>تاریخ ثبت</th>
				<th>مبلغ پورسانت</th>
				<th>وضعیت پرداخت</th>
				<th>تاریخ پرداخت</th>
				<th>کد پیگیری</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $visible ) ) : ?>
				<tr><td colspan="6" class="arp-table__empty"><?php echo esc_html( $has_filters ? 'موردی با این فیلترها پیدا نشد.' : 'هنوز پورسانتی برای شما ثبت نشده است.' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $visible as $i => $row ) : ?>
					<tr>
						<td class="arp-table__num"><?php echo esc_html( Arp_Format::fa( $offset + $i + 1 ) ); ?></td>
						<td><?php echo esc_html( Arp_Format::fa( Arp_Format::to_jalali( (string) $row['created_at'] ) ) ); ?></td>
						<td class="arp-comm__amount">
							<strong><?php echo esc_html( Arp_Format::money( $row['amount'] ) ); ?></strong>
							<span>تومان</span>
						</td>
						<td><span class="arp-status arp-status--<?php echo esc_attr( Arp_Ui::commission_tone( (string) $row['state'] ) ); ?>"><?php echo esc_html( Arp_Ui::commission_label( (string) $row['state'] ) ); ?></span></td>
						<td>
							<?php if ( ! empty( $row['paid_at'] ) ) : ?>
								<?php echo esc_html( Arp_Format::fa( Arp_Format::to_jalali( (string) $row['paid_at'] ) ) ); ?>
							<?php else : ?>
								<span class="arp-table__dash">–</span>
							<?php endif; ?>
						</td>
						<td class="arp-comm__tracking" dir="ltr">
							<?php if ( ! empty( $row['tracking_code'] ) ) : ?>
								<?php echo esc_html( $row['tracking_code'] ); ?>
							<?php else : ?>
								<span class="arp-table__dash">–</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<?php include __DIR__ . '/pager.php'; ?>
