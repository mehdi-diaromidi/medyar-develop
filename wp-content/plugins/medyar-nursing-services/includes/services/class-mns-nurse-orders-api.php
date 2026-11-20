<?php
/**
 * Nurse order list/detail whitelist (T-3.7 / API-NOR-1/2 / R-PRV-1/2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Orders_Api {

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{data:array,meta:array}
	 */
	public static function list_for( int $nurse_user_id, array $params ): array {
		global $wpdb;
		$tab = isset( $params['tab'] ) ? sanitize_key( (string) $params['tab'] ) : 'upcoming';
		$p   = max( 1, isset( $params['p'] ) ? (int) $params['p'] : 1 );
		$per = min( 50, max( 1, isset( $params['per'] ) ? (int) $params['per'] : 10 ) );
		$ot  = MNS_DB::table( 'orders' );

		$where = 'nurse_user_id = %d';
		$args  = array( $nurse_user_id );
		if ( 'upcoming' === $tab || 'pending' === $tab ) {
			$where .= " AND status IN ('assigned','in_progress')";
		} elseif ( 'done' === $tab ) {
			$where .= " AND status IN ('service_ended','needs_review','completed','cancelled')";
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ot} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$offset = ( $p - 1 ) * $per;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$ot} WHERE {$where} ORDER BY service_date ASC, slot_id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = self::list_item( $row );
			}
		}
		return array(
			'data' => array( 'items' => $items ),
			'meta' => array(
				'total' => $total,
				'pages' => (int) ceil( max( 1, $total ) / $per ),
				'per'   => $per,
				'p'     => $p,
			),
		);
	}

	/**
	 * @param array<string,mixed> $row Order row.
	 * @return array<string,mixed>
	 */
	private static function list_item( array $row ): array {
		$svc    = MNS_Services::get( (int) $row['service_id'] );
		$status = (string) $row['status'];
		$tab    = 'upcoming';
		if ( in_array( $status, array( 'service_ended', 'needs_review' ), true ) ) {
			$tab = 'pending';
		} elseif ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			$tab = 'done';
		} elseif ( in_array( $status, array( 'assigned', 'in_progress' ), true ) ) {
			$tab = 'upcoming';
		} else {
			$tab = 'done';
		}
		$pat_name = '';
		if ( ! empty( $row['patient_id'] ) ) {
			global $wpdb;
			$pt = MNS_DB::table( 'patients' );
			$pat_name = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT name FROM {$pt} WHERE id = %d LIMIT 1", (int) $row['patient_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		$area = 'منطقهٔ ' . MNS_Format::digits( (string) (int) $row['district_no'] );
		$url  = add_query_arg(
			'mns_order',
			rawurlencode( (string) $row['code'] ),
			MNS_Pages::panel_url( 'nurse', 'order-view' )
		);
		$item = array(
			'code'     => (string) $row['code'],
			'tracking' => (string) $row['code'],
			'service'  => $svc ? (string) $svc['name'] : '',
			'when'     => (string) $row['service_date'] . ' · ' . (string) $row['slot_id'],
			'status'   => $status,
			'tab'      => $tab,
			'patient'  => $pat_name,
			'area'     => $area,
			'url'      => $url,
			'share'    => 0,
			'share_state' => '',
		);
		if ( in_array( $status, array( 'assigned', 'in_progress' ), true ) ) {
			$item['phone'] = (string) ( $row['contact_phone'] ?? '' );
		}
		return $item;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_for( int $nurse_user_id, string $code ): array {
		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s LIMIT 1", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || (int) $row['nurse_user_id'] !== $nurse_user_id ) {
			throw MNS_Error::not_found( 'سفارش یافت نشد.' );
		}
		$status = (string) $row['status'];
		$show_contact = in_array( $status, array( 'assigned', 'in_progress' ), true );
		$svc = MNS_Services::get( (int) $row['service_id'] );
		$raw_ess = json_decode( (string) ( $row['essentials'] ?? '{}' ), true );
		if ( ! is_array( $raw_ess ) ) {
			$raw_ess = array();
		}
		$allowed = array( 'serum_type' => true, 'allergy' => true, 'allergy_name' => true, 'rx_file_id' => true, 'vein_access' => true );
		if ( $svc && isset( $svc['config']['order_fields'] ) && is_array( $svc['config']['order_fields'] ) ) {
			foreach ( array_keys( $svc['config']['order_fields'] ) as $fk ) {
				$key = sanitize_key( (string) $fk );
				if ( '' !== $key && ! in_array( $key, array( 'serum_types', 'rx_types' ), true ) ) {
					$allowed[ $key ] = true;
				}
			}
		}
		$essentials = array();
		foreach ( $raw_ess as $ek => $ev ) {
			$ek = sanitize_key( (string) $ek );
			if ( isset( $allowed[ $ek ] ) && is_scalar( $ev ) ) {
				$essentials[ $ek ] = $ev;
			}
		}
		$oid    = (int) $row['id'];
		$groups = class_exists( 'MNS_Order_Items', true ) ? MNS_Order_Items::checklist_groups( $oid ) : array();
		$names  = array();
		foreach ( $groups as $g ) {
			$names[] = (string) $g['name'] . ( ! empty( $g['is_extra'] ) ? ' (اضافه)' : '' );
		}
		$out = array(
			'code'              => (string) $row['code'],
			'status'            => $status,
			'service'           => $svc ? $svc['name'] : '',
			'services'          => $names,
			'checklist_groups'  => $groups,
			'when'              => (string) $row['service_date'] . ' · ' . (string) $row['slot_id'],
			'notes'             => (string) ( $row['notes'] ?? '' ),
			'essentials'        => $essentials,
			'province'          => (string) $row['province_code'],
			'city_id'           => (int) $row['city_id'],
			'district'          => (int) $row['district_no'],
			'date'              => (string) $row['service_date'],
			'slot_id'           => (string) $row['slot_id'],
		);
		if ( $show_contact ) {
			$out['address']       = (string) $row['address'];
			$out['contact_phone'] = (string) $row['contact_phone'];
			$out['phone']         = (string) $row['contact_phone'];
		}
		// Never expose amounts (R-PRV-2).
		return $out;
	}

	/**
	 * View-model for nurse order-view template (T-E.3).
	 *
	 * @return array<string,mixed>
	 */
	public static function view_model_for( int $nurse_user_id, string $code ): array {
		$api  = self::get_for( $nurse_user_id, $code );
		$slot = (string) ( $api['slot_id'] ?? '' );
		$from = 0;
		$to   = 0;
		if ( preg_match( '/^(\d+)-(\d+)$/', $slot, $m ) ) {
			$from = (int) $m[1];
			$to   = (int) $m[2];
		}
		$start_ts = class_exists( 'MNS_Time', true )
			? MNS_Time::slot_start_ts( (string) ( $api['date'] ?? '' ), $slot )
			: 0;
		$can_start = $start_ts > 0 && ( time() >= ( $start_ts - HOUR_IN_SECONDS ) );
		$ess_lines = array();
		foreach ( (array) ( $api['essentials'] ?? array() ) as $k => $v ) {
			if ( 'rx_file_id' === $k || ! is_scalar( $v ) || '' === (string) $v ) {
				continue;
			}
			$ess_lines[ (string) $k ] = $v;
		}
		$status = (string) $api['status'];
		$steps  = class_exists( 'MNS_Execution', true )
			? MNS_Execution::steps_for( self::id_by_code( $code ) )
			: array(
				'checklist' => array( 'state' => 'locked' ),
				'drug'      => array( 'state' => 'locked' ),
				'report'    => array( 'state' => 'locked' ),
			);
		if ( in_array( $status, array( 'in_progress', 'medication' ), true ) ) {
			if ( ( $steps['checklist']['state'] ?? '' ) === 'locked' ) {
				$steps['checklist']['state'] = 'todo';
			}
			if ( ( $steps['drug']['state'] ?? '' ) === 'locked' ) {
				$steps['drug']['state'] = 'todo';
			}
		} elseif ( in_array( $status, array( 'service_ended', 'needs_review', 'completed', 'cancelled' ), true ) ) {
			foreach ( array( 'checklist', 'drug', 'report' ) as $sk ) {
				if ( ( $steps[ $sk ]['state'] ?? '' ) === 'locked' ) {
					$steps[ $sk ]['state'] = 'todo';
				}
			}
		}
		return array(
			'status'            => $status,
			'service'           => (string) $api['service'],
			'services'          => isset( $api['services'] ) && is_array( $api['services'] ) ? $api['services'] : array( (string) $api['service'] ),
			'checklist_groups'  => isset( $api['checklist_groups'] ) && is_array( $api['checklist_groups'] ) ? $api['checklist_groups'] : array(),
			'tracking'          => (string) $api['code'],
			'date'              => (string) ( $api['date'] ?? '' ),
			'from'              => $from,
			'to'                => $to,
			'phone'             => (string) ( $api['phone'] ?? '' ),
			'address'           => (string) ( $api['address'] ?? '' ),
			'essentials'        => $ess_lines,
			'needs_drug'        => ! empty( $ess_lines ) && ( isset( $ess_lines['allergy'] ) || isset( $api['essentials']['rx_file_id'] ) ),
			'restricted'        => false,
			'finance'           => array(),
			'session'           => array(),
			'timeline'          => array(),
			'steps'             => $steps,
			'can_start'         => $can_start && 'assigned' === $status,
			'start_ts'          => $start_ts,
			'urgent'            => false,
			'changed'           => false,
			'cancelled_by'      => '',
		);
	}

	private static function id_by_code( string $code ): int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'orders' ) . ' WHERE code = %s LIMIT 1', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $id;
	}
}
