<?php
/**
 * My profile — identity card, personal info form (same storage as WC edit-account), security banner.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$arp_fa = static function ( $value ): string {
	return strtr( (string) $value, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
};

$user       = wp_get_current_user();
$uid        = (int) $user->ID;
$first_name = (string) get_user_meta( $uid, 'first_name', true );
$last_name  = (string) get_user_meta( $uid, 'last_name', true );
$display    = (string) $user->display_name;
$name       = trim( $first_name . ' ' . $last_name );
if ( '' === $name ) {
	$name = $display;
}

if ( class_exists( '\DrPlus\Utils\User' ) ) {
	$mobile = (string) \DrPlus\Utils\User::get_phone( $uid );
	$nid    = (string) \DrPlus\Utils\User::get_nid( $uid );
} else {
	$mobile = (string) get_user_meta( $uid, 'mobile', true );
	if ( '' === $mobile ) {
		$mobile = (string) get_user_meta( $uid, 'billing_phone', true );
	}
	$nid = (string) get_user_meta( $uid, 'nid', true );
}

// Read the meta directly: the theme's get_birthday() turns an empty value into "now".
$birthday = (string) get_user_meta( $uid, 'birthday', true );
if ( '' !== $birthday && ! is_numeric( $birthday ) ) {
	$birthday = (string) strtotime( $birthday );
}

$about     = (string) get_user_meta( $uid, 'arp_about', true );
$job_id    = (int) get_user_meta( $uid, 'arp_job_type_id', true );
$job_types = Arp_Job_Types_Repo::all( true );

$job_name = '';
foreach ( $job_types as $jt ) {
	if ( (int) $jt['id'] === $job_id ) {
		$job_name = (string) $jt['name'];
		break;
	}
}

$birthday_ts     = is_numeric( $birthday ) ? (int) $birthday : 0;
$birthday_jalali = $birthday_ts > 0 ? Arp_Format::to_jalali( gmdate( 'Y-m-d', $birthday_ts ) ) : '';

$svg = static function ( string $name ): string {
	$map = array(
		'person'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'phone'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.81.36 1.6.68 2.35a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.73-1.27a2 2 0 0 1 2.11-.45c.75.32 1.54.55 2.35.68A2 2 0 0 1 22 16.92z"/></svg>',
		'mail'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
		'bag'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2 13h20"/></svg>',
		'check'    => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/><path d="m7.5 12.5 3 3 6-6.5" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'camera'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>',
		'trash'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>',
		'pencil'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
		'save'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>',
		'chevron'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>',
		'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
		'id'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M14 10h5M14 14h3"/></svg>',
		'shield'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
		'lock'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
		'lock-big' => '<svg viewBox="0 0 64 64" fill="none"><path d="M32 4 8 13v17c0 15 10.5 26.5 24 30 13.5-3.5 24-15 24-30V13L32 4z" fill="#0272b1"/><path d="M32 4v56c13.5-3.5 24-15 24-30V13L32 4z" fill="#0a86cc"/><rect x="22" y="28" width="20" height="16" rx="3" fill="#fff"/><path d="M26 28v-4a6 6 0 0 1 12 0v4" stroke="#fff" stroke-width="3"/><circle cx="32" cy="36" r="2.4" fill="#0272b1"/></svg>',
	);
	return $map[ $name ] ?? '';
};

$security_items = array(
	array( 'icon' => 'lock', 'label' => 'رمزگذاری اطلاعات' ),
	array( 'icon' => 'shield', 'label' => 'پایش فعالیت‌ها' ),
	array( 'icon' => 'person', 'label' => 'تأیید هویت' ),
);

$err = isset( $_GET['arp_err'] ) ? sanitize_key( wp_unslash( $_GET['arp_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$err_msg = 'ok' === $err ? '' : Arp_Panel_Actions::message( 'profile', $err );
?>
<section class="arp-refs arp-profile" dir="rtl">

	<header class="arp-refs__head">
		<div class="arp-refs__intro">
			<h1 class="arp-refs__title">
				<span class="drplus-simple-icon-wrap icon-has-bg arp-refs__title-icon" aria-hidden="true">
					<i class="drplus-icon-user drplus-simple-icon"></i>
				</span>
				<span class="arp-refs__title-text">پروفایل من</span>
			</h1>
			<p class="arp-refs__lead">مشاهده و ویرایش اطلاعات حساب کاربری شما</p>
		</div>
	</header>

	<?php if ( '' !== $err_msg ) : ?>
		<div class="arp-notice arp-notice--error" role="alert"><?php echo esc_html( $err_msg ); ?></div>
	<?php endif; ?>

	<div class="arp-card arp-profile-hero">
		<div class="arp-profile-hero__info">
			<h2 class="arp-profile-hero__name" data-arp-bind="name"><?php echo esc_html( $name ); ?></h2>
			<span class="arp-profile-hero__status"><span aria-hidden="true"><?php echo $svg( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>فعال</span>
			<ul class="arp-profile-hero__meta">
				<li>
					<span class="arp-profile-hero__icon" aria-hidden="true"><?php echo $svg( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span dir="ltr" data-arp-bind="mobile"><?php echo '' !== $mobile ? esc_html( $arp_fa( $mobile ) ) : '—'; ?></span>
				</li>
				<?php if ( '' !== $user->user_email ) : ?>
					<li><span class="arp-profile-hero__icon" aria-hidden="true"><?php echo $svg( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="arp-profile-hero__en" dir="ltr" data-arp-bind="email"><?php echo esc_html( $user->user_email ); ?></span></li>
				<?php endif; ?>
				<li>
					<span class="arp-profile-hero__icon" aria-hidden="true"><?php echo $svg( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span>نوع شغل: <span data-arp-bind="job"><?php echo '' !== $job_name ? esc_html( $job_name ) : 'ثبت نشده'; ?></span></span>
				</li>
			</ul>
		</div>

		<div class="arp-profile-hero__side">
			<div class="arp-profile-hero__avatar" data-arp-avatar="hero">
				<?php echo get_avatar( $uid, 128, '', '', array( 'class' => 'arp-profile-hero__avatar-img' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<label class="arp-profile-hero__camera" for="arp-avatar-input" aria-label="تغییر تصویر پروفایل" title="آپلود تصویر پروفایل"><?php echo $svg( 'camera' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<button type="button" class="arp-profile-hero__remove" data-arp-avatar-remove aria-label="حذف تصویر پروفایل" title="حذف تصویر پروفایل" <?php echo Arp_Avatars::has_custom( $uid ) ? '' : 'hidden'; ?>><?php echo $svg( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
			<a class="arp-profile-hero__edit" href="#arp-profile-form"><span aria-hidden="true"><?php echo $svg( 'pencil' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>ویرایش پروفایل</a>
		</div>
	</div>

	<div class="arp-card arp-profile-form-card" id="arp-profile-form">
		<h2 class="arp-profile-form-card__title">
			<span aria-hidden="true"><?php echo $svg( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			اطلاعات شخصی
		</h2>

		<form method="post" class="arp-submit-form arp-profile-form" id="arp-profile-form-el" enctype="multipart/form-data" data-arp-ajax="arp_save_profile">
			<div class="arp-form-notice" role="status" aria-live="polite" tabindex="-1" hidden></div>
			<?php wp_nonce_field( 'arp_save_profile' ); ?>
			<input type="hidden" name="arp_action" value="save_profile" />
			<input
				type="file"
				name="arp_avatar"
				id="arp-avatar-input"
				class="arp-profile-avatar-input"
				accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
				hidden
			/>

			<div class="arp-submit-form__row arp-submit-form__row--2">
				<label class="arp-field-block">
					<span class="arp-field-block__label">نام <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="text" name="account_first_name" required maxlength="100" value="<?php echo esc_attr( $first_name ); ?>" placeholder="نام" autocomplete="given-name" />
					</span>
				</label>
				<label class="arp-field-block">
					<span class="arp-field-block__label">نام خانوادگی <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="text" name="account_last_name" required maxlength="100" value="<?php echo esc_attr( $last_name ); ?>" placeholder="نام خانوادگی" autocomplete="family-name" />
					</span>
				</label>
			</div>

			<label class="arp-field-block">
				<span class="arp-field-block__label">نام نمایشی <span class="arp-req" aria-hidden="true">*</span></span>
				<span class="arp-field-block__control">
					<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<input type="text" name="account_display_name" required maxlength="191" value="<?php echo esc_attr( $display ); ?>" autocomplete="nickname" />
				</span>
				<span class="arp-field-hint">به این صورت اسم شما در حساب کاربری دیده خواهد شد.</span>
			</label>

			<div class="arp-submit-form__row arp-submit-form__row--2">
				<label class="arp-field-block">
					<span class="arp-field-block__label">ایمیل <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="email" name="account_email" required value="<?php echo esc_attr( $user->user_email ); ?>" placeholder="example@gmail.com" autocomplete="email" dir="ltr" />
					</span>
				</label>
				<label class="arp-field-block">
					<span class="arp-field-block__label">شماره تماس <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="text" name="drplus_mobile" required maxlength="11" value="<?php echo esc_attr( preg_replace( '/\D+/', '', $mobile ) ); ?>" placeholder="09123456789" inputmode="tel" autocomplete="tel" dir="ltr" data-arp-iran-mobile />
					</span>
				</label>
			</div>

			<div class="arp-submit-form__row arp-submit-form__row--2">
				<label class="arp-field-block">
					<span class="arp-field-block__label">تاریخ تولد <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input
							type="text"
							name="drplus_birthday"
							value="<?php echo esc_attr( $birthday_jalali ); ?>"
							readonly
							autocomplete="off"
							data-arp-jdate="birthday"
							aria-label="تاریخ تولد"
							placeholder="انتخاب تاریخ"
						/>
					</span>
				</label>
				<label class="arp-field-block">
					<span class="arp-field-block__label">کد ملی <span class="arp-req" aria-hidden="true">*</span></span>
					<span class="arp-field-block__control">
						<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="text" name="drplus_nid" required inputmode="numeric" minlength="10" maxlength="10" value="<?php echo esc_attr( $nid ); ?>" placeholder="۰۰۱۲۳۴۵۶۷۸" dir="ltr" />
					</span>
				</label>
			</div>

			<label class="arp-field-block">
				<span class="arp-field-block__label">نوع شغل <span class="arp-req" aria-hidden="true">*</span></span>
				<span class="arp-field-block__control arp-field-block__control--select">
					<span class="arp-field-block__icon" aria-hidden="true"><?php echo $svg( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<select name="job_type_id" required class="arp-select-native" data-arp-select data-arp-select-search>
						<option value="">انتخاب کنید</option>
						<?php foreach ( $job_types as $jt ) : ?>
							<option value="<?php echo esc_attr( (string) $jt['id'] ); ?>" <?php selected( $job_id, (int) $jt['id'] ); ?>><?php echo esc_html( $jt['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="arp-field-block__chevron" aria-hidden="true"><?php echo $svg( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</span>
			</label>

			<label class="arp-field-block">
				<span class="arp-field-block__label">درباره من</span>
				<span class="arp-field-block__control arp-field-block__control--area">
					<textarea name="about" id="arp-about" maxlength="200" rows="3" placeholder="توضیح کوتاهی درباره سابقه و تخصص خود بنویسید ..."><?php echo esc_textarea( $about ); ?></textarea>
					<span class="arp-field-block__meta">
						<span class="arp-field-block__counter"><span id="arp-about-count"><?php echo esc_html( $arp_fa( mb_strlen( $about ) ) ); ?></span>/۲۰۰</span>
					</span>
				</span>
			</label>

			<div class="arp-profile-form__foot">
				<button type="submit" class="arp-submit-btn arp-profile-form__btn">
					<span aria-hidden="true"><?php echo $svg( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					ذخیره اطلاعات
				</button>
			</div>
		</form>
	</div>

	<aside class="arp-profile-security">
		<div class="arp-profile-security__text">
			<strong>امنیت حساب کاربری</strong>
			<span>اطلاعات حساب شما با بالاترین سطح امنیت محافظت می‌شود.</span>
		</div>
		<ul class="arp-profile-security__items">
			<?php foreach ( $security_items as $item ) : ?>
				<li>
					<span class="arp-profile-security__icon" aria-hidden="true"><?php echo $svg( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php echo esc_html( $item['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</aside>

</section>
