<?php
/**
 * SMS via medyar-sms-queue only (T-1.5 / 05 §4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Sms {

	public const EVT_NURSE_APPLY      = 'nurse_apply';
	public const EVT_NURSE_APPROVED   = 'nurse_approved';
	public const EVT_NURSE_REJECTED   = 'nurse_rejected';
	public const EVT_ORDER_CREATED    = 'order_created';
	public const EVT_ORDER_ASSIGNED   = 'order_assigned';
	public const EVT_SERVICE_ENDED    = 'service_ended';
	public const EVT_URGENT_ADMIN     = 'urgent_admin';
	public const EVT_INVOICE_ISSUED   = 'invoice_issued';
	public const EVT_INVOICE_REMIND   = 'invoice_remind';
	public const EVT_PAYMENT_OK       = 'payment_ok';
	public const EVT_ORDER_UNASSIGNED = 'order_unassigned';
	public const EVT_ORDER_CANCELLED  = 'order_cancelled';

	public static function boot(): void {
		add_action( 'mns_nurse_applied', array( __CLASS__, 'on_applied' ), 10, 2 );
		add_action( 'mns_nurse_approved', array( __CLASS__, 'on_approved' ), 10, 2 );
		add_action( 'mns_nurse_rejected', array( __CLASS__, 'on_rejected' ), 10, 3 );
		add_action( 'mns_order_transitioned', array( __CLASS__, 'on_order_transitioned' ), 10, 1 );
	}

	/**
	 * SMS #4 create, #5 assign/reassign, #11 unassigned (cancel freeing nurse), #12 cancelled (T-3.8).
	 *
	 * @param array<string,mixed> $h Hook payload after commit.
	 */
	public static function on_order_transitioned( array $h ): void {
		$action = isset( $h['action'] ) ? (string) $h['action'] : '';
		$order  = isset( $h['order'] ) && is_array( $h['order'] ) ? $h['order'] : array();
		$code   = isset( $order['code'] ) ? (string) $order['code'] : '';
		$oid    = isset( $h['order_id'] ) ? (int) $h['order_id'] : 0;
		$account = isset( $order['account_user_id'] ) ? (int) $order['account_user_id'] : 0;
		$phone   = self::user_phone( $account );
		if ( ! $phone && ! empty( $order['contact_phone'] ) ) {
			$phone = (string) $order['contact_phone'];
		}

		if ( 'create' === $action && $phone ) {
			self::send( self::EVT_ORDER_CREATED, $phone, array( 'code' => $code ), 'mns:order_created:' . $oid . ':' . $phone );
		}
		if ( ( 'assign' === $action || 'reassign' === $action ) && $phone ) {
			self::send( self::EVT_ORDER_ASSIGNED, $phone, array( 'code' => $code ), 'mns:order_assigned:' . $oid . ':' . $action . ':' . $phone );
			if ( ! empty( $order['nurse_user_id'] ) ) {
				$nphone = self::user_phone( (int) $order['nurse_user_id'] );
				if ( $nphone ) {
					self::send( self::EVT_ORDER_ASSIGNED, $nphone, array( 'code' => $code ), 'mns:order_assigned_nurse:' . $oid . ':' . $action . ':' . $nphone );
				}
			}
		}
		if ( 'cancel' === $action || 'cancel_admin' === $action ) {
			if ( $phone ) {
				self::send( self::EVT_ORDER_CANCELLED, $phone, array( 'code' => $code ), 'mns:order_cancelled:' . $oid . ':' . $phone );
			}
			// #11: previous nurse notified of unassign via cancel.
			if ( ! empty( $order['nurse_user_id'] ) ) {
				$nphone = self::user_phone( (int) $order['nurse_user_id'] );
				if ( $nphone ) {
					self::send( self::EVT_ORDER_UNASSIGNED, $nphone, array( 'code' => $code ), 'mns:order_unassigned:' . $oid . ':' . $nphone );
				}
			}
		}
	}

	/**
	 * @param int                 $user_id User.
	 * @param array<string,mixed> $row     Nurse row.
	 */
	public static function on_applied( int $user_id, array $row ): void {
		$phone = self::user_phone( $user_id );
		if ( $phone ) {
			self::send( self::EVT_NURSE_APPLY, $phone, array( 'name' => self::user_name( $user_id ) ), 'mns:nurse_apply:' . $user_id . ':' . $phone );
		}
		foreach ( self::admin_phones() as $admin_phone ) {
			self::send( self::EVT_NURSE_APPLY, $admin_phone, array( 'name' => self::user_name( $user_id ) ), 'mns:nurse_apply_admin:' . $user_id . ':' . $admin_phone, 10 );
		}
	}

	public static function on_approved( int $user_id, int $admin_id ): void {
		$phone = self::user_phone( $user_id );
		if ( $phone ) {
			self::send( self::EVT_NURSE_APPROVED, $phone, array( 'name' => self::user_name( $user_id ) ), 'mns:nurse_approved:' . $user_id . ':' . $phone );
		}
	}

	public static function on_rejected( int $user_id, int $admin_id, string $note ): void {
		$phone = self::user_phone( $user_id );
		if ( $phone ) {
			self::send( self::EVT_NURSE_REJECTED, $phone, array( 'name' => self::user_name( $user_id ) ), 'mns:nurse_rejected:' . $user_id . ':' . $phone );
		}
	}

	/**
	 * @param array<string,string> $vars Pattern vars.
	 */
	public static function send( string $event, string $phone, array $vars, string $dedupe_key, int $priority = 20 ): bool {
		if ( ! MNS_Settings::get( 'sms_enabled' ) ) {
			MNS_Log::info( 'sms_disabled', array( 'event' => $event ) );
			return false;
		}

		$pattern_key = 'sms_pattern_' . $event;
		$pattern     = (string) MNS_Settings::get( $pattern_key );
		if ( '' === $pattern ) {
			MNS_Log::warn( 'pattern_missing', array( 'event' => $event, 'phone' => $phone ) );
			return false;
		}

		if ( ! class_exists( 'MSQ_Client' ) || ! is_callable( array( 'MSQ_Client', 'is_ready' ) ) || ! MSQ_Client::is_ready() ) {
			MNS_Log::warn( 'msq_unavailable', array( 'event' => $event ) );
			return false;
		}

		$args = array(
			'channel'    => 'nursing',
			'event'      => $event,
			'phone'      => $phone,
			'pattern'    => $pattern,
			'vars'       => $vars,
			'priority'   => $priority,
			'dedupe_key' => $dedupe_key,
			'dedupe'     => 'skip',
		);

		try {
			MSQ_Client::enqueue( $args );
			return true;
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'sms_enqueue_failed', array( 'event' => $event, 'err' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * @return array<int,string>
	 */
	private static function admin_phones(): array {
		$list = MNS_Settings::get( 'sms_admins' );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	private static function user_phone( int $user_id ): string {
		if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'get_phone' ) ) ) {
			$p = \DrPlus\Utils\User::get_phone( $user_id );
			if ( is_string( $p ) && '' !== $p ) {
				return $p;
			}
		}
		foreach ( array( 'drplus_mobile', 'mobile', 'billing_phone' ) as $key ) {
			$v = get_user_meta( $user_id, $key, true );
			if ( is_string( $v ) && '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	private static function user_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}
}
