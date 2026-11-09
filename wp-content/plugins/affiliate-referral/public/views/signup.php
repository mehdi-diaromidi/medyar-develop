<?php
/**
 * Affiliate onboarding form: shown on the signup page and on the panel page to logged-in non-affiliates.
 *
 * @var string $msg
 * @var string $name
 * @var string $mobile
 * @var array  $job_types
 * @var string $inviter_code
 * @var bool   $inviter_locked
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$inviter_code   = isset( $inviter_code ) ? (string) $inviter_code : '';
$inviter_locked = ! empty( $inviter_locked );
$job_type_id    = isset( $job_type_id ) ? absint( $job_type_id ) : 0;
$err            = isset( $_GET['arp_err'] ) ? sanitize_key( wp_unslash( $_GET['arp_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$err_field      = isset( $_GET['arp_field'] ) ? sanitize_key( wp_unslash( $_GET['arp_field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$err_msg        = ( '' === $err || 'ok' === $err ) ? '' : Arp_Panel_Actions::message( 'signup', $err );
$ok_map = array(
	'registered' => 'ok',
	'already'    => 'already',
	'pending'    => 'pending',
);
$ok_msg = isset( $ok_map[ $msg ] ) ? Arp_Panel_Actions::message( 'signup', $ok_map[ $msg ] ) : '';

$steps = array(
	array( 'person', 'تکمیل اطلاعات', 'نام، موبایل، شغل و در صورت نیاز مدارک را ارسال کنید.' ),
	array( 'clipboard', 'بررسی ادمین', 'درخواست شما در صف بررسی قرار می‌گیرد و نتیجه پیامک می‌شود.' ),
	array( 'user-plus', 'معرفی بیمار', 'پس از تأیید، بیماران را از پنل طرح همکاران معرفی کنید.' ),
	array( 'wallet', 'دریافت پورسانت', 'پس از تکمیل خدمت، پورسانت شما محاسبه و پرداخت می‌شود.' ),
);

$svg = static function ( string $name ): string {
	$map = array(
		'person'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'phone'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.81.36 1.6.68 2.35a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.73-1.27a2 2 0 0 1 2.11-.45c.75.32 1.54.55 2.35.68A2 2 0 0 1 22 16.92z"/></svg>',
		'job'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2 13h20"/></svg>',
		'ticket'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M9 7v10"/></svg>',
		'chevron' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>',
		'user-plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>',
		'clipboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>',
		'wallet'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V5"/><path d="M17 14h.01"/></svg>',
	);
	return $map[ $name ] ?? '';
};
?>
<section class="arp-submit arp-signup" dir="rtl">

	<header class="arp-refs__head">
		<div class="arp-refs__intro">
			<h1 class="arp-refs__title">
				<span class="drplus-simple-icon-wrap icon-has-bg arp-refs__title-icon" aria-hidden="true">
					<i class="drplus-icon-profile-2user drplus-simple-icon"></i>
				</span>
				<span class="arp-refs__title-text">عضویت در طرح همکاران مدیار</span>
			</h1>
			<p class="arp-refs__lead">برای درخواست دسترسی به پنل طرح همکاران، اطلاعات زیر را تکمیل کنید.</p>
		</div>
	</header>

	<?php if ( '' !== $ok_msg ) : ?>
		<div class="arp-notice" role="status"><?php echo esc_html( $ok_msg ); ?></div>
	<?php elseif ( '' !== $err_msg ) : ?>
		<div class="arp-notice arp-notice--error" role="alert"><?php echo esc_html( $err_msg ); ?></div>
	<?php elseif ( ! $job_types ) : ?>
		<div class="arp-notice arp-notice--error" role="alert">در حال حاضر امکان عضویت فعال نیست. لطفاً بعداً مراجعه کنید.</div>
	<?php endif; ?>

	<div class="arp-submit__grid">
		<div class="arp-submit-card arp-submit-card--form">
			<div class="arp-submit-card__head">
				<h2 class="arp-submit-card__title">اطلاعات طرح همکاران</h2>
			</div>

			<form method="post" class="arp-submit-form arp-signup-form" enctype="multipart/form-data" novalidate>
				<?php wp_nonce_field( 'arp_signup' ); ?>
				<input type="hidden" name="arp_action" value="signup" />

				<div class="arp-submit-form__row arp-submit-form__row--2">
					<label class="arp-field-block<?php echo ( 'full_name' === $err_field ) ? ' is-invalid' : ''; ?>">
						<span class="arp-field-block__label">نام و نام خانوادگی <span class="arp-req" aria-hidden="true">*</span></span>
						<span class="arp-field-block__control">
							<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<input type="text" name="full_name" required maxlength="191" placeholder="مثال: علی محمدی" autocomplete="name" value="<?php echo esc_attr( $name ); ?>"<?php echo ( 'full_name' === $err_field ) ? ' aria-invalid="true"' : ''; ?> />
						</span>
					</label>
					<label class="arp-field-block<?php echo ( 'mobile' === $err_field ) ? ' is-invalid' : ''; ?>">
						<span class="arp-field-block__label">شماره موبایل <span class="arp-req" aria-hidden="true">*</span></span>
						<span class="arp-field-block__control">
							<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<input type="text" name="mobile" required maxlength="20" placeholder="مثال: 09123456789" inputmode="tel" autocomplete="tel" dir="ltr" data-arp-iran-mobile value="<?php echo esc_attr( $mobile ); ?>"<?php echo ( 'mobile' === $err_field ) ? ' aria-invalid="true"' : ''; ?> />
						</span>
					</label>
				</div>

				<label class="arp-field-block<?php echo ( 'job_type_id' === $err_field ) ? ' is-invalid' : ''; ?>">
					<span class="arp-field-block__label">نوع شغل <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control arp-field-block__control--select">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'job' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<select name="job_type_id" required class="arp-select-native" data-arp-select data-arp-select-search<?php echo ( 'job_type_id' === $err_field ) ? ' aria-invalid="true"' : ''; ?>>
							<option value="">انتخاب کنید</option>
							<?php foreach ( $job_types as $jt ) : ?>
								<option value="<?php echo esc_attr( (string) $jt['id'] ); ?>"<?php selected( $job_type_id, (int) $jt['id'] ); ?>><?php echo esc_html( $jt['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="arp-field-block__chevron" aria-hidden="true"><?php echo $svg( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</span>
				</label>

				<label class="arp-field-block<?php echo $inviter_locked ? ' arp-field-block--inviter-locked' : ''; ?><?php echo ( 'inviter_code' === $err_field ) ? ' is-invalid' : ''; ?>">
					<span class="arp-field-block__label">کد معرف <span class="arp-muted">(اختیاری)</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="text"
							name="inviter_code"
							maxlength="16"
							minlength="4"
							pattern="[a-z0-9]{4,16}"
							autocomplete="off"
							autocapitalize="none"
							spellcheck="false"
							dir="ltr"
							inputmode="text"
							value="<?php echo esc_attr( $inviter_code ); ?>"
							<?php echo ( 'inviter_code' === $err_field ) ? 'aria-invalid="true"' : ''; ?>
							<?php echo $inviter_locked ? 'readonly aria-readonly="true" tabindex="-1"' : ''; ?>
						/>
					</span>
					<?php if ( $inviter_locked ) : ?>
						<span class="arp-field-block__help">کد معرف از لینک دعوت تنظیم شده و قابل تغییر نیست.</span>
					<?php else : ?>
						<span class="arp-field-block__help">اگر کسی شما را معرفی کرده، کد معرف او را وارد کنید.</span>
					<?php endif; ?>
				</label>

				<label class="arp-field-block<?php echo ( 'arp_documents' === $err_field ) ? ' is-invalid' : ''; ?>">
					<span class="arp-field-block__label">آپلود مدارک <span class="arp-muted">(اختیاری)</span></span>
					<span class="arp-field-block__hint">حداکثر ۳ فایل — JPG، PNG، WEBP یا PDF — هر فایل تا ۵ مگابایت</span>
					<span class="arp-field-block__control arp-field-block__control--file">
						<input type="file"
							name="arp_documents[]"
							id="arp_documents"
							accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
							multiple
							aria-describedby="arpDocsHelp" />
					</span>
					<span class="arp-field-block__help" id="arpDocsHelp">مدارک فقط برای بررسی ادمین استفاده می‌شود و به‌صورت خصوصی ذخیره می‌گردد.</span>
				</label>

				<div class="arp-submit-form__foot">
					<button type="submit" class="arp-submit-btn" <?php disabled( ! $job_types ); ?>>ارسال درخواست طرح همکاران</button>
				</div>
			</form>
		</div>

		<aside class="arp-submit-card arp-submit-card--how">
			<div class="arp-submit-card__head">
				<h2 class="arp-submit-card__title">طرح همکاران چطور کار می‌کند؟</h2>
			</div>
			<ol class="arp-how-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="arp-how-steps__item">
						<span class="arp-how-steps__bubble" aria-hidden="true"><?php echo $svg( $step[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="arp-how-steps__text">
							<strong class="arp-how-steps__title"><?php echo esc_html( $step[1] ); ?></strong>
							<span class="arp-how-steps__desc"><?php echo esc_html( $step[2] ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</aside>
	</div>
</section>
