<?php
/**
 * Order status machine — only place that updates orders.status (T-0.5).
 *
 * Slice-1 transitions: create, assign, reassign, cancel, cancel_admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Order_Machine {

	/**
	 * Dispatch a named transition.
	 *
	 * @param string              $action create|assign|reassign|cancel|cancel_admin.
	 * @param array<string,mixed> $args   Action args.
	 * @return array<string,mixed> Result (usually order row).
	 * @throws MNS_Error On guard failure.
	 */
	public static function transition( string $action, array $args = array() ): array {
		switch ( $action ) {
			case 'create':
				return self::create( $args );
			case 'assign':
				return self::assign( $args );
			case 'reassign':
				return self::reassign( $args );
			case 'cancel':
				return self::cancel( $args );
			case 'cancel_admin':
				return self::cancel_admin( $args );
			default:
				throw MNS_Error::bad_state();
		}
	}

	/**
	 * ∅ → submitted.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private static function create( array $args ): array {
		$skip_a13 = ! empty( $args['skip_a13'] ); // admin phone order.
		$actor    = isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : 0;
		$role     = isset( $args['actor_role'] ) ? (string) $args['actor_role'] : 'patient';

		$account   = isset( $args['account_user_id'] ) ? (int) $args['account_user_id'] : 0;
		$patient   = isset( $args['patient_id'] ) ? (int) $args['patient_id'] : 0;
		$service   = isset( $args['service_id'] ) ? (int) $args['service_id'] : 0;
		$prov      = isset( $args['province_code'] ) ? sanitize_text_field( (string) $args['province_code'] ) : '';
		$city      = isset( $args['city_id'] ) ? (int) $args['city_id'] : 0;
		$district  = isset( $args['district_no'] ) ? (int) $args['district_no'] : 0;
		$address   = isset( $args['address'] ) ? sanitize_textarea_field( (string) $args['address'] ) : '';
		$phone     = isset( $args['contact_phone'] ) ? sanitize_text_field( (string) $args['contact_phone'] ) : '';
		$date      = isset( $args['service_date'] ) ? sanitize_text_field( (string) $args['service_date'] ) : '';
		$slot      = isset( $args['slot_id'] ) ? sanitize_text_field( (string) $args['slot_id'] ) : '';
		$gender    = isset( $args['gender_pref'] ) ? sanitize_text_field( (string) $args['gender_pref'] ) : '';
		$essentials = isset( $args['essentials'] ) && is_array( $args['essentials'] ) ? $args['essentials'] : array();
		$notes     = isset( $args['notes'] ) ? sanitize_textarea_field( (string) $args['notes'] ) : '';
		$source    = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'site';

		if ( $account <= 0 || $patient <= 0 || $service <= 0 || '' === $date || '' === $slot || '' === $gender ) {
			throw MNS_Error::validation( 'اطلاعات سفارش ناقص است.' );
		}

		$scope = array(
			'service_id'    => $service,
			'province_code' => $prov,
			'city_id'       => $city,
			'district_no'   => $district,
			'service_date'  => $date,
			'slot_id'       => $slot,
			'gender_pref'   => $gender,
		);

		$pending_hooks = array();

		$order = MNS_DB::tx(
			static function () use ( $skip_a13, $scope, $account, $patient, $service, $prov, $city, $district, $address, $phone, $date, $slot, $gender, $essentials, $notes, $source, $actor, $role, &$pending_hooks ) {
				global $wpdb;

				if ( ! $skip_a13 ) {
					$check = MNS_Availability::check( $scope );
					if ( 'green' !== $check['state'] ) {
						throw MNS_Error::conflict( 'ERR_NO_NURSE', 'همین حالا این زمان پر شد یا پرستاری موجود نیست.' );
					}
				}

				$code  = self::generate_code();
				$now   = MNS_Time::now_utc();
				$table = MNS_DB::table( 'orders' );

				$ok = $wpdb->insert(
					$table,
					array(
						'code'            => $code,
						'account_user_id' => $account,
						'patient_id'      => $patient,
						'service_id'      => $service,
						'source'          => $source,
						'status'          => MNS_Order_Status::SUBMITTED,
						'province_code'   => $prov,
						'city_id'         => $city,
						'district_no'     => $district,
						'address'         => $address,
						'contact_phone'   => $phone,
						'service_date'    => $date,
						'slot_id'         => $slot,
						'gender_pref'     => $gender,
						'essentials'      => wp_json_encode( $essentials ),
						'notes'           => $notes,
						'steps'           => wp_json_encode(
							array(
								'checklist' => 'none',
								'drug'      => 'na',
								'report'    => 'none',
							)
						),
						'version'         => 1,
						'created_at'      => $now,
						'updated_at'      => $now,
					),
					array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
				);

				if ( false === $ok ) {
					throw MNS_Error::server( 'ثبت سفارش ممکن نشد.' );
				}

				$order_id = (int) $wpdb->insert_id;
				self::write_log( $order_id, $actor, $role, 'create', '', MNS_Order_Status::SUBMITTED, array( 'code' => $code ) );

				$row = self::get_order( $order_id );
				$pending_hooks[] = array(
					'order_id' => $order_id,
					'action'   => 'create',
					'from'     => '',
					'to'       => MNS_Order_Status::SUBMITTED,
					'order'    => $row,
				);
				return $row;
			}
		);

		self::fire_hooks( $pending_hooks );
		return $order;
	}

	/**
	 * submitted → assigned.
	 *
	 * @param array<string,mixed> $args order_id, nurse_user_id, mode, actor_*.
	 * @return array<string,mixed>
	 */
	private static function assign( array $args ): array {
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$nurse_id = isset( $args['nurse_user_id'] ) ? (int) $args['nurse_user_id'] : 0;
		$mode     = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : 'manual';
		$actor    = isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : 0;
		$role     = isset( $args['actor_role'] ) ? (string) $args['actor_role'] : 'system';

		if ( $order_id <= 0 || $nurse_id <= 0 ) {
			throw MNS_Error::validation( 'تخصیص نامعتبر است.' );
		}

		$pending_hooks = array();

		$row = MNS_DB::tx(
			static function () use ( $order_id, $nurse_id, $mode, $actor, $role, &$pending_hooks ) {
				global $wpdb;

				MNS_DB::lock_row( MNS_DB::table( 'nurses' ), 'user_id', $nurse_id );

				$order = self::get_order( $order_id );
				if ( ! $order || MNS_Order_Status::SUBMITTED !== $order['status'] ) {
					throw MNS_Error::bad_state();
				}

				$scope = array(
					'service_id'    => (int) $order['service_id'],
					'province_code' => (string) $order['province_code'],
					'city_id'       => (int) $order['city_id'],
					'district_no'   => (int) $order['district_no'],
					'service_date'  => (string) $order['service_date'],
					'slot_id'       => (string) $order['slot_id'],
					'gender_pref'   => (string) $order['gender_pref'],
				);
				$eligible_ids = array_map(
					static function ( $r ) {
						return (int) $r['user_id'];
					},
					MNS_Eligibility::nurses_for( $scope )
				);
				if ( ! in_array( $nurse_id, $eligible_ids, true ) ) {
					throw MNS_Error::conflict( 'ERR_NO_NURSE', 'پرستار برای این سفارش واجد شرایط نیست.' );
				}

				$now   = MNS_Time::now_utc();
				$table = MNS_DB::table( 'orders' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = %s, nurse_user_id = %d, assigned_at = %s, assignment_mode = %s, updated_at = %s
						WHERE id = %d AND status = %s AND nurse_user_id IS NULL",
						MNS_Order_Status::ASSIGNED,
						$nurse_id,
						$now,
						$mode,
						$now,
						$order_id,
						MNS_Order_Status::SUBMITTED
					)
				);
				if ( 1 !== (int) $updated ) {
					throw MNS_Error::bad_state();
				}

				self::write_log( $order_id, $actor, $role, 'assign', MNS_Order_Status::SUBMITTED, MNS_Order_Status::ASSIGNED, array( 'nurse_user_id' => $nurse_id ) );
				$fresh = self::get_order( $order_id );
				$pending_hooks[] = array(
					'order_id' => $order_id,
					'action'   => 'assign',
					'from'     => MNS_Order_Status::SUBMITTED,
					'to'       => MNS_Order_Status::ASSIGNED,
					'order'    => $fresh,
				);
				return $fresh;
			}
		);

		self::fire_hooks( $pending_hooks );
		return $row;
	}

	/**
	 * assigned → assigned (new nurse).
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private static function reassign( array $args ): array {
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$nurse_id = isset( $args['nurse_user_id'] ) ? (int) $args['nurse_user_id'] : 0;
		$actor    = isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : 0;
		$role     = isset( $args['actor_role'] ) ? (string) $args['actor_role'] : 'admin';

		if ( $order_id <= 0 || $nurse_id <= 0 ) {
			throw MNS_Error::validation( 'تغییر پرستار نامعتبر است.' );
		}

		$pending_hooks = array();

		$row = MNS_DB::tx(
			static function () use ( $order_id, $nurse_id, $actor, $role, &$pending_hooks ) {
				global $wpdb;

				MNS_DB::lock_row( MNS_DB::table( 'nurses' ), 'user_id', $nurse_id );
				$order = self::get_order( $order_id );
				if ( ! $order || MNS_Order_Status::ASSIGNED !== $order['status'] ) {
					throw MNS_Error::bad_state();
				}

				$prev = isset( $order['nurse_user_id'] ) ? (int) $order['nurse_user_id'] : 0;
				$now  = MNS_Time::now_utc();
				$table = MNS_DB::table( 'orders' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET nurse_user_id = %d, assigned_at = %s, assignment_mode = %s, updated_at = %s
						WHERE id = %d AND status = %s",
						$nurse_id,
						$now,
						'manual',
						$now,
						$order_id,
						MNS_Order_Status::ASSIGNED
					)
				);
				if ( 1 !== (int) $updated ) {
					throw MNS_Error::bad_state();
				}

				self::write_log(
					$order_id,
					$actor,
					$role,
					'reassign',
					MNS_Order_Status::ASSIGNED,
					MNS_Order_Status::ASSIGNED,
					array(
						'from_nurse' => $prev,
						'to_nurse'   => $nurse_id,
					)
				);

				$fresh = self::get_order( $order_id );
				$pending_hooks[] = array(
					'order_id' => $order_id,
					'action'   => 'reassign',
					'from'     => MNS_Order_Status::ASSIGNED,
					'to'       => MNS_Order_Status::ASSIGNED,
					'order'    => $fresh,
				);
				return $fresh;
			}
		);

		self::fire_hooks( $pending_hooks );
		return $row;
	}

	/**
	 * Patient cancel: submitted|assigned → cancelled.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private static function cancel( array $args ): array {
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$account  = isset( $args['account_user_id'] ) ? (int) $args['account_user_id'] : 0;
		$reason   = isset( $args['reason'] ) ? sanitize_textarea_field( (string) $args['reason'] ) : '';
		$actor    = isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : $account;
		$role     = 'patient';

		if ( $order_id <= 0 || $account <= 0 ) {
			throw MNS_Error::validation( 'لغو نامعتبر است.' );
		}

		$pending_hooks = array();

		$row = MNS_DB::tx(
			static function () use ( $order_id, $account, $reason, $actor, $role, &$pending_hooks ) {
				global $wpdb;
				$order = self::get_order( $order_id );
				if ( ! $order || (int) $order['account_user_id'] !== $account ) {
					throw MNS_Error::not_found();
				}
				$from = (string) $order['status'];
				if ( ! in_array( $from, array( MNS_Order_Status::SUBMITTED, MNS_Order_Status::ASSIGNED ), true ) ) {
					throw MNS_Error::bad_state();
				}

				$now   = MNS_Time::now_utc();
				$table = MNS_DB::table( 'orders' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = %s, cancelled_at = %s, cancelled_by = %s, cancel_reason = %s, updated_at = %s
						WHERE id = %d AND status IN ('submitted','assigned')",
						MNS_Order_Status::CANCELLED,
						$now,
						'patient',
						$reason,
						$now,
						$order_id
					)
				);
				if ( 1 !== (int) $updated ) {
					throw MNS_Error::bad_state();
				}

				self::write_log( $order_id, $actor, $role, 'cancel', $from, MNS_Order_Status::CANCELLED, array( 'reason' => $reason ) );
				$fresh = self::get_order( $order_id );
				$pending_hooks[] = array(
					'order_id' => $order_id,
					'action'   => 'cancel',
					'from'     => $from,
					'to'       => MNS_Order_Status::CANCELLED,
					'order'    => $fresh,
				);
				return $fresh;
			}
		);

		self::fire_hooks( $pending_hooks );
		return $row;
	}

	/**
	 * Admin cancel from early/mid states.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private static function cancel_admin( array $args ): array {
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$reason   = isset( $args['reason'] ) ? sanitize_textarea_field( (string) $args['reason'] ) : '';
		$counts   = ! empty( $args['cancel_counts_for_nurse'] ) ? 1 : 0;
		$actor    = isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : 0;
		$role     = 'admin';

		if ( $order_id <= 0 || '' === $reason ) {
			throw MNS_Error::validation( 'دلیل لغو الزامی است.', 'reason' );
		}

		$allowed_from = array(
			MNS_Order_Status::SUBMITTED,
			MNS_Order_Status::ASSIGNED,
			MNS_Order_Status::IN_PROGRESS,
			MNS_Order_Status::SERVICE_ENDED,
			MNS_Order_Status::NEEDS_REVIEW,
		);

		$pending_hooks = array();

		$row = MNS_DB::tx(
			static function () use ( $order_id, $reason, $counts, $actor, $role, $allowed_from, &$pending_hooks ) {
				global $wpdb;
				$order = self::get_order( $order_id );
				if ( ! $order ) {
					throw MNS_Error::not_found();
				}
				$from = (string) $order['status'];
				if ( ! in_array( $from, $allowed_from, true ) ) {
					throw MNS_Error::bad_state();
				}

				$now   = MNS_Time::now_utc();
				$table = MNS_DB::table( 'orders' );
				$in    = "'" . implode( "','", array_map( 'esc_sql', $allowed_from ) ) . "'";
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = %s, cancelled_at = %s, cancelled_by = %s, cancel_reason = %s, cancel_counts_for_nurse = %d, updated_at = %s
						WHERE id = %d AND status IN ({$in})",
						MNS_Order_Status::CANCELLED,
						$now,
						'admin',
						$reason,
						$counts,
						$now,
						$order_id
					)
				);
				if ( 1 !== (int) $updated ) {
					throw MNS_Error::bad_state();
				}

				self::write_log( $order_id, $actor, $role, 'cancel_admin', $from, MNS_Order_Status::CANCELLED, array( 'reason' => $reason ) );
				$fresh = self::get_order( $order_id );
				$pending_hooks[] = array(
					'order_id' => $order_id,
					'action'   => 'cancel_admin',
					'from'     => $from,
					'to'       => MNS_Order_Status::CANCELLED,
					'order'    => $fresh,
				);
				return $fresh;
			}
		);

		self::fire_hooks( $pending_hooks );
		return $row;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_order( int $order_id ): ?array {
		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 */
	private static function write_log( int $order_id, int $actor, string $role, string $action, string $from, string $to, array $payload = array() ): void {
		global $wpdb;
		$wpdb->insert(
			MNS_DB::table( 'order_log' ),
			array(
				'order_id'      => $order_id,
				'actor_user_id' => $actor,
				'actor_role'    => $role,
				'action'        => $action,
				'from_status'   => '' === $from ? null : $from,
				'to_status'     => '' === $to ? null : $to,
				'payload'       => wp_json_encode( $payload ),
				'created_at'    => MNS_Time::now_utc(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fire mns_order_transitioned AFTER commit.
	 *
	 * @param array<int,array<string,mixed>> $hooks Hooks queued inside tx.
	 */
	private static function fire_hooks( array $hooks ): void {
		foreach ( $hooks as $h ) {
			/**
			 * After a successful order transition (post-commit).
			 *
			 * @param array<string,mixed> $h Hook payload.
			 */
			do_action( 'mns_order_transitioned', $h );
		}
	}

	private static function generate_code(): string {
		$digits = (int) MNS_Settings::get( 'order_code_digits' );
		if ( $digits < 5 ) {
			$digits = 5;
		}
		$max = (int) str_repeat( '9', $digits );
		$min = (int) str_pad( '1', $digits, '0' );

		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		for ( $i = 0; $i < 10; $i++ ) {
			$code = (string) random_int( $min, $max );
			$code = str_pad( $code, $digits, '0', STR_PAD_LEFT );
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT 1 FROM {$table} WHERE code = %s", $code ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( null === $exists ) {
				return $code;
			}
		}
		// Fallback 6-digit.
		return (string) random_int( 100000, 999999 );
	}
}
