<?php
/**
 * Finance & reports admin (M5/M8 v1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Finance {

	public const TABS = array(
		'unpaid'    => 'فاکتورهای پرداخت‌نشده',
		'share'     => 'سهم پرستار',
		'attention' => 'تلاش‌های نیازمند پیگیری',
		'reports'   => 'گزارش و خروجی',
	);

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 57 );
		add_action( 'admin_post_mns_finance_pay', array( __CLASS__, 'handle_pay' ) );
		add_action( 'admin_post_mns_finance_issue', array( __CLASS__, 'handle_issue' ) );
	}

	public static function register_menu(): void {
		remove_submenu_page( MNS_Admin_Menu::SLUG_DASHBOARD, MNS_Admin_Menu::SLUG_FINANCE );
		remove_all_actions( get_plugin_page_hookname( MNS_Admin_Menu::SLUG_FINANCE, MNS_Admin_Menu::SLUG_DASHBOARD ) );
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'مالی و گزارش', 'medyar-nursing-services' ),
			__( 'مالی و گزارش', 'medyar-nursing-services' ),
			'manage_options',
			MNS_Admin_Menu::SLUG_FINANCE,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		$tab = MNS_Admin_Ui::current_tab( self::TABS );
		MNS_Admin_Ui::header( 'مالی و گزارش' );
		MNS_Admin_Ui::tabs( MNS_Admin_Menu::SLUG_FINANCE, self::TABS, $tab );
		echo '<div class="mns-tab-body">';
		if ( 'share' === $tab ) {
			self::render_share();
		} elseif ( 'attention' === $tab ) {
			self::render_attention();
		} elseif ( 'reports' === $tab ) {
			self::render_reports();
		} else {
			self::render_unpaid();
		}
		echo '</div>';
		MNS_Admin_Ui::footer();
	}

	private static function render_unpaid(): void {
		$items = class_exists( 'MNS_Invoices', true ) ? MNS_Invoices::list_unpaid( 50 ) : array();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-inline-form">';
		wp_nonce_field( 'mns_finance_issue' );
		echo '<input type="hidden" name="action" value="mns_finance_issue" />';
		echo '<label class="mns-label" for="order_id">صدور فاکتور برای سفارش #</label>';
		echo '<input type="number" name="order_id" id="order_id" class="small-text" required />';
		echo ' <label class="mns-label" for="amount">مبلغ نهایی (تومان)</label> ';
		echo '<input type="number" name="amount" id="amount" class="regular-text" min="1" required />';
		submit_button( 'صدور', 'secondary', 'submit', false );
		echo '</form>';
		echo '<table class="widefat striped mns-compact"><thead><tr><th>شماره</th><th>سفارش</th><th>مبلغ</th><th>پرداخت</th></tr></thead><tbody>';
		foreach ( $items as $inv ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $inv['number'] ) . '</code></td>';
			echo '<td>#' . (int) $inv['order_id'] . '</td>';
			echo '<td>' . esc_html( MNS_Admin_Ui::money( $inv['amount'] ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-inline-form">';
			wp_nonce_field( 'mns_finance_pay' );
			echo '<input type="hidden" name="action" value="mns_finance_pay" />';
			echo '<input type="hidden" name="invoice_id" value="' . (int) $inv['id'] . '" />';
			echo '<select name="method"><option value="cash">نقدی</option><option value="card">کارت‌به‌کارت</option><option value="zarinpal">زرین‌پال</option></select>';
			echo '<input name="tracking" placeholder="پیگیری" class="regular-text" />';
			submit_button( 'ثبت پرداخت', 'primary', 'submit', false );
			echo '</form></td></tr>';
		}
		if ( ! $items ) {
			echo '<tr><td colspan="4"><p class="mns-empty">فاکتور پرداخت‌نشده‌ای نیست.</p></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_share(): void {
		$items = class_exists( 'MNS_Invoices', true ) ? MNS_Invoices::nurse_shares( 50 ) : array();
		echo '<p class="mns-lead">سهم پرستاران از فاکتورهای پرداخت‌شده (واریز واقعی از کیف پول تومان مدیار).</p>';
		echo '<table class="widefat striped mns-compact"><thead><tr><th>فاکتور</th><th>پرستار</th><th>سهم</th><th>روش / پیگیری</th><th>پرداخت</th></tr></thead><tbody>';
		foreach ( $items as $inv ) {
			$pay = class_exists( 'MNS_Payments', true ) ? MNS_Payments::latest_payment_for_invoice( (int) $inv['id'] ) : null;
			$method = is_array( $pay ) ? (string) ( $pay['method'] ?? '' ) : '';
			$track  = is_array( $pay ) ? (string) ( $pay['tracking'] ?? '' ) : '';
			$label  = 'zarinpal' === $method ? 'زرین‌پال' : ( $method ? $method : '—' );
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $inv['number'] ) . '</code></td>';
			echo '<td>' . esc_html( MNS_Admin_Ui::user_name( (int) ( $inv['nurse_user_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( MNS_Admin_Ui::money( $inv['nurse_share'] ) ) . '</td>';
			echo '<td>' . esc_html( $label );
			if ( $track ) {
				echo ' / <code dir="ltr">' . esc_html( $track ) . '</code>';
			}
			echo '</td>';
			echo '<td>' . esc_html( MNS_Admin_Ui::date( (string) ( $inv['paid_at'] ?? '' ), true ) ) . '</td>';
			echo '</tr>';
		}
		if ( ! $items ) {
			echo '<tr><td colspan="5"><p class="mns-empty">موردی نیست.</p></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_attention(): void {
		$items = class_exists( 'MNS_Payments', true ) ? MNS_Payments::list_attention( 50 ) : array();
		echo '<p class="mns-lead">تلاش‌های <code>paid_duplicate</code>، <code>amount_mismatch</code> و <code>paid_error</code> برای پیگیری دستی (فقط‌خواندنی؛ بازپرداخت خودکار وجود ندارد).</p>';
		echo '<table class="widefat striped mns-compact"><thead><tr><th>شناسه</th><th>فاکتور</th><th>وضعیت</th><th>مبلغ</th><th>Authority</th><th>Ref</th><th>زمان</th></tr></thead><tbody>';
		foreach ( $items as $row ) {
			echo '<tr>';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td>#' . (int) $row['invoice_id'] . '</td>';
			echo '<td><code>' . esc_html( (string) $row['status'] ) . '</code></td>';
			echo '<td>' . esc_html( MNS_Admin_Ui::money( $row['amount'] ) ) . '</td>';
			echo '<td><code dir="ltr">' . esc_html( (string) $row['authority'] ) . '</code></td>';
			echo '<td><code dir="ltr">' . esc_html( (string) ( $row['ref_id'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( MNS_Admin_Ui::date( (string) ( $row['updated_at'] ?? '' ), true ) ) . '</td>';
			echo '</tr>';
		}
		if ( ! $items ) {
			echo '<tr><td colspan="7"><p class="mns-empty">موردی نیست.</p></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_reports(): void {
		$url = add_query_arg(
			array(
				'page'       => MNS_Admin_Menu::SLUG_FINANCE,
				'tab'        => 'reports',
				'mns_export' => 'orders',
			),
			admin_url( 'admin.php' )
		);
		echo '<p class="mns-lead">خروجی CSV سفارش‌ها و فاکتورها برای گزارش‌گیری.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '"><span class="dashicons dashicons-download"></span> خروجی سفارش‌ها (CSV)</a></p>';
		$url2 = add_query_arg(
			array(
				'page'       => MNS_Admin_Menu::SLUG_FINANCE,
				'tab'        => 'reports',
				'mns_export' => 'invoices',
			),
			admin_url( 'admin.php' )
		);
		echo '<p><a class="button" href="' . esc_url( $url2 ) . '">خروجی فاکتورها (CSV)</a></p>';
	}

	public static function handle_pay(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		check_admin_referer( 'mns_finance_pay' );
		$id = isset( $_POST['invoice_id'] ) ? (int) $_POST['invoice_id'] : 0;
		$method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( (string) $_POST['method'] ) ) : 'cash';
		$tracking = isset( $_POST['tracking'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tracking'] ) ) : '';
		try {
			MNS_Invoices::record_payment( $id, $method, $tracking, get_current_user_id() );
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_FINANCE, 'payment_recorded', array( 'tab' => 'unpaid' ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	public static function handle_issue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		check_admin_referer( 'mns_finance_issue' );
		$oid    = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$amount = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		try {
			MNS_Invoices::issue_for_order( $oid, get_current_user_id(), $amount );
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_FINANCE, 'invoice_issued', array( 'tab' => 'unpaid' ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}
}
