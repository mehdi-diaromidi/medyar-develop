<?php
/**
 * RUN-G catalog definitions (10 categories / 44 subservices from §۵). Prices are TEST seeds.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Catalog_V4 {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$out  = array();
		$sort = 10;
		foreach ( self::raw() as $row ) {
			$row['sort']            = $sort;
			$row['checklist_draft'] = 1;
			if ( empty( $row['checklist'] ) || ! is_array( $row['checklist'] ) ) {
				$row['checklist'] = self::default_checklist( (string) $row['name'] );
			}
			$out[] = $row;
			$sort += 10;
		}
		return $out;
	}

	/**
	 * @return array<int,string>
	 */
	private static function default_checklist( string $name ): array {
		return array(
			'بررسی هویت بیمار و آماده‌سازی محیط',
			'انجام خدمت: ' . $name,
			'ثبت مشاهدات و واکنش بیمار',
			'آموزش نکات مراقبتی به بیمار/همراه',
			'جمع‌آوری وسایل و ثبت گزارش',
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function raw(): array {
		$rx   = static function ( array $r ): array {
			$r['requires_rx'] = 1;
			return $r;
		};
		$exp  = static function ( array $r ): array {
			$r['needs_explicit_nurse'] = 1;
			return $r;
		};
		$man  = static function ( array $r ): array {
			$r['manual_assign'] = 1;
			return $r;
		};
		$item = static function ( string $cat, string $label, string $slug, string $name, int $from, int $to, array $extra = array() ): array {
			return array_merge(
				array(
					'slug'                  => $slug,
					'category'              => $cat,
					'category_label'        => $label,
					'name'                  => $name,
					'price_from'            => $from,
					'price_to'              => $to,
					'requires_rx'           => 0,
					'needs_explicit_nurse'  => 0,
					'manual_assign'         => 0,
					'has_drug_step'         => 0,
					'checklist'             => array(),
				),
				$extra
			);
		};

		$c1 = 'inj_serum';
		$l1 = 'تزریقات و سرم‌تراپی';
		$c2 = 'wound';
		$l2 = 'پانسمان و مراقبت از زخم';
		$c3 = 'catheter';
		$l3 = 'خدمات سوند ادراری';
		$c4 = 'tube_stoma';
		$l4 = 'مراقبت از لوله تغذیه و استوما';
		$c5 = 'vitals';
		$l5 = 'پایش وضعیت بیمار';
		$c6 = 'post_dis';
		$l6 = 'مراقبت پس از ترخیص و جراحی';
		$c7 = 'hourly';
		$l7 = 'مراقبت پرستاری ساعتی و شیفتی';
		$c8 = 'respiratory';
		$l8 = 'خدمات تنفسی';
		$c9 = 'education';
		$l9 = 'آموزش بیمار و خانواده';
		$c0 = 'other';
		$l0 = 'سایر خدمات';

		$rows = array(
			$item( $c1, $l1, 'im_injection', 'تزریق عضلانی', 400000, 700000, array(
				'checklist' => array( 'بررسی هویت بیمار و دستور دارو', 'محل تزریق', 'داروی مصرف‌شده', 'واکنش بیمار پس از تزریق' ),
			) ),
			$item( $c1, $l1, 'sc_injection', 'تزریق زیرجلدی', 400000, 700000, array(
				'checklist' => array( 'بررسی هویت بیمار و دستور دارو', 'محل تزریق زیرجلدی', 'داروی مصرف‌شده', 'واکنش بیمار پس از تزریق' ),
			) ),
			$rx( $item( $c1, $l1, 'iv_injection', 'تزریق وریدی طبق دستور پزشک', 700000, 1200000, array(
				'checklist' => array( 'تأیید نسخه پزشک', 'بررسی رگ و محل تزریق', 'داروی وریدی و دوز', 'پایش واکنش بیمار' ),
			) ) ),
			$rx( $item( $c1, $l1, 'iv_infusion', 'وصل سرم و اجرای دارودرمانی تجویزشده', 2000000, 2500000, array(
				'has_drug_step' => 1,
				'checklist'     => array( 'تأیید نسخه و نوع سرم', 'رگ‌گیری / آنژیوکت', 'نرخ انفوزیون', 'داروهای افزوده‌شده', 'واکنش بیمار' ),
			) ) ),
			$item( $c1, $l1, 'iv_cannula', 'رگ‌گیری و تعویض یا خارج کردن آنژیوکت', 600000, 1000000 ),

			$item( $c2, $l2, 'wound_surg_dress', 'تعویض پانسمان زخم جراحی', 800000, 1500000 ),
			$item( $c2, $l2, 'wound_pressure', 'مراقبت از زخم بستر', 1000000, 1800000 ),
			$item( $c2, $l2, 'wound_diabetic', 'مراقبت از زخم پای دیابتی', 1200000, 2000000 ),
			$item( $c2, $l2, 'wound_other', 'مراقبت از سایر زخم‌ها پس از ارزیابی', 900000, 1600000 ),
			$rx( $item( $c2, $l2, 'suture_removal', 'کشیدن بخیه یا منگنه با تأیید پزشک', 600000, 1000000 ) ),
			$item( $c2, $l2, 'wound_edu', 'آموزش مراقبت از زخم به بیمار و خانواده', 400000, 700000 ),

			$rx( $item( $c3, $l3, 'foley_manage', 'گذاشتن، تعویض و خارج کردن سوند ادراری طبق دستور پزشک', 1000000, 1600000 ) ),
			$item( $c3, $l3, 'urine_bag', 'تعویض کیسه ادرار', 400000, 700000 ),
			$item( $c3, $l3, 'foley_care_edu', 'مراقبت از سوند و آموزش به بیمار و خانواده', 500000, 800000 ),

			$item( $c4, $l4, 'ng_tube_care', 'مراقبت از لوله تغذیه بینی‌ـ‌معده‌ای', 700000, 1200000 ),
			$item( $c4, $l4, 'peg_site_care', 'مراقبت از محل لوله تغذیه معده (PEG)', 800000, 1300000 ),
			$item( $c4, $l4, 'tube_feed_edu', 'آموزش تغذیه از طریق لوله طبق برنامه درمانی', 500000, 800000 ),
			$item( $c4, $l4, 'stoma_care', 'تعویض کیسه و مراقبت از پوست اطراف استوما', 800000, 1400000 ),

			$item( $c5, $l5, 'bp_check', 'اندازه‌گیری و ثبت فشار خون', 250000, 400000 ),
			$item( $c5, $l5, 'bg_check', 'اندازه‌گیری قند خون با دستگاه', 250000, 400000 ),
			$item( $c5, $l5, 'pulse_rr_temp', 'بررسی نبض، تعداد تنفس و دمای بدن', 250000, 450000 ),
			$item( $c5, $l5, 'spo2_check', 'اندازه‌گیری سطح اکسیژن خون', 250000, 400000 ),
			$item( $c5, $l5, 'vitals_report', 'ثبت علائم حیاتی و گزارش به پزشک معالج', 350000, 600000 ),

			$item( $c6, $l6, 'post_dis_assess', 'بررسی نیازهای پرستاری پس از ترخیص', 700000, 1200000 ),
			$rx( $item( $c6, $l6, 'post_dis_plan', 'اجرای دستورات پزشک و برنامه مراقبتی', 800000, 1400000 ) ),
			$item( $c6, $l6, 'med_assist', 'کمک به مصرف صحیح داروهای تجویزشده', 500000, 900000 ),
			$rx( $item( $c6, $l6, 'surgery_drain', 'مراقبت از محل جراحی و درن طبق دستور پزشک', 900000, 1500000 ) ),
			$item( $c6, $l6, 'home_care_edu', 'آموزش مراقبت در منزل به بیمار و همراه', 600000, 1000000 ),

			$man( $item( $c7, $l7, 'hourly_elder', 'مراقبت از بیمار سالمند (قیمت هر ساعت)', 400000, 700000 ) ),
			$man( $item( $c7, $l7, 'hourly_bed', 'مراقبت از بیمار کم‌توان یا وابسته به تخت (قیمت هر ساعت)', 500000, 800000 ) ),
			$man( $item( $c7, $l7, 'hourly_chronic', 'مراقبت از بیماران مبتلا به بیماری‌های مزمن (قیمت هر ساعت)', 400000, 700000 ) ),
			$man( $item( $c7, $l7, 'hourly_stroke', 'مراقبت از بیماران نیازمند رسیدگی پس از سکته یا جراحی (قیمت هر ساعت)', 500000, 900000 ) ),
			$man( $item( $c7, $l7, 'hourly_shift', 'خدمات پرستاری در شیفت‌های توافق‌شده', 0, 0 ) ),

			$rx( $item( $c8, $l8, 'o2_therapy', 'اجرای اکسیژن‌درمانی تجویزشده', 600000, 1000000 ) ),
			$rx( $item( $c8, $l8, 'nebulizer', 'نبولایزر طبق دستور پزشک', 400000, 700000 ) ),
			$exp( $item( $c8, $l8, 'suction', 'ساکشن ترشحات در بیماران واجد شرایط', 700000, 1200000 ) ),
			$exp( $item( $c8, $l8, 'tracheostomy', 'مراقبت از تراکئوستومی توسط نیروی دارای صلاحیت', 1000000, 1800000 ) ),

			$item( $c9, $l9, 'edu_bp_bg', 'آموزش استفاده از دستگاه فشار خون و قند خون', 400000, 700000 ),
			$item( $c9, $l9, 'edu_sc_inject', 'آموزش تزریق داروهای زیرجلدی تجویزشده، مانند انسولین', 500000, 800000 ),
			$item( $c9, $l9, 'edu_pressure_ulcer', 'آموزش پیشگیری از زخم بستر', 400000, 700000 ),
			$item( $c9, $l9, 'edu_safe_transfer', 'آموزش جابه‌جایی ایمن بیمار', 400000, 700000 ),
			$item( $c9, $l9, 'edu_devices', 'آموزش مراقبت از سوند، لوله تغذیه و تجهیزات بیمار', 500000, 800000 ),

			$item( $c0, $l0, 'home_assess', 'ارزیابی اولیه نیازهای پرستاری در منزل', 500000, 900000 ),
			$man( $item( $c0, $l0, 'custom_request', 'درخواست خدمت خارج از فهرست و بررسی توسط پشتیبانی', 0, 0 ) ),
		);

		return $rows;
	}
}
