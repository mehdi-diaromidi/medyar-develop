<?php
/**
 * Self-test registry (T-0.6 / T-C.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest {

	/** @var array<string,array{title:string,callback:callable}> */
	private static $registry = array();

	/** @var bool True while run() is executing (gates Fake payment gateway). */
	private static $running = false;

	public static function is_running(): bool {
		return self::$running;
	}

	/**
	 * @param mixed $gw Current gateway.
	 * @return MNS_Payment_Gateway
	 */
	public static function force_fake_gateway( $gw ) {
		return new MNS_Payment_Gateway_Fake();
	}

	/**
	 * @param string   $ac_id    AC id.
	 * @param string   $title    Title.
	 * @param callable $callback Returns array{ok:bool,detail:string}.
	 */
	public static function register( string $ac_id, string $title, callable $callback ): void {
		self::$registry[ $ac_id ] = array(
			'title'    => $title,
			'callback' => $callback,
		);
	}

	/**
	 * @return array<string,array{title:string,callback:callable}>
	 */
	public static function all(): array {
		return self::$registry;
	}

	/**
	 * Purge leftover self-test users/services/rows (T-C.1).
	 *
	 * @return array{users:int,services:int,leftover:int}
	 */
	public static function purge_leftovers(): array {
		global $wpdb;

		$users = get_users(
			array(
				'search'         => 'mns_selftest_*',
				'search_columns' => array( 'user_login' ),
				'fields'         => array( 'ID', 'user_login' ),
				'number'         => 500,
			)
		);
		$user_ids = array();
		foreach ( $users as $u ) {
			$login = is_object( $u ) ? (string) $u->user_login : '';
			if ( 0 !== strpos( $login, 'mns_selftest_' ) ) {
				continue;
			}
			$user_ids[] = (int) ( is_object( $u ) ? $u->ID : 0 );
		}
		$user_ids = array_values( array_filter( $user_ids ) );
		$purged_users = 0;
		foreach ( $user_ids as $uid ) {
			self::purge_user_rows( $uid );
			wp_delete_user( $uid );
			$purged_users++;
		}

		$services_table = MNS_DB::table( 'services' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$svc_ids = $wpdb->get_col( "SELECT id FROM {$services_table} WHERE slug LIKE 'mns\\_selftest%'" );
		$purged_svc = 0;
		if ( is_array( $svc_ids ) ) {
			foreach ( $svc_ids as $sid ) {
				$sid = (int) $sid;
				$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'service_id' => $sid ), array( '%d' ) );
				$wpdb->delete( $services_table, array( 'id' => $sid ), array( '%d' ) );
				$purged_svc++;
			}
		}

		$leftover = self::count_leftovers();
		return array(
			'users'    => $purged_users,
			'services' => $purged_svc,
			'leftover' => $leftover,
		);
	}

	/**
	 * Count remaining self-test markers.
	 */
	public static function count_leftovers(): int {
		global $wpdb;
		$n = 0;
		$users = get_users(
			array(
				'search'         => 'mns_selftest_*',
				'search_columns' => array( 'user_login' ),
				'fields'         => array( 'ID', 'user_login' ),
				'number'         => 500,
			)
		);
		foreach ( $users as $u ) {
			$login = is_object( $u ) ? (string) $u->user_login : '';
			if ( 0 === strpos( $login, 'mns_selftest_' ) ) {
				$n++;
			}
		}
		$services_table = MNS_DB::table( 'services' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$services_table} WHERE slug LIKE 'mns\\_selftest%'" );
		return $n;
	}

	/**
	 * Delete all MNS rows for a test user.
	 */
	public static function purge_user_rows( int $uid ): void {
		if ( $uid <= 0 ) {
			return;
		}
		global $wpdb;
		$orders = MNS_DB::table( 'orders' );
		$oids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$orders} WHERE account_user_id = %d OR nurse_user_id = %d OR patient_id IN (SELECT id FROM " . MNS_DB::table( 'patients' ) . ' WHERE owner_user_id = %d)', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$uid,
				$uid,
				$uid
			)
		);
		if ( is_array( $oids ) ) {
			foreach ( $oids as $oid ) {
				$oid = (int) $oid;
				$wpdb->delete( MNS_DB::table( 'order_log' ), array( 'order_id' => $oid ), array( '%d' ) );
				$wpdb->delete( MNS_DB::table( 'order_edits' ), array( 'order_id' => $oid ), array( '%d' ) );
				$wpdb->delete( MNS_DB::table( 'notifications' ), array( 'order_id' => $oid ), array( '%d' ) );
				$wpdb->delete( $orders, array( 'id' => $oid ), array( '%d' ) );
			}
		}
		$wpdb->delete( MNS_DB::table( 'notifications' ), array( 'user_id' => $uid ), array( '%d' ) );
		$iids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE account_user_id = %d OR nurse_user_id = %d', $uid, $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( is_array( $iids ) ) {
			foreach ( $iids as $iid ) {
				$wpdb->delete( MNS_DB::table( 'payment_attempts' ), array( 'invoice_id' => (int) $iid ), array( '%d' ) );
				$wpdb->delete( MNS_DB::table( 'payments' ), array( 'invoice_id' => (int) $iid ), array( '%d' ) );
				$wpdb->delete( MNS_DB::table( 'invoices' ), array( 'id' => (int) $iid ), array( '%d' ) );
			}
		}
		$wpdb->delete( MNS_DB::table( 'payment_attempts' ), array( 'account_user_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'files' ), array( 'owner_user_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'patients' ), array( 'owner_user_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'nurse_day_slots' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'nurse_regions' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'nurses' ), array( 'user_id' => $uid ), array( '%d' ) );
	}

	/**
	 * Run all (or selected) scenarios.
	 *
	 * @param array<int,string>|null $only Optional AC ids.
	 * @return array{pass:int,fail:int,rows:array<int,array<string,mixed>>}
	 */
	/**
	 * @param array<int,mixed> $ids Candidate nurse user ids.
	 * @return array<int,int>
	 */
	public static function only_selftest_nurses( $ids ): array {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( $user && 0 === strpos( (string) $user->user_login, 'mns_selftest_' ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Exact id, or a prefix pattern ending in "*" (e.g. "AC-M5-*").
	 *
	 * @param array<int,string> $only Ids/patterns.
	 */
	private static function id_matches( string $id, array $only ): bool {
		foreach ( $only as $pattern ) {
			$pattern = (string) $pattern;
			if ( '*' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $id, rtrim( $pattern, '*' ) ) ) {
					return true;
				}
			} elseif ( $pattern === $id ) {
				return true;
			}
		}
		return false;
	}

	public static function run( ?array $only = null ): array {
		$original_user = get_current_user_id();
		self::$running = true;
		// Real nurses (e.g. the owner's own approved nurse profile) must not compete with the test nurses.
		add_filter( 'mns_eligibility_candidate_ids', array( __CLASS__, 'only_selftest_nurses' ), 10, 1 );
		add_filter( 'mns_payment_gateway', array( __CLASS__, 'force_fake_gateway' ), 10, 1 );
		self::purge_leftovers();
		MNS_Selftest_Scenarios::register_all();

		$pass = 0;
		$fail = 0;
		$rows = array();

		try {
			foreach ( self::$registry as $id => $meta ) {
				if ( is_array( $only ) && ! self::id_matches( (string) $id, $only ) ) {
					continue;
				}
				$ok     = false;
				$detail = '';
				try {
					$result = call_user_func( $meta['callback'] );
					if ( is_array( $result ) ) {
						$ok     = ! empty( $result['ok'] );
						$detail = isset( $result['detail'] ) ? (string) $result['detail'] : '';
					} else {
						$detail = 'bad_return';
					}
				} catch ( \Throwable $e ) {
					$ok     = false;
					$detail = get_class( $e ) . ': ' . $e->getMessage();
				}
				if ( $ok ) {
					$pass++;
				} else {
					$fail++;
				}
				$rows[] = array(
					'id'     => $id,
					'title'  => $meta['title'],
					'ok'     => $ok,
					'detail' => $detail,
				);
			}

			// Final leftover sweep + report row.
			$final = self::purge_leftovers();
			$rows[] = array(
				'id'     => 'AC-TC-1',
				'title'  => 'Self-test leftovers purged',
				'ok'     => ( 0 === (int) $final['leftover'] ),
				'detail' => 'leftover=' . (int) $final['leftover'] . '; purged_users=' . (int) $final['users'] . '; purged_services=' . (int) $final['services'],
			);
			if ( 0 === (int) $final['leftover'] ) {
				$pass++;
			} else {
				$fail++;
			}
		} finally {
			self::$running = false;
			remove_filter( 'mns_eligibility_candidate_ids', array( __CLASS__, 'only_selftest_nurses' ), 10 );
			remove_filter( 'mns_payment_gateway', array( __CLASS__, 'force_fake_gateway' ), 10 );
			if ( class_exists( 'MNS_Payment_Gateway_Fake', true ) ) {
				MNS_Payment_Gateway_Fake::reset();
			}
			// Scenarios impersonate test users; always give the session back to whoever started the run.
			wp_set_current_user( $original_user );
		}

		return array(
			'pass' => $pass,
			'fail' => $fail,
			'rows' => $rows,
		);
	}
}
