<?php
/**
 * Affiliate panel write operations, shared by the AJAX endpoints and the no-JS POST fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Panel_Actions {

	private const AVATAR_MAX   = 5 * MB_IN_BYTES;
	private const AVATAR_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/** context => code => message. */
	private const MESSAGES = array(
		'referral' => array(
			'required'  => 'لطفاً نام بیمار، شماره تماس و نوع خدمت را وارد کنید.',
			'phone'     => 'شماره موبایل بیمار معتبر نیست (نمونه: ۰۹۱۲۳۴۵۶۷۸۹).',
			'service'   => 'خدمت انتخاب‌شده در دسترس نیست. لطفاً خدمت دیگری انتخاب کنید.',
			'duplicate' => 'این بیمار برای همین خدمت، دقایقی پیش توسط شما ثبت شده است.',
			'no_status' => 'امکان ثبت معرفی در حال حاضر وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.',
			'failed'    => 'ثبت معرفی با خطا مواجه شد. لطفاً دوباره تلاش کنید.',
			'ok'        => 'معرفی بیمار با موفقیت ثبت شد.',
		),
		'profile'  => array(
			'required'    => 'لطفاً فیلدهای الزامی را کامل کنید.',
			'email'       => 'ایمیل واردشده معتبر نیست یا قبلاً استفاده شده است.',
			'mobile'      => 'شماره تماس معتبر نیست (نمونه: ۰۹۱۲۳۴۵۶۷۸۹).',
			'nid'         => 'کد ملی باید ۱۰ رقم باشد.',
			'birthday'    => 'تاریخ تولد معتبر نیست.',
			'job'         => 'نوع شغل انتخاب‌شده معتبر نیست.',
			'avatar_type' => 'فقط فایل تصویری (JPG، PNG، WEBP، GIF) مجاز است.',
			'avatar_size' => 'حجم تصویر نباید بیشتر از ۵ مگابایت باشد.',
			'avatar_fail' => 'آپلود تصویر ناموفق بود. دوباره تلاش کنید.',
			'avatar_ok'   => 'تصویر پروفایل به‌روزرسانی شد.',
			'avatar_none' => 'تصویری برای حذف وجود ندارد.',
			'avatar_del'  => 'تصویر پروفایل حذف شد.',
			'ok'          => 'پروفایل ذخیره شد.',
		),
		'signup'   => array(
			'required'   => 'لطفاً همه فیلدهای الزامی را پر کنید.',
			'mobile'     => 'شماره موبایل معتبر نیست (نمونه: ۰۹۱۲۳۴۵۶۷۸۹).',
			'job'        => 'نوع شغل انتخاب‌شده معتبر نیست.',
			'already'    => 'شما از قبل عضو طرح همکاران هستید.',
			'pending'    => 'درخواست شما قبلاً ثبت شده و در صف بررسی است.',
			'docs_type'  => 'فقط فایل‌های JPG، PNG، WEBP یا PDF مجاز است.',
			'docs_size'  => 'حجم هر مدرک نباید بیشتر از ۵ مگابایت باشد.',
			'docs_count' => 'حداکثر ۳ فایل می‌توانید آپلود کنید.',
			'docs_fail'  => 'آپلود یکی از مدارک ناموفق بود. دوباره تلاش کنید.',
			'inviter'    => 'کد معرف معتبر نیست.',
			'ok'         => 'درخواست شما ثبت شد و در صف بررسی قرار گرفت.',
		),
	);

	public static function message( string $context, string $code ): string {
		return self::MESSAGES[ $context ][ $code ] ?? '';
	}

	private static function error( string $context, string $code, string $field = '' ): WP_Error {
		return new WP_Error( $code, self::message( $context, $code ), array( 'field' => $field ) );
	}

	/**
	 * Raw request value, unslashed and sanitized as a single-line string.
	 *
	 * @param array<string,mixed> $in Request data (slashed, as in $_POST).
	 */
	private static function text( array $in, string $key ): string {
		return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $in[ $key ] ) ) ) : '';
	}

	/**
	 * Jalali "Y/m/d" (or a legacy Unix timestamp) to the midnight-UTC timestamp the theme stores; '' if invalid or in the future.
	 */
	private static function birthday( string $raw ): string {
		if ( ctype_digit( $raw ) ) {
			$ts = (int) $raw;
		} else {
			$date = Arp_Format::jalali_to_date( $raw );
			$ts   = '' !== $date ? (int) strtotime( $date . ' 00:00:00 UTC' ) : 0;
		}
		return ( $ts > 0 && $ts <= time() ) ? (string) $ts : '';
	}

	/* ---------------------------------------------------------------- referral */

	/**
	 * @param array<string,mixed> $in Slashed request data.
	 * @return array{id:int,old:array<string,mixed>}|WP_Error Error data carries 'old' (sanitized input) for re-filling the form.
	 */
	public static function submit_referral( int $uid, array $in ) {
		$name        = mb_substr( self::text( $in, 'patient_name' ), 0, 191 );
		$phone_raw   = self::text( $in, 'patient_phone' );
		$service_id  = isset( $in['service_id'] ) ? absint( $in['service_id'] ) : 0;
		$description = isset( $in['description'] ) && is_scalar( $in['description'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( (string) $in['description'] ) ), 0, 400 ) : '';
		$phone       = Arp_Format::iran_mobile( $phone_raw );
		$old         = array(
			'patient_name'  => $name,
			'patient_phone' => $phone_raw,
			'service_id'    => $service_id,
			'description'   => $description,
		);

		$fail = static function ( string $code, string $field = '' ) use ( $old ): WP_Error {
			$error = self::error( 'referral', $code, $field );
			$error->add_data( array( 'field' => $field, 'old' => $old ) );
			return $error;
		};

		if ( '' === $name ) {
			return $fail( 'required', 'patient_name' );
		}
		if ( '' === $phone_raw ) {
			return $fail( 'required', 'patient_phone' );
		}
		if ( ! $service_id ) {
			return $fail( 'required', 'service_id' );
		}
		if ( '' === $phone ) {
			return $fail( 'phone', 'patient_phone' );
		}
		$service = Arp_Services_Repo::get( $service_id );
		if ( ! $service || empty( $service['is_active'] ) ) {
			return $fail( 'service', 'service_id' );
		}
		$default = Arp_Statuses_Repo::get_default();
		if ( ! $default ) {
			return $fail( 'no_status' );
		}
		if ( Arp_Referrals_Repo::exists_recent( $uid, $phone, $service_id, 10 * MINUTE_IN_SECONDS ) ) {
			return $fail( 'duplicate', 'patient_phone' );
		}

		$id = Arp_Referrals_Repo::insert(
			array(
				'affiliate_user_id' => $uid,
				'patient_name'      => $name,
				'patient_phone'     => $phone,
				'service_id'        => $service_id,
				'description'       => $description,
				'status_id'         => (int) $default['id'],
			)
		);
		if ( ! $id ) {
			return $fail( 'failed' );
		}

		$aff_code     = Arp_Referral_Code::get_for_user( $uid );
		$service_name = $service ? (string) $service['name'] : '';
		$referral_id  = (int) $id;
		// Admin SMS first so staff are not queued behind the patient Faraz call.
		Arp_Faraz_Sms_Gateway::enqueue(
			'admin_new_referral',
			array(
				'referral_id'       => $referral_id,
				'affiliate_user_id' => $uid,
				'patient_name'      => $name,
				'patient_phone'     => $phone,
				'service_name'      => $service_name,
			)
		);
		Arp_Faraz_Sms_Gateway::enqueue(
			'patient_referral',
			array(
				'phone'          => $phone,
				'patient_name'   => $name,
				'affiliate_code' => $aff_code,
			)
		);
		return array( 'id' => $id, 'old' => $old );
	}

	/* ---------------------------------------------------------------- profile */

	/**
	 * @param array<string,mixed> $in    Slashed request data.
	 * @param array<string,mixed> $files $_FILES.
	 * @return true|WP_Error
	 */
	public static function save_profile( int $uid, array $in, array $files ) {
		$first    = self::text( $in, 'account_first_name' );
		$last     = self::text( $in, 'account_last_name' );
		$display  = self::text( $in, 'account_display_name' );
		$email    = isset( $in['account_email'] ) && is_scalar( $in['account_email'] ) ? sanitize_email( wp_unslash( (string) $in['account_email'] ) ) : '';
		$mobile   = Arp_Format::iran_mobile( self::text( $in, 'drplus_mobile' ) );
		$nid      = preg_replace( '/\D+/', '', Arp_Format::en( self::text( $in, 'drplus_nid' ) ) );
		$bday     = self::birthday( self::text( $in, 'drplus_birthday' ) );
		$job_type = isset( $in['job_type_id'] ) ? absint( $in['job_type_id'] ) : 0;
		$about    = isset( $in['about'] ) && is_scalar( $in['about'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( (string) $in['about'] ) ), 0, 200 ) : '';

		$required = array(
			'account_first_name'   => $first,
			'account_last_name'    => $last,
			'account_display_name' => $display,
			'account_email'        => self::text( $in, 'account_email' ),
			'drplus_mobile'        => self::text( $in, 'drplus_mobile' ),
			'drplus_nid'           => $nid,
			'drplus_birthday'      => self::text( $in, 'drplus_birthday' ),
			'job_type_id'          => $job_type ? (string) $job_type : '',
		);
		foreach ( $required as $field => $value ) {
			if ( '' === $value ) {
				return self::error( 'profile', 'required', $field );
			}
		}
		if ( '' === $bday ) {
			return self::error( 'profile', 'birthday', 'drplus_birthday' );
		}
		if ( ! is_email( $email ) ) {
			return self::error( 'profile', 'email', 'account_email' );
		}
		$email_owner = email_exists( $email );
		if ( $email_owner && (int) $email_owner !== $uid ) {
			return self::error( 'profile', 'email', 'account_email' );
		}
		if ( '' === $mobile ) {
			return self::error( 'profile', 'mobile', 'drplus_mobile' );
		}
		if ( 10 !== strlen( $nid ) ) {
			return self::error( 'profile', 'nid', 'drplus_nid' );
		}
		$job = Arp_Job_Types_Repo::get( $job_type );
		if ( ! $job || empty( $job['is_active'] ) ) {
			return self::error( 'profile', 'job', 'job_type_id' );
		}

		if ( ! empty( $files['arp_avatar']['name'] ) ) {
			$avatar = self::save_avatar( $uid, $files['arp_avatar'] );
			if ( is_wp_error( $avatar ) ) {
				return $avatar;
			}
		}

		$data = array(
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $display,
			'email'        => $email,
			'mobile'       => $mobile,
			'nid'          => $nid,
			'birthday'     => $bday,
		);

		if ( class_exists( '\DrPlus\Utils\User' ) ) {
			\DrPlus\Utils\User::update_user( $data, $uid );
		} else {
			update_user_meta( $uid, 'first_name', $first );
			update_user_meta( $uid, 'last_name', $last );
			update_user_meta( $uid, 'mobile', $mobile );
			update_user_meta( $uid, 'billing_phone', $mobile );
			update_user_meta( $uid, 'shipping_phone', $mobile );
			update_user_meta( $uid, 'nid', $nid );
			update_user_meta( $uid, 'birthday', $bday );
			wp_update_user(
				array(
					'ID'           => $uid,
					'display_name' => $display,
					'user_email'   => $email,
				)
			);
		}

		update_user_meta( $uid, 'arp_job_type_id', $job_type );
		update_user_meta( $uid, 'arp_about', $about );
		clean_user_cache( $uid );
		return true;
	}

	/**
	 * @param array<string,mixed> $files $_FILES.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function upload_avatar( int $uid, array $files ) {
		if ( empty( $files['arp_avatar']['name'] ) || ! is_array( $files['arp_avatar'] ) ) {
			return self::error( 'profile', 'avatar_fail', 'arp_avatar' );
		}
		return self::save_avatar( $uid, $files['arp_avatar'] );
	}

	/**
	 * Store an uploaded avatar under the theme's `avatar` meta; the previous own upload is deleted.
	 *
	 * @param array<string,mixed> $file One $_FILES entry.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function save_avatar( int $uid, array $file ) {
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_INI_SIZE === (int) $file['error'] ) {
			return self::error( 'profile', 'avatar_size', 'arp_avatar' );
		}
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return self::error( 'profile', 'avatar_fail', 'arp_avatar' );
		}
		if ( (int) $file['size'] > self::AVATAR_MAX ) {
			return self::error( 'profile', 'avatar_size', 'arp_avatar' );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'] );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::AVATAR_MIMES, true ) ) {
			return self::error( 'profile', 'avatar_type', 'arp_avatar' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Avatars only ever render as small squares: skip the dozen site-wide sub-sizes (the slow part).
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'avatar_sizes' ), PHP_INT_MAX );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'avatar_max_edge' ), PHP_INT_MAX );
		$attachment_id = media_handle_upload( 'arp_avatar', 0, array(), array( 'test_form' => false ) );
		remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'avatar_sizes' ), PHP_INT_MAX );
		remove_filter( 'big_image_size_threshold', array( __CLASS__, 'avatar_max_edge' ), PHP_INT_MAX );
		if ( is_wp_error( $attachment_id ) ) {
			return self::error( 'profile', 'avatar_fail', 'arp_avatar' );
		}

		$old_id = self::avatar_id( $uid );
		self::set_avatar_id( $uid, (int) $attachment_id );
		self::delete_own_attachment( $uid, $old_id, (int) $attachment_id );
		return (int) $attachment_id;
	}

	/**
	 * Revert to the default avatar; the user's own uploaded file is deleted.
	 */
	public static function remove_avatar( int $uid ): bool {
		$old_id = self::avatar_id( $uid );
		if ( $old_id <= 0 ) {
			return false;
		}
		self::set_avatar_id( $uid, 0 );
		self::delete_own_attachment( $uid, $old_id, 0 );
		return true;
	}

	/**
	 * @param array<string,array<string,mixed>> $sizes Sub-sizes WordPress would generate.
	 * @return array<string,array<string,mixed>>
	 */
	public static function avatar_sizes( $sizes ): array {
		$out = array();
		if ( isset( $sizes['thumbnail'] ) ) {
			$out['thumbnail'] = $sizes['thumbnail'];
		}
		$out['arp_avatar_2x'] = array(
			'width'  => 300,
			'height' => 300,
			'crop'   => true,
		);
		return $out;
	}

	public static function avatar_max_edge(): int {
		return 1024;
	}

	private static function avatar_id( int $uid ): int {
		return class_exists( '\DrPlus\Utils\User' )
			? (int) \DrPlus\Utils\User::get_avatar_id( $uid )
			: (int) get_user_meta( $uid, 'avatar', true );
	}

	private static function set_avatar_id( int $uid, int $attachment_id ): void {
		if ( ! $attachment_id ) {
			delete_user_meta( $uid, 'avatar' );
		} elseif ( class_exists( '\DrPlus\Utils\User' ) ) {
			\DrPlus\Utils\User::save_avatar_id( $attachment_id, $uid );
		} else {
			update_user_meta( $uid, 'avatar', $attachment_id );
		}
	}

	private static function delete_own_attachment( int $uid, int $old_id, int $keep_id ): void {
		if ( $old_id > 0 && $old_id !== $keep_id && 'attachment' === get_post_type( $old_id ) && (int) get_post_field( 'post_author', $old_id ) === $uid ) {
			wp_delete_attachment( $old_id, true );
		}
	}

	/**
	 * Values the profile screen shows, for refreshing the page after an AJAX save.
	 *
	 * @return array<string,string>
	 */
	public static function profile_snapshot( int $uid ): array {
		$user   = get_userdata( $uid );
		$name   = trim( get_user_meta( $uid, 'first_name', true ) . ' ' . get_user_meta( $uid, 'last_name', true ) );
		$mobile = class_exists( '\DrPlus\Utils\User' ) ? (string) \DrPlus\Utils\User::get_phone( $uid ) : (string) get_user_meta( $uid, 'mobile', true );
		$job    = Arp_Job_Types_Repo::get( (int) get_user_meta( $uid, 'arp_job_type_id', true ) );

		return array(
			'name'           => '' !== $name ? $name : (string) $user->display_name,
			'display_name'   => (string) $user->display_name,
			'email'          => (string) $user->user_email,
			'mobile'         => Arp_Format::fa( $mobile ),
			'mobile_display' => class_exists( '\DrPlus\Utils\Formatters' ) ? (string) \DrPlus\Utils\Formatters::phone( $mobile ) : $mobile,
			'job'            => $job ? (string) $job['name'] : 'ثبت نشده',
			'has_avatar'     => Arp_Avatars::has_custom( $uid ) ? '1' : '',
			'avatar_hero'    => get_avatar( $uid, 128, '', '', array( 'class' => 'arp-profile-hero__avatar-img' ) ),
			'avatar_side'    => get_avatar( $uid, 64 ),
		);
	}

	/* ---------------------------------------------------------------- signup */

	/**
	 * Queue an affiliate application for admin review (role is granted only after approve).
	 *
	 * @param array<string,mixed> $in    Slashed request data.
	 * @param array<string,mixed> $files $_FILES (optional documents).
	 * @return true|WP_Error
	 */
	public static function signup( WP_User $user, array $in, array $files = array() ) {
		$user_id = (int) $user->ID;
		if ( Arp_Roles::user_is_affiliate( $user_id ) ) {
			return self::error( 'signup', 'already' );
		}
		if ( Arp_Affiliate_Applications::is_pending( $user_id ) ) {
			return self::error( 'signup', 'pending' );
		}

		$full_name = mb_substr( self::text( $in, 'full_name' ), 0, 191 );
		$mobile    = Arp_Format::iran_mobile( self::text( $in, 'mobile' ) );
		$job_type  = isset( $in['job_type_id'] ) ? absint( $in['job_type_id'] ) : 0;
		$inviter   = Arp_Inviter::resolve_for_signup( self::text( $in, 'inviter_code' ) );

		if ( '' === $full_name ) {
			return self::error( 'signup', 'required', 'full_name' );
		}
		if ( '' === self::text( $in, 'mobile' ) ) {
			return self::error( 'signup', 'required', 'mobile' );
		}
		if ( '' === $mobile ) {
			return self::error( 'signup', 'mobile', 'mobile' );
		}
		$job = $job_type ? Arp_Job_Types_Repo::get( $job_type ) : null;
		if ( ! $job || empty( $job['is_active'] ) ) {
			return self::error( 'signup', $job_type ? 'job' : 'required', 'job_type_id' );
		}
		if ( is_wp_error( $inviter ) ) {
			return self::error( 'signup', 'inviter', 'inviter_code' );
		}

		/*
		 * Claim the signup BEFORE slow document upload.
		 * Concurrent AJAX retries used to all pass is_pending(), each enqueue SMS → flood.
		 * add_user_meta( ..., unique=true ) is atomic for the first writer.
		 */
		$lock_key = 'arp_signup_inflight';
		$stale    = (int) get_user_meta( $user_id, $lock_key, true );
		if ( $stale && ( time() - $stale ) > 10 * MINUTE_IN_SECONDS ) {
			delete_user_meta( $user_id, $lock_key );
		}
		if ( ! add_user_meta( $user_id, $lock_key, (string) time(), true ) ) {
			return self::error( 'signup', 'pending' );
		}

		$docs = Arp_Affiliate_Applications::store_documents( $user_id, $files );
		if ( is_wp_error( $docs ) ) {
			delete_user_meta( $user_id, $lock_key );
			$code = $docs->get_error_code();
			$map  = array(
				'docs_type'  => 'docs_type',
				'docs_size'  => 'docs_size',
				'docs_count' => 'docs_count',
				'docs_fail'  => 'docs_fail',
			);
			return self::error( 'signup', $map[ $code ] ?? 'docs_fail', 'arp_documents' );
		}

		$parts = preg_split( '/\s+/', $full_name, 2 );
		update_user_meta( $user_id, 'first_name', $parts[0] ?? '' );
		update_user_meta( $user_id, 'last_name', $parts[1] ?? '' );
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $full_name,
			)
		);
		update_user_meta( $user_id, 'mobile', $mobile );
		update_user_meta( $user_id, 'billing_phone', $mobile );
		update_user_meta( $user_id, 'arp_job_type_id', $job_type );
		update_user_meta( $user_id, Arp_Affiliate_Applications::META_STATUS, Arp_Affiliate_Applications::STATUS_PENDING );
		update_user_meta( $user_id, Arp_Affiliate_Applications::META_APPLIED_AT, current_time( 'mysql' ) );
		update_user_meta( $user_id, Arp_Affiliate_Applications::META_DOCS, $docs );
		delete_user_meta( $user_id, Arp_Affiliate_Applications::META_REJECT_NOTE );
		Arp_Inviter::persist_for_user( $user_id, (string) $inviter['code'], (int) $inviter['user_id'] );

		// نقش و کد همکاری فقط پس از تأیید ادمین.
		$user->remove_role( Arp_Roles::ROLE );

		$job_name = $job ? (string) $job['name'] : '';
		// SMS only after successful claim + persist (idempotent inside gateway).
		Arp_Faraz_Sms_Gateway::enqueue(
			'admin_affiliate_signup',
			array(
				'user_id'   => $user_id,
				'full_name' => $full_name,
				'mobile'    => $mobile,
				'job_name'  => $job_name,
			)
		);
		Arp_Faraz_Sms_Gateway::enqueue(
			'applicant_pending',
			array( 'user_id' => $user_id )
		);

		delete_user_meta( $user_id, $lock_key );

		return true;
	}
}
