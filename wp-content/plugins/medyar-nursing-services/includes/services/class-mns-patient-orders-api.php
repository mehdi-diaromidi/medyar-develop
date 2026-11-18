<?php
/**
 * Patient-facing order list/detail/cancel/patch (T-3.6 / API-ORD-2..4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Patient_Orders_Api {

	/** @var array<string,string> */
	private static $labels = array(
		'submitted'     => 'در انتظار تخصیص',
		'assigned'      => 'پرستار مشخص شد',
		'in_progress'   => 'در حال انجام',
		'service_ended' => 'پایان خدمت',
		'needs_review'  => 'در حال بررسی',
		'completed'     => 'انجام‌شده',
		'cancelled'     => 'لغو شده',
	);

	/**
	 * @param array<string,mixed> $params Query.
	 * @return array{data:array,meta:array}
	 */
	public static function list_for( int $account_user_id, array $params ): array {
		global $wpdb;
		$tab = isset( $params['tab'] ) ? sanitize_key( (string) $params['tab'] ) : 'all';
		$p   = max( 1, isset( $params['p'] ) ? (int) $params['p'] : 1 );
		$per = min( 50, max( 1, isset( $params['per'] ) ? (int) $params['per'] : 10 ) );
		$ot  = MNS_DB::table( 'orders' );

		$where = 'account_user_id = %d';
		$args  = array( $account_user_id );
		if ( 'active' === $tab ) {
			$where .= " AND status IN ('submitted','assigned','in_progress','service_ended','needs_review')";
		} elseif ( 'done' === $tab ) {
			$where .= " AND status = 'completed'";
		} elseif ( 'cancelled' === $tab ) {
			$where .= " AND status = 'cancelled'";
		} elseif ( 'unpaid' === $tab ) {
			$where .= " AND status IN ('service_ended','needs_review','completed')"; // placeholder until invoices.
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ot} WHERE {$where}", $args ) );
		$offset = ( $p - 1 ) * $per;
		$args2  = array_merge( $args, array( $per, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ot} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $args2 ), ARRAY_A );
		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = self::list_item( $row );
			}
		}

		$tabs = array();
		foreach ( array( 'all', 'active', 'unpaid', 'done', 'cancelled' ) as $t ) {
			$tabs[ $t ] = self::count_tab( $account_user_id, $t );
		}

		return array(
			'data' => array(
				'items' => $items,
				'tabs'  => $tabs,
			),
			'meta' => array(
				'total' => $total,
				'pages' => (int) ceil( max( 1, $total ) / $per ),
				'per'   => $per,
				'p'     => $p,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_for( int $account_user_id, string $code ): array {
		$row = self::owned_row( $account_user_id, $code );
		$svc = MNS_Services::get( (int) $row['service_id'] );
		$pat = null;
		try {
			$pat = MNS_Patients::get_owned( (int) $row['patient_id'], $account_user_id );
		} catch ( MNS_Error $e ) {
			$pat = null;
		}
		$nurse_name = '';
		$nurse_photo = null;
		if ( ! empty( $row['nurse_user_id'] ) ) {
			$u = get_userdata( (int) $row['nurse_user_id'] );
			$nurse_name = $u ? $u->display_name : '';
			$nr = MNS_Nurse_Access::nurse_row( (int) $row['nurse_user_id'] );
			if ( $nr && ! empty( $nr['photo_approved'] ) && ! empty( $nr['profile_photo_file_id'] ) ) {
				$nurse_photo = rest_url( MNS_REST::NS . '/files/' . (int) $nr['profile_photo_file_id'] );
			}
		}
		$status = (string) $row['status'];
		return array(
			'code'          => (string) $row['code'],
			'status'        => $status,
			'status_label'  => self::$labels[ $status ] ?? $status,
			'tone'          => self::tone( $status ),
			'service'       => $svc ? $svc['name'] : '',
			'patient'       => $pat ? $pat['name'] : '',
			'when'          => (string) $row['service_date'] . ' · ' . (string) $row['slot_id'],
			'address'       => (string) $row['address'],
			'notes'         => (string) ( $row['notes'] ?? '' ),
			'essentials'    => json_decode( (string) ( $row['essentials'] ?? '{}' ), true ),
			'nurse'         => $nurse_name ? array( 'name' => $nurse_name, 'photo' => $nurse_photo ) : null,
			'can_cancel'    => in_array( $status, array( 'submitted', 'assigned' ), true ),
			'can_edit'      => in_array( $status, array( 'submitted', 'assigned' ), true ),
			'can_repick'    => false,
			'timeline'      => self::timeline( (int) $row['id'] ),
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed>
	 */
	public static function patch_for( int $account_user_id, string $code, array $params ): array {
		$row = self::owned_row( $account_user_id, $code );
		if ( ! in_array( (string) $row['status'], array( 'submitted', 'assigned' ), true ) ) {
			throw MNS_Error::bad_state();
		}
		if ( isset( $params['date'] ) || isset( $params['slot'] ) || isset( $params['service_date'] ) || isset( $params['slot_id'] ) ) {
			throw MNS_Error::validation( 'تغییر زمان ممکن نیست؛ لغو و ثبت دوباره کنید.' );
		}
		$fields  = array();
		$formats = array();
		$edits   = array();
		if ( array_key_exists( 'notes', $params ) ) {
			$new = sanitize_textarea_field( (string) $params['notes'] );
			$new = function_exists( 'mb_substr' ) ? mb_substr( $new, 0, 500 ) : substr( $new, 0, 500 );
			$old = (string) ( $row['notes'] ?? '' );
			if ( $new !== $old ) {
				$fields['notes'] = $new;
				$formats[]       = '%s';
				$edits[]         = array( 'field' => 'notes', 'old' => $old, 'new' => $new );
			}
		}
		if ( array_key_exists( 'essentials', $params ) && is_array( $params['essentials'] ) ) {
			$svc = MNS_Services::get( (int) $row['service_id'] );
			if ( $svc ) {
				MNS_Orders::validate_essentials( $svc, $params['essentials'] );
			}
			$new = wp_json_encode( $params['essentials'] );
			$old = (string) ( $row['essentials'] ?? '' );
			if ( $new !== $old ) {
				$fields['essentials'] = $new;
				$formats[]            = '%s';
				$edits[]              = array( 'field' => 'essentials', 'old' => $old, 'new' => $new );
			}
		}
		if ( empty( $fields ) ) {
			return self::get_for( $account_user_id, $code );
		}
		$fields['updated_at'] = MNS_Time::now_utc();
		$formats[]            = '%s';
		global $wpdb;
		$r = $wpdb->update( MNS_DB::table( 'orders' ), $fields, array( 'id' => (int) $row['id'] ), $formats, array( '%d' ) );
		if ( false === $r ) {
			throw MNS_Error::server();
		}
		foreach ( $edits as $e ) {
			$wpdb->insert(
				MNS_DB::table( 'order_edits' ),
				array(
					'order_id'   => (int) $row['id'],
					'field'      => $e['field'],
					'old_value'  => $e['old'],
					'new_value'  => $e['new'],
					'edited_by'  => $account_user_id,
					'created_at' => MNS_Time::now_utc(),
				)
			);
		}
		if ( ! empty( $row['nurse_user_id'] ) ) {
			MNS_Notifications::add( (int) $row['nurse_user_id'], 'patient_edit', (int) $row['id'] );
		}
		return self::get_for( $account_user_id, $code );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function cancel_for( int $account_user_id, string $code, string $reason = '' ): array {
		$row = self::owned_row( $account_user_id, $code );
		$fresh = MNS_Order_Machine::transition(
			'cancel',
			array(
				'order_id'        => (int) $row['id'],
				'account_user_id' => $account_user_id,
				'reason'        => $reason,
				'actor_user_id' => $account_user_id,
				'actor_role'    => 'patient',
			)
		);
		return self::get_for( $account_user_id, (string) $fresh['code'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function owned_row( int $account_user_id, string $code ): array {
		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s LIMIT 1", $code ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) $row['account_user_id'] !== $account_user_id ) {
			throw MNS_Error::not_found( 'سفارش یافت نشد.' );
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function list_item( array $row ): array {
		$svc = MNS_Services::get( (int) $row['service_id'] );
		$status = (string) $row['status'];
		$nurse_name = '';
		if ( ! empty( $row['nurse_user_id'] ) ) {
			$u = get_userdata( (int) $row['nurse_user_id'] );
			$nurse_name = $u ? $u->display_name : '';
		}
		$patient_id = (int) ( $row['patient_id'] ?? 0 );
		$patient_name = '';
		if ( $patient_id > 0 ) {
			try {
				$pat = MNS_Patients::get_owned( $patient_id, (int) $row['account_user_id'] );
				$patient_name = (string) ( $pat['name'] ?? '' );
			} catch ( \Throwable $e ) {
				$patient_name = '';
			}
		}
		return array(
			'code'         => (string) $row['code'],
			'service'      => $svc ? $svc['name'] : '',
			'patient_id'   => $patient_id,
			'patient'      => $patient_name,
			'patient_name' => $patient_name,
			'when'         => (string) $row['service_date'] . ' · ' . (string) $row['slot_id'],
			'status'       => $status,
			'status_label' => self::$labels[ $status ] ?? $status,
			'tone'         => self::tone( $status ),
			'nurse_name'   => $nurse_name,
			'nurse'        => $nurse_name ? array( 'name' => $nurse_name ) : null,
			'amount'       => null,
			'url'          => add_query_arg(
				'mns_order',
				rawurlencode( (string) $row['code'] ),
				MNS_Pages::panel_url( 'patient', 'order-view' )
			),
			'tracking'     => (string) $row['code'],
		);
	}

	/**
	 * View-model for patient order-view template (T-E.1).
	 *
	 * @return array<string,mixed>
	 */
	public static function view_model_for( int $account_user_id, string $code ): array {
		$api = self::get_for( $account_user_id, $code );
		$row = self::owned_row( $account_user_id, $code );
		$ess_lines = array();
		$ess = isset( $api['essentials'] ) && is_array( $api['essentials'] ) ? $api['essentials'] : array();
		$labels = array(
			'serum_type'  => 'نوع سرم',
			'allergy'     => 'حساسیت',
			'allergy_name'=> 'نام حساسیت',
			'vein_access' => 'دسترسی رگ',
		);
		foreach ( $ess as $k => $v ) {
			if ( 'rx_file_id' === $k || ! is_scalar( $v ) || '' === (string) $v ) {
				continue;
			}
			$lab = $labels[ $k ] ?? (string) $k;
			$val = (string) $v;
			if ( 'allergy' === $k ) {
				$val = ( 'yes' === $val || '1' === $val ) ? 'دارد' : ( ( 'no' === $val || '0' === $val ) ? 'ندارد' : $val );
			}
			$ess_lines[] = $lab . ': ' . $val;
		}
		return array(
			'status'     => (string) $api['status'],
			'service'    => (string) $api['service'],
			'patient'    => (string) $api['patient'],
			'when'       => (string) $api['when'],
			'address'    => (string) ( $api['address'] ?? '' ),
			'phone'      => (string) ( $row['contact_phone'] ?? '' ),
			'essentials' => $ess_lines,
			'nurse'      => $api['nurse'],
			'can_cancel' => ! empty( $api['can_cancel'] ),
			'can_edit'   => ! empty( $api['can_edit'] ),
			'can_repick' => false,
			'tracking'   => (string) $api['code'],
			'invoice'    => null,
			'report'     => '',
			'medication' => array( 'enabled' => false ),
			'recurring'  => null,
		);
	}

	private static function tone( string $status ): string {
		if ( in_array( $status, array( 'cancelled' ), true ) ) {
			return 'warn';
		}
		if ( in_array( $status, array( 'completed' ), true ) ) {
			return 'ok';
		}
		return 'ok';
	}

	private static function count_tab( int $uid, string $tab ): int {
		global $wpdb;
		$ot    = MNS_DB::table( 'orders' );
		$where = 'account_user_id = %d';
		$args  = array( $uid );
		if ( 'active' === $tab ) {
			$where .= " AND status IN ('submitted','assigned','in_progress','service_ended','needs_review')";
		} elseif ( 'done' === $tab ) {
			$where .= " AND status = 'completed'";
		} elseif ( 'cancelled' === $tab ) {
			$where .= " AND status = 'cancelled'";
		} elseif ( 'unpaid' === $tab ) {
			$where .= " AND status IN ('service_ended','needs_review','completed')";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ot} WHERE {$where}", $args ) );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private static function timeline( int $order_id ): array {
		global $wpdb;
		$table = MNS_DB::table( 'order_log' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT action, to_status, created_at FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = array(
					'action' => (string) $r['action'],
					'at'     => (string) $r['created_at'],
				);
			}
		}
		return $out;
	}
}
