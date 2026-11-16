<?php
/**
 * Central settings registry (T-0.3 / AC-M0-4).
 *
 * Storage: option mns_settings (array). Unknown/invalid ⇒ default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Settings {

	public const OPTION = 'mns_settings';

	/**
	 * Registry of settings.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$districts = array();
		for ( $i = 1; $i <= 22; $i++ ) {
			$districts[] = sprintf( 'منطقه %d', $i );
		}

		$sms_events = array(
			'nurse_apply',
			'nurse_approved',
			'nurse_rejected',
			'order_created',
			'order_assigned',
			'service_ended',
			'urgent_admin',
			'invoice_issued',
			'invoice_remind',
			'payment_ok',
			'order_unassigned',
			'order_cancelled',
		);

		$defs = array(
			'assignment_mode'      => array(
				'type'    => 'enum',
				'default' => 'manual',
				'enum'    => array( 'auto', 'manual' ),
				'group'   => 'assignment',
				'label'   => 'حالت تخصیص',
				's_key'   => 'S-ASG-MODE',
			),
			'asg_weights'          => array(
				'type'    => 'assoc_int',
				'default' => array(
					'prev'   => 35,
					'rating' => 30,
					'fair'   => 20,
					'cancel' => 15,
				),
				'group'   => 'assignment',
				'label'   => 'وزن‌های رتبه‌بندی',
				's_key'   => 'S-ASG-WEIGHTS',
			),
			'slot_capacity'        => array(
				'type'    => 'int',
				'default' => 1,
				'min'     => 1,
				'max'     => 20,
				'group'   => 'assignment',
				'label'   => 'ظرفیت هر بازه',
				's_key'   => 'S-CAP-SLOT',
			),
			'slots'                => array(
				'type'    => 'slots',
				'default' => self::default_slots(),
				'group'   => 'assignment',
				'label'   => 'بازه‌های زمانی',
				's_key'   => 'S-SLOTS',
			),
			'schedule_horizon'     => array(
				'type'    => 'int',
				'default' => 90,
				'min'     => 7,
				'max'     => 365,
				'group'   => 'assignment',
				'label'   => 'افق برنامه (روز)',
				's_key'   => 'S-SCH-HORIZON',
			),
			'order_horizon'        => array(
				'type'    => 'int',
				'default' => 30,
				'min'     => 1,
				'max'     => 90,
				'group'   => 'assignment',
				'label'   => 'افق ثبت سفارش (روز)',
				's_key'   => 'S-ORD-HORIZON',
			),
			'start_before_minutes' => array(
				'type'    => 'int',
				'default' => 60,
				'min'     => 0,
				'max'     => 240,
				'group'   => 'assignment',
				'label'   => 'شروع مجاز از چند دقیقه قبل',
				's_key'   => 'S-START-BEFORE',
			),
			'a13_rate'             => array(
				'type'    => 'string',
				'default' => '30/min/user',
				'group'   => 'assignment',
				'label'   => 'نرخ A-13',
				's_key'   => 'S-A13-RATE',
			),
			'order_code_digits'    => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 5,
				'max'     => 6,
				'group'   => 'assignment',
				'label'   => 'ارقام کد سفارش',
				's_key'   => 'S-ORD-CODE-DIGITS',
			),
			'reg_districts'        => array(
				'type'    => 'assoc_list',
				'default' => array(
					'THR|تهران' => $districts,
				),
				'group'   => 'regions',
				'label'   => 'مناطق شهری',
				's_key'   => 'S-REG-DISTRICTS',
			),
			'reg_active'           => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'regions',
				'label'   => 'استان‌های فعال (خالی=همه)',
				's_key'   => 'S-REG-ACTIVE',
			),
			'exe_long_minutes'     => array(
				'type'    => 'int',
				'default' => 480,
				'min'     => 60,
				'max'     => 1440,
				'group'   => 'finance',
				'label'   => 'هشدار خدمت طولانی (دقیقه)',
				's_key'   => 'S-EXE-LONG',
			),
			'nurse_share_percent'  => array(
				'type'    => 'int',
				'default' => 90,
				'min'     => 1,
				'max'     => 100,
				'group'   => 'finance',
				'label'   => 'سهم پرستار ٪',
				's_key'   => 'S-SHARE-PCT',
			),
			'invoice_after'        => array(
				'type'    => 'enum',
				'default' => 'all_required_steps',
				'enum'    => array( 'all_required_steps' ),
				'group'   => 'finance',
				'label'   => 'زمان صدور فاکتور',
				's_key'   => 'S-INV-AFTER',
			),
			'invoice_remind'       => array(
				'type'    => 'assoc_int',
				'default' => array(
					'after_hours' => 72,
					'count'       => 2,
				),
				'group'   => 'finance',
				'label'   => 'یادآوری فاکتور',
				's_key'   => 'S-INV-REMIND',
			),
			'invoice_number'       => array(
				'type'    => 'string',
				'default' => 'INV-{seq}',
				'group'   => 'finance',
				'label'   => 'قالب شمارهٔ فاکتور',
				's_key'   => 'S-INV-NUMBER',
			),
			'consult_fee'          => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'max'     => 100000000,
				'group'   => 'finance',
				'label'   => 'مبلغ مشاوره پزشکی (تومان)',
				's_key'   => 'S-CONSULT-FEE',
			),
			'pricing_night'        => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'finance',
				'label'   => 'ضریب شب',
				's_key'   => 'S-PRICING-NIGHT',
			),
			'pricing_holiday'      => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'finance',
				'label'   => 'ضریب تعطیل',
				's_key'   => 'S-PRICING-HOLIDAY',
			),
			'pricing_urgent'       => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'finance',
				'label'   => 'ضریب فوری',
				's_key'   => 'S-PRICING-URGENT',
			),
			'badge_threshold'      => array(
				'type'    => 'float',
				'default' => 4.8,
				'min'     => 1,
				'max'     => 5,
				'group'   => 'loyalty',
				'label'   => 'آستانهٔ نشان',
				's_key'   => 'S-BDG-THRESHOLD',
			),
			'badge_min_surveys'    => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 100,
				'group'   => 'loyalty',
				'label'   => 'حداقل پرسشنامه برای نشان',
				's_key'   => 'S-BDG-MIN',
			),
			'loyalty_n'            => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 50,
				'group'   => 'loyalty',
				'label'   => 'تعداد مهر وفاداری',
				's_key'   => 'S-LOY-N',
			),
			'disc_first'           => array(
				'type'    => 'json',
				'default' => array(
					'type'     => 'percent',
					'value'    => 0,
					'cap'      => 0,
					'services' => array(),
				),
				'group'   => 'loyalty',
				'label'   => 'تخفیف سفارش اول',
				's_key'   => 'S-DISC-FIRST',
			),
			'disc_combine'         => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'loyalty',
				'label'   => 'ترکیب تخفیف‌ها',
				's_key'   => 'S-DISC-COMBINE',
			),
			'rec_lead_days'        => array(
				'type'    => 'int',
				'default' => 3,
				'min'     => 1,
				'max'     => 30,
				'group'   => 'loyalty',
				'label'   => 'ساخت نوبت دوره‌ای (روز قبل)',
				's_key'   => 'S-REC-LEAD',
			),
			'rec_max'              => array(
				'type'    => 'int',
				'default' => 30,
				'min'     => 2,
				'max'     => 30,
				'group'   => 'loyalty',
				'label'   => 'سقف نوبت دوره‌ای',
				's_key'   => 'S-REC-MAX',
			),
			'sms_admins'           => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'sms',
				'label'   => 'موبایل ادمین‌های اعلان',
				's_key'   => 'S-SMS-ADMINS',
			),
			'sms_enabled'          => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'sms',
				'label'   => 'فعال بودن پیامک',
				's_key'   => 'S-SMS-ENABLED',
			),
			'fil_doc_max'          => array(
				'type'    => 'int',
				'default' => 5242880,
				'min'     => 1024,
				'max'     => 20971520,
				'group'   => 'upload',
				'label'   => 'سقف مدرک (بایت)',
				's_key'   => 'S-FIL-DOC-MAX',
			),
			'fil_photo_max'        => array(
				'type'    => 'int',
				'default' => 10485760,
				'min'     => 1024,
				'max'     => 20971520,
				'group'   => 'upload',
				'label'   => 'سقف عکس (بایت)',
				's_key'   => 'S-FIL-PHOTO-MAX',
			),
			'fil_report_max'       => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 1,
				'max'     => 20,
				'group'   => 'upload',
				'label'   => 'حداکثر عکس گزارش',
				's_key'   => 'S-FIL-REPORT-MAX',
			),
			'support_phone'        => array(
				'type'    => 'string',
				'default' => '02188607015',
				'group'   => 'general',
				'label'   => 'تلفن پشتیبانی',
				's_key'   => 'S-SUPPORT-PHONE',
			),
			'ui_static'            => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'general',
				'label'   => 'UI استاتیک (fixture)',
				's_key'   => 'S-UI-STATIC',
			),
		);

		foreach ( $sms_events as $evt ) {
			$defs[ 'sms_pattern_' . $evt ] = array(
				'type'    => 'string',
				'default' => '',
				'group'   => 'sms',
				'label'   => 'پترن SMS: ' . $evt,
				's_key'   => 'S-SMS-' . strtoupper( $evt ),
			);
		}

		return $defs;
	}

	/**
	 * Default six 4-hour slots (S-SLOTS).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function default_slots(): array {
		return array(
			array(
				'id'         => '0-4',
				'start_hour' => 0,
				'end_hour'   => 4,
				'name'       => 'بسته',
				'closed'     => true,
			),
			array(
				'id'         => '4-8',
				'start_hour' => 4,
				'end_hour'   => 8,
				'name'       => 'صبح زود',
				'closed'     => false,
			),
			array(
				'id'         => '8-12',
				'start_hour' => 8,
				'end_hour'   => 12,
				'name'       => 'صبح',
				'closed'     => false,
			),
			array(
				'id'         => '12-16',
				'start_hour' => 12,
				'end_hour'   => 16,
				'name'       => 'ظهر',
				'closed'     => false,
			),
			array(
				'id'         => '16-20',
				'start_hour' => 16,
				'end_hour'   => 20,
				'name'       => 'عصر',
				'closed'     => false,
			),
			array(
				'id'         => '20-24',
				'start_hour' => 20,
				'end_hour'   => 24,
				'name'       => 'شب',
				'closed'     => false,
			),
		);
	}

	/**
	 * Resolve storage key from S-xxx alias or raw key.
	 */
	public static function resolve_key( string $key ): string {
		if ( isset( self::definitions()[ $key ] ) ) {
			return $key;
		}
		foreach ( self::definitions() as $storage => $def ) {
			if ( isset( $def['s_key'] ) && $def['s_key'] === $key ) {
				return $storage;
			}
		}
		return $key;
	}

	/**
	 * Get a setting (invalid/missing ⇒ default).
	 *
	 * @param string $key Storage key or S-xxx.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$key  = self::resolve_key( $key );
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) ) {
			return null;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! array_key_exists( $key, $stored ) ) {
			return $defs[ $key ]['default'];
		}

		$validated = self::validate( $key, $stored[ $key ] );
		return ( null === $validated ) ? $defs[ $key ]['default'] : $validated;
	}

	/**
	 * Set a setting after validation. Invalid ⇒ false (unchanged).
	 *
	 * @param string $key   Storage key or S-xxx.
	 * @param mixed  $value Raw value.
	 */
	public static function set( string $key, $value ): bool {
		$key  = self::resolve_key( $key );
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) ) {
			return false;
		}

		$validated = self::validate( $key, $value );
		if ( null === $validated ) {
			return false;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored[ $key ] = $validated;
		update_option( self::OPTION, $stored, false );
		return true;
	}

	/**
	 * All settings with effective values.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::definitions() ) as $key ) {
			$out[ $key ] = self::get( $key );
		}
		return $out;
	}

	/**
	 * Validate against definition. Returns null if invalid.
	 *
	 * @param string $key   Storage key.
	 * @param mixed  $value Raw.
	 * @return mixed|null
	 */
	public static function validate( string $key, $value ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) ) {
			return null;
		}
		$def  = $defs[ $key ];
		$type = $def['type'];

		switch ( $type ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( '1' === $value || 1 === $value || 'true' === $value ) {
					return true;
				}
				if ( '0' === $value || 0 === $value || 'false' === $value || '' === $value ) {
					return false;
				}
				return null;

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$i = (int) $value;
				if ( isset( $def['min'] ) && $i < (int) $def['min'] ) {
					return null;
				}
				if ( isset( $def['max'] ) && $i > (int) $def['max'] ) {
					return null;
				}
				return $i;

			case 'float':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$f = (float) $value;
				if ( isset( $def['min'] ) && $f < (float) $def['min'] ) {
					return null;
				}
				if ( isset( $def['max'] ) && $f > (float) $def['max'] ) {
					return null;
				}
				return $f;

			case 'enum':
				$v = is_string( $value ) ? $value : (string) $value;
				return in_array( $v, $def['enum'], true ) ? $v : null;

			case 'string':
				if ( ! is_scalar( $value ) ) {
					return null;
				}
				return sanitize_text_field( (string) $value );

			case 'list':
				if ( ! is_array( $value ) ) {
					if ( is_string( $value ) ) {
						$parts = preg_split( '/[\s,;]+/', $value ) ?: array();
						$value = array_values( array_filter( array_map( 'sanitize_text_field', $parts ) ) );
					} else {
						return null;
					}
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$out[] = sanitize_text_field( (string) $item );
					}
				}
				return $out;

			case 'assoc_int':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$out = array();
				foreach ( $def['default'] as $k => $default_v ) {
					if ( ! isset( $value[ $k ] ) || ! is_numeric( $value[ $k ] ) ) {
						return null;
					}
					$out[ $k ] = (int) $value[ $k ];
				}
				return $out;

			case 'assoc_list':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$out = array();
				foreach ( $value as $k => $list ) {
					if ( ! is_string( $k ) || ! is_array( $list ) ) {
						continue;
					}
					$clean = array();
					foreach ( $list as $item ) {
						if ( is_scalar( $item ) ) {
							$clean[] = sanitize_text_field( (string) $item );
						}
					}
					$out[ sanitize_text_field( $k ) ] = $clean;
				}
				return $out;

			case 'json':
				if ( is_string( $value ) ) {
					$decoded = json_decode( $value, true );
					$value   = is_array( $decoded ) ? $decoded : null;
				}
				return is_array( $value ) ? $value : null;

			case 'slots':
				if ( ! is_array( $value ) || count( $value ) < 1 ) {
					return null;
				}
				$out = array();
				foreach ( $value as $slot ) {
					if ( ! is_array( $slot ) || empty( $slot['id'] ) ) {
						return null;
					}
					$out[] = array(
						'id'         => sanitize_text_field( (string) $slot['id'] ),
						'start_hour' => isset( $slot['start_hour'] ) ? (int) $slot['start_hour'] : 0,
						'end_hour'   => isset( $slot['end_hour'] ) ? (int) $slot['end_hour'] : 0,
						'name'       => isset( $slot['name'] ) ? sanitize_text_field( (string) $slot['name'] ) : '',
						'closed'     => ! empty( $slot['closed'] ),
					);
				}
				return $out;
		}

		return null;
	}

	/**
	 * Human group labels.
	 *
	 * @return array<string,string>
	 */
	public static function groups(): array {
		return array(
			'assignment' => 'تخصیص و دسترسی',
			'regions'    => 'مناطق',
			'finance'    => 'اجرا و مالی',
			'loyalty'    => 'ارزیابی و تخفیف',
			'sms'        => 'پیامک',
			'upload'     => 'آپلود',
			'general'    => 'عمومی',
		);
	}
}
