<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen: queue health, cron setup, delivery log, retry/cancel.
 */
final class MSQ_Admin {

	public const PAGE   = 'medyar-sms-queue';
	public const ACTION = 'msq_admin_action';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_menu_page(
			'صف پیامک',
			'صف پیامک',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-email-alt',
			59
		);
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'msq-admin', MSQ_PLUGIN_URL . 'assets/css/admin.css', array(), MSQ_VERSION );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_url( string $do, int $id = 0 ): string {
		$args = array(
			'action' => self::ACTION,
			'do'     => $do,
		);
		if ( $id > 0 ) {
			$args['id'] = $id;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $do );
	}

	public static function system_cron_url(): string {
		return add_query_arg(
			array(
				'action' => MSQ_Worker::CRON_ACTION,
				'key'    => MSQ_Install::cron_key(),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( (string) $_GET['do'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $do );
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$notice = '';

		switch ( $do ) {
			case 'retry':
				$notice = MSQ_Queue::retry( $id ) ? 'retried' : 'noop';
				if ( 'retried' === $notice ) {
					MSQ_Worker::spawn( true );
				}
				break;
			case 'cancel':
				$notice = MSQ_Queue::cancel( $id ) ? 'canceled' : 'noop';
				break;
			case 'retry_dead':
				$n      = MSQ_Queue::retry_all_dead();
				$notice = $n > 0 ? 'retried_all' : 'noop';
				if ( $n > 0 ) {
					MSQ_Worker::spawn( true );
				}
				break;
			case 'drain':
				$n = MSQ_Worker::drain( 'manual' );
				wp_safe_redirect( self::url( array( 'msq_notice' => 'drained', 'n' => $n ) ) );
				exit;
			case 'regen_key':
				MSQ_Install::regenerate_cron_key();
				$notice = 'key_regenerated';
				break;
		}

		wp_safe_redirect( self::url( array( 'msq_notice' => $notice ) ) );
		exit;
	}

	/** @return array<string,string> */
	public static function status_labels(): array {
		return array(
			MSQ_Queue::STATUS_PENDING  => 'در صف',
			MSQ_Queue::STATUS_RESERVED => 'در حال ارسال',
			MSQ_Queue::STATUS_SENT     => 'ارسال‌شده',
			MSQ_Queue::STATUS_DEAD     => 'ناموفق',
			MSQ_Queue::STATUS_CANCELED => 'لغوشده',
		);
	}

	/** @return array<string,string> */
	public static function channel_labels(): array {
		return array(
			'arp'     => 'طرح همکاران',
			'wallet'  => 'کیف پول',
			'booking' => 'رزرو نوبت',
		);
	}

	public static function format_utc( ?string $utc ): string {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '—';
		}
		$ts = strtotime( $utc . ' UTC' );
		return $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : '—';
	}

	public static function format_ts( $ts ): string {
		$ts = (int) $ts;
		return $ts > 0 ? wp_date( 'Y-m-d H:i:s', $ts ) : '—';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filters = array(
			'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '',
			'channel' => isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( (string) $_GET['channel'] ) ) : '',
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '',
		);
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$notice  = isset( $_GET['msq_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['msq_notice'] ) ) : '';
		$drained = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable

		$installed = MSQ_Install::is_installed();
		$counts    = $installed ? MSQ_Queue::counts() : array();
		$result    = $installed ? MSQ_Queue::search( $filters, $paged, 30 ) : array( 'rows' => array(), 'total' => 0 );
		$channels  = $installed ? MSQ_Queue::channels() : array();
		$diag      = MSQ_Worker::diag_snapshot();
		$pages     = max( 1, (int) ceil( $result['total'] / 30 ) );

		include MSQ_PLUGIN_DIR . 'includes/admin/views/page-queue.php';
	}
}
