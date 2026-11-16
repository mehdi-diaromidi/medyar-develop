<?php
/**
 * WP_List_Table for nurse applications (T-1.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MNS_Nurses_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mns_nurse',
				'plural'   => 'mns_nurses',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'name'        => __( 'نام', 'medyar-nursing-services' ),
			'status'      => __( 'وضعیت', 'medyar-nursing-services' ),
			'gender'      => __( 'جنسیت', 'medyar-nursing-services' ),
			'national_id' => __( 'کد ملی', 'medyar-nursing-services' ),
			'applied_at'  => __( 'زمان درخواست', 'medyar-nursing-services' ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;
		$table  = MNS_DB::table( 'nurses' );
		$status = isset( $_REQUEST['mns_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['mns_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q      = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$where = '1=1';
		$args  = array();
		if ( $status ) {
			$where .= ' AND n.status = %s';
			$args[] = $status;
		}
		if ( '' !== $q ) {
			$where .= ' AND ( n.national_id LIKE %s OR n.license_no LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s )';
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		$sql = "SELECT n.*, u.display_name, u.user_email FROM {$table} n LEFT JOIN {$wpdb->users} u ON u.ID = n.user_id WHERE {$where} ORDER BY n.applied_at DESC LIMIT 100";
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->items = $wpdb->get_results( $sql, ARRAY_A );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * @param array<string,mixed> $item Item.
	 */
	public function column_name( $item ) {
		$name = isset( $item['display_name'] ) ? (string) $item['display_name'] : ( 'User #' . (int) $item['user_id'] );
		$url  = admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . (int) $item['user_id'] );
		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
	}

	/**
	 * @param array<string,mixed> $item Item.
	 * @param string              $col  Column.
	 */
	public function column_default( $item, $col ) {
		return isset( $item[ $col ] ) ? esc_html( (string) $item[ $col ] ) : '';
	}
}
