<?php
/**
 * Nurse execution steps stored on orders.steps JSON (M4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Execution {

	/**
	 * @return array<string,mixed>
	 */
	public static function steps_for( int $order_id ): array {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			return self::empty_steps();
		}
		$raw = json_decode( (string) ( $order['steps'] ?? '' ), true );
		if ( ! is_array( $raw ) ) {
			return self::empty_steps();
		}
		return array_merge( self::empty_steps(), $raw );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function empty_steps(): array {
		return array(
			'checklist' => array( 'state' => 'locked', 'items' => array() ),
			'drug'      => array( 'state' => 'locked', 'items' => array() ),
			'report'    => array( 'state' => 'locked', 'sections' => array() ),
		);
	}

	/**
	 * @param array<string,mixed> $data Step payload.
	 */
	public static function save_step( int $order_id, string $step, array $data, int $actor, string $role = 'nurse' ): array {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			throw MNS_Error::not_found();
		}
		if ( 'nurse' === $role && (int) $order['nurse_user_id'] !== $actor ) {
			throw MNS_Error::forbidden();
		}
		$steps = self::steps_for( $order_id );
		$step  = sanitize_key( $step );
		if ( ! isset( $steps[ $step ] ) ) {
			throw MNS_Error::validation( 'مرحله نامعتبر است.' );
		}

		// Supervisor fix: a nurse may only fill an unlocked step while the visit is running or just ended
		// (never after completion/invoice/payment/cancel), and only whitelisted, sanitized, size-capped data.
		if ( 'nurse' === $role ) {
			if ( ! in_array( (string) $order['status'], array( 'in_progress', 'service_ended', 'needs_review' ), true ) ) {
				throw MNS_Error::bad_state();
			}
			$current_state = isset( $steps[ $step ]['state'] ) ? (string) $steps[ $step ]['state'] : 'locked';
			if ( 'locked' === $current_state ) {
				// Same opening rule the nurse view uses: checklist/drug open during the visit, everything after it ends.
				$ostatus = (string) $order['status'];
				$opened  = ( 'in_progress' === $ostatus && in_array( $step, array( 'checklist', 'drug' ), true ) )
					|| in_array( $ostatus, array( 'service_ended', 'needs_review' ), true );
				if ( ! $opened ) {
					throw MNS_Error::forbidden( 'این مرحله هنوز باز نشده است.' );
				}
			}
			$clean = array();
			if ( isset( $data['state'] ) && in_array( (string) $data['state'], array( 'draft', 'done' ), true ) ) {
				$clean['state'] = (string) $data['state'];
			}
			foreach ( array( 'items', 'sections' ) as $list_key ) {
				if ( isset( $data[ $list_key ] ) && is_array( $data[ $list_key ] ) ) {
					$clean[ $list_key ] = map_deep( $data[ $list_key ], 'sanitize_textarea_field' );
				}
			}
			if ( strlen( (string) wp_json_encode( $clean ) ) > 20000 ) {
				throw MNS_Error::validation( 'حجم اطلاعات مرحله بیش از حد مجاز است.' );
			}
			$data = $clean;
		}
		$steps[ $step ] = array_merge( is_array( $steps[ $step ] ) ? $steps[ $step ] : array(), $data );
		if ( empty( $steps[ $step ]['state'] ) ) {
			$steps[ $step ]['state'] = 'draft';
		}
		global $wpdb;
		$saved = $wpdb->update(
			MNS_DB::table( 'orders' ),
			array(
				'steps'      => wp_json_encode( $steps ),
				'updated_at' => MNS_Time::now_utc(),
			),
			array( 'id' => $order_id )
		);
		if ( false === $saved ) {
			throw MNS_Error::server( 'ذخیرهٔ مرحله ممکن نشد.' );
		}
		return $steps;
	}

	public static function unlock( int $order_id, string $step, int $actor ): array {
		return self::save_step(
			$order_id,
			$step,
			array( 'state' => 'todo' ),
			$actor,
			'admin'
		);
	}
}
