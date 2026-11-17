<?php
/**
 * Ops cron: end-of-day needs_review for incomplete service_ended orders (M4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Ops_Cron {

	public const HOOK = 'mns_daily_needs_review';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_needs_review' ) );
		add_action( 'mns_order_transitioned', array( __CLASS__, 'on_transition' ), 10, 1 );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$ts = strtotime( 'tomorrow 21:00:00 Asia/Tehran' );
			if ( ! $ts ) {
				$ts = time() + DAY_IN_SECONDS;
			}
			wp_schedule_event( $ts, 'daily', self::HOOK );
		}
	}

	public static function run_needs_review(): void {
		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		$ids   = $wpdb->get_col(
			"SELECT id FROM {$table} WHERE status = 'service_ended' ORDER BY id ASC LIMIT 100" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! is_array( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			try {
				MNS_Order_Machine::transition(
					'needs_review',
					array(
						'order_id'      => (int) $id,
						'actor_user_id' => 0,
						'actor_role'    => 'system',
					)
				);
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
	}

	/**
	 * @param array<string,mixed> $h Hook payload.
	 */
	public static function on_transition( array $h ): void {
		$action = (string) ( $h['action'] ?? '' );
		$order  = isset( $h['order'] ) && is_array( $h['order'] ) ? $h['order'] : array();
		if ( 'complete' === $action || 'end' === $action ) {
			$oid     = (int) ( $h['order_id'] ?? ( $order['id'] ?? 0 ) );
			$account = (int) ( $order['account_user_id'] ?? 0 );
			$nurse   = (int) ( $order['nurse_user_id'] ?? 0 );
			if ( $oid && $account && class_exists( 'MNS_Surveys', true ) ) {
				try {
					MNS_Surveys::open_for_order( $oid, $account, $nurse );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
		}
		if ( 'pay' === $action && class_exists( 'MNS_Loyalty', true ) ) {
			$account = (int) ( $order['account_user_id'] ?? 0 );
			if ( $account ) {
				try {
					MNS_Loyalty::add_stamp( $account );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
		}
	}
}
