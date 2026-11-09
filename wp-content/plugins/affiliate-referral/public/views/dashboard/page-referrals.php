<?php
/**
 * My referrals — filters, table, pagination, CTA banner.
 *
 * @var WP_User $user
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = Arp_Statuses_Repo::all();
$services = Arp_Services_Repo::all( false );
$list     = Arp_Panel_Lists::referrals( (int) $user->ID );
$f_status  = $list['f_status'];
$f_service = $list['f_service'];
$f_q       = $list['f_q'];
$range     = $list['range'];
?>
<section class="arp-refs" dir="rtl">

	<header class="arp-refs__head">
		<div class="arp-refs__intro">
			<h1 class="arp-refs__title">
				<span class="drplus-simple-icon-wrap icon-has-bg arp-refs__title-icon" aria-hidden="true">
					<i class="drplus-icon-users drplus-simple-icon"></i>
				</span>
				<span class="arp-refs__title-text">معرفی‌های من</span>
			</h1>
			<p class="arp-refs__lead">لیست تمامی معرفی‌های ثبت‌شده شما، به همراه وضعیت و جزئیات</p>
		</div>
	</header>

	<form class="arp-card arp-refs-filters" method="get" action="<?php echo esc_url( Arp_Pages::dashboard_url() ); ?>" data-arp-list="referrals" role="search">
		<input type="hidden" name="arp_page" value="referrals" />

		<?php include __DIR__ . '/partials/date-range.php'; ?>

		<label class="arp-field-block">
			<span class="arp-field-block__label">نوع وضعیت</span>
			<span class="arp-field-block__control arp-field-block__control--select">
				<select name="arp_status" class="arp-select-native" data-arp-select data-arp-autosubmit>
					<option value="">همه وضعیت‌ها</option>
					<?php foreach ( $statuses as $st ) : ?>
						<option value="<?php echo esc_attr( $st['id'] ); ?>" data-arp-color="<?php echo esc_attr( Arp_Ui::status_color( $st ) ); ?>" <?php selected( $f_status, (int) $st['id'] ); ?>><?php echo esc_html( $st['name'] ); ?></option>
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

		<label class="arp-field-block">
			<span class="arp-field-block__label screen-reader-text">جستجو</span>
			<span class="arp-field-block__control">
				<input type="search" name="arp_q" value="<?php echo esc_attr( $f_q ); ?>" placeholder="جستجو..." maxlength="100" />
				<button type="submit" class="arp-field-block__action" aria-label="جستجو">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
				</button>
			</span>
		</label>

		<?php
		$arp_list_view = 'referrals';
		include __DIR__ . '/partials/filters-reset.php';
		?>
	</form>

	<div class="arp-card arp-card--table" data-arp-results="referrals" aria-live="polite">
		<?php echo Arp_Panel_Lists::render( 'referrals', $list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the partial. ?>
	</div>

	<aside class="arp-refs-cta">
		<div class="arp-refs-cta__text">
			<strong>با معرفی بیشتر، درآمد بیشتری داشته باشید.</strong>
			<span>هر معرفی موفق، به افزایش پورسانت شما کمک می‌کند.</span>
			<a class="arp-refs-cta__btn" href="<?php echo esc_url( Arp_Pages::page_url( 'submit' ) ); ?>">ثبت معرفی جدید</a>
		</div>
	</aside>

</section>
