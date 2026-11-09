<?php
/**
 * My commissions — filters, details table, pagination.
 *
 * @var WP_User $user
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$services  = Arp_Services_Repo::all( false );
$list      = Arp_Panel_Lists::commissions( (int) $user->ID );
$states    = $list['states'];
$f_state   = $list['f_state'];
$f_service = $list['f_service'];
$range     = $list['range'];
?>
<section class="arp-refs arp-comm" dir="rtl">

	<header class="arp-refs__head">
		<div class="arp-refs__intro">
			<h1 class="arp-refs__title">
				<span class="drplus-simple-icon-wrap icon-has-bg arp-refs__title-icon" aria-hidden="true">
					<i class="drplus-icon-wallet drplus-simple-icon"></i>
				</span>
				<span class="arp-refs__title-text">پورسانت من</span>
			</h1>
			<p class="arp-refs__lead">مشاهده جزئیات پورسانت‌های کسب شده و وضعیت پرداخت‌ها</p>
		</div>
	</header>

	<form class="arp-card arp-refs-filters arp-refs-filters--3" method="get" action="<?php echo esc_url( Arp_Pages::dashboard_url() ); ?>" data-arp-list="commissions" role="search">
		<input type="hidden" name="arp_page" value="commissions" />

		<?php include __DIR__ . '/partials/date-range.php'; ?>

		<label class="arp-field-block">
			<span class="arp-field-block__label">وضعیت پرداخت</span>
			<span class="arp-field-block__control arp-field-block__control--select">
				<select name="arp_state" class="arp-select-native" data-arp-select data-arp-autosubmit>
					<option value="">همه وضعیت‌ها</option>
					<?php foreach ( $states as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f_state, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="arp-field-block__chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></span>
			</span>
		</label>

		<label class="arp-field-block">
			<span class="arp-field-block__label">نوع خدمت</span>
			<span class="arp-field-block__control arp-field-block__control--select">
				<select name="arp_service" class="arp-select-native" data-arp-select data-arp-autosubmit>
					<option value="">همه خدمات</option>
					<?php foreach ( $services as $svc ) : ?>
						<option value="<?php echo esc_attr( $svc['id'] ); ?>" <?php selected( $f_service, (int) $svc['id'] ); ?>><?php echo esc_html( $svc['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="arp-field-block__chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></span>
			</span>
		</label>

		<?php
		$arp_list_view = 'commissions';
		include __DIR__ . '/partials/filters-reset.php';
		?>
	</form>

	<div class="arp-card arp-card--table" data-arp-results="commissions" aria-live="polite">
		<?php echo Arp_Panel_Lists::render( 'commissions', $list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the partial. ?>
	</div>

</section>
