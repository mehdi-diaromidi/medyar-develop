<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var array $settings
 * @var array $vars
 */
?>
<form method="post" action="options.php" id="mmad_admin_reserve_sms_form">
	<?php settings_fields( 'medyar_admin_reserve_sms_group' ); ?>
	<input type="hidden" name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[_tab]" value="sms" />

	<section class="mmad-sms-category" aria-labelledby="mmad-sms-admin-heading">
		<h2 class="title" id="mmad-sms-admin-heading">وضعیت و شماره‌های ادمین</h2>
		<p class="mmad-sms-lead">پیامک اعلان رزرو فقط وقتی فعال باشد برای شماره‌های زیر ارسال می‌شود.</p>

		<div class="mmad-sms-category__item">
			<h3 class="mmad-sms-category__subtitle">وضعیت</h3>
			<p class="description mmad-sms-category__hint">با فعال‌سازی، هنگام ثبت و لغو رزرو، پیامک الگو به شماره‌های ادمین ارسال می‌شود.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mmad_admin_sms_enabled">فعال‌سازی</label></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[enabled]" id="mmad_admin_sms_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
							پیامک ادمین رزرو فعال باشد
						</label>
					</td>
				</tr>
			</table>
		</div>

		<div class="mmad-sms-category__item">
			<h3 class="mmad-sms-category__subtitle">گیرندگان ادمین</h3>
			<p class="description mmad-sms-category__hint">گیرندگان پیامک هنگام ثبت یا لغو رزرو. فقط افراد تیک‌خورده پیامک می‌گیرند.</p>
			<?php
			$selected_ids = MMAD_Admin_Reserve_SMS::selected_admin_ids( $settings );
			$picker_name  = MMAD_Admin_Reserve_SMS::OPTION . '[admin_ids][]';
			?>
			<div class="mmad-admin-picker" id="mmad_admin_ids">
				<?php if ( empty( $admin_users ) ) : ?>
					<p class="description" style="margin:0;">کاربری با نقش مدیرکل یافت نشد.</p>
				<?php else : ?>
					<?php
					foreach ( $admin_users as $admin_user ) :
						if ( ! $admin_user instanceof WP_User ) {
							continue;
						}
						$uid      = (int) $admin_user->ID;
						$display  = $admin_user->display_name ?: $admin_user->user_login;
						$input_id = 'mmad_admin_ids-' . $uid;
						$mobile   = MMAD_Admin_Reserve_SMS::user_mobile( $admin_user );
						?>
						<label class="mmad-admin-picker__item" for="<?php echo esc_attr( $input_id ); ?>">
							<input type="checkbox" name="<?php echo esc_attr( $picker_name ); ?>" id="<?php echo esc_attr( $input_id ); ?>"
								value="<?php echo esc_attr( (string) $uid ); ?>" <?php checked( in_array( $uid, $selected_ids, true ) ); ?>>
							<?php echo get_avatar( $uid, 32, '', $display, [ 'class' => 'mmad-admin-picker__avatar' ] ); ?>
							<span class="mmad-admin-picker__meta">
								<span class="mmad-admin-picker__name"><?php echo esc_html( $display ); ?></span>
								<span class="mmad-admin-picker__sub">
									@<?php echo esc_html( $admin_user->user_login ); ?>
									· مدیرکل
									<?php if ( $mobile !== '' ) : ?>
										· <span dir="ltr"><?php echo esc_html( $mobile ); ?></span>
									<?php else : ?>
										· <span class="mmad-admin-picker__warn">بدون موبایل</span>
									<?php endif; ?>
								</span>
							</span>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php
	$events = [
		'book'          => [
			'title' => 'پیامک ثبت رزرو به ادمین',
			'hint'  => 'وقتی رزرو با موفقیت ثبت می‌شود، برای شماره‌های ادمین بالا ارسال می‌شود.',
		],
		'book_canceled' => [
			'title' => 'پیامک لغو رزرو به ادمین',
			'hint'  => 'وقتی رزرو لغو می‌شود، برای شماره‌های ادمین بالا ارسال می‌شود.',
		],
	];
	foreach ( $events as $event_key => $event_meta ) :
		$ev = $settings[ $event_key ];
		?>
		<section class="mmad-sms-category" aria-labelledby="mmad-sms-<?php echo esc_attr( $event_key ); ?>-heading">
			<h2 class="title" id="mmad-sms-<?php echo esc_attr( $event_key ); ?>-heading"><?php echo esc_html( $event_meta['title'] ); ?></h2>
			<p class="mmad-sms-lead"><?php echo esc_html( $event_meta['hint'] ); ?></p>

			<div class="mmad-sms-category__item">
				<h3 class="mmad-sms-category__subtitle">تنظیمات الگو</h3>
				<p class="description mmad-sms-category__hint">کد الگو و متغیرها مشابه بخش یادآور پیامک رزرو دکترپلاس هستند.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mmad_pattern_<?php echo esc_attr( $event_key ); ?>">کد الگو</label></th>
						<td>
							<input
								type="text"
								class="regular-text ltr"
								id="mmad_pattern_<?php echo esc_attr( $event_key ); ?>"
								name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[<?php echo esc_attr( $event_key ); ?>][pattern]"
								value="<?php echo esc_attr( $ev['pattern'] ); ?>"
								dir="ltr"
								placeholder="مثلاً qoxRKmS7LS"
							>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mmad_message_<?php echo esc_attr( $event_key ); ?>">متغیرهای پیام</label></th>
						<td>
							<textarea
								class="large-text code ltr"
								rows="3"
								id="mmad_message_<?php echo esc_attr( $event_key ); ?>"
								name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[<?php echo esc_attr( $event_key ); ?>][message]"
								dir="ltr"
							><?php echo esc_textarea( $ev['message'] ); ?></textarea>
							<p class="description">
								فرمت فراز: <code>gateway_var:{site_var};...</code>
								—
								متغیرهای مجاز:
								<?php
								$labels = [];
								foreach ( $vars as $key => $label ) {
									$labels[] = '<code>{' . esc_html( $key ) . '}</code> ' . esc_html( $label );
								}
								echo implode( '، ', $labels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</section>
	<?php endforeach; ?>

	<?php submit_button( 'ذخیره تنظیمات' ); ?>
</form>
