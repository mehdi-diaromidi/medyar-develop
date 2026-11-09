<?php
/**
 * Submit referral body — pixel layout from client mockup.
 * Shell (sidebar + site header) comes from layout.
 *
 * @var WP_User $user
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$services = Arp_Services_Repo::all( true );
$old      = Arp_Public::pull_old_referral_input( (int) $user->ID );
$old      = wp_parse_args(
	$old,
	array(
		'patient_name'  => '',
		'patient_phone' => '',
		'service_id'    => 0,
		'description'   => '',
	)
);

$err     = isset( $_GET['arp_err'] ) ? sanitize_key( wp_unslash( $_GET['arp_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$err_msg = 'ok' === $err ? '' : Arp_Panel_Actions::message( 'referral', $err );

$steps = array(
	array(
		'n'     => '۱',
		'title' => 'ثبت معرفی بیمار',
		'desc'  => 'اطلاعات بیمار را وارد کنید.',
		'icon'  => 'user-refer',
	),
	array(
		'n'     => '۲',
		'title' => 'پیگیری توسط مدیر',
		'desc'  => 'کارشناسان با بیمار تماس می‌گیرند.',
		'icon'  => 'monitor',
	),
	array(
		'n'     => '۳',
		'title' => 'تعیین وضعیت',
		'desc'  => 'وضعیت معرفی، در پنل شما قابل مشاهده است.',
		'icon'  => 'list-status',
	),
	array(
		'n'     => '۴',
		'title' => 'محاسبه پورسانت',
		'desc'  => 'پس از تکمیل خدمت، پورسانت محاسبه می‌شود.',
		'icon'  => 'timer',
	),
);

$svg = static function ( string $name ): string {
	$map = array(
		'user-plus'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>',
		'send'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m22 2-7 20-4-9-9-4 20-7z"/><path d="M22 2 11 13"/></svg>',
		'plane'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m22 2-7 20-4-9-9-4 20-7z"/><path d="M22 2 11 13"/></svg>',
		'id-card'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M15 10h4M15 14h3"/></svg>',
		'person'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'phone'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.81.36 1.6.68 2.35a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.73-1.27a2 2 0 0 1 2.11-.45c.75.32 1.54.55 2.35.68A2 2 0 0 1 22 16.92z"/></svg>',
		'grid'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
		'chevron'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>',
		'pencil'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
		'monitor'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4M12 8v3M12 14h.01"/></svg>',
		'clipboard'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>',
		'timer'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 1.5M10 2h4M12 2v2"/></svg>',
		'user-refer'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M16 11h6"/><path d="m19 8 3 3-3 3"/></svg>',
		'list-status' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 6 2 2 4-4"/><path d="M13 6h8"/><path d="m3 13 2 2 4-4"/><path d="M13 13h8"/><circle cx="6" cy="19.5" r="1.5"/><path d="M13 20h8"/></svg>',
		'hand-heart'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 14h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16"/><path d="m7 20 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 15 6 6"/><path d="M19.5 8.5c.7-.7 1.5-1.6 1.5-2.7A2.73 2.73 0 0 0 16 4a2.78 2.78 0 0 0-5 1.8c0 1.2.8 2 1.5 2.8L16 12Z"/></svg>',
		'handshake'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.74-3.74a2 2 0 0 0-2.83 0L9 13"/><path d="M3 13h2l2-2"/><path d="m5 11 4 4"/><path d="M21 13h-2l-2-2"/><path d="m7 7 2-2a2 2 0 0 1 2.83 0L14 7"/></svg>',
	);
	return $map[ $name ] ?? '';
};
?>
<section class="arp-submit" dir="rtl">

	<header class="arp-refs__head">
		<div class="arp-refs__intro">
			<h1 class="arp-refs__title">
				<span class="drplus-simple-icon-wrap icon-has-bg arp-refs__title-icon" aria-hidden="true">
					<i class="drplus-icon-discovery drplus-simple-icon"></i>
				</span>
				<span class="arp-refs__title-text">معرفی بیمار</span>
			</h1>
			<p class="arp-refs__lead">اطلاعات بیمار را با دقت وارد کنید تا در سریع‌ترین زمان، در سیستم ثبت شود</p>
		</div>
	</header>

	<?php if ( '' !== $err_msg ) : ?>
		<div class="arp-notice arp-notice--error" role="alert"><?php echo esc_html( $err_msg ); ?></div>
	<?php elseif ( ! $services ) : ?>
		<div class="arp-notice arp-notice--error" role="alert">در حال حاضر خدمتی برای معرفی فعال نیست. لطفاً بعداً مراجعه کنید.</div>
	<?php endif; ?>

	<div class="arp-submit__grid">

		<!-- Right (wider in RTL): patient form -->
		<div class="arp-submit-card arp-submit-card--form">
			<div class="arp-submit-card__head">
				<h2 class="arp-submit-card__title">اطلاعات بیمار</h2>
			</div>

			<form method="post" class="arp-submit-form" novalidate data-arp-ajax="arp_submit_referral" data-arp-reset>
				<div class="arp-form-notice" role="status" aria-live="polite" tabindex="-1" hidden></div>
				<?php wp_nonce_field( 'arp_submit_referral' ); ?>
				<input type="hidden" name="arp_action" value="submit_referral" />

				<div class="arp-submit-form__row arp-submit-form__row--2">
					<label class="arp-field-block">
						<span class="arp-field-block__label">نام و نام خانوادگی بیمار <span class="arp-req" aria-hidden="true">*</span></span>
						<span class="arp-field-block__control">
							<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<input type="text" name="patient_name" required maxlength="191" placeholder="مثال: علی محمدی" autocomplete="off" value="<?php echo esc_attr( $old['patient_name'] ); ?>" />
						</span>
					</label>
					<label class="arp-field-block">
						<span class="arp-field-block__label">شماره تماس بیمار <span class="arp-req" aria-hidden="true">*</span></span>
						<span class="arp-field-block__control">
							<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<input type="text" name="patient_phone" required maxlength="20" placeholder="مثال: 09123446789" inputmode="tel" autocomplete="off" dir="ltr" data-arp-iran-mobile value="<?php echo esc_attr( $old['patient_phone'] ); ?>" />
						</span>
					</label>
				</div>

				<label class="arp-field-block">
					<span class="arp-field-block__label">نوع خدمت موردنظر <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control arp-field-block__control--select">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<select name="service_id" required class="arp-select-native" data-arp-select>
							<option value="">انتخاب کنید</option>
							<?php foreach ( $services as $svc ) : ?>
								<option value="<?php echo esc_attr( $svc['id'] ); ?>" <?php selected( (int) $old['service_id'], (int) $svc['id'] ); ?>><?php echo esc_html( $svc['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="arp-field-block__chevron" aria-hidden="true"><?php echo $svg( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</span>
				</label>

				<label class="arp-field-block">
					<span class="arp-field-block__label">توضیحات (در صورت نیاز)</span>
					<span class="arp-field-block__control arp-field-block__control--area">
						<textarea name="description" id="arp-desc" maxlength="400" rows="5" placeholder="توضیحات اختیاری در مورد بیمار یا نیاز خاص او ..."><?php echo esc_textarea( $old['description'] ); ?></textarea>
						<span class="arp-field-block__meta">
							<span class="arp-field-block__counter"><span id="arp-desc-count">۰</span>/۴۰۰</span>
							<span class="arp-field-block__pencil" aria-hidden="true"><?php echo $svg( 'pencil' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</span>
					</span>
				</label>

				<div class="arp-submit-form__foot">
					<button type="submit" class="arp-submit-btn" <?php disabled( ! $services ); ?>>ثبت معرفی بیمار</button>
				</div>
			</form>
		</div>

		<!-- Left (narrower in RTL): how it works -->
		<aside class="arp-submit-card arp-submit-card--how">
			<div class="arp-submit-card__head">
				<h2 class="arp-submit-card__title">چطور کار می‌کنید؟</h2>
			</div>

			<ol class="arp-how-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="arp-how-steps__item">
						<span class="arp-how-steps__bubble" aria-hidden="true"><?php echo $svg( $step['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="arp-how-steps__text">
							<strong class="arp-how-steps__title"><?php echo esc_html( $step['title'] ); ?></strong>
							<span class="arp-how-steps__desc"><?php echo esc_html( $step['desc'] ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="arp-how-cta">
				<span class="arp-how-cta__icon" aria-hidden="true"><?php echo $svg( 'hand-heart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<p class="arp-how-cta__text">با معرفی،<br />هم بیمار را راهی کنید<br />و هم درآمد داشته باشید</p>
			</div>
		</aside>

	</div>
</section>
