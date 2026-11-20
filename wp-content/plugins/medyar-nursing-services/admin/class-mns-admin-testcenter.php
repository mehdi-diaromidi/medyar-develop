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
		MNS_E2E::enable_two_hours();
		self::back( 'ابزار تست برای ۲ ساعت فعال شد.' );
	}

	public static function handle_users(): void {
		self::guard();
		try {
			$res = MNS_E2E::seed();
			$d   = self::data();
			$d['links'] = self::links_from( isset( $res['users'] ) && is_array( $res['users'] ) ? $res['users'] : array() );
			self::save_data( $d );
		} catch ( \Throwable $e ) {
			self::back( 'ساخت کاربران ناموفق بود: ' . $e->getMessage(), false );
		}
		self::back( 'کاربران تستی ساخته شدند و لینک‌های ورود پایین صفحه آماده است.' );
	}

	public static function handle_orders(): void {
		self::guard();
		try {
			$res = MNS_E2E::seed_assigned_orders();
			$d   = self::data();
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
		self::back( 'لینک‌های ورود تازه ساخته شد (هر کدام یک‌بار مصرف، ۳۰ دقیقه).' );
	}

	public static function handle_purge(): void {
		self::guard();
		MNS_E2E::purge_all();
		delete_transient( 'mns_tc_data_' . get_current_user_id() );
		self::back( 'همهٔ کاربران و سفارش‌های تستی پاک شدند.' );
	}

	/* ------------------------------------------------------------------ view helpers */

	private static function post_form( string $act, string $label, string $class = 'button', bool $disabled = false ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-tc__inline">';
		$html .= wp_nonce_field( 'mns_tc', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="mns_tc_' . esc_attr( $act ) . '" />';
		$html .= '<button type="submit" class="' . esc_attr( $class ) . '"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button>';
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

	private static function a( string $url, string $label ): string {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}

	/**
	 * One checkable test step.
	 */
	private static function step( string $id, string $who, string $what, string $expect, string $link = '' ): string {
		$html  = '<li class="mns-tc__step"><label><input type="checkbox" data-mns-tc-check="' . esc_attr( $id ) . '" />';
		$html .= '<span class="mns-tc__who mns-tc__who--' . esc_attr( $who ) . '">' . esc_html( self::who_label( $who ) ) . '</span>';
		$html .= '<span class="mns-tc__what">' . esc_html( $what ) . '</span></label>';
		if ( '' !== $link ) {
			$html .= '<span class="mns-tc__go">' . $link . '</span>';
		}
		$html .= '<p class="mns-tc__expect">' . esc_html( $expect ) . '</p></li>';
		return $html;
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
		$user_state = '<span class="mns-tc__tag mns-tc__tag--off">ساخته نشده</span>';
		if ( get_user_by( 'login', 'mns_e2e_patient' ) ) {
			$user_state = '<span class="mns-tc__tag mns-tc__tag--on">ساخته شده</span>';
		}
		?>
		<style>
			.mns-tc { max-width: 980px; }
			.mns-tc h1 { margin-bottom: 4px; }
			.mns-tc__lead { margin: 0 0 16px; color: #50575e; font-size: 14px; }
			.mns-tc__flash { margin: 12px 0; padding: 10px 14px; border-radius: 8px; font-weight: 600; }
			.mns-tc__flash--ok { background: #e7f7ee; border: 1px solid #9ad6b3; color: #0b5d2e; }
			.mns-tc__flash--err { background: #fdecef; border: 1px solid #f1a9b8; color: #8f1034; }
			.mns-tc__card { background: #fff; border: 1px solid #dcdcde; border-radius: 12px; padding: 16px 18px; margin: 0 0 16px; }
			.mns-tc__card h2 { margin: 0 0 4px; font-size: 16px; display: flex; align-items: center; gap: 8px; }
			.mns-tc__card h2 span.n { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #0272b1; color: #fff; font-size: 13px; }
			.mns-tc__hint { margin: 0 0 12px; color: #646970; font-size: 13px; }
			.mns-tc__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; }
			.mns-tc__stat { border: 1px solid #e3e3e6; border-radius: 10px; padding: 10px 12px; background: #fafafa; }
			.mns-tc__stat small { display: block; color: #646970; }
			.mns-tc__stat strong { font-size: 15px; }
			.mns-tc__tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; }
			.mns-tc__tag--on { background: #dff4e7; color: #0b5d2e; }
			.mns-tc__tag--off { background: #eceff1; color: #50575e; }
			.mns-tc__row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
			.mns-tc__inline { display: inline-block; margin: 0; }
			.mns-tc__mode { display: inline-flex; align-items: center; justify-content: center; min-height: 36px; padding: 0 16px; border: 1px solid #c3c4c7; border-radius: 8px; background: #fff; text-decoration: none; font-weight: 600; }
			.mns-tc__mode.is-current { background: #0272b1; border-color: #0272b1; color: #fff; }
			.mns-tc__note { margin: 10px 0 0; padding: 8px 12px; border-radius: 8px; background: #fff8e5; border: 1px solid #f0d58a; color: #6b4a00; font-size: 13px; }
			.mns-tc__links { display: grid; gap: 8px; margin-top: 12px; }
			.mns-tc__link { display: flex; align-items: center; gap: 8px; }
			.mns-tc__link b { min-width: 110px; }
			.mns-tc__link input { flex: 1; min-width: 0; direction: ltr; font-size: 12px; }
			.mns-tc__steps { list-style: none; margin: 0; padding: 0; }
			.mns-tc__step { padding: 10px 0; border-top: 1px solid #eee; }
			.mns-tc__step:first-child { border-top: 0; }
			.mns-tc__step label { display: flex; align-items: center; gap: 10px; cursor: pointer; }
			.mns-tc__who { display: inline-block; min-width: 54px; text-align: center; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; }
			.mns-tc__who--patient { background: #e1f0fa; color: #014a74; }
			.mns-tc__who--nurse { background: #e6f6ec; color: #0b5d2e; }
			.mns-tc__who--admin { background: #efe9fb; color: #4b2a8a; }
			.mns-tc__what { font-weight: 600; }
			.mns-tc__go { margin-inline-start: 8px; font-size: 13px; }
			.mns-tc__expect { margin: 4px 0 0; padding-inline-start: 74px; color: #50575e; font-size: 13px; }
			.mns-tc__step.is-done .mns-tc__what { text-decoration: line-through; color: #8c8f94; }
			.mns-tc__progress { margin-inline-start: auto; font-size: 12px; font-weight: 600; color: #646970; }
			.mns-tc__reset { margin-top: 8px; }
		</style>
		<div class="wrap mns-tc">
			<h1>🧪 مرکز تست</h1>
			<p class="mns-tc__lead">همهٔ تست‌های دستی از همین یک صفحه انجام می‌شود. از بالا به پایین برو؛ هر مرحله را که تست کردی تیک بزن (تیک‌ها در همین مرورگر ذخیره می‌شوند). این صفحه فقط روی سایت develop هست و موقتی است.</p>

			<?php if ( is_array( $flash ) ) : ?>
				<div class="mns-tc__flash <?php echo ! empty( $flash['ok'] ) ? 'mns-tc__flash--ok' : 'mns-tc__flash--err'; ?>"><?php echo esc_html( (string) $flash['msg'] ); ?></div>
			<?php endif; ?>

			<div class="mns-tc__card">
				<h2>وضعیت الان</h2>
				<div class="mns-tc__grid">
					<div class="mns-tc__stat"><small>نقش حساب خودت</small><strong><?php echo esc_html( $labels[ $mode ] ?? $mode ); ?></strong></div>
					<div class="mns-tc__stat"><small>ابزار تست (کاربران تستی)</small><strong><?php echo $enabled ? '<span class="mns-tc__tag mns-tc__tag--on">فعال</span> تا ' . esc_html( wp_date( 'H:i', $until ) ) : '<span class="mns-tc__tag mns-tc__tag--off">خاموش</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></div>
					<div class="mns-tc__stat"><small>کاربران تستی</small><strong><?php echo $user_state; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></div>
					<div class="mns-tc__stat"><small>نسخهٔ افزونه</small><strong><?php echo esc_html( defined( 'MNS_VERSION' ) ? MNS_VERSION : '?' ); ?></strong></div>
				</div>
			</div>

			<div class="mns-tc__card">
				<h2><span class="n">۱</span> دو راه برای تست پنل بیمار و پرستار — یکی را انتخاب کن</h2>
				<p class="mns-tc__hint">قاعدهٔ طلایی: برای هر بخش فقط <b>یک</b> راه را به کار ببر تا قاطی نشود.</p>
				<p><b>راه الف (پیشنهادی، بدون قاطی‌شدن): کاربران تستی در پنجرهٔ ناشناس.</b> حساب ادمین تو دست‌نخورده می‌ماند. پایین در بخش «۲» لینک ورود بیمار/پرستار را در یک <b>پنجرهٔ ناشناس (Ctrl+Shift+N)</b> باز کن.</p>
				<p><b>راه ب (سریع): عوض‌کردن نقش خود حسابت.</b> با دکمه‌های زیر نقش را عوض کن. حواست باشد این نقش روی خود حساب می‌ماند و منوی مدیریت را می‌بندد تا دوباره «ادمین» را بزنی.</p>
				<div class="mns-tc__row">
					<?php foreach ( $labels as $m => $lab ) : ?>
						<a class="mns-tc__mode<?php echo $m === $mode ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::switch_url( $m ) ); ?>"><?php echo esc_html( $lab ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php if ( 'admin' !== $mode ) : ?>
					<p class="mns-tc__note">الان نقش تو «<?php echo esc_html( $labels[ $mode ] ?? $mode ); ?>» است. برای برگشت به مدیریت دکمهٔ «ادمین» را بزن (یا همین صفحه را از نوار بالا باز کن).</p>
				<?php endif; ?>
			</div>

			<div class="mns-tc__card">
				<h2><span class="n">۲</span> کاربران تستی و لینک ورود (راه الف)</h2>
				<p class="mns-tc__hint">به ترتیب بزن. هر لینک ورود فقط یک‌بار و ۳۰ دقیقه کار می‌کند؛ اگر منقضی شد «لینک تازه» بزن.</p>
				<div class="mns-tc__row">
					<?php
					echo self::post_form( 'enable', 'الف) فعال‌سازی ابزار (۲ ساعت)', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::post_form( 'users', 'ب) ساخت کاربران تستی', 'button', ! $enabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::post_form( 'orders', 'ج) ساخت ۲ سفارش نمونه', 'button', ! $enabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::post_form( 'links', 'د) لینک ورود تازه', 'button', ! $enabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::post_form( 'purge', 'پاک‌سازی همه (در پایان تست)', 'button button-link-delete' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<?php if ( ! empty( $links ) ) : ?>
					<div class="mns-tc__links">
						<?php
						$names = array(
							'patient' => 'بیمار تستی',
							'nurse_f' => 'پرستار خانم',
							'nurse_m' => 'پرستار آقا',
						);
						foreach ( $links as $key => $url ) :
							?>
							<div class="mns-tc__link">
								<b><?php echo esc_html( $names[ $key ] ?? $key ); ?></b>
								<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" />
								<button type="button" class="button" data-mns-tc-copy="<?php echo esc_attr( $url ); ?>">کپی</button>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="mns-tc__note">لینک را کپی کن و در <b>پنجرهٔ ناشناس</b> باز کن. اگر در همین پنجره باز کنی از حساب ادمینت بیرون می‌افتی.</p>
				<?php endif; ?>
				<?php if ( ! empty( $orders ) ) : ?>
					<p class="mns-tc__hint" style="margin-top:12px">سفارش‌های نمونه:
						<?php
						foreach ( $orders as $o ) {
							echo esc_html( '#' . (string) ( $o['code'] ?? '' ) . ' (' . ( 'nurse_f' === ( $o['nurse'] ?? '' ) ? 'پرستار خانم' : 'پرستار آقا' ) . ') ' );
						}
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="mns-tc__card" data-mns-tc-section>
				<h2><span class="n">۳</span> جریان کامل یک سفارش (از ثبت تا پرداخت)</h2>
				<p class="mns-tc__hint">این همان مسیر واقعی است. هر مرحله می‌گوید چه کسی انجامش می‌دهد و چه چیزی باید ببینی.</p>
				<ol class="mns-tc__steps">
					<?php
					echo self::step( 'f1', 'patient', 'داشبورد را باز کن و یک دسته انتخاب کن', 'کارت «ثبت سریع سفارش» ۱۰ دسته با تعداد خدمت دارد. با زدن یک دسته وارد ثبت سفارش می‌شوی و بالا نوشته «دسته: …».', self::a( self::p( 'patient', 'dashboard' ), 'باز کردن داشبورد بیمار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f2', 'patient', 'ثبت سفارش را تا آخر پر کن (مرحلهٔ ۱ تا ۳)', 'در مرحلهٔ ۲ چند زیرخدمت همان دسته را تیک بزن و بازهٔ قیمت جمع را ببین. اگر برای منطقه/جنسیت پرستار نباشد، کادر قرمز با دکمهٔ تماس می‌آید.', self::a( self::p( 'patient', 'new-order', array( 'mns_category' => 'inj_serum' ) ), 'ثبت سفارش (تزریقات و سرم)' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f3', 'patient', 'سفارش را ثبت کن', 'کد پیگیری ۵ رقمی و لینک پیگیری می‌آید. دوبار کلیک نباید دو سفارش بسازد.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f4', 'admin', 'سفارش را در رصد مرکزی پیدا کن', 'سفارش با وضعیت «ثبت‌شده» یا «تخصیص‌یافته» (اگر تخصیص خودکار روشن باشد) دیده می‌شود.', self::a( admin_url( 'admin.php?page=mns-monitor' ), 'رصد مرکزی' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f5', 'admin', 'اگر تخصیص نخورده بود، پرستار را دستی تعیین کن', 'بعد از تخصیص، وضعیت «تخصیص‌یافته» می‌شود و پرستار سفارش را می‌بیند.', self::a( admin_url( 'admin.php?page=mns-assign' ), 'تخصیص' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f6', 'nurse', 'پرستار: سفارش را در فهرست و جزئیات ببین', 'نشانی و تلفن فقط برای سفارش در حال انجام/تخصیص‌یافته دیده می‌شود؛ کد ملی و مبلغ هرگز.', self::a( self::p( 'nurse', 'orders' ), 'سفارش‌های پرستار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f7', 'nurse', 'پرستار: چک‌لیست خدمت‌ها', 'چک‌لیست همهٔ زیرخدمت‌های سفارش، گروه‌بندی‌شده، دیده می‌شود (فرم کامل تعاملی هنوز در حال ساخت است).', self::a( self::p( 'nurse', 'checklist' ), 'چک‌لیست پرستار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f8', 'admin', 'صدور فاکتور برای سفارش تمام‌شده', 'مبلغ نهایی را خودت وارد می‌کنی؛ بدون مبلغ رد می‌شود. بعد از صدور وضعیت سفارش «فاکتورشده» می‌شود.', self::a( admin_url( 'admin.php?page=mns-finance&tab=unpaid' ), 'مالی و فاکتور' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f9', 'patient', 'بیمار: فاکتور و دکمهٔ «پرداخت آنلاین»', 'فاکتور فقط برای صاحبش دیده می‌شود. پرداخت واقعی زرین‌پال روی develop ممکن است به‌خاطر دامنهٔ مرچنت رد شود (خطای ۱۴- یا ۱۸-).', self::a( self::p( 'patient', 'invoices' ), 'فاکتورهای بیمار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'f10', 'patient', 'لغو یک سفارش', 'در جزئیات سفارش «لغو» را بزن و دلیل بنویس؛ وضعیت «لغو شده» می‌شود و دکمه می‌رود.', self::a( self::p( 'patient', 'orders' ), 'سفارش‌های بیمار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</ol>
			</div>

			<div class="mns-tc__card" data-mns-tc-section>
				<h2><span class="n">۴</span> صفحهٔ ثبت سفارش — جزئیات ظاهری</h2>
				<ol class="mns-tc__steps">
					<?php
					echo self::step( 'u1', 'patient', 'تاریخ‌پیکر', 'باکس تقویم هم‌عرض و هم‌تراز ورودی باز شود و ۷ ستون روز کل عرض را پر کنند (دسکتاپ و موبایل).', self::a( self::p( 'patient', 'new-order', array( 'mns_category' => 'wound' ) ), 'باز کردن' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'u2', 'patient', 'کادر قرمز «پرستار موجود نیست»', 'منطقه و جنسیتی را انتخاب کن که پرستار ندارد: کادر نرم با نوار قرمز و دکمهٔ تماس (کنار پیام در دسکتاپ، تمام‌عرض در موبایل).' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'u3', 'patient', 'دستهٔ ساعتی/شیفتی', 'پیام «همکاران ما با شما هماهنگ می‌کنند» دیده شود و تخصیص خودکار اجرا نشود.', self::a( self::p( 'patient', 'new-order', array( 'mns_category' => 'hourly' ) ), 'دستهٔ ساعتی' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'u4', 'patient', 'حالت خالی بیمار تازه‌وارد', 'در داشبورد کارت آبی «اولین سفارش پرستاری» با سه مرحله و دکمهٔ سفید دیده شود (فقط وقتی بیمار سفارشی ندارد).' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</ol>
			</div>

			<div class="mns-tc__card" data-mns-tc-section>
				<h2><span class="n">۵</span> پنل پرستار</h2>
				<ol class="mns-tc__steps">
					<?php
					echo self::step( 'n1', 'nurse', 'داشبورد', 'نام خودت در هدر (نه «علی رضایی») و برنامهٔ پیش‌رو با ساعت درست (مثلاً ۰۸:۰۰ تا ۱۲:۰۰).', self::a( self::p( 'nurse', 'dashboard' ), 'داشبورد پرستار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'n2', 'nurse', 'برنامه و دسترسی', 'روز دارای سفارش را خاموش کن ⇒ پیام «این بازه سفارش تخصیص‌یافته دارد». حذف منطقهٔ سفارش ⇒ هشدار تعداد.', self::a( self::p( 'nurse', 'availability' ), 'برنامه و دسترسی' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'n3', 'nurse', 'تب مناطق', 'دکمه‌های «انتخاب همه / پاک‌کردن همه» ساده و بدون انیمیشن هاور باشند.', self::a( self::p( 'nurse', 'availability', array( 'mns_tab' => 'regions' ) ), 'تب مناطق' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'n4', 'nurse', 'پروفایل', 'شمارهٔ موبایل و جنسیت قابل ویرایش نباشد؛ کد ملی نامعتبر خطای فیلد بدهد.', self::a( self::p( 'nurse', 'profile' ), 'پروفایل پرستار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</ol>
			</div>

			<div class="mns-tc__card" data-mns-tc-section>
				<h2><span class="n">۶</span> بخش ادمین</h2>
				<ol class="mns-tc__steps">
					<?php
					echo self::step( 'a1', 'admin', 'خدمات (۱۰ دسته، ۴۴ زیرخدمت)', 'گروه‌بندی‌شده بر اساس دسته؛ قیمت از/تا و فلگ‌ها و چک‌لیست قابل ویرایش‌اند (چک‌لیست‌ها «پیش‌نویس»).', self::a( admin_url( 'admin.php?page=mns-services' ), 'خدمات' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'a2', 'admin', 'عضویت پرستاران', 'فهرست درخواست‌ها؛ تأیید/رد/تعلیق فقط از وضعیت درست کار کند.', self::a( admin_url( 'admin.php?page=mns-nurses' ), 'پرستاران' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'a3', 'admin', 'تنظیمات', 'تخصیص خودکار/دستی، ظرفیت بازه و سایر تنظیمات.', self::a( admin_url( 'admin.php?page=mns-settings' ), 'تنظیمات' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::step( 'a4', 'admin', 'تست‌های خودکار', 'روی هر ۴ گروه (هسته، سفارش، مالی، کاتالوگ) بزن: همه باید «PASS» و «FAIL=0» باشند.', self::a( admin_url( 'tools.php?page=mns-selftest' ), 'تست‌های خودکار' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</ol>
			</div>

			<div class="mns-tc__card">
				<h2>گیج شدی؟</h2>
				<p class="mns-tc__hint">۱) نقش خودت را به «ادمین» برگردان. ۲) اگر کاربران تستی را ساخته‌ای، در پایان «پاک‌سازی همه» را بزن. ۳) تیک‌های این صفحه را صفر کن و از اول شروع کن.</p>
				<div class="mns-tc__row">
					<a class="mns-tc__mode" href="<?php echo esc_url( self::switch_url( 'admin' ) ); ?>">برگرد به «ادمین»</a>
					<button type="button" class="button" data-mns-tc-reset>تیک‌ها را صفر کن</button>
					<span class="mns-tc__progress" data-mns-tc-progress></span>
				</div>
			</div>
		</div>
		<script>
		(function () {
			var KEY = 'mnsTestCenterChecks';
			function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
			function save(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} }
			var state = load();
			var boxes = [].slice.call(document.querySelectorAll('[data-mns-tc-check]'));
			var prog = document.querySelector('[data-mns-tc-progress]');
			function paint() {
				var done = 0;
				boxes.forEach(function (b) {
					var on = !!state[b.getAttribute('data-mns-tc-check')];
					b.checked = on;
					if (on) done++;
					var li = b.closest('.mns-tc__step');
					if (li) li.classList.toggle('is-done', on);
				});
				if (prog) prog.textContent = done + ' از ' + boxes.length + ' مرحله تست شد';
			}
			boxes.forEach(function (b) {
				b.addEventListener('change', function () {
					state[b.getAttribute('data-mns-tc-check')] = b.checked;
					save(state);
					paint();
				});
			});
			var reset = document.querySelector('[data-mns-tc-reset]');
			if (reset) reset.addEventListener('click', function () { state = {}; save(state); paint(); });
			[].forEach.call(document.querySelectorAll('[data-mns-tc-copy]'), function (btn) {
				btn.addEventListener('click', function () {
					var url = btn.getAttribute('data-mns-tc-copy');
					var done = function () { btn.textContent = 'کپی شد'; setTimeout(function () { btn.textContent = 'کپی'; }, 1500); };
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
