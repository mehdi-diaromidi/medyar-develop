<?php
/**
 * @var string $template
 * @var bool   $sms_enabled
 * @var string $aff_template
 * @var bool   $aff_sms_enabled
 * @var bool   $admin_sms_enabled
 * @var int[]  $admin_sms_recipient_ids
 * @var WP_User[] $admin_users
 * @var bool   $faraz_ready
 * @var string $panel_url
 * @var bool   $has_panel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$admin_sms_enabled       = ! empty( $admin_sms_enabled );
$admin_sms_recipient_ids = isset( $admin_sms_recipient_ids ) && is_array( $admin_sms_recipient_ids )
	? array_map( 'absint', $admin_sms_recipient_ids )
	: array();
$admin_users             = isset( $admin_users ) && is_array( $admin_users ) ? $admin_users : array();

$arp_pattern_row = static function ( string $name, string $hint, string $label = 'کد پترن فراز' ): void {
	$value = (string) get_option( $name, '' );
	?>
	<tr>
		<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<input type="text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>"
				class="regular-text" dir="ltr" autocomplete="off"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="خالی = ارسال متن آزاد" />
			<p class="description">
				اگر پر باشد، به‌جای متن آزاد از پترن فراز ارسال می‌شود.
				<?php if ( '' !== $hint ) : ?>
					متغیرهای پترن: <code dir="ltr"><?php echo esc_html( $hint ); ?></code>
				<?php endif; ?>
			</p>
		</td>
	</tr>
	<?php
};
?>
<div class="arp-settings-grid">
	<form method="post" class="arp-settings-main">
		<?php
		wp_nonce_field( 'arp_save_settings' );

		$digest_enabled = '1' === (string) get_option( 'arp_admin_digest_sms_enabled', '1' );
		$digest_tpl     = (string) get_option( 'arp_admin_digest_sms_template', Arp_Admin_Pending_Digest::DEFAULT_TEMPLATE );
		if ( '' === trim( $digest_tpl ) ) {
			$digest_tpl = Arp_Admin_Pending_Digest::DEFAULT_TEMPLATE;
		}
		$digest_counts          = class_exists( 'Arp_Admin_Pending_Digest' ) ? Arp_Admin_Pending_Digest::collect_counts() : array();
		$commission_sms_enabled = '1' === (string) get_option( 'arp_commission_sms_enabled', '1' );
		$commission_tpl         = (string) get_option( 'arp_commission_sms_template', Arp_Faraz_Sms_Gateway::COMMISSION_SMS_TEMPLATE );
		if ( '' === trim( $commission_tpl ) ) {
			$commission_tpl = Arp_Faraz_Sms_Gateway::COMMISSION_SMS_TEMPLATE;
		}
		?>

		<section class="arp-sms-category" aria-labelledby="arp-sms-admin-heading">
			<h2 class="title" id="arp-sms-admin-heading">پیامک‌های ادمین</h2>
			<p class="arp-lead">پیامک‌هایی که برای ادمین‌های انتخاب‌شده ارسال می‌شود. متن‌ها اغلب سیستمی‌اند؛ در صورت نیاز کد پترن فراز را پر کنید.</p>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">تنظیمات مشترک ادمین</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">ارسال پیامک ادمین</th>
						<td>
							<label for="arp_admin_sms_enabled">
								<input type="checkbox" name="arp_admin_sms_enabled" id="arp_admin_sms_enabled" value="1" <?php checked( $admin_sms_enabled ); ?> />
								پیامک‌های ادمین (ثبت‌نام، معرفی، یادآوری شبانه) فعال باشد
							</label>
						</td>
					</tr>
					<?php
					$arp_sms_diag = Arp_Faraz_Sms_Gateway::diag_snapshot();
					$arp_sms_when = static function ( string $key ) use ( $arp_sms_diag ): string {
						$ts = (int) ( $arp_sms_diag[ $key ] ?? 0 );
						return $ts > 0 ? wp_date( 'Y-m-d H:i:s', $ts ) : '—';
					};
					$arp_sms_src  = array(
						'loopback' => 'درخواست پس‌زمینه',
						'cron'     => 'کرون (پلن B)',
					);
					?>
					<tr>
						<th scope="row">وضعیت صف پیامک</th>
						<td id="arp-sms-outbox-diag">
							<?php if ( Arp_Faraz_Sms_Gateway::msq_ready() && class_exists( 'MSQ_Admin' ) ) : ?>
								<?php $arp_msq_counts = MSQ_Queue::counts(); ?>
								<p class="description" style="margin:0;">
									ارسال از طریق «صف پیامک Medyar» با تلاش مجدد خودکار —
									در صف: <strong><?php echo esc_html( (string) ( $arp_msq_counts['pending'] + $arp_msq_counts['reserved'] ) ); ?></strong>
									· ناموفق: <strong><?php echo esc_html( (string) $arp_msq_counts['dead'] ); ?></strong>
									· <a href="<?php echo esc_url( MSQ_Admin::url( array( 'channel' => 'arp' ) ) ); ?>">گزارش ارسال پیامک‌های طرح همکاران</a>
								</p>
							<?php else : ?>
							<p class="description" style="margin:0;">
								در صف: <strong data-arp-diag="queued"><?php echo esc_html( (string) $arp_sms_diag['queued'] ); ?></strong>
								· آخرین شلیک پس‌زمینه: <span data-arp-diag="spawn_at"><?php echo esc_html( $arp_sms_when( 'spawn_at' ) ); ?></span>
								· آخرین اجرای ورکر: <span data-arp-diag="worker_at"><?php echo esc_html( $arp_sms_when( 'worker_at' ) ); ?></span>
								· آخرین ارسال: <span data-arp-diag="drain_at"><?php echo esc_html( $arp_sms_when( 'drain_at' ) ); ?></span>
								(<span data-arp-diag="drain_src"><?php echo esc_html( $arp_sms_src[ (string) ( $arp_sms_diag['drain_src'] ?? '' ) ] ?? '—' ); ?></span>)
							</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">گیرندگان</th>
						<td>
							<?php if ( empty( $admin_users ) ) : ?>
								<p class="description" style="margin:0;">کاربری با نقش مدیرکل یافت نشد.</p>
							<?php else : ?>
								<div class="arp-admin-picker" id="arp-admin-sms-recipients">
									<?php
									foreach ( $admin_users as $admin_user ) :
										if ( ! $admin_user instanceof WP_User ) {
											continue;
										}
										$uid      = (int) $admin_user->ID;
										$display  = $admin_user->display_name ?: $admin_user->user_login;
										$mobile   = Arp_Faraz_Sms_Gateway::admin_mobile( $uid );
										$input_id = 'arp_admin_sms_recipient_' . $uid;
										?>
										<label class="arp-admin-picker__item" for="<?php echo esc_attr( $input_id ); ?>">
											<input type="checkbox"
												name="arp_admin_sms_recipient_ids[]"
												id="<?php echo esc_attr( $input_id ); ?>"
												value="<?php echo esc_attr( (string) $uid ); ?>"
												<?php checked( in_array( $uid, $admin_sms_recipient_ids, true ) ); ?> />
											<?php echo get_avatar( $uid, 32, '', $display, array( 'class' => 'arp-admin-picker__avatar' ) ); ?>
											<span class="arp-admin-picker__meta">
												<span class="arp-admin-picker__name"><?php echo esc_html( $display ); ?></span>
												<span class="arp-admin-picker__sub">
													@<?php echo esc_html( $admin_user->user_login ); ?>
													<?php if ( '' !== $mobile ) : ?>
														· <span dir="ltr"><?php echo esc_html( $mobile ); ?></span>
													<?php else : ?>
														· <span class="arp-admin-picker__no-mobile">بدون موبایل</span>
													<?php endif; ?>
												</span>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description">فقط کاربران با نقش مدیرکل (administrator). شماره از فیلد Billing phone (تلفن صورتحساب) خوانده می‌شود؛ ادمین بدون این فیلد پیامک نمی‌گیرد.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">۱. ثبت‌نام همکار جدید</h3>
				<p class="description arp-sms-category__hint">وقتی متقاضی برای طرح همکاران ثبت‌نام می‌کند و در صف بررسی قرار می‌گیرد.</p>
				<table class="form-table" role="presentation">
					<?php $arp_pattern_row( 'arp_admin_signup_sms_pattern', 'name, mobile, job, docs, url', 'پترن ثبت‌نام همکار' ); ?>
				</table>
			</div>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">۲. معرفی بیمار جدید</h3>
				<p class="description arp-sms-category__hint">وقتی همکار یک معرفی بیمار جدید ثبت می‌کند.</p>
				<table class="form-table" role="presentation">
					<?php $arp_pattern_row( 'arp_admin_referral_sms_pattern', 'affiliate_name, affiliate_code, patient_name, patient_phone, service, referral_id', 'پترن معرفی جدید' ); ?>
				</table>
			</div>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">۳. یادآوری شبانه صف‌ها (۲۲:۰۰ تهران)</h3>
				<p class="description arp-sms-category__hint">هر شب حدود ساعت ۲۲ تهران، اگر هنوز موردی در صف تأیید ادمین باشد، خلاصه برای گیرندگان بالا ارسال می‌شود.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">ارسال یادآوری</th>
						<td>
							<label for="arp_admin_digest_sms_enabled">
								<input type="checkbox" name="arp_admin_digest_sms_enabled" id="arp_admin_digest_sms_enabled" value="1" <?php checked( $digest_enabled ); ?> />
								یادآوری شبانه فعال باشد
							</label>
							<?php if ( ! empty( $digest_counts ) ) : ?>
								<p class="description" style="margin-top:8px;">
									وضعیت فعلی صف:
									برداشت <?php echo esc_html( (string) ( $digest_counts['withdraw'] ?? 0 ) ); ?>،
									همکار <?php echo esc_html( (string) ( $digest_counts['affiliate'] ?? 0 ) ); ?>،
									معرفی <?php echo esc_html( (string) ( $digest_counts['referral'] ?? 0 ) ); ?>
									<?php if ( ! empty( $digest_counts['docs'] ) ) : ?>
										، مدارک <?php echo esc_html( (string) $digest_counts['docs'] ); ?>
									<?php endif; ?>
									<?php if ( ! empty( $digest_counts['commission'] ) ) : ?>
										، پورسانت قدیمی <?php echo esc_html( (string) $digest_counts['commission'] ); ?>
									<?php endif; ?>
									— جمع <?php echo esc_html( (string) ( $digest_counts['total'] ?? 0 ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="arp_admin_digest_sms_template">متن پیامک</label></th>
						<td>
							<textarea name="arp_admin_digest_sms_template" id="arp_admin_digest_sms_template" class="large-text" rows="7"><?php echo esc_textarea( $digest_tpl ); ?></textarea>
							<p class="description">
								متغیرها:
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{withdraw}"><code>{withdraw}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{affiliate}"><code>{affiliate}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{referral}"><code>{referral}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{docs}"><code>{docs}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{commission}"><code>{commission}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{total}"><code>{total}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{lines}"><code>{lines}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{date}"><code>{date}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_admin_digest_sms_template" data-var="{url}"><code>{url}</code></button>
							</p>
							<p class="description arp-sms-counter" data-for="arp_admin_digest_sms_template" aria-live="polite"></p>
						</td>
					</tr>
					<?php $arp_pattern_row( 'arp_admin_digest_sms_pattern', 'withdraw, affiliate, referral, docs, commission, total, lines, date, url', 'پترن یادآوری شبانه' ); ?>
				</table>
			</div>
		</section>

		<section class="arp-sms-category" aria-labelledby="arp-sms-affiliate-heading">
			<h2 class="title" id="arp-sms-affiliate-heading">پیامک‌های همکار</h2>
			<p class="arp-lead">پیامک‌هایی که برای همکار معرف یا متقاضی طرح همکاران ارسال می‌شود.</p>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">۱. تغییر وضعیت معرفی</h3>
				<p class="description arp-sms-category__hint">هر بار که وضعیت یک معرفی تغییر کند، برای همکارِ ثبت‌کننده ارسال می‌شود.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">ارسال پیامک</th>
						<td>
							<label for="arp_affiliate_sms_enabled">
								<input type="checkbox" name="arp_affiliate_sms_enabled" id="arp_affiliate_sms_enabled" value="1" <?php checked( $aff_sms_enabled ); ?> />
								با تغییر وضعیت معرفی برای همکار پیامک ارسال شود
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="arp_affiliate_sms_template">متن پیامک</label></th>
						<td>
							<textarea name="arp_affiliate_sms_template" id="arp_affiliate_sms_template" class="large-text" rows="4"><?php echo esc_textarea( $aff_template ); ?></textarea>
							<p class="description">
								متغیرها (برای درج کلیک کنید):
								<button type="button" class="button-link arp-insert-var" data-target="arp_affiliate_sms_template" data-var="{affiliate_name}"><code>{affiliate_name}</code></button> نام همکار،
								<button type="button" class="button-link arp-insert-var" data-target="arp_affiliate_sms_template" data-var="{patient_name}"><code>{patient_name}</code></button> نام بیمار،
								<button type="button" class="button-link arp-insert-var" data-target="arp_affiliate_sms_template" data-var="{service}"><code>{service}</code></button> خدمت،
								<button type="button" class="button-link arp-insert-var" data-target="arp_affiliate_sms_template" data-var="{status}"><code>{status}</code></button> وضعیت جدید
							</p>
							<p class="description arp-sms-counter" data-for="arp_affiliate_sms_template" aria-live="polite"></p>
						</td>
					</tr>
					<?php $arp_pattern_row( 'arp_affiliate_sms_pattern', 'affiliate_name, patient_name, service, status', 'پترن تغییر وضعیت' ); ?>
				</table>
			</div>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">۲. تثبیت پورسانت</h3>
				<p class="description arp-sms-category__hint">وقتی وضعیت معرفی به مرحلهٔ دارای پورسانت برسد و مبلغ به کیف پول واریز شود.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">ارسال پیامک</th>
						<td>
							<label for="arp_commission_sms_enabled">
								<input type="checkbox" name="arp_commission_sms_enabled" id="arp_commission_sms_enabled" value="1" <?php checked( $commission_sms_enabled ); ?> />
								با تثبیت پورسانت برای همکار پیامک ارسال شود
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="arp_commission_sms_template">متن پیامک</label></th>
						<td>
							<textarea name="arp_commission_sms_template" id="arp_commission_sms_template" class="large-text" rows="4"><?php echo esc_textarea( $commission_tpl ); ?></textarea>
							<p class="description">
								متغیرها:
								<button type="button" class="button-link arp-insert-var" data-target="arp_commission_sms_template" data-var="{affiliate_name}"><code>{affiliate_name}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_commission_sms_template" data-var="{patient_name}"><code>{patient_name}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_commission_sms_template" data-var="{service}"><code>{service}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_commission_sms_template" data-var="{amount}"><code>{amount}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_commission_sms_template" data-var="{referral_id}"><code>{referral_id}</code></button>،
								<button type="button" class="button-link arp-insert-var" data-target="arp_commission_sms_template" data-var="{commission_id}"><code>{commission_id}</code></button>
							</p>
							<p class="description arp-sms-counter" data-for="arp_commission_sms_template" aria-live="polite"></p>
						</td>
					</tr>
					<?php $arp_pattern_row( 'arp_commission_sms_pattern', 'affiliate_name, patient_name, service, amount, referral_id, commission_id', 'پترن پورسانت' ); ?>
				</table>
			</div>

			<div class="arp-sms-category__item">
				<h3 class="arp-sms-category__subtitle">۳. متقاضی (صف بررسی)</h3>
				<p class="description arp-sms-category__hint">پیامک به خودِ متقاضی هنگام ورود به صف، تأیید یا رد. متن سیستمی است؛ فقط در صورت نیاز پترن را وارد کنید.</p>
				<table class="form-table" role="presentation">
					<?php
					$arp_pattern_row( 'arp_applicant_pending_sms_pattern', 'name', 'پترن صف بررسی' );
					$arp_pattern_row( 'arp_applicant_approved_sms_pattern', 'name', 'پترن تأیید' );
					$arp_pattern_row( 'arp_applicant_rejected_sms_pattern', 'name, note', 'پترن رد' );
					?>
				</table>
			</div>
		</section>

		<section class="arp-sms-category" aria-labelledby="arp-sms-patient-heading">
			<h2 class="title" id="arp-sms-patient-heading">پیامک بیمار</h2>
			<p class="arp-lead">بعد از ثبت هر معرفی، یک پیامک برای بیمار فرستاده می‌شود.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">ارسال پیامک</th>
					<td>
						<label for="arp_patient_sms_enabled">
							<input type="checkbox" name="arp_patient_sms_enabled" id="arp_patient_sms_enabled" value="1" <?php checked( $sms_enabled ); ?> />
							بعد از ثبت معرفی برای بیمار پیامک ارسال شود
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="arp_patient_sms_template">متن پیامک</label></th>
					<td>
						<textarea name="arp_patient_sms_template" id="arp_patient_sms_template" class="large-text" rows="4"><?php echo esc_textarea( $template ); ?></textarea>
						<p class="description">
							متغیرها (برای درج کلیک کنید):
							<button type="button" class="button-link arp-insert-var" data-target="arp_patient_sms_template" data-var="{patient_name}"><code>{patient_name}</code></button> نام بیمار،
							<button type="button" class="button-link arp-insert-var" data-target="arp_patient_sms_template" data-var="{affiliate_code}"><code>{affiliate_code}</code></button> کد همکار
						</p>
						<p class="description arp-sms-counter" data-for="arp_patient_sms_template" aria-live="polite"></p>
					</td>
				</tr>
				<?php $arp_pattern_row( 'arp_patient_sms_pattern', 'patient_name, affiliate_code' ); ?>
			</table>
		</section>

		<?php
		$special_inviter_ids   = isset( $special_inviter_ids ) && is_array( $special_inviter_ids )
			? array_map( 'absint', $special_inviter_ids )
			: Arp_Inviter::special_inviter_ids();
		$special_inviter_users = isset( $special_inviter_users ) && is_array( $special_inviter_users )
			? $special_inviter_users
			: array();
		?>
		<section class="arp-sms-category" aria-labelledby="arp-special-inviters-heading">
			<h2 class="title" id="arp-special-inviters-heading">یوزرهای خاص دعوت</h2>
			<p class="arp-lead">این کاربران در تب «همکاران معرفی‌شده» پنل همکار، جزئیات پورسانت همکاران دعوت‌شده خود را می‌بینند. بقیه فقط لیست دعوت‌شده‌ها را می‌بینند.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">انتخاب کاربران</th>
					<td>
						<?php if ( empty( $special_inviter_users ) ) : ?>
							<p class="description" style="margin:0;">کاربری یافت نشد.</p>
						<?php else : ?>
							<p style="margin:0 0 8px;">
								<label class="screen-reader-text" for="arp-special-inviters-filter">جستجوی کاربر</label>
								<input type="search" id="arp-special-inviters-filter" class="regular-text" placeholder="جستجو نام، نام کاربری یا شماره…" autocomplete="off" />
							</p>
							<div class="arp-admin-picker" id="arp-special-inviters">
								<?php
								foreach ( $special_inviter_users as $special_user ) :
									if ( ! $special_user instanceof WP_User ) {
										continue;
									}
									$uid      = (int) $special_user->ID;
									$display  = $special_user->display_name ?: $special_user->user_login;
									$input_id = 'arp_special_inviter_' . $uid;
									$code     = (string) get_user_meta( $uid, Arp_Inviter::META_OWN_CODE, true );
									$haystack = strtolower( $display . ' ' . $special_user->user_login . ' ' . $uid . ' ' . $code );
									?>
									<label class="arp-admin-picker__item" for="<?php echo esc_attr( $input_id ); ?>" data-arp-filter="<?php echo esc_attr( $haystack ); ?>">
										<input type="checkbox"
											name="arp_special_inviter_ids[]"
											id="<?php echo esc_attr( $input_id ); ?>"
											value="<?php echo esc_attr( (string) $uid ); ?>"
											<?php checked( in_array( $uid, $special_inviter_ids, true ) ); ?> />
										<?php echo get_avatar( $uid, 32, '', $display, array( 'class' => 'arp-admin-picker__avatar' ) ); ?>
										<span class="arp-admin-picker__meta">
											<span class="arp-admin-picker__name"><?php echo esc_html( $display ); ?></span>
											<span class="arp-admin-picker__sub">
												#<?php echo esc_html( (string) $uid ); ?>
												· @<?php echo esc_html( $special_user->user_login ); ?>
												<?php if ( '' !== $code ) : ?>
													· <code dir="ltr"><?php echo esc_html( $code ); ?></code>
												<?php endif; ?>
											</span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description">همه کاربران سایت در لیست هستند. پیش‌فرض اولیه کاربر ۱۱۸ است تا وقتی تنظیمات را ذخیره کنید. خالی گذاشتن یعنی هیچ‌کس نمای مالی دعوت را نمی‌بیند.</p>
							<script>
							(function () {
								var input = document.getElementById('arp-special-inviters-filter');
								var box = document.getElementById('arp-special-inviters');
								if (!input || !box) return;
								input.addEventListener('input', function () {
									var q = (input.value || '').toLowerCase().trim();
									box.querySelectorAll('.arp-admin-picker__item').forEach(function (el) {
										var hay = el.getAttribute('data-arp-filter') || '';
										el.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
									});
								});
							})();
							</script>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</section>

		<?php submit_button( 'ذخیره تنظیمات', 'primary', 'arp_save_settings' ); ?>
	</form>

	<div class="arp-settings-side">
		<div class="postbox">
			<div class="postbox-header"><h2>وضعیت سرویس‌ها</h2></div>
			<div class="inside">
				<ul class="arp-checks">
					<li class="<?php echo $faraz_ready ? 'is-ok' : 'is-bad'; ?>">
						<span class="dashicons <?php echo $faraz_ready ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
						<?php if ( $faraz_ready ) : ?>
							Faraz SMS آماده است
						<?php else : ?>
							Faraz SMS آماده نیست — افزونه را فعال و API Key و شماره فرستنده را تنظیم کنید.
						<?php endif; ?>
					</li>
					<li class="<?php echo $has_panel ? 'is-ok' : 'is-bad'; ?>">
						<span class="dashicons <?php echo $has_panel ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
						<?php if ( $has_panel ) : ?>
							صفحه پنل همکاران: <a href="<?php echo esc_url( $panel_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $panel_url, PHP_URL_PATH ) ?: $panel_url ); ?></a>
						<?php else : ?>
							هیچ برگه‌ای با قالب «داشبورد همکار» منتشر نشده است.
						<?php endif; ?>
					</li>
				</ul>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header"><h2>ارسال پیامک آزمایشی</h2></div>
			<div class="inside">
				<form method="post">
					<?php wp_nonce_field( 'arp_test_sms' ); ?>
					<p>متن ذخیره‌شده با مقادیر نمونه به این شماره فرستاده می‌شود (حتی اگر ارسال خودکار خاموش باشد).</p>
					<p>
						<label for="arp-test-template" class="screen-reader-text">کدام پیامک</label>
						<select name="test_template" id="arp-test-template" class="widefat" <?php disabled( ! $faraz_ready ); ?>>
							<optgroup label="ادمین">
								<option value="admin_signup">ثبت‌نام همکار</option>
								<option value="admin_referral">معرفی بیمار جدید</option>
							</optgroup>
							<optgroup label="همکار">
								<option value="affiliate">تغییر وضعیت معرفی</option>
								<option value="commission">تثبیت پورسانت</option>
								<option value="applicant_pending">متقاضی — صف بررسی</option>
								<option value="applicant_approved">متقاضی — تأیید</option>
								<option value="applicant_rejected">متقاضی — رد</option>
							</optgroup>
							<optgroup label="بیمار">
								<option value="patient">ثبت معرفی</option>
							</optgroup>
						</select>
					</p>
					<p>
						<label for="arp-test-phone" class="screen-reader-text">شماره موبایل</label>
						<input type="tel" name="test_phone" id="arp-test-phone" class="widefat" dir="ltr" placeholder="09123456789" required <?php disabled( ! $faraz_ready ); ?> />
					</p>
					<?php submit_button( 'ارسال آزمایشی', 'secondary', 'arp_test_sms', false, $faraz_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header"><h2>ارسال یادآوری صف‌ها الان</h2></div>
			<div class="inside">
				<form method="post">
					<?php wp_nonce_field( 'arp_send_digest_now' ); ?>
					<p>همان پیامک شبانه را فوراً برای گیرندگان انتخاب‌شده می‌فرستد (اگر جمع صف صفر باشد ارسال نمی‌شود).</p>
					<?php submit_button( 'ارسال یادآوری الان', 'secondary', 'arp_send_digest_now', false, $faraz_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			</div>
		</div>
	</div>
</div>
