<?php
/**
 * Summary meta box on the main WordPress dashboard (wp-admin/index.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Dashboard_Widget {

	private const ID = 'arp_summary_widget';

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	public static function register(): void {
		if ( ! Arp_Admin::can_manage() ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, 'همکاران مدیار', array( __CLASS__, 'render' ), null, null, 'normal', 'high' );
	}

	public static function styles( string $hook ): void {
		if ( 'index.php' !== $hook || ! Arp_Admin::can_manage() ) {
			return;
		}
		$w = '#' . self::ID;
		wp_add_inline_style(
			'dashboard',
			$w . ' .inside{margin:0;padding:0}'
			. $w . ' .arp-w-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border-bottom:1px solid #f0f0f1}'
			. $w . ' .arp-w-stat{display:block;padding:12px 14px;text-decoration:none;color:#1d2327;border-inline-start:3px solid var(--c,#2271b1);border-bottom:1px solid #f0f0f1}'
			. $w . ' .arp-w-stat:hover{background:#f6f7f7}'
			. $w . ' .arp-w-stat strong{display:block;font-size:20px;line-height:1.4;color:var(--c,#2271b1)}'
			. $w . ' .arp-w-stat span{color:#646970;font-size:12px}'
			. $w . ' .arp-w-sec{padding:10px 14px}'
			. $w . ' .arp-w-sec h3{margin:0 0 8px;font-size:13px;font-weight:600}'
			. $w . ' .arp-w-list{margin:0}'
			. $w . ' .arp-w-list li{display:flex;justify-content:space-between;gap:8px;margin:0;padding:6px 0;border-bottom:1px solid #f0f0f1}'
			. $w . ' .arp-w-list li:last-child{border-bottom:0}'
			. $w . ' .arp-w-list .arp-w-age{color:#646970;font-size:12px;white-space:nowrap}'
			. $w . ' .arp-w-links{display:flex;flex-wrap:wrap;gap:6px;padding:10px 14px 14px;border-top:1px solid #f0f0f1}'
			. $w . ' .arp-w-links .button .dashicons{font-size:16px;width:16px;height:16px;vertical-align:text-bottom;margin-inline-end:2px}'
		);
	}

	public static function render(): void {
		$default     = Arp_Statuses_Repo::get_default();
		$pending     = Arp_Admin_Menu::instance()->pending_count();
		$paid_month  = Arp_Commissions_Repo::sum_paid_since( Arp_Format::jalali_month_start() );
		$waiting     = $default ? Arp_Referrals_Repo::query( array( 'status_id' => (int) $default['id'], 'order' => 'asc', 'limit' => 5 ) ) : array();
		$fa_n        = static function ( $n ): string {
			return Arp_Format::fa( number_format_i18n( (float) $n ) );
		};
		$waiting_url = $default ? Arp_Admin_Ui::url( 'arp-referrals', array( 'status_id' => (int) $default['id'] ) ) : Arp_Admin_Ui::url( 'arp-referrals' );
		$wallet_url  = Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'wallet' ) );

		cache_users( array_column( $waiting, 'affiliate_user_id' ) );

		$stats = array(
			array( $fa_n( $pending ), $default ? (string) $default['name'] : 'در انتظار تماس', $waiting_url, $pending ? '#d63638' : '#00a32a' ),
			array( $fa_n( Arp_Referrals_Repo::count( array( 'date_from' => current_time( 'Y-m-d' ) ) ) ), 'معرفی امروز', Arp_Admin_Ui::url( 'arp-referrals' ), '#2271b1' ),
			array( Arp_Admin_Ui::money( $paid_month ), 'پورسانت پرداخت‌شده این ماه', $wallet_url, '#0f766e' ),
			array( $fa_n( Arp_Admin_Menu::affiliate_count() ), 'همکار ثبت‌شده', Arp_Admin_Ui::url( 'arp-affiliates' ), '#6b3fa0' ),
		);

		$links = array(
			array( 'dashicons-list-view', 'معرفی‌ها', Arp_Admin_Ui::url( 'arp-referrals' ) ),
			array( 'dashicons-money-alt', 'کیف پول', $wallet_url ),
			array( 'dashicons-groups', 'همکاران', Arp_Admin_Ui::url( 'arp-affiliates' ) ),
			array( 'dashicons-tag', 'خدمات و پورسانت', Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => 'services' ) ) ),
			array( 'dashicons-art', 'وضعیت‌ها', Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => 'statuses' ) ) ),
			array( 'dashicons-email-alt', 'پیامک', Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => 'general' ) ) ),
			array( 'dashicons-dashboard', 'پیشخوان همکاران', Arp_Admin_Ui::url( 'arp-dashboard' ) ),
		);
		?>
		<div class="arp-w-stats">
			<?php foreach ( $stats as $s ) : ?>
				<a class="arp-w-stat" href="<?php echo esc_url( $s[2] ); ?>" style="--c:<?php echo esc_attr( $s[3] ); ?>">
					<strong><?php echo esc_html( $s[0] ); ?></strong>
					<span><?php echo esc_html( $s[1] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="arp-w-sec">
			<h3>قدیمی‌ترین معرفی‌های منتظر تماس</h3>
			<?php if ( ! $waiting ) : ?>
				<p class="arp-w-age">موردی در صف نیست.</p>
			<?php else : ?>
				<ul class="arp-w-list">
					<?php foreach ( $waiting as $row ) : ?>
						<li>
							<span>
								<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => (int) $row['id'] ) ) ); ?>"><?php echo esc_html( $row['patient_name'] ); ?></a>
								<span class="arp-w-age">— <?php echo esc_html( (string) ( $row['service_name'] ?? '' ) ); ?> — <?php echo esc_html( Arp_Admin_Ui::user_name( (int) $row['affiliate_user_id'] ) ); ?></span>
							</span>
							<span class="arp-w-age"><?php echo esc_html( Arp_Admin_Ui::ago( (string) $row['created_at'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="arp-w-links">
			<?php foreach ( $links as $l ) : ?>
				<a class="button" href="<?php echo esc_url( $l[2] ); ?>"><span class="dashicons <?php echo esc_attr( $l[0] ); ?>" aria-hidden="true"></span><?php echo esc_html( $l[1] ); ?></a>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
