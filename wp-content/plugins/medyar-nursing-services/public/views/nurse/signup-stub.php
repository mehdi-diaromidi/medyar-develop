<?php
/**
 * Nurse signup — real apply form + status states (T-1.2).
 *
 * @package medyar-nursing-services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

MNS_UI::boot_icons();

$user_id = get_current_user_id();
$status  = MNS_Nurse_Access::status( $user_id );
$state   = 'form';
if ( in_array( $status, array( 'pending', 'rejected', 'suspended', 'approved' ), true ) ) {
	$state = $status;
}
// Dev preview override for admins.
if ( class_exists( 'MNS_Dev' ) && MNS_Dev::enabled() && current_user_can( 'manage_options' ) ) {
	$raw = isset( $_GET['mns_state'] ) ? sanitize_key( wp_unslash( $_GET['mns_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( in_array( $raw, array( 'form', 'pending', 'rejected', 'suspended', 'approved' ), true ) ) {
		$state = $raw;
	}
}
if ( isset( $_GET['mns_state'] ) && 'pending' === sanitize_key( wp_unslash( $_GET['mns_state'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$state = 'pending';
}

$err   = isset( $_GET['mns_err'] ) ? sanitize_text_field( wp_unslash( $_GET['mns_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$field = isset( $_GET['mns_field'] ) ? sanitize_key( wp_unslash( $_GET['mns_field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$saved = get_transient( 'mns_nurse_apply_form_' . $user_id );
if ( is_array( $saved ) ) {
	delete_transient( 'mns_nurse_apply_form_' . $user_id );
} else {
	$saved = array();
}

$user  = wp_get_current_user();
$first = isset( $saved['first_name'] ) ? (string) $saved['first_name'] : (string) $user->first_name;
$last  = isset( $saved['last_name'] ) ? (string) $saved['last_name'] : (string) $user->last_name;
$mobile = '';
if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'get_phone' ) ) ) {
	$mobile = (string) \DrPlus\Utils\User::get_phone( $user_id );
}
$contract = class_exists( 'MNS_Contract' ) ? MNS_Contract::active() : null;
$row      = MNS_Nurse_Access::nurse_row( $user_id );
$panel    = MNS_Pages::panel_url( 'nurse', 'dashboard' );
?>
<section class="mns-nsu" dir="rtl">
	<header class="mns-nsu__hero">
		<span class="mns-nsu__hero-icon" aria-hidden="true"></span>
		<h1 class="mns-nsu__title"><?php echo esc_html__( 'عضویت پرستار مدیار', 'medyar-nursing-services' ); ?></h1>
		<p class="mns-nsu__lead"><?php echo esc_html__( 'اطلاعات و مدارک خود را ارسال کنید؛ پس از بررسی، پنل پرستار فعال می‌شود.', 'medyar-nursing-services' ); ?></p>
	</header>

	<?php if ( 'pending' === $state ) : ?>
		<div class="mns-nsu-card mns-nsu-card--status">
			<h2><?php echo esc_html__( 'در حال بررسی', 'medyar-nursing-services' ); ?></h2>
			<p><?php echo esc_html__( 'درخواست شما ثبت شد و توسط همکاران ما بررسی می‌شود.', 'medyar-nursing-services' ); ?></p>
		</div>
	<?php elseif ( 'rejected' === $state ) : ?>
		<div class="mns-nsu-card mns-nsu-card--status">
			<h2><?php echo esc_html__( 'درخواست رد شد', 'medyar-nursing-services' ); ?></h2>
			<p><?php echo esc_html( $row && ! empty( $row['reject_note'] ) ? (string) $row['reject_note'] : __( 'می‌توانید دوباره ارسال کنید.', 'medyar-nursing-services' ) ); ?></p>
			<p><a class="mns-nsu-btn" href="<?php echo esc_url( remove_query_arg( array( 'mns_state', 'mns_err' ) ) ); ?>"><?php echo esc_html__( 'ارسال مجدد', 'medyar-nursing-services' ); ?></a></p>
		</div>
	<?php elseif ( 'suspended' === $state ) : ?>
		<div class="mns-nsu-card mns-nsu-card--status">
			<h2><?php echo esc_html__( 'حساب موقتاً غیرفعال است', 'medyar-nursing-services' ); ?></h2>
			<p><?php echo esc_html__( 'برای پیگیری با پشتیبانی تماس بگیرید.', 'medyar-nursing-services' ); ?></p>
		</div>
	<?php elseif ( 'approved' === $state ) : ?>
		<div class="mns-nsu-card mns-nsu-card--status">
			<h2><?php echo esc_html__( 'عضویت تأیید شد', 'medyar-nursing-services' ); ?></h2>
			<p><a class="mns-nsu-btn" href="<?php echo esc_url( $panel ); ?>"><?php echo esc_html__( 'ورود به پنل پرستار', 'medyar-nursing-services' ); ?></a></p>
		</div>
	<?php else : ?>
		<div class="mns-nsu__grid">
			<div class="mns-nsu-card mns-nsu-card--form">
				<h2 class="mns-nsu-card__title"><?php echo esc_html__( 'اطلاعات عضویت', 'medyar-nursing-services' ); ?></h2>
				<?php if ( $err ) : ?>
					<p class="mns-nsu-error" data-field="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $err ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="mns-nsu-form" novalidate>
					<?php wp_nonce_field( 'mns_nurse_apply' ); ?>
					<input type="hidden" name="action" value="mns_nurse_apply" />
					<div class="mns-field-block mns-nsu-row">
						<label><?php echo esc_html__( 'نام', 'medyar-nursing-services' ); ?></label>
						<input type="text" name="first_name" required value="<?php echo esc_attr( $first ); ?>" />
					</div>
					<div class="mns-field-block mns-nsu-row">
						<label><?php echo esc_html__( 'نام خانوادگی', 'medyar-nursing-services' ); ?></label>
						<input type="text" name="last_name" required value="<?php echo esc_attr( $last ); ?>" />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'موبایل', 'medyar-nursing-services' ); ?></label>
						<input type="text" value="<?php echo esc_attr( $mobile ); ?>" disabled />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'جنسیت', 'medyar-nursing-services' ); ?></label>
						<select name="gender" required id="mns-nsu-gender">
							<option value=""><?php echo esc_html__( 'انتخاب کنید', 'medyar-nursing-services' ); ?></option>
							<option value="female" <?php selected( isset( $saved['gender'] ) ? $saved['gender'] : '', 'female' ); ?>><?php echo esc_html__( 'زن', 'medyar-nursing-services' ); ?></option>
							<option value="male" <?php selected( isset( $saved['gender'] ) ? $saved['gender'] : '', 'male' ); ?>><?php echo esc_html__( 'مرد', 'medyar-nursing-services' ); ?></option>
						</select>
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'کد ملی', 'medyar-nursing-services' ); ?></label>
						<input type="text" name="national_id" required maxlength="10" value="<?php echo esc_attr( isset( $saved['national_id'] ) ? (string) $saved['national_id'] : '' ); ?>" />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'شماره نظام پرستاری', 'medyar-nursing-services' ); ?></label>
						<input type="text" name="license_no" required value="<?php echo esc_attr( isset( $saved['license_no'] ) ? (string) $saved['license_no'] : '' ); ?>" />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'مدرک', 'medyar-nursing-services' ); ?></label>
						<select name="qualification" required>
							<?php foreach ( array( 'diploma' => 'دیپلم', 'assoc' => 'کاردانی', 'bsc' => 'کارشناسی', 'msc' => 'کارشناسی ارشد', 'phd' => 'دکتری' ) as $k => $lab ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $lab ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'سابقه', 'medyar-nursing-services' ); ?></label>
						<select name="experience" required>
							<?php foreach ( array( '0-1', '1-3', '3-5', '5-10', '10+' ) as $k ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $k ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'محل کار', 'medyar-nursing-services' ); ?></label>
						<input type="text" name="workplace" required value="<?php echo esc_attr( isset( $saved['workplace'] ) ? (string) $saved['workplace'] : '' ); ?>" />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'کارت ملی', 'medyar-nursing-services' ); ?></label>
						<input type="file" name="national_card" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
					</div>
					<div class="mns-field-block" id="mns-nsu-military" hidden>
						<label><?php echo esc_html__( 'کارت پایان خدمت / معافیت', 'medyar-nursing-services' ); ?></label>
						<input type="file" name="military_card" accept=".jpg,.jpeg,.png,.webp,.pdf" />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'کارت نظام پرستاری', 'medyar-nursing-services' ); ?></label>
						<input type="file" name="license_card" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'عکس پروفایل', 'medyar-nursing-services' ); ?></label>
						<input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" required />
					</div>
					<div class="mns-field-block">
						<label><?php echo esc_html__( 'درباره من', 'medyar-nursing-services' ); ?></label>
						<textarea name="about" maxlength="200" rows="3"><?php echo esc_textarea( isset( $saved['about'] ) ? (string) $saved['about'] : '' ); ?></textarea>
					</div>
					<?php if ( $contract ) : ?>
						<details class="mns-nsu-contract">
							<summary><?php echo esc_html( (string) $contract['title'] ); ?></summary>
							<div class="mns-nsu-contract__body"><?php echo wp_kses_post( nl2br( esc_html( (string) $contract['body'] ) ) ); ?></div>
						</details>
						<label class="mns-nsu-check"><input type="checkbox" name="accept_contract" value="1" required /> <?php echo esc_html__( 'متن قرارداد را می‌پذیرم', 'medyar-nursing-services' ); ?></label>
					<?php endif; ?>
					<button type="submit" class="mns-nsu-btn"><?php echo esc_html__( 'ارسال درخواست', 'medyar-nursing-services' ); ?></button>
				</form>
			</div>
			<aside class="mns-nsu-card mns-nsu-card--how">
				<h2 class="mns-nsu-card__title"><?php echo esc_html__( 'چطور کار می‌کند؟', 'medyar-nursing-services' ); ?></h2>
				<ol class="mns-nsu-steps">
					<li><?php echo esc_html__( 'تکمیل اطلاعات و مدارک', 'medyar-nursing-services' ); ?></li>
					<li><?php echo esc_html__( 'بررسی توسط ادمین', 'medyar-nursing-services' ); ?></li>
					<li><?php echo esc_html__( 'تنظیم برنامه و مناطق', 'medyar-nursing-services' ); ?></li>
					<li><?php echo esc_html__( 'دریافت سفارش', 'medyar-nursing-services' ); ?></li>
				</ol>
			</aside>
		</div>
		<script>
		(function(){
			var g=document.getElementById('mns-nsu-gender');
			var m=document.getElementById('mns-nsu-military');
			if(!g||!m) return;
			function sync(){ m.hidden = g.value !== 'male'; }
			g.addEventListener('change', sync); sync();
		})();
		</script>
	<?php endif; ?>
</section>
<?php
/* Minimal CSS injected once if public.css not yet patched — also appended to public.css */ ?>
<style id="mns-nsu-inline">
.mns-nsu{max-width:980px;margin:0 auto;padding:24px 16px}
.mns-nsu__hero{text-align:center;margin-bottom:24px}
.mns-nsu__title{font-size:1.5rem;font-weight:800;margin:8px 0}
.mns-nsu__lead{color:#567;margin:0}
.mns-nsu__grid{display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
@media(max-width:800px){.mns-nsu__grid{grid-template-columns:1fr}}
.mns-nsu-card{background:#fff;border:1px solid #e3e8ee;border-radius:14px;padding:20px}
.mns-nsu-card__title{font-size:1.1rem;font-weight:700;margin:0 0 12px}
.mns-nsu-form .mns-field-block{margin-bottom:12px}
.mns-nsu-form input,.mns-nsu-form select,.mns-nsu-form textarea{width:100%;min-height:50px;border:1px solid #cfd8e3;border-radius:12px;padding:10px 12px;font-size:16px}
.mns-nsu-btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 20px;border:0;border-radius:12px;background:var(--mns-primary,#0272b1);color:#fff;font-weight:600;cursor:pointer;text-decoration:none}
.mns-nsu-error{color:#b42318;background:#fef3f2;border-radius:10px;padding:10px 12px}
.mns-nsu-steps{margin:0;padding-inline-start:18px;line-height:1.9}
.mns-nsu-contract{margin:12px 0;border:1px solid #e3e8ee;border-radius:10px;padding:10px}
</style>
