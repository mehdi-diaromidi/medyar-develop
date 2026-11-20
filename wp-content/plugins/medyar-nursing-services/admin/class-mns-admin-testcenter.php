<?php
/**
 * Temporary "Test center": one page that guides every manual test (develop site only).
 *
 * Reuses MNS_E2E (test users, sample orders, one-time login links) and MNS_Role_Switch (own-account role switch).
 * Remove together with the E2E tool before going live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_TestCenter {

	public const PAGE = 'mns-testcenter';

	public static function boot(): void {
		if ( ! class_exists( 'MNS_E2E', true ) || ! MNS_E2E::host_allowed() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'bar' ), 90 );
		foreach ( array( 'enable', 'users', 'orders', 'links', 'purge' ) as $act ) {
			add_action( 'admin_post_mns_tc_' . $act, array( __CLASS__, 'handle_' . $act ) );
		}
	}

	public static function menu(): void {
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			'مرکز تست',
			'🧪 مرکز تست',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function bar( $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'mns-testcenter',
				'title' => '🧪 مرکز تست',
				'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
			)
		);
	}

	/* ------------------------------------------------------------------ actions */

	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) || ! MNS_E2E::host_allowed() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mns_tc' );
	}

	private static function back( string $msg, bool $ok = true ): void {
		set_transient( 'mns_tc_flash_' . get_current_user_id(), array( 'ok' => $ok, 'msg' => $msg ), 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	private static function data(): array {
		$d = get_transient( 'mns_tc_data_' . get_current_user_id() );
		return is_array( $d ) ? $d : array();
	}

	private static function save_data( array $d ): void {
		set_transient( 'mns_tc_data_' . get_current_user_id(), $d, 1800 );
	}

	private static function links_from( array $users ): array {
		$out = array();
		foreach ( $users as $key => $row ) {
			if ( is_array( $row ) && ! empty( $row['login_url'] ) ) {
				$out[ (string) $key ] = (string) $row['login_url'];
			}
		}
		return $out;
	}

	public static function handle_enable(): void {
		self::guard();
		MNS_E2E::enable_forever();
		self::back( 'ابزار تست بدون محدودیت زمانی فعال شد.' );
	}

	public static function handle_users(): void {
		self::guard();
		try {
			$res        = MNS_E2E::seed();
			$d          = self::data();
			$d['links'] = self::links_from( isset( $res['users'] ) && is_array( $res['users'] ) ? $res['users'] : array() );
			self::save_data( $d );
		} catch ( \Throwable $e ) {
			self::back( 'ساخت کاربران ناموفق بود: ' . $e->getMessage(), false );
		}
		self::back( 'کاربران تستی ساخته شدند و لینک‌های ورود پایین همین بخش آماده است.' );
	}

	public static function handle_orders(): void {
		self::guard();
		try {
			$res         = MNS_E2E::seed_assigned_orders();
			$d           = self::data();
			$d['orders'] = isset( $res['orders'] ) && is_array( $res['orders'] ) ? $res['orders'] : array();
			self::save_data( $d );
		} catch ( \Throwable $e ) {
			self::back( 'ساخت سفارش نمونه ناموفق بود: ' . $e->getMessage(), false );
		}
		self::back( 'دو سفارش نمونهٔ تخصیص‌یافته ساخته شد.' );
	}

	public static function handle_links(): void {
		self::guard();
		$links = self::links_from( MNS_E2E::mint_links_for_existing() );
		if ( empty( $links ) ) {
			self::back( 'اول ابزار را فعال کنید و کاربران تستی را بسازید.', false );
		}
		$d          = self::data();
		$d['links'] = $links;
		self::save_data( $d );
		self::back( 'لینک‌های ورود تازه ساخته شد (هر کدام یک‌بار مصرف و ۲۴ ساعت معتبر).' );
	}

	public static function handle_purge(): void {
		self::guard();
		MNS_E2E::purge_all();
		delete_transient( 'mns_tc_data_' . get_current_user_id() );
		self::back( 'همهٔ کاربران و سفارش‌های تستی پاک شدند.' );
	}

	/* ------------------------------------------------------------------ view helpers */

	private static function post_form( string $act, string $label, string $class = '', bool $disabled = false ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-tc__form">';
		$html .= wp_nonce_field( 'mns_tc', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="mns_tc_' . esc_attr( $act ) . '" />';
		$html .= '<button type="submit" class="mns-tc__btn ' . esc_attr( $class ) . '"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	private static function switch_url( string $mode ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . MNS_Role_Switch::ACTION . '&mode=' . rawurlencode( $mode ) ),
			MNS_Role_Switch::NONCE
		);
	}

	private static function p( string $panel, string $page, array $args = array() ): string {
		$base = 'nurse' === $panel ? '/nursing-nurse-panel/' : '/nursing-patient-panel/';
		return add_query_arg(
			array_merge(
				array(
					'mns_panel' => $panel,
					'mns_page'  => $page,
				),
				$args
			),
			home_url( $base )
		);
	}

	/**
	 * Sections with their checkable steps: [id, emoji, title, hint, steps[]], step = [id, who, what, expect, url, link label].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function sections(): array {
		return array(
			array(
				'id'    => 'flow',
				'emoji' => '🛒',
				'title' => 'جریان کامل یک سفارش',
				'hint'  => 'از ثبت توسط بیمار تا تخصیص، فاکتور و پرداخت. هر مرحله می‌گوید چه کسی انجامش می‌دهد و چه چیزی باید ببینی.',
				'steps' => array(
					array( 'f1', 'patient', 'داشبورد را باز کن و یک دسته انتخاب کن', 'کارت «ثبت سریع سفارش» ۱۰ دسته با تعداد خدمت دارد. با زدن یک دسته وارد ثبت سفارش می‌شوی و بالا نوشته «دسته: …».', self::p( 'patient', 'dashboard' ), 'داشبورد بیمار' ),
					array( 'f2', 'patient', 'ثبت سفارش را تا آخر پر کن', 'در مرحلهٔ ۲ چند زیرخدمت همان دسته را تیک بزن و بازهٔ قیمت جمع را ببین. اگر برای منطقه/جنسیت پرستار نباشد، کادر قرمز با دکمهٔ تماس می‌آید.', self::p( 'patient', 'new-order', array( 'mns_category' => 'inj_serum' ) ), 'ثبت سفارش' ),
					array( 'f3', 'patient', 'سفارش را ثبت کن', 'کد پیگیری ۵ رقمی و لینک پیگیری می‌آید. دوبار کلیک نباید دو سفارش بسازد.', '', '' ),
					array( 'f4', 'admin', 'سفارش را در رصد مرکزی پیدا کن', 'سفارش با وضعیت «ثبت‌شده» یا «تخصیص‌یافته» (اگر تخصیص خودکار روشن باشد) دیده می‌شود.', admin_url( 'admin.php?page=mns-monitor' ), 'رصد مرکزی' ),
					array( 'f5', 'admin', 'اگر تخصیص نخورده بود، پرستار را دستی تعیین کن', 'بعد از تخصیص، وضعیت «تخصیص‌یافته» می‌شود و پرستار سفارش را می‌بیند.', admin_url( 'admin.php?page=mns-assign' ), 'تخصیص' ),
					array( 'f6', 'nurse', 'سفارش را در فهرست و جزئیات ببین', 'نشانی و تلفن فقط برای سفارش در حال انجام/تخصیص‌یافته دیده می‌شود؛ کد ملی و مبلغ هرگز.', self::p( 'nurse', 'orders' ), 'سفارش‌های پرستار' ),
					array( 'f7', 'nurse', 'چک‌لیست خدمت‌ها', 'چک‌لیست همهٔ زیرخدمت‌های سفارش، گروه‌بندی‌شده، دیده می‌شود (فرم کامل تعاملی هنوز در حال ساخت است).', self::p( 'nurse', 'checklist' ), 'چک‌لیست پرستار' ),
					array( 'f8', 'admin', 'صدور فاکتور برای سفارش تمام‌شده', 'مبلغ نهایی را خودت وارد می‌کنی؛ بدون مبلغ رد می‌شود. بعد از صدور، وضعیت سفارش «فاکتورشده» می‌شود.', admin_url( 'admin.php?page=mns-finance&tab=unpaid' ), 'مالی و فاکتور' ),
					array( 'f9', 'patient', 'فاکتور و دکمهٔ «پرداخت آنلاین»', 'فاکتور فقط برای صاحبش دیده می‌شود. پرداخت واقعی زرین‌پال روی develop ممکن است به‌خاطر دامنهٔ مرچنت رد شود (خطای ۱۴- یا ۱۸-).', self::p( 'patient', 'invoices' ), 'فاکتورهای بیمار' ),
					array( 'f10', 'patient', 'لغو یک سفارش', 'در جزئیات سفارش «لغو» را بزن و دلیل بنویس؛ وضعیت «لغو شده» می‌شود و دکمه می‌رود.', self::p( 'patient', 'orders' ), 'سفارش‌های بیمار' ),
				),
			),
			array(
				'id'    => 'ui',
				'emoji' => '🎨',
				'title' => 'صفحهٔ ثبت سفارش — جزئیات ظاهری',
				'hint'  => 'چیزهایی که با چشم باید دید.',
				'steps' => array(
					array( 'u1', 'patient', 'تاریخ‌پیکر', 'باکس تقویم هم‌عرض و هم‌تراز ورودی باز شود و ۷ ستون روز کل عرض را پر کنند (دسکتاپ و موبایل).', self::p( 'patient', 'new-order', array( 'mns_category' => 'wound' ) ), 'باز کردن' ),
					array( 'u2', 'patient', 'کادر قرمز «پرستار موجود نیست»', 'منطقه و جنسیتی را انتخاب کن که پرستار ندارد: کادر نرم با نوار قرمز و دکمهٔ تماس (کنار پیام در دسکتاپ، تمام‌عرض در موبایل).', '', '' ),
					array( 'u3', 'patient', 'دستهٔ ساعتی/شیفتی', 'پیام «همکاران ما با شما هماهنگ می‌کنند» دیده شود و تخصیص خودکار اجرا نشود.', self::p( 'patient', 'new-order', array( 'mns_category' => 'hourly' ) ), 'دستهٔ ساعتی' ),
					array( 'u4', 'patient', 'حالت خالی بیمار تازه‌وارد', 'در داشبورد کارت آبی «اولین سفارش پرستاری» با سه مرحله و دکمهٔ سفید دیده شود (فقط وقتی بیمار سفارشی ندارد).', '', '' ),
				),
			),
			array(
				'id'    => 'nurse',
				'emoji' => '🩺',
				'title' => 'پنل پرستار',
				'hint'  => 'با پرستار تستی یا نقش پرستار.',
				'steps' => array(
					array( 'n1', 'nurse', 'داشبورد', 'نام خودت در هدر (نه «علی رضایی») و برنامهٔ پیش‌رو با ساعت درست (مثلاً ۰۸:۰۰ تا ۱۲:۰۰).', self::p( 'nurse', 'dashboard' ), 'داشبورد پرستار' ),
					array( 'n2', 'nurse', 'برنامه و دسترسی', 'روز دارای سفارش را خاموش کن ⇒ پیام «این بازه سفارش تخصیص‌یافته دارد». حذف منطقهٔ سفارش ⇒ هشدار تعداد.', self::p( 'nurse', 'availability' ), 'برنامه و دسترسی' ),
					array( 'n3', 'nurse', 'تب مناطق', 'دکمه‌های «انتخاب همه / پاک‌کردن همه» ساده و بدون انیمیشن هاور باشند.', self::p( 'nurse', 'availability', array( 'mns_tab' => 'regions' ) ), 'تب مناطق' ),
					array( 'n4', 'nurse', 'پروفایل', 'شمارهٔ موبایل و جنسیت قابل ویرایش نباشد؛ کد ملی نامعتبر خطای فیلد بدهد.', self::p( 'nurse', 'profile' ), 'پروفایل پرستار' ),
				),
			),
			array(
				'id'    => 'admin',
				'emoji' => '🛠️',
				'title' => 'بخش ادمین',
				'hint'  => 'مدیریت خدمات، پرستاران و تنظیمات.',
				'steps' => array(
					array( 'a1', 'admin', 'خدمات (۱۰ دسته، ۴۴ زیرخدمت)', 'گروه‌بندی‌شده بر اساس دسته؛ قیمت از/تا و فلگ‌ها و چک‌لیست قابل ویرایش‌اند (چک‌لیست‌ها «پیش‌نویس»).', admin_url( 'admin.php?page=mns-services' ), 'خدمات' ),
					array( 'a2', 'admin', 'عضویت پرستاران', 'فهرست درخواست‌ها؛ تأیید/رد/تعلیق فقط از وضعیت درست کار کند.', admin_url( 'admin.php?page=mns-nurses' ), 'پرستاران' ),
					array( 'a3', 'admin', 'تنظیمات', 'تخصیص خودکار/دستی، ظرفیت بازه و سایر تنظیمات.', admin_url( 'admin.php?page=mns-settings' ), 'تنظیمات' ),
					array( 'a4', 'admin', 'تست‌های خودکار', 'روی هر ۴ گروه (هسته، سفارش، مالی، کاتالوگ) بزن: همه باید «PASS» و «FAIL=0» باشند.', admin_url( 'tools.php?page=mns-selftest' ), 'تست‌های خودکار' ),
				),
			),
		);
	}

	private static function who_label( string $who ): string {
		$map = array(
			'patient' => 'بیمار',
			'nurse'   => 'پرستار',
			'admin'   => 'ادمین',
		);
		return $map[ $who ] ?? $who;
	}

	/* ------------------------------------------------------------------ page */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! MNS_E2E::host_allowed() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		$flash = get_transient( 'mns_tc_flash_' . get_current_user_id() );
		if ( false !== $flash ) {
			delete_transient( 'mns_tc_flash_' . get_current_user_id() );
		}
		$data    = self::data();
		$links   = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array();
		$orders  = isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : array();
		$enabled = MNS_E2E::is_enabled();
		$until   = (int) get_option( MNS_E2E::OPT_UNTIL, 0 );
		$mode    = MNS_Role_Switch::current_mode();
		$labels  = array(
			'admin'   => 'ادمین',
			'nurse'   => 'پرستار',
			'patient' => 'بیمار',
		);
		$have_users = (bool) get_user_by( 'login', 'mns_e2e_patient' );
		$names      = array(
			'patient' => 'بیمار تستی',
			'nurse_f' => 'پرستار خانم',
			'nurse_m' => 'پرستار آقا',
		);
		?>
		<style>
			.mns-tc { --c1: #0272b1; --c2: #0a3d5c; --ok: #16a34a; --line: #e4e7eb; --ink: #1d2327; --mut: #646970; max-width: 1000px; font-size: 14px; color: var(--ink); }
			.mns-tc * { box-sizing: border-box; }
			/* hero */
			.mns-tc__hero { position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between; gap: 20px; margin: 16px 0 14px; padding: 22px 24px; border-radius: 18px; color: #fff; background: linear-gradient(135deg, #0a7fc2 0%, #0272b1 45%, #0a3d5c 100%); box-shadow: 0 12px 28px rgba(2, 114, 177, 0.28); }
			.mns-tc__hero::before, .mns-tc__hero::after { content: ""; position: absolute; border-radius: 50%; pointer-events: none; background: rgba(255, 255, 255, 0.08); }
			.mns-tc__hero::before { width: 240px; height: 240px; top: -110px; inset-inline-end: 140px; }
			.mns-tc__hero::after { width: 150px; height: 150px; bottom: -70px; inset-inline-start: -30px; }
			.mns-tc__hero > * { position: relative; z-index: 1; }
			.mns-tc__title { margin: 0 0 4px; color: #fff; font-size: 26px; font-weight: 800; line-height: 1.4; }
			/* WordPress moves every plugin notice under the page heading; keep this page focused. */
			.mns-tc .notice, .mns-tc .updated, .mns-tc .update-nag, .mns-tc div.error { display: none !important; }
			.mns-tc__hero p { margin: 0 0 14px; max-width: 560px; color: rgba(255, 255, 255, 0.9); line-height: 1.8; }
			.mns-tc__chips { display: flex; flex-wrap: wrap; gap: 8px; }
			.mns-tc__chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px; background: rgba(255, 255, 255, 0.16); font-size: 12.5px; font-weight: 600; }
			.mns-tc__dot { width: 8px; height: 8px; border-radius: 50%; background: #9aa5ad; }
			.mns-tc__dot.is-on { background: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.3); }
			.mns-tc__ring { position: relative; flex: 0 0 auto; width: 132px; height: 132px; display: grid; place-items: center; text-align: center; }
			.mns-tc__ring svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
			.mns-tc__ring circle { fill: none; stroke-width: 10; }
			.mns-tc__ring .bg { stroke: rgba(255, 255, 255, 0.2); }
			.mns-tc__ring .fg { stroke: #fff; stroke-linecap: round; stroke-dasharray: 339.3; stroke-dashoffset: 339.3; transition: stroke-dashoffset 0.7s cubic-bezier(0.22, 1, 0.36, 1); }
			.mns-tc__ring b { display: block; font-size: 28px; line-height: 1.1; }
			.mns-tc__ring small { display: block; margin-top: 2px; font-size: 11.5px; opacity: 0.9; }
			.mns-tc__cheer { margin: 0 0 14px; font-weight: 700; color: var(--c2); }
			/* sticky progress */
			.mns-tc__sticky { position: sticky; top: 32px; z-index: 20; height: 5px; margin: 0 0 14px; border-radius: 999px; background: #dfe6ec; overflow: hidden; }
			.mns-tc__sticky i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #0272b1, #16a34a); transition: width 0.6s cubic-bezier(0.22, 1, 0.36, 1); }
			/* flash */
			.mns-tc__flash { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; padding: 11px 14px; border-radius: 12px; font-weight: 600; animation: mns-tc-pop 0.35s cubic-bezier(0.22, 1, 0.36, 1) both; }
			.mns-tc__flash--ok { background: #e7f7ee; border: 1px solid #9ad6b3; color: #0b5d2e; }
			.mns-tc__flash--err { background: #fdecef; border: 1px solid #f1a9b8; color: #8f1034; }
			/* cards */
			.mns-tc__card { position: relative; margin: 0 0 14px; padding: 18px 20px; border: 1px solid var(--line); border-radius: 16px; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); }
			.mns-tc__head { display: flex; align-items: center; gap: 12px; margin: 0 0 12px; }
			.mns-tc__emoji { flex: 0 0 auto; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: #eaf4fb; font-size: 22px; }
			.mns-tc__head h2 { margin: 0; padding: 0; font-size: 16px; line-height: 1.5; }
			.mns-tc__head p { margin: 2px 0 0; color: var(--mut); font-size: 13px; line-height: 1.7; }
			.mns-tc__head > div { flex: 1; min-width: 0; }
			.mns-tc__sec-prog { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 700; color: var(--mut); }
			.mns-tc__sec-bar { width: 84px; height: 6px; border-radius: 999px; background: #e6ebf0; overflow: hidden; }
			.mns-tc__sec-bar i { display: block; height: 100%; width: 0; background: var(--ok); transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1); }
			.mns-tc__card.is-complete { border-color: #9ad6b3; background: linear-gradient(180deg, #f3fbf6, #fff 60%); }
			.mns-tc__card.is-complete .mns-tc__emoji { background: #dcf3e4; }
			/* buttons */
			.mns-tc__row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
			.mns-tc__form { margin: 0; }
			.mns-tc__btn, .mns-tc__mode, .mns-tc__go { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 38px; padding: 0 16px; border: 1px solid #c8d0d8; border-radius: 10px; background: #fff; color: var(--ink); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; transition: background-color 0.15s ease, border-color 0.15s ease; }
			.mns-tc__btn:hover, .mns-tc__mode:hover, .mns-tc__go:hover { background: #f1f6fa; border-color: #9fb5c6; color: var(--ink); }
			.mns-tc__btn:focus-visible, .mns-tc__mode:focus-visible, .mns-tc__go:focus-visible { outline: 2px solid var(--c1); outline-offset: 2px; }
			.mns-tc__btn:disabled { opacity: 0.45; cursor: not-allowed; }
			.mns-tc__btn--primary { background: var(--c1); border-color: var(--c1); color: #fff; }
			.mns-tc__btn--primary:hover { background: #015a8c; border-color: #015a8c; color: #fff; }
			.mns-tc__btn--danger { color: #a30036; border-color: #e9a8b8; }
			.mns-tc__btn--danger:hover { background: #fdecf0; border-color: #e08ba1; color: #a30036; }
			.mns-tc__mode.is-current { background: var(--c1); border-color: var(--c1); color: #fff; }
			/* ways A/B */
			.mns-tc__ways { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
			.mns-tc__way { position: relative; padding: 14px 16px; border: 1px solid var(--line); border-radius: 14px; background: #fafbfc; }
			.mns-tc__way--best { border-color: #9ad6b3; background: #f3fbf6; }
			.mns-tc__way h3 { margin: 0 0 6px; font-size: 14.5px; }
			.mns-tc__way p { margin: 0 0 10px; color: #3c434a; line-height: 1.8; }
			.mns-tc__ribbon { position: absolute; top: -10px; inset-inline-end: 14px; padding: 2px 10px; border-radius: 999px; background: var(--ok); color: #fff; font-size: 11.5px; font-weight: 700; }
			.mns-tc__note { margin: 12px 0 0; padding: 9px 12px; border-radius: 10px; background: #fff8e5; border: 1px solid #f0d58a; color: #6b4a00; line-height: 1.8; }
			/* flow of buttons */
			.mns-tc__flowbtns { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 4px; }
			.mns-tc__flowbtns .arrow { color: #9aa5ad; font-weight: 700; }
			.mns-tc__links { display: grid; gap: 8px; margin-top: 14px; }
			.mns-tc__link { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 12px; background: #fafbfc; }
			.mns-tc__link b { flex: 0 0 96px; }
			.mns-tc__link input { flex: 1; min-width: 0; height: 34px; border-radius: 8px; direction: ltr; font-size: 12px; }
			/* steps */
			.mns-tc__steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
			.mns-tc__step { padding: 11px 12px; border: 1px solid var(--line); border-radius: 12px; background: #fff; transition: background-color 0.25s ease, border-color 0.25s ease; }
			.mns-tc__step:hover { border-color: #c4d3df; }
			.mns-tc__step.is-done { background: #f3fbf6; border-color: #bfe5cd; }
			.mns-tc__top { display: flex; align-items: center; gap: 10px; }
			.mns-tc__chk { position: relative; flex: 0 0 auto; display: inline-flex; width: 24px; height: 24px; cursor: pointer; }
			.mns-tc__chk input { position: absolute; inset: 0; margin: 0; opacity: 0; cursor: pointer; }
			.mns-tc__chk span { display: grid; place-items: center; width: 24px; height: 24px; border: 2px solid #b6c1cb; border-radius: 8px; background: #fff; transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.2s ease; }
			.mns-tc__chk svg { width: 14px; height: 14px; stroke: #fff; stroke-width: 3; fill: none; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 22; stroke-dashoffset: 22; transition: stroke-dashoffset 0.25s ease 0.05s; }
			.mns-tc__chk input:checked + span { background: var(--ok); border-color: var(--ok); transform: scale(1.06); }
			.mns-tc__chk input:checked + span svg { stroke-dashoffset: 0; }
			.mns-tc__chk input:focus-visible + span { outline: 2px solid var(--c1); outline-offset: 2px; }
			.mns-tc__who { flex: 0 0 auto; min-width: 54px; padding: 3px 9px; border-radius: 999px; text-align: center; font-size: 12px; font-weight: 700; }
			.mns-tc__who--patient { background: #e1f0fa; color: #014a74; }
			.mns-tc__who--nurse { background: #e3f6ea; color: #0b5d2e; }
			.mns-tc__who--admin { background: #eee8fb; color: #4b2a8a; }
			.mns-tc__what { flex: 1; min-width: 0; font-weight: 700; line-height: 1.6; }
			.mns-tc__step.is-done .mns-tc__what { color: #5f7a6a; }
			.mns-tc__go { min-height: 32px; padding: 0 12px; border-radius: 8px; font-size: 12.5px; color: var(--c1); }
			.mns-tc__expect { margin: 6px 0 0; padding-inline-start: 74px; color: #50575e; line-height: 1.8; }
			/* toast + confetti */
			.mns-tc__toast { position: fixed; inset-inline: 0; bottom: 28px; z-index: 99999; display: flex; justify-content: center; pointer-events: none; }
			.mns-tc__toast span { padding: 11px 20px; border-radius: 999px; background: #0a3d5c; color: #fff; font-weight: 700; box-shadow: 0 10px 28px rgba(0, 0, 0, 0.25); opacity: 0; transform: translateY(14px); transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
			.mns-tc__toast.is-on span { opacity: 1; transform: none; }
			.mns-tc__spark { position: absolute; top: 20px; inset-inline-start: 50%; width: 8px; height: 8px; border-radius: 2px; pointer-events: none; opacity: 0; animation: mns-tc-spark 1.1s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
			@keyframes mns-tc-spark { 0% { opacity: 1; transform: translate(0, 0) rotate(0); } 100% { opacity: 0; transform: translate(var(--dx), var(--dy)) rotate(var(--rot)); } }
			@keyframes mns-tc-pop { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
			@media (max-width: 782px) {
				.mns-tc__hero { flex-direction: column-reverse; align-items: flex-start; padding: 18px; }
				.mns-tc__ring { width: 110px; height: 110px; }
				.mns-tc__expect { padding-inline-start: 0; }
				.mns-tc__sec-prog { display: none; }
				.mns-tc__link { flex-wrap: wrap; }
				.mns-tc__link b { flex-basis: 100%; }
				/* steps: [tick][who] ... [go] on the first line, the title gets the full second line */
				.mns-tc__top { flex-wrap: wrap; row-gap: 8px; }
				.mns-tc__chk { order: 1; }
				.mns-tc__who { order: 2; }
				.mns-tc__go { order: 3; margin-inline-start: auto; }
				.mns-tc__what { order: 4; flex: 1 1 100%; }
				.mns-tc__flowbtns .arrow { display: none; }
				.mns-tc__flowbtns, .mns-tc__flowbtns .mns-tc__form { width: 100%; }
				.mns-tc__flowbtns .mns-tc__btn { width: 100%; }
			}
			@media (prefers-reduced-motion: reduce) {
				.mns-tc *, .mns-tc__toast span { transition: none !important; animation: none !important; }
			}
		</style>

		<div class="wrap mns-tc">
			<h1 class="screen-reader-text">مرکز تست</h1>
			<header class="mns-tc__hero">
				<div>
					<div class="mns-tc__title">🧪 مرکز تست</div>
					<p>همهٔ تست‌های دستی از همین یک صفحه. از بالا به پایین برو، هر مرحله را که دیدی تیک بزن و لذتش را ببر. تیک‌ها در همین مرورگر ذخیره می‌شوند.</p>
					<div class="mns-tc__chips">
						<span class="mns-tc__chip">👤 نقش تو: <?php echo esc_html( $labels[ $mode ] ?? $mode ); ?></span>
						<span class="mns-tc__chip"><i class="mns-tc__dot<?php echo $enabled ? ' is-on' : ''; ?>"></i><?php echo $enabled ? ( $until >= MNS_E2E::FOREVER ? 'ابزار تست فعال (بدون محدودیت)' : esc_html( 'ابزار تست فعال تا ' . wp_date( 'H:i', $until ) ) ) : 'ابزار تست خاموش'; ?></span>
						<span class="mns-tc__chip"><i class="mns-tc__dot<?php echo $have_users ? ' is-on' : ''; ?>"></i><?php echo $have_users ? 'کاربران تستی آماده' : 'کاربران تستی ساخته نشده'; ?></span>
						<span class="mns-tc__chip">🏷️ نسخه <?php echo esc_html( defined( 'MNS_VERSION' ) ? MNS_VERSION : '?' ); ?></span>
					</div>
				</div>
				<div class="mns-tc__ring" aria-live="polite">
					<svg viewBox="0 0 120 120" aria-hidden="true"><circle class="bg" cx="60" cy="60" r="54"></circle><circle class="fg" cx="60" cy="60" r="54" data-mns-tc-ring></circle></svg>
					<div><b data-mns-tc-pct>۰٪</b><small data-mns-tc-count>—</small></div>
				</div>
			</header>
			<div class="mns-tc__sticky" aria-hidden="true"><i data-mns-tc-bar></i></div>
			<p class="mns-tc__cheer" data-mns-tc-cheer></p>

			<?php if ( is_array( $flash ) ) : ?>
				<div class="mns-tc__flash <?php echo ! empty( $flash['ok'] ) ? 'mns-tc__flash--ok' : 'mns-tc__flash--err'; ?>"><?php echo ! empty( $flash['ok'] ) ? '✅' : '⚠️'; ?> <?php echo esc_html( (string) $flash['msg'] ); ?></div>
			<?php endif; ?>

			<section class="mns-tc__card">
				<div class="mns-tc__head">
					<span class="mns-tc__emoji">🧭</span>
					<div><h2>قبل از شروع: با چه نقشی تست کنم؟</h2><p>برای هر بخش فقط یک راه را به کار ببر تا قاطی نشود.</p></div>
				</div>
				<div class="mns-tc__ways">
					<div class="mns-tc__way mns-tc__way--best">
						<span class="mns-tc__ribbon">پیشنهادی</span>
						<h3>راه الف — کاربران تستی در پنجرهٔ ناشناس</h3>
						<p>حساب ادمین تو دست‌نخورده می‌ماند. لینک ورود بیمار یا پرستار را (از بخش پایین) در یک <b>پنجرهٔ ناشناس (Ctrl+Shift+N)</b> باز کن.</p>
					</div>
					<div class="mns-tc__way">
						<h3>راه ب — عوض‌کردن نقش خود حسابت</h3>
						<p>سریع است ولی نقش روی خود حساب می‌ماند و منوی مدیریت را می‌بندد؛ بعد از تست «ادمین» را بزن.</p>
						<div class="mns-tc__row">
							<?php foreach ( $labels as $m => $lab ) : ?>
								<a class="mns-tc__mode<?php echo $m === $mode ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::switch_url( $m ) ); ?>"><?php echo esc_html( $lab ); ?></a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<?php if ( 'admin' !== $mode ) : ?>
					<p class="mns-tc__note">الان نقش تو «<?php echo esc_html( $labels[ $mode ] ?? $mode ); ?>» است. برای برگشت به مدیریت دکمهٔ «ادمین» را بزن.</p>
				<?php endif; ?>
			</section>

			<section class="mns-tc__card">
				<div class="mns-tc__head">
					<span class="mns-tc__emoji">🔑</span>
					<div><h2>کاربران تستی و لینک ورود (راه الف)</h2><p>به ترتیب بزن. هر لینک ورود یک‌بار مصرف است و ۲۴ ساعت اعتبار دارد؛ مصرف یا منقضی شد «لینک تازه» بزن.</p></div>
				</div>
				<div class="mns-tc__flowbtns">
					<?php
					echo self::post_form( 'enable', 'الف) فعال‌سازی (بدون محدودیت)', 'mns-tc__btn--primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<span class="arrow">←</span>';
					echo self::post_form( 'users', 'ب) ساخت کاربران تستی', '', ! $enabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<span class="arrow">←</span>';
					echo self::post_form( 'orders', 'ج) ۲ سفارش نمونه', '', ! $enabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<span class="arrow">←</span>';
					echo self::post_form( 'links', 'د) لینک ورود تازه', '', ! $enabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::post_form( 'purge', '🧹 پاک‌سازی همه', 'mns-tc__btn--danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<?php if ( ! empty( $links ) ) : ?>
					<div class="mns-tc__links">
						<?php foreach ( $links as $key => $url ) : ?>
							<div class="mns-tc__link">
								<b><?php echo esc_html( $names[ $key ] ?? $key ); ?></b>
								<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" />
								<button type="button" class="mns-tc__btn" data-mns-tc-copy="<?php echo esc_attr( $url ); ?>">کپی</button>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="mns-tc__note">لینک را کپی کن و در <b>پنجرهٔ ناشناس</b> باز کن. اگر در همین پنجره باز کنی از حساب ادمینت بیرون می‌افتی.</p>
				<?php endif; ?>
				<?php if ( ! empty( $orders ) ) : ?>
					<p class="mns-tc__note" style="background:#eaf4fb;border-color:#b9d9ee;color:#0a3d5c">سفارش‌های نمونه:
						<?php
						foreach ( $orders as $o ) {
							echo esc_html( '#' . (string) ( $o['code'] ?? '' ) . ' (' . ( 'nurse_f' === ( $o['nurse'] ?? '' ) ? 'پرستار خانم' : 'پرستار آقا' ) . ')  ' );
						}
						?>
					</p>
				<?php endif; ?>
			</section>

			<?php foreach ( self::sections() as $sec ) : ?>
				<section class="mns-tc__card" data-mns-tc-sec="<?php echo esc_attr( (string) $sec['id'] ); ?>">
					<div class="mns-tc__head">
						<span class="mns-tc__emoji"><?php echo esc_html( (string) $sec['emoji'] ); ?></span>
						<div><h2><?php echo esc_html( (string) $sec['title'] ); ?></h2><p><?php echo esc_html( (string) $sec['hint'] ); ?></p></div>
						<div class="mns-tc__sec-prog"><span data-mns-tc-sec-count></span><span class="mns-tc__sec-bar"><i data-mns-tc-sec-bar></i></span></div>
					</div>
					<ol class="mns-tc__steps">
						<?php foreach ( $sec['steps'] as $st ) : ?>
							<li class="mns-tc__step">
								<div class="mns-tc__top">
									<label class="mns-tc__chk"><input type="checkbox" data-mns-tc-check="<?php echo esc_attr( (string) $st[0] ); ?>" /><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span></label>
									<span class="mns-tc__who mns-tc__who--<?php echo esc_attr( (string) $st[1] ); ?>"><?php echo esc_html( self::who_label( (string) $st[1] ) ); ?></span>
									<span class="mns-tc__what"><?php echo esc_html( (string) $st[2] ); ?></span>
									<?php if ( '' !== (string) $st[4] ) : ?>
										<a class="mns-tc__go" href="<?php echo esc_url( (string) $st[4] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $st[5] ); ?> ↗</a>
									<?php endif; ?>
								</div>
								<p class="mns-tc__expect"><?php echo esc_html( (string) $st[3] ); ?></p>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endforeach; ?>

			<section class="mns-tc__card">
				<div class="mns-tc__head">
					<span class="mns-tc__emoji">🆘</span>
					<div><h2>گیج شدی؟</h2><p>۱) نقش خودت را «ادمین» کن. ۲) کاربران تستی را در پایان پاک کن. ۳) تیک‌ها را صفر کن و از اول شروع کن.</p></div>
				</div>
				<div class="mns-tc__row">
					<a class="mns-tc__mode" href="<?php echo esc_url( self::switch_url( 'admin' ) ); ?>">↩︎ برگرد به «ادمین»</a>
					<button type="button" class="mns-tc__btn" data-mns-tc-reset>🔄 تیک‌ها را صفر کن</button>
				</div>
			</section>
		</div>
		<div class="mns-tc__toast" data-mns-tc-toast><span></span></div>
		<script>
		(function () {
			var KEY = 'mnsTestCenterChecks';
			var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			var fa = function (n) { try { return Number(n).toLocaleString('fa-IR'); } catch (e) { return String(n); } };
			function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
			function save(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} }
			var state = load();
			var boxes = [].slice.call(document.querySelectorAll('[data-mns-tc-check]'));
			var secs = [].slice.call(document.querySelectorAll('[data-mns-tc-sec]'));
			var ring = document.querySelector('[data-mns-tc-ring]');
			var pctEl = document.querySelector('[data-mns-tc-pct]');
			var countEl = document.querySelector('[data-mns-tc-count]');
			var bar = document.querySelector('[data-mns-tc-bar]');
			var cheer = document.querySelector('[data-mns-tc-cheer]');
			var toast = document.querySelector('[data-mns-tc-toast]');
			var wasComplete = {};
			var firstPaint = true;
			function say(text) {
				if (!toast) return;
				var s = toast.querySelector('span');
				s.textContent = text;
				toast.classList.add('is-on');
				clearTimeout(say.t);
				say.t = setTimeout(function () { toast.classList.remove('is-on'); }, 2400);
			}
			function burst(card) {
				if (reduce || !card) return;
				var colors = ['#16a34a', '#0272b1', '#f59e0b', '#ef4444', '#8b5cf6'];
				for (var i = 0; i < 26; i++) {
					var s = document.createElement('i');
					s.className = 'mns-tc__spark';
					var ang = (Math.PI * 2 * i) / 26;
					var dist = 90 + Math.random() * 90;
					s.style.setProperty('--dx', Math.round(Math.cos(ang) * dist) + 'px');
					s.style.setProperty('--dy', Math.round(Math.sin(ang) * dist - 20) + 'px');
					s.style.setProperty('--rot', Math.round(Math.random() * 540 - 270) + 'deg');
					s.style.background = colors[i % colors.length];
					card.appendChild(s);
					(function (el) { setTimeout(function () { el.remove(); }, 1200); })(s);
				}
			}
			function message(p) {
				if (p === 0) return 'بزن بریم! 🚀';
				if (p < 30) return 'شروع خوبی بود 👌';
				if (p < 60) return 'داری پیش می‌ری 💪';
				if (p < 100) return 'تقریباً تمومه، ادامه بده 🔥';
				return 'همه‌چیز تست شد! دمت گرم 🎉';
			}
			function paint() {
				var done = 0;
				boxes.forEach(function (b) {
					var on = !!state[b.getAttribute('data-mns-tc-check')];
					b.checked = on;
					if (on) done++;
					var li = b.closest('.mns-tc__step');
					if (li) li.classList.toggle('is-done', on);
				});
				var total = boxes.length || 1;
				var p = Math.round((done * 100) / total);
				if (ring) ring.style.strokeDashoffset = String(339.3 * (1 - done / total));
				if (pctEl) pctEl.textContent = fa(p) + '٪';
				if (countEl) countEl.textContent = fa(done) + ' از ' + fa(boxes.length);
				if (bar) bar.style.width = p + '%';
				if (cheer) cheer.textContent = message(p);
				secs.forEach(function (sec) {
					var bs = [].slice.call(sec.querySelectorAll('[data-mns-tc-check]'));
					var d = bs.filter(function (b) { return b.checked; }).length;
					var c = sec.querySelector('[data-mns-tc-sec-count]');
					var sb = sec.querySelector('[data-mns-tc-sec-bar]');
					if (c) c.textContent = fa(d) + '/' + fa(bs.length);
					if (sb) sb.style.width = (bs.length ? (d * 100) / bs.length : 0) + '%';
					var complete = bs.length > 0 && d === bs.length;
					sec.classList.toggle('is-complete', complete);
					var id = sec.getAttribute('data-mns-tc-sec');
					if (!firstPaint && complete && !wasComplete[id]) {
						burst(sec);
						say(p === 100 ? 'همه‌چیز تست شد! دمت گرم 🎉' : 'یک بخش کامل شد ✅');
					}
					wasComplete[id] = complete;
				});
				firstPaint = false;
			}
			boxes.forEach(function (b) {
				b.addEventListener('change', function () {
					state[b.getAttribute('data-mns-tc-check')] = b.checked;
					save(state);
					paint();
				});
			});
			var reset = document.querySelector('[data-mns-tc-reset]');
			if (reset) reset.addEventListener('click', function () { state = {}; save(state); firstPaint = true; paint(); say('از اول شروع کنیم 🔄'); });
			[].forEach.call(document.querySelectorAll('[data-mns-tc-copy]'), function (btn) {
				btn.addEventListener('click', function () {
					var url = btn.getAttribute('data-mns-tc-copy');
					var done = function () { btn.textContent = 'کپی شد ✓'; setTimeout(function () { btn.textContent = 'کپی'; }, 1500); };
					if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done); }
					else { var i = btn.parentNode.querySelector('input'); if (i) { i.select(); document.execCommand('copy'); done(); } }
				});
			});
			paint();
		})();
		</script>
		<?php
	}
}
