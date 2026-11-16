<?php
/**
 * In-app notifications + cleanup cron (T-3.7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Notifications {

	public const CRON = 'mns_notifications_cleanup';

	public static function boot(): void {
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
		add_action( 'mns_order_transitioned', array( __CLASS__, 'on_transition' ), 20, 1 );
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
	}

	/**
	 * @param array<string,mixed> $h Hook payload.
	 */
	public static function on_transition( array $h ): void {
		$order = isset( $h['order'] ) && is_array( $h['order'] ) ? $h['order'] : array();
		$action = isset( $h['action'] ) ? (string) $h['action'] : '';
		$oid = isset( $h['order_id'] ) ? (int) $h['order_id'] : 0;
		if ( 'cancel' === $action || 'cancel_admin' === $action ) {
			if ( ! empty( $order['nurse_user_id'] ) ) {
				self::add( (int) $order['nurse_user_id'], 'order_cancelled', $oid );
			}
		}
		if ( 'reassign' === $action ) {
			$payload = array();
			// previous nurse notified without PII beyond order id.
			if ( ! empty( $order['nurse_user_id'] ) ) {
				self::add( (int) $order['nurse_user_id'], 'order_reassigned', $oid );
			}
		}
		if ( 'assign' === $action && ! empty( $order['nurse_user_id'] ) ) {
			self::add( (int) $order['nurse_user_id'], 'order_assigned', $oid );
		}
	}

	public static function add( int $user_id, string $type, ?int $order_id = null, array $payload = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			MNS_DB::table( 'notifications' ),
			array(
				'user_id'    => $user_id,
				'type'       => sanitize_key( $type ),
				'order_id'   => $order_id,
				'payload'    => wp_json_encode( $payload ),
				'seen_at'    => null,
				'created_at' => MNS_Time::now_utc(),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$table = MNS_DB::table( 'notifications' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, order_id, payload, seen_at, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = array(
					'id'         => (int) $r['id'],
					'type'       => (string) $r['type'],
					'order_id'   => $r['order_id'] ? (int) $r['order_id'] : null,
					'seen'       => ! empty( $r['seen_at'] ),
					'created_at' => (string) $r['created_at'],
				);
			}
		}
		return $out;
	}

	public static function mark_seen( int $user_id, int $id ): void {
		global $wpdb;
		$wpdb->update(
			MNS_DB::table( 'notifications' ),
			array( 'seen_at' => MNS_Time::now_utc() ),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	public static function cleanup(): void {
		global $wpdb;
		$table = MNS_DB::table( 'notifications' );
		$cut   = gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
