<?php
/**
 * WP_List_Table for central order monitor (P-22).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MNS_Orders_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mns_order',
				'plural'   => 'mns_orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'code'    => __( 'کد', 'medyar-nursing-services' ),
			'patient' => __( 'بیمار / تماس', 'medyar-nursing-services' ),
			'service' => __( 'خدمت', 'medyar-nursing-services' ),
			'when'    => __( 'زمان خدمت', 'medyar-nursing-services' ),
			'nurse'   => __( 'پرستار', 'medyar-nursing-services' ),
			'status'  => __( 'وضعیت', 'medyar-nursing-services' ),
			'source'  => __( 'منبع', 'medyar-nursing-services' ),
			'created' => __( 'ثبت', 'medyar-nursing-services' ),
		);
	}

	public function prepare_items(): void {
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source = isset( $_REQUEST['source'] ) ? sanitize_key( wp_unslash( $_REQUEST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$s      = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$p      = isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 20;

		$pack = MNS_Admin_Stats::query_orders(
			array(
				'status' => $status,
				'source' => $source,
				's'      => $s,
				'p'      => $p,
				'per'    => $per,
			)
		);
		$this->items = $pack['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => $pack['total'],
				'per_page'    => $per,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * @param array<string,mixed> $item Item.
	 */
	public function column_code( $item ) {
		$url = MNS_Admin_Ui::url(
			MNS_Admin_Menu::SLUG_MONITOR,
			array(
				'action' => 'view',
				'id'     => (int) $item['id'],
			)
		);
		$code = esc_html( (string) $item['code'] );
		$pin  = '';
		if ( 'needs_review' === (string) $item['status'] || ( 'submitted' === (string) $item['status'] && empty( $item['nurse_user_id'] ) ) ) {
			$pin = ' <span class="mns-badge" style="--mns-c:#EA580C">اولویت</span>';
		}
		return '<a class="row-title" href="' . esc_url( $url ) . '" dir="ltr"><strong>' . $code . '</strong></a>' . $pin;
	}

	/**
	 * @param array<string,mixed> $item Item.
	 * @param string              $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'patient':
				return esc_html( (string) ( $item['contact_phone'] ?? '' ) );
			case 'service':
				$svc = MNS_Services::get( (int) $item['service_id'] );
				return esc_html( $svc ? (string) $svc['name'] : '—' );
			case 'when':
				return esc_html( (string) $item['service_date'] . ' · ' . (string) $item['slot_id'] );
			case 'nurse':
				return esc_html( MNS_Admin_Ui::user_name( (int) ( $item['nurse_user_id'] ?? 0 ) ) );
			case 'status':
				return MNS_Admin_Ui::status_badge( (string) $item['status'] );
			case 'source':
				return esc_html( (string) ( $item['source'] ?? 'site' ) );
			case 'created':
				return esc_html( MNS_Admin_Ui::date( (string) $item['created_at'], true ) );
			default:
				return '';
		}
	}

	public function no_items(): void {
		echo esc_html__( 'سفارشی یافت نشد.', 'medyar-nursing-services' );
	}
}
