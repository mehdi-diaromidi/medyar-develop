<?php
/**
 * @var array<int,array<string,mixed>> $items
 * @var array<int,int>                 $usage
 * @var array<int,array<string,mixed>> $rules service_id => active rule
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$toggle_type = 'service';
$action      = 'arp_save_service';
$singular    = 'خدمت';
$usage_label = 'تعداد معرفی';
$extra_label = 'مبلغ خدمت / پورسانت';
$help        = 'خدماتی که همکاران هنگام معرفی بیمار انتخاب می‌کنند. خدمت غیرفعال در فرم معرفی نمایش داده نمی‌شود ولی در گزارش‌ها باقی می‌ماند.';

$extra_fields = static function ( ?array $item ) use ( $rules ): void {
	$rule  = $item ? ( $rules[ $item['id'] ] ?? null ) : null;
	$price = ( $item && null !== $item['price'] ) ? number_format( (float) $item['price'] ) : '';
	$pct   = $rule ? (string) (float) $rule['percentage'] : '';
	?>
	<div class="arp-service-pricing" data-arp-commission-preview>
		<div class="form-field">
			<label for="arp-svc-price">مبلغ خدمت (تومان)</label>
			<input type="text" name="price" id="arp-svc-price" class="arp-money" inputmode="numeric" dir="ltr" autocomplete="off" value="<?php echo esc_attr( $price ); ?>" data-arp-price />
		</div>
		<div class="form-field">
			<label for="arp-svc-pct">درصد پورسانت</label>
			<input type="number" name="percentage" id="arp-svc-pct" min="0" max="100" step="0.01" dir="ltr" class="small-text" value="<?php echo esc_attr( $pct ); ?>" data-arp-percent /> ٪
		</div>
		<p class="description">پورسانت پیشنهادی: <strong data-arp-commission-out>—</strong><br />هنگام تغییر وضعیت معرفی به «پورسانت‌دار» همین مبلغ از پیش پر می‌شود و قابل ویرایش است.</p>
	</div>
	<?php
};

$extra_col = static function ( array $row ) use ( $rules ): string {
	$rule  = $rules[ $row['id'] ] ?? null;
	$price = null !== $row['price'] ? (float) $row['price'] : null;
	$pct   = $rule ? (float) $rule['percentage'] : null;
	if ( null === $price && null === $pct ) {
		return '<span class="arp-muted">تعریف نشده</span>';
	}
	$parts = array(
		null !== $price ? Arp_Admin_Ui::money( $price ) : '—',
		null !== $pct ? Arp_Format::fa( $pct ) . '٪' : '—',
	);
	$out = esc_html( implode( ' / ', $parts ) );
	if ( null !== $price && $pct ) {
		$out .= '<br /><span class="arp-muted">' . esc_html( 'پورسانت: ' . Arp_Admin_Ui::money( round( $price * $pct / 100 ) ) ) . '</span>';
	}
	return $out;
};

require __DIR__ . '/named-items.php';
