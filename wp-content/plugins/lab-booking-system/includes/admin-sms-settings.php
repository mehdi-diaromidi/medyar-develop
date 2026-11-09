<?php
/**
 * تنظیمات پیامک ادمین درخواست‌ها (آزمایشگاه / پرستاری / داروخانه)
 * منو: تنظیمات ← پیامک ادمین درخواست‌ها
 *
 * پیش‌فرض: متن آزاد via farazsms_send_sms.
 * اگر کد پترن پر باشد: farazsms_send_pattern.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lab_Admin_Sms_Settings {

	public const PAGE_SLUG = 'lab-admin-sms';

	/** Shared recipients (administrator user IDs). */
	public const OPTION_IDS = 'lab_admin_sms_recipient_ids';

	public const SERVICES = array(
		'lab'   => array(
			'label'           => 'آزمایشگاه',
			'enabled'         => 'lab_admin_sms_enabled',
			'template'        => 'lab_admin_sms_template',
			'pattern'         => 'lab_admin_sms_pattern',
			'default'         => "درخواست آزمایش جدید ثبت شد.\nنام: {name}\nموبایل: {mobile}\nشهر: {city}\nاستان: {province}\nآدرس: {address}\nکد ملی: {national_code}\nبیمه: {insurance}\nبیمه تکمیلی: {sup_insurance}\nنوع نسخه: {pres_type}\nکد پیگیری: {token}\nمدیار",
			'legacy_pattern'  => 'lab_pat_admin_submit',
			'vars'            => array(
				'{name}'          => 'نام',
				'{mobile}'        => 'موبایل',
				'{token}'         => 'کد پیگیری',
				'{city}'          => 'شهر',
				'{province}'      => 'استان',
				'{address}'       => 'آدرس',
				'{national_code}' => 'کد ملی',
				'{insurance}'     => 'بیمه',
				'{sup_insurance}' => 'بیمه تکمیلی',
				'{pres_type}'     => 'نوع نسخه',
			),
		),
		'nurse' => array(
			'label'           => 'پرستاری در منزل',
			'enabled'         => 'nurse_admin_sms_enabled',
			'template'        => 'nurse_admin_sms_template',
			'pattern'         => 'nurse_admin_sms_pattern',
			'default'         => "درخواست پرستاری در منزل ثبت شد.\nنام: {name}\nموقعیت: {location}\nموبایل: {mobile}\nایمیل: {email}\nمدیار",
			'legacy_pattern'  => '',
			'vars'            => array(
				'{name}'       => 'نام',
				'{first_name}' => 'نام کوچک',
				'{last_name}'  => 'نام خانوادگی',
				'{location}'   => 'موقعیت مکانی',
				'{mobile}'     => 'موبایل',
				'{email}'      => 'ایمیل',
				'{entry_id}'   => 'شناسه ورودی',
			),
		),
		'phar'  => array(
			'label'           => 'داروخانه',
			'enabled'         => 'phar_admin_sms_enabled',
			'template'        => 'phar_admin_sms_template',
			'pattern'         => 'phar_admin_sms_pattern',
			'default'         => "درخواست داروخانه جدید ثبت شد.\nنام: {name}\nموبایل: {mobile}\nشهر: {city}\nاستان: {province}\nآدرس: {address}\nکد ملی: {national_code}\nبیمه: {insurance}\nکد بیمه: {insurance_code}\nنوع نسخه: {pres_type}\nکد پیگیری: {token}\nمدیار",
			'legacy_pattern'  => '',
			'vars'            => array(
				'{name}'           => 'نام',
				'{mobile}'         => 'موبایل',
				'{token}'          => 'کد پیگیری',
				'{city}'           => 'شهر',
				'{province}'       => 'استان',
				'{address}'        => 'آدرس',
				'{national_code}'  => 'کد ملی',
				'{insurance}'      => 'نوع بیمه',
				'{insurance_code}' => 'کد بیمه',
				'{pres_type}'      => 'نوع نسخه',
			),
		),
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Gravity Forms — فرم ۶: خدمات پرستاری در منزل
		add_action( 'gform_after_submission_6', array( __CLASS__, 'on_nurse_form_submit' ), 20, 2 );
	}

	public static function register_menu(): void {
		add_options_page(
			'پیامک ادمین درخواست‌ها',
			'پیامک ادمین درخواست‌ها',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$css = '
		.lab-admin-picker{display:grid;gap:8px;max-width:560px}
		.lab-admin-picker__item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#fff;cursor:pointer}
		.lab-admin-picker__item:hover{border-color:#2271b1}
		.lab-admin-picker__avatar{border-radius:50%}
		.lab-admin-picker__meta{display:flex;flex-direction:column;gap:2px;line-height:1.4}
		.lab-admin-picker__name{font-weight:600}
		.lab-admin-picker__sub{color:#646970;font-size:12px}
		.lab-admin-picker__no-mobile{color:#d63638}
		.lab-admin-sms-template{width:100%;max-width:560px;min-height:120px;font-family:Tahoma,sans-serif;line-height:1.7}
		.lab-admin-sms-section{margin:28px 0 8px;padding-top:18px;border-top:1px solid #dcdcde}
		.lab-admin-sms-section:first-of-type{border-top:0;padding-top:0}
		.lab-admin-sms-section h2{margin:0 0 8px;font-size:1.15em}
		.lab-admin-insert-var{margin:0 2px;vertical-align:baseline}
		.lab-admin-insert-var code{cursor:pointer}
		.lab-admin-sms-counter{margin-top:6px!important}
		';
		wp_register_style( 'lab-admin-sms-settings', false, array(), '1.3' );
		wp_enqueue_style( 'lab-admin-sms-settings' );
		wp_add_inline_style( 'lab-admin-sms-settings', $css );

		$js = <<<'JS'
(function () {
	function toFa(n) {
		return String(n).replace(/\d/g, function (d) {
			return '۰۱۲۳۴۵۶۷۸۹'[d];
		});
	}
	function syncCounter(out, area) {
		var text = area.value || '';
		var unicode = /[^\x00-\x7F]/.test(text);
		var single = unicode ? 70 : 160;
		var multi = unicode ? 67 : 153;
		var len = text.length;
		var parts = len <= single ? (len ? 1 : 0) : Math.ceil(len / multi);
		out.textContent = toFa(len) + ' کاراکتر — ' + toFa(parts) + ' پیامک (پیش از جایگزینی متغیرها)';
	}
	document.querySelectorAll('.lab-admin-sms-counter').forEach(function (out) {
		var area = document.getElementById(out.getAttribute('data-for'));
		if (!area) return;
		function sync() { syncCounter(out, area); }
		area.addEventListener('input', sync);
		sync();
	});
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.lab-admin-insert-var');
		if (!btn) return;
		var area = document.getElementById(btn.getAttribute('data-target'));
		var token = btn.getAttribute('data-var');
		if (!area || !token) return;
		var start = area.selectionStart || area.value.length;
		var end = area.selectionEnd || start;
		area.value = area.value.slice(0, start) + token + area.value.slice(end);
		area.focus();
		area.selectionStart = area.selectionEnd = start + token.length;
		area.dispatchEvent(new Event('input'));
	});
})();
JS;
		wp_register_script( 'lab-admin-sms-settings', false, array(), '1.3', true );
		wp_enqueue_script( 'lab-admin-sms-settings' );
		wp_add_inline_script( 'lab-admin-sms-settings', $js );
	}

	public static function handle_save(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['lab_admin_sms_save'] ) ) {
			return;
		}
		check_admin_referer( 'lab_admin_sms_save' );

		foreach ( self::SERVICES as $svc ) {
			$en_key  = $svc['enabled'];
			$tpl_key = $svc['template'];
			$pat_key = $svc['pattern'];
			update_option( $en_key, isset( $_POST[ $en_key ] ) ? '1' : '0', false );

			$template = isset( $_POST[ $tpl_key ] )
				? sanitize_textarea_field( wp_unslash( $_POST[ $tpl_key ] ) )
				: '';
			if ( '' === trim( $template ) ) {
				$template = $svc['default'];
			}
			update_option( $tpl_key, $template, false );

			$praw = isset( $_POST[ $pat_key ] ) ? wp_unslash( $_POST[ $pat_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			update_option( $pat_key, self::sanitize_pattern_code( $praw ), false );

			if ( ! empty( $svc['legacy_pattern'] ) ) {
				delete_option( $svc['legacy_pattern'] );
			}
		}

		$ids = array();
		if ( isset( $_POST['lab_admin_sms_recipient_ids'] ) && is_array( $_POST['lab_admin_sms_recipient_ids'] ) ) {
			foreach ( wp_unslash( $_POST['lab_admin_sms_recipient_ids'] ) as $raw_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$uid = absint( $raw_id );
				if ( ! $uid ) {
					continue;
				}
				$user = get_userdata( $uid );
				if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
					$ids[] = $uid;
				}
			}
		}
		update_option( self::OPTION_IDS, array_values( array_unique( $ids ) ), false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * @return WP_User[]
	 */
	public static function administrator_users(): array {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 200,
			)
		);
		return is_array( $users ) ? $users : array();
	}

	/**
	 * @return int[]
	 */
	public static function recipient_ids(): array {
		$raw = get_option( self::OPTION_IDS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$uid = absint( $id );
			if ( ! $uid ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
				$ids[] = $uid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * شماره تماس ادمین — فقط فیلد ووکامرس billing_phone (id="billing_phone").
	 * بدون fallback به meta قدیمی mobile.
	 */
	public static function admin_mobile( int $user_id ): string {
		return self::normalize_mobile( (string) get_user_meta( $user_id, 'billing_phone', true ) );
	}

	/**
	 * تبدیل ارقام فارسی/عربی به لاتین سپس نرمال‌سازی به 09XXXXXXXXX.
	 */
	public static function normalize_mobile( string $phone ): string {
		$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$phone = str_replace( $fa, range( 0, 9 ), $phone );
		$phone = str_replace( $ar, range( 0, 9 ), $phone );
		$phone = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $phone ) || '' === $phone ) {
			return '';
		}
		if ( 0 === strpos( $phone, '0098' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( 0 === strpos( $phone, '98' ) && 12 === strlen( $phone ) ) {
			$phone = '0' . substr( $phone, 2 );
		} elseif ( 0 === strpos( $phone, '9' ) && 10 === strlen( $phone ) ) {
			$phone = '0' . $phone;
		}
		return preg_match( '/^09\d{9}$/', $phone ) ? $phone : '';
	}

	/**
	 * @param array<string,string> $vars
	 */
	public static function render_message( string $service, array $vars ): string {
		if ( ! isset( self::SERVICES[ $service ] ) ) {
			return '';
		}
		$cfg      = self::SERVICES[ $service ];
		$template = (string) get_option( $cfg['template'], $cfg['default'] );
		if ( '' === trim( $template ) ) {
			$template = $cfg['default'];
		}

		$replace = array();
		foreach ( array_keys( $cfg['vars'] ) as $token ) {
			$key             = trim( $token, '{}' );
			$replace[ $token ] = (string) ( $vars[ $key ] ?? '' );
		}

		$message = trim( strtr( $template, $replace ) );

		/**
		 * Filters the free-text SMS sent to admins for a service request.
		 *
		 * @param string $message Rendered message.
		 * @param array  $vars    Vars.
		 * @param string $service lab|nurse|phar
		 */
		return trim( (string) apply_filters( 'lab_admin_new_order_sms', $message, $vars, $service ) );
	}

	/** Sanitize Faraz pattern code from settings. */
	public static function sanitize_pattern_code( $raw ): string {
		if ( class_exists( 'Arp_Faraz_Sms_Gateway' ) && method_exists( 'Arp_Faraz_Sms_Gateway', 'sanitize_pattern_option' ) ) {
			return Arp_Faraz_Sms_Gateway::sanitize_pattern_option( $raw );
		}
		$code = sanitize_text_field( (string) $raw );
		$code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $code );
		return is_string( $code ) ? $code : '';
	}

	/**
	 * @param array<string,mixed> $vars Pattern attribute map (keys with or without braces).
	 */
	public static function pattern_attrs( array $vars ): array {
		if ( class_exists( 'Arp_Faraz_Sms_Gateway' ) && method_exists( 'Arp_Faraz_Sms_Gateway', 'pattern_attrs' ) ) {
			return Arp_Faraz_Sms_Gateway::pattern_attrs( $vars );
		}
		$out = array();
		foreach ( $vars as $key => $value ) {
			$k = trim( (string) $key, '{}' );
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = (string) $value;
		}
		return $out;
	}

	public static function send_plain( string $phone, string $message ): bool {
		$phone   = self::normalize_mobile( $phone );
		$message = trim( $message );
		if ( '' === $phone || '' === $message ) {
			return false;
		}

		if ( function_exists( 'farazsms_is_ready' ) && farazsms_is_ready() && function_exists( 'farazsms_send_sms' ) ) {
			$result = farazsms_send_sms( $phone, $message );
			$ok     = is_array( $result ) ? ! empty( $result['ok'] ) : (bool) $result;
			if ( ! $ok ) {
				error_log( '[Admin SMS] Faraz send failed: ' . wp_json_encode( $result ) ); // phpcs:ignore
			}
			return $ok;
		}

		error_log( '[Admin SMS] Faraz not ready; message not sent to ' . $phone ); // phpcs:ignore
		return false;
	}

	/**
	 * Optional Faraz pattern; falls back to free-text when pattern is empty.
	 *
	 * @param array<string,mixed> $pattern_vars
	 */
	public static function deliver( string $phone, string $message, string $pattern_code = '', array $pattern_vars = array() ): bool {
		$phone        = self::normalize_mobile( $phone );
		$pattern_code = trim( $pattern_code );
		if ( '' === $phone ) {
			return false;
		}

		if ( '' !== $pattern_code && class_exists( 'Arp_Faraz_Sms_Gateway' ) && method_exists( 'Arp_Faraz_Sms_Gateway', 'deliver' ) ) {
			return ( new Arp_Faraz_Sms_Gateway() )->deliver( $phone, $message, $pattern_code, $pattern_vars );
		}

		if ( '' !== $pattern_code && function_exists( 'farazsms_send_pattern' ) ) {
			$result = farazsms_send_pattern( $phone, $pattern_code, self::pattern_attrs( $pattern_vars ) );
			$ok     = is_array( $result ) ? ! empty( $result['ok'] ) : (bool) $result;
			if ( ! $ok ) {
				error_log( '[Admin SMS] Faraz pattern send failed: ' . wp_json_encode( $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $ok;
		}

		return self::send_plain( $phone, $message );
	}

	/**
	 * @param array<string,string> $vars
	 * @return int Number of successful sends.
	 */
	public static function notify_admins( string $service, array $vars ): int {
		if ( ! isset( self::SERVICES[ $service ] ) ) {
			return 0;
		}
		$cfg = self::SERVICES[ $service ];
		if ( '1' !== (string) get_option( $cfg['enabled'], '1' ) ) {
			return 0;
		}

		$message = self::render_message( $service, $vars );
		$pattern = trim( (string) get_option( $cfg['pattern'], '' ) );
		if ( '' === $message && '' === $pattern ) {
			return 0;
		}

		$ids = self::recipient_ids();
		if ( ! $ids ) {
			return 0;
		}

		$sent = 0;
		$seen = array();
		foreach ( $ids as $uid ) {
			$phone = self::admin_mobile( $uid );
			if ( '' === $phone || isset( $seen[ $phone ] ) ) {
				continue;
			}
			$seen[ $phone ] = true;
			if ( self::deliver( $phone, $message, $pattern, $vars ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * @param array{name?:string,mobile?:string,token?:string,city?:string,province?:string,address?:string,national_code?:string,insurance?:string,sup_insurance?:string,pres_type?:string} $vars
	 */
	public static function notify_admins_new_order( array $vars ): int {
		return self::notify_admins( 'lab', $vars );
	}

	/**
	 * Gravity Forms form 6 — پرستاری در منزل.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function on_nurse_form_submit( $entry, $form ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! is_array( $entry ) ) {
			return;
		}

		$get = static function ( $key ) use ( $entry ): string {
			if ( function_exists( 'rgar' ) ) {
				return (string) rgar( $entry, $key );
			}
			return isset( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
		};

		$first = trim( $get( '1.3' ) );
		$last  = trim( $get( '1.6' ) );
		$name  = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = trim( $get( '1' ) );
		}

		$loc_a    = trim( $get( '10.3' ) );
		$loc_b    = trim( $get( '10.6' ) );
		$location = trim( $loc_a . ' ' . $loc_b );
		if ( '' === $location ) {
			$location = trim( $get( '10' ) );
		}

		$raw_mobile = $get( '5' );
		$mobile     = self::normalize_mobile( $raw_mobile );
		if ( '' === $mobile ) {
			$mobile = $raw_mobile;
		}

		self::notify_admins(
			'nurse',
			array(
				'name'       => $name,
				'first_name' => $first,
				'last_name'  => $last,
				'location'   => $location,
				'mobile'     => $mobile,
				'email'      => $get( '6' ),
				'entry_id'   => (string) ( $entry['id'] ?? '' ),
			)
		);
	}

	private static function render_var_buttons( string $textarea_id, array $vars ): void {
		$parts = array();
		foreach ( $vars as $token => $label ) {
			$parts[] = sprintf(
				'<button type="button" class="button-link lab-admin-insert-var" data-target="%1$s" data-var="%2$s"><code>%2$s</code></button> %3$s',
				esc_attr( $textarea_id ),
				esc_attr( $token ),
				esc_html( $label )
			);
		}
		echo implode( '، ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$recipient_ids = self::recipient_ids();
		$admin_users   = self::administrator_users();
		$faraz_ready   = function_exists( 'farazsms_is_ready' ) && farazsms_is_ready();
		?>
		<div class="wrap">
			<h1>پیامک ادمین درخواست‌ها</h1>
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
			<?php endif; ?>

			<p>با ثبت درخواست آزمایشگاه، پرستاری در منزل یا داروخانه، برای ادمین‌های انتخاب‌شده پیامک ارسال می‌شود. متن آزاد پیش‌فرض است؛ اگر کد پترن فراز را پر کنید از پترن ارسال می‌شود.</p>

			<?php if ( ! $faraz_ready ) : ?>
				<div class="notice notice-warning"><p>سامانه فراز اس‌ام‌اس آماده نیست. تنظیمات افزونه فراز را بررسی کنید تا پیامک ادمین ارسال شود.</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'lab_admin_sms_save' ); ?>
				<input type="hidden" name="lab_admin_sms_save" value="1" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">گیرندگان (مشترک)</th>
						<td>
							<?php if ( empty( $admin_users ) ) : ?>
								<p class="description" style="margin:0;">کاربری با نقش مدیرکل یافت نشد.</p>
							<?php else : ?>
								<div class="lab-admin-picker">
									<?php
									foreach ( $admin_users as $admin_user ) :
										if ( ! $admin_user instanceof WP_User ) {
											continue;
										}
										$uid      = (int) $admin_user->ID;
										$display  = $admin_user->display_name ?: $admin_user->user_login;
										$mobile   = self::admin_mobile( $uid );
										$input_id = 'lab_admin_sms_recipient_' . $uid;
										?>
										<label class="lab-admin-picker__item" for="<?php echo esc_attr( $input_id ); ?>">
											<input type="checkbox"
												name="lab_admin_sms_recipient_ids[]"
												id="<?php echo esc_attr( $input_id ); ?>"
												value="<?php echo esc_attr( (string) $uid ); ?>"
												<?php checked( in_array( $uid, $recipient_ids, true ) ); ?> />
											<?php echo get_avatar( $uid, 32, '', $display, array( 'class' => 'lab-admin-picker__avatar' ) ); ?>
											<span class="lab-admin-picker__meta">
												<span class="lab-admin-picker__name"><?php echo esc_html( $display ); ?></span>
												<span class="lab-admin-picker__sub">
													@<?php echo esc_html( $admin_user->user_login ); ?>
													<?php if ( '' !== $mobile ) : ?>
														· <span dir="ltr"><?php echo esc_html( $mobile ); ?></span>
													<?php else : ?>
														· <span class="lab-admin-picker__no-mobile">بدون موبایل</span>
													<?php endif; ?>
												</span>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description">فقط کاربران با نقش مدیرکل. شماره فقط از فیلد <code>billing_phone</code> پروفایل (id=&quot;billing_phone&quot;) خوانده می‌شود؛ ارقام فارسی هم پشتیبانی می‌شود.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php foreach ( self::SERVICES as $key => $svc ) : ?>
					<?php
					$enabled  = '1' === (string) get_option( $svc['enabled'], '1' );
					$template = (string) get_option( $svc['template'], $svc['default'] );
					if ( '' === trim( $template ) ) {
						$template = $svc['default'];
					}
					$tpl_id = $svc['template'];
					?>
					<div class="lab-admin-sms-section">
						<h2><?php echo esc_html( $svc['label'] ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">ارسال پیامک</th>
								<td>
									<label for="<?php echo esc_attr( $svc['enabled'] ); ?>">
										<input type="checkbox" name="<?php echo esc_attr( $svc['enabled'] ); ?>" id="<?php echo esc_attr( $svc['enabled'] ); ?>" value="1" <?php checked( $enabled ); ?> />
										برای ادمین‌های انتخاب‌شده پیامک ارسال شود
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row">متن پیامک</th>
								<td>
									<textarea class="lab-admin-sms-template" name="<?php echo esc_attr( $tpl_id ); ?>" id="<?php echo esc_attr( $tpl_id ); ?>" rows="6"><?php echo esc_textarea( $template ); ?></textarea>
									<p class="description">
										متغیرها (برای درج کلیک کنید):
										<?php self::render_var_buttons( $tpl_id, $svc['vars'] ); ?>
									</p>
									<p class="description lab-admin-sms-counter" data-for="<?php echo esc_attr( $tpl_id ); ?>" aria-live="polite"></p>
								</td>
							</tr>
							<?php
							$pat_id    = $svc['pattern'];
							$pat_value = (string) get_option( $pat_id, '' );
							$pat_hint  = implode(
								', ',
								array_map(
									static function ( $token ) {
										return trim( (string) $token, '{}' );
									},
									array_keys( $svc['vars'] )
								)
							);
							?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $pat_id ); ?>">کد پترن فراز</label></th>
								<td>
									<input type="text" name="<?php echo esc_attr( $pat_id ); ?>" id="<?php echo esc_attr( $pat_id ); ?>"
										class="regular-text" dir="ltr" autocomplete="off"
										value="<?php echo esc_attr( $pat_value ); ?>"
										placeholder="خالی = ارسال متن آزاد" />
									<p class="description">
										اگر پر باشد، به‌جای متن بالا از پترن فراز ارسال می‌شود.
										متغیرهای پترن: <code dir="ltr"><?php echo esc_html( $pat_hint ); ?></code>
									</p>
								</td>
							</tr>
						</table>
					</div>
				<?php endforeach; ?>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
		</div>
		<?php
	}
}

Lab_Admin_Sms_Settings::init();
