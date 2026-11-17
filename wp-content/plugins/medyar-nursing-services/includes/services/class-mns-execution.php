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
		$steps[ $step ] = array_merge( is_array( $steps[ $step ] ) ? $steps[ $step ] : array(), $data );
		if ( empty( $steps[ $step ]['state'] ) ) {
			$steps[ $step ]['state'] = 'draft';
		}
		global $wpdb;
		$wpdb->update(
			MNS_DB::table( 'orders' ),
			array(
				'steps'      => wp_json_encode( $steps ),
				'updated_at' => MNS_Time::now_utc(),
			),
			array( 'id' => $order_id )
		);
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
