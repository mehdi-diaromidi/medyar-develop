<?php
/**
 * Central order monitor (P-22 / P-23 read slice).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Monitor {

	/** @var MNS_Orders_Table|null */
	private static $table = null;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 50 );
		add_action( 'admin_post_mns_monitor_assign', array( __CLASS__, 'handle_assign' ) );
		add_action( 'admin_post_mns_monitor_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_mns_monitor_note', array( __CLASS__, 'handle_note' ) );
		add_action( 'admin_post_mns_monitor_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_mns_monitor_end', array( __CLASS__, 'handle_end' ) );
		add_action( 'admin_post_mns_monitor_unlock', array( __CLASS__, 'handle_unlock' ) );
	}

	public static function register_menu(): void {
		remove_submenu_page( MNS_Admin_Menu::SLUG_DASHBOARD, MNS_Admin_Menu::SLUG_MONITOR );
		remove_all_actions( get_plugin_page_hookname( MNS_Admin_Menu::SLUG_MONITOR, MNS_Admin_Menu::SLUG_DASHBOARD ) );
		$hook = add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'رصد مرکزی', 'medyar-nursing-services' ),
			__( 'رصد مرکزی', 'medyar-nursing-services' ),
			'manage_options',
			MNS_Admin_Menu::SLUG_MONITOR,
			array( __CLASS__, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
		}
	}

	public static function load(): void {
		if ( 'view' === self::action() ) {
			return;
		}
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		self::$table = new MNS_Orders_Table();
	}

	private static function action(): string {
		return isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'view' === self::action() && $id ) {
			self::render_detail( $id );
			return;
		}
		if ( ! self::$table ) {
			self::$table = new MNS_Orders_Table();
		}
		self::$table->prepare_items();
		MNS_Admin_Ui::header(
			'رصد مرکزی',
			array(
				array( 'label' => 'تخصیص', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_ASSIGN ) ),
				array( 'label' => 'سفارش تلفنی', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MANUAL ) ),
			)
		);
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( MNS_Admin_Menu::SLUG_MONITOR ) . '" />';
		echo '<div class="alignleft actions mns-filters">';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$statuses = array(
			''              => 'همه وضعیت‌ها',
			'submitted'     => 'ثبت‌شده',
			'assigned'      => 'تخصیص‌یافته',
			'in_progress'   => 'در حال انجام',
			'service_ended' => 'پایان خدمت',
			'needs_review'  => 'نیازمند بررسی',
			'completed'     => 'تکمیل',
			'cancelled'     => 'لغو',
			'invoiced'      => 'فاکتور',
			'paid'          => 'پرداخت',
		);
		echo '<select name="status" class="mns-autosubmit">';
		foreach ( $statuses as $k => $lab ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $k ), selected( $status, $k, false ), esc_html( $lab ) );
		}
		echo '</select> ';
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="source" class="mns-autosubmit"><option value="">همه منابع</option>';
		printf( '<option value="site"%s>سایت</option>', selected( $source, 'site', false ) );
		printf( '<option value="phone"%s>تلفنی</option>', selected( $source, 'phone', false ) );
		echo '</select> ';
		submit_button( 'فیلتر', '', 'filter_action', false );
		echo '</div>';
		self::$table->search_box( 'جستجوی کد/تلفن', 'mns-order' );
		self::$table->display();
		echo '</form>';
		MNS_Admin_Ui::footer();
	}

	private static function render_detail( int $id ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'orders' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $row ) ) {
			MNS_Admin_Ui::header( 'رصد مرکزی' );
			echo '<div class="notice notice-error inline"><p>سفارش یافت نشد.</p></div>';
			MNS_Admin_Ui::footer();
			return;
		}
		$svc  = MNS_Services::get( (int) $row['service_id'] );
		$logs = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'order_log' ) . ' WHERE order_id = %d ORDER BY id ASC', $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$ess = json_decode( (string) ( $row['essentials'] ?? '{}' ), true );
		if ( ! is_array( $ess ) ) {
			$ess = array();
		}
		MNS_Admin_Ui::header(
			'سفارش ' . (string) $row['code'],
			array( array( 'label' => 'بازگشت به رصد', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR ) ) )
		);
		?>
		<div class="mns-grid">
			<div class="postbox mns-box">
				<div class="postbox-header"><h2>جزئیات</h2></div>
				<div class="inside">
					<table class="mns-details widefat striped">
						<tr><th>وضعیت</th><td><?php echo MNS_Admin_Ui::status_badge( (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<tr><th>خدمت</th><td><?php echo esc_html( $svc ? (string) $svc['name'] : '—' ); ?></td></tr>
						<tr><th>زمان</th><td><?php echo esc_html( (string) $row['service_date'] . ' · ' . (string) $row['slot_id'] ); ?></td></tr>
						<tr><th>تماس</th><td dir="ltr"><?php echo esc_html( (string) $row['contact_phone'] ); ?></td></tr>
						<tr><th>نشانی</th><td><?php echo esc_html( (string) $row['address'] ); ?></td></tr>
						<tr><th>پرستار</th><td><?php echo esc_html( MNS_Admin_Ui::user_name( (int) ( $row['nurse_user_id'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>منبع</th><td><?php echo esc_html( (string) ( $row['source'] ?? '' ) ); ?></td></tr>
						<tr><th>یادداشت</th><td><?php echo esc_html( (string) ( $row['notes'] ?? '' ) ); ?></td></tr>
					</table>
					<?php if ( $ess ) : ?>
						<h3 class="mns-subhead">اطلاعات ضروری</h3>
						<ul>
							<?php foreach ( $ess as $k => $v ) : ?>
								<?php if ( is_scalar( $v ) ) : ?>
									<li><code><?php echo esc_html( (string) $k ); ?></code>: <?php echo esc_html( (string) $v ); ?></li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
			<div class="postbox mns-box">
				<div class="postbox-header"><h2>اقدامات</h2></div>
				<div class="inside">
					<?php if ( in_array( (string) $row['status'], array( 'submitted', 'assigned' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mns-inline-form">
							<?php wp_nonce_field( 'mns_monitor_assign' ); ?>
							<input type="hidden" name="action" value="mns_monitor_assign" />
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<label class="mns-label" for="nurse_id">شناسه پرستار</label>
							<input type="number" name="nurse_id" id="nurse_id" class="small-text" required />
							<label><input type="checkbox" name="force" value="1" /> اجبار (force)</label>
							<?php submit_button( 'تخصیص / تغییر پرستار', 'primary', 'submit', false ); ?>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'mns_monitor_cancel' ); ?>
							<input type="hidden" name="action" value="mns_monitor_cancel" />
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<label class="mns-label" for="cancel_reason">دلیل لغو</label>
							<textarea name="reason" id="cancel_reason" class="large-text" rows="2" required></textarea>
							<?php submit_button( 'لغو سفارش', 'delete', 'submit', false, array( 'data-confirm' => 'سفارش لغو شود؟' ) ); ?>
						</form>
					<?php endif; ?>
					<?php if ( 'assigned' === (string) $row['status'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'mns_monitor_start' ); ?>
							<input type="hidden" name="action" value="mns_monitor_start" />
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<?php submit_button( 'شروع خدمت (ادمین)', 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<?php if ( 'in_progress' === (string) $row['status'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'mns_monitor_end' ); ?>
							<input type="hidden" name="action" value="mns_monitor_end" />
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<?php submit_button( 'پایان خدمت (ادمین)', 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'mns_monitor_note' ); ?>
						<input type="hidden" name="action" value="mns_monitor_note" />
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<label class="mns-label" for="staff_note">یادداشت داخلی</label>
						<textarea name="note" id="staff_note" class="large-text" rows="3"></textarea>
						<?php submit_button( 'ثبت یادداشت', 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mns-inline-form">
						<?php wp_nonce_field( 'mns_monitor_unlock' ); ?>
						<input type="hidden" name="action" value="mns_monitor_unlock" />
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<label class="mns-label" for="unlock_step">آنلاک مرحله</label>
						<select name="step" id="unlock_step">
							<option value="checklist">چک‌لیست</option>
							<option value="drug">دارو</option>
							<option value="report">گزارش</option>
						</select>
						<?php submit_button( 'باز کردن', 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<div class="postbox mns-box">
			<div class="postbox-header"><h2>تایم‌لاین</h2></div>
			<div class="inside">
				<?php if ( ! $logs ) : ?>
					<p class="mns-empty">رویدادی ثبت نشده.</p>
				<?php else : ?>
					<ul class="mns-timeline">
						<?php foreach ( $logs as $log ) : ?>
							<?php
							$payload = json_decode( (string) ( $log['payload'] ?? '' ), true );
							$note_txt = is_array( $payload ) ? (string) ( $payload['note'] ?? '' ) : '';
							?>
							<li class="<?php echo 'note' === (string) ( $log['action'] ?? '' ) ? 'is-note' : ''; ?>">
								<div class="mns-timeline__head">
									<strong><?php echo esc_html( (string) ( $log['action'] ?? 'رویداد' ) ); ?></strong>
									<span class="mns-muted"><?php echo esc_html( MNS_Admin_Ui::date( (string) ( $log['created_at'] ?? '' ), true ) ); ?></span>
								</div>
								<?php if ( '' !== $note_txt ) : ?>
									<div class="mns-timeline__note"><?php echo esc_html( $note_txt ); ?></div>
								<?php elseif ( ! empty( $log['from_status'] ) || ! empty( $log['to_status'] ) ) : ?>
									<div class="mns-timeline__note"><?php echo esc_html( (string) ( $log['from_status'] ?? '' ) . ' → ' . (string) ( $log['to_status'] ?? '' ) ); ?></div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
		MNS_Admin_Ui::footer();
	}

	public static function handle_assign(): void {
		self::guard_post( 'mns_monitor_assign' );
		$oid   = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$nurse = isset( $_POST['nurse_id'] ) ? (int) $_POST['nurse_id'] : 0;
		$force = ! empty( $_POST['force'] );
		try {
			MNS_Assignment::admin_set_nurse( $oid, $nurse, get_current_user_id(), $force );
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_MONITOR, 'assigned', array( 'action' => 'view', 'id' => $oid ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	public static function handle_cancel(): void {
		self::guard_post( 'mns_monitor_cancel' );
		$oid    = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		try {
			MNS_Order_Machine::transition(
				'cancel_admin',
				array(
					'order_id'      => $oid,
					'reason'        => $reason,
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'admin',
				)
			);
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_MONITOR, 'cancelled', array( 'action' => 'view', 'id' => $oid ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	public static function handle_note(): void {
		self::guard_post( 'mns_monitor_note' );
		$oid  = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';
		if ( $oid && '' !== $note ) {
			global $wpdb;
			$wpdb->insert(
				MNS_DB::table( 'order_log' ),
				array(
					'order_id'      => $oid,
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'admin',
					'action'        => 'note',
					'from_status'   => null,
					'to_status'     => null,
					'payload'       => wp_json_encode( array( 'note' => $note ) ),
					'created_at'    => MNS_Time::now_utc(),
				)
			);
		}
		MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_MONITOR, 'saved', array( 'action' => 'view', 'id' => $oid ) );
	}

	public static function handle_start(): void {
		self::guard_post( 'mns_monitor_start' );
		$oid = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		try {
			MNS_Order_Machine::transition(
				'start',
				array(
					'order_id'      => $oid,
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'admin',
				)
			);
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_MONITOR, 'started', array( 'action' => 'view', 'id' => $oid ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	public static function handle_end(): void {
		self::guard_post( 'mns_monitor_end' );
		$oid = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		try {
			MNS_Order_Machine::transition(
				'end',
				array(
					'order_id'      => $oid,
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'admin',
				)
			);
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_MONITOR, 'ended', array( 'action' => 'view', 'id' => $oid ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	public static function handle_unlock(): void {
		self::guard_post( 'mns_monitor_unlock' );
		$oid  = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( (string) $_POST['step'] ) ) : '';
		try {
			MNS_Execution::unlock( $oid, $step, get_current_user_id() );
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_MONITOR, 'step_unlocked', array( 'action' => 'view', 'id' => $oid ) );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	private static function guard_post( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		check_admin_referer( $nonce_action );
	}
}
