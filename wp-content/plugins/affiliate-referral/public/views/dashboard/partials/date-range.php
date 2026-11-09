<?php
/**
 * Jalali from/to filter control.
 *
 * @var array{from:string,to:string} $range From Arp_Ui::date_range_from_request().
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

list( $arp_jy, $arp_jm, $arp_jd ) = Arp_Format::jalali_today();
$arp_ph_from = Arp_Format::fa( sprintf( '%04d/%02d/01', $arp_jy, $arp_jm ) );
$arp_ph_to   = Arp_Format::fa( sprintf( '%04d/%02d/%02d', $arp_jy, $arp_jm, $arp_jd ) );
?>
<label class="arp-field-block">
	<span class="arp-field-block__label">تاریخ</span>
	<span class="arp-field-block__control arp-field-block__control--range">
		<span class="arp-field-block__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
		<input
			type="text"
			name="arp_from"
			value="<?php echo esc_attr( $range['from'] ); ?>"
			placeholder="<?php echo esc_attr( $arp_ph_from ); ?>"
			readonly
			autocomplete="off"
			data-arp-jdate
			aria-label="از تاریخ"
		/>
		<span class="arp-field-block__sep">تا</span>
		<input
			type="text"
			name="arp_to"
			value="<?php echo esc_attr( $range['to'] ); ?>"
			placeholder="<?php echo esc_attr( $arp_ph_to ); ?>"
			readonly
			autocomplete="off"
			data-arp-jdate
			aria-label="تا تاریخ"
		/>
	</span>
</label>
