<?php
/**
 * @var array<int,array<string,mixed>> $services
 * @var array<int,array<string,mixed>> $rules
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$by_service   = array_column( $rules, null, 'service_id' );
$services_url = Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => 'services' ) );
?>
<p class="description arp-lead">مبلغ خدمت و درصد پورسانت در <a href="<?php echo esc_url( $services_url ); ?>">تنظیمات ← خدمات</a> تعیین می‌شود. هنگام انتقال معرفی به وضعیت پورسانت‌دار، «پورسانت پیشنهادی» در فرم از پیش پر می‌شود و کارشناس می‌تواند آن را تغییر دهد.</p>

<?php if ( ! $services ) : ?>
	<p>ابتدا در <a href="<?php echo esc_url( $services_url ); ?>">تنظیمات ← خدمات</a> خدمتی تعریف کنید.</p>
<?php else : ?>
	<table class="widefat striped arp-rules">
		<thead><tr><th>خدمت</th><th>مبلغ خدمت</th><th>درصد پورسانت</th><th>پورسانت پیشنهادی</th><th>آخرین تغییر درصد</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $services as $svc ) : ?>
			<?php
			$rule       = $by_service[ $svc['id'] ] ?? null;
			$suggestion = Arp_Services_Repo::commission_suggestion( (int) $svc['id'] );
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $svc['name'] ); ?></strong>
					<?php if ( empty( $svc['is_active'] ) ) : ?>
						<span class="arp-muted">(غیرفعال)</span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( null !== $suggestion['price'] ? Arp_Admin_Ui::money( $suggestion['price'] ) : '—' ); ?></td>
				<td><?php echo esc_html( null !== $suggestion['percentage'] ? Arp_Format::fa( $suggestion['percentage'] ) . '٪' : '—' ); ?></td>
				<td><?php echo null !== $suggestion['amount'] ? '<strong>' . esc_html( Arp_Admin_Ui::money( $suggestion['amount'] ) ) . '</strong>' : '<span class="arp-muted">—</span>'; ?></td>
				<td><span class="arp-muted"><?php echo esc_html( $rule ? Arp_Admin_Ui::date( $rule['updated_at'] ) : '—' ); ?></span></td>
				<td><a class="button" href="<?php echo esc_url( add_query_arg( 'edit', (int) $svc['id'], $services_url ) ); ?>">ویرایش</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
