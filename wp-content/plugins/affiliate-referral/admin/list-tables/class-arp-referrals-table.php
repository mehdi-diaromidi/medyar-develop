<?php
/**
 * Admin referrals list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Arp_Referrals_Table extends WP_List_Table {

	/** @var array<int,array<string,mixed>> id => status row */
	private $statuses = array();

	/** @var array<string,mixed> */
	private $filters;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'referral',
				'plural'   => 'referrals',
				'ajax'     => false,
			)
		);
		foreach ( Arp_Statuses_Repo::all() as $st ) {
			$this->statuses[ (int) $st['id'] ] = $st;
		}
		$this->filters = self::filters_from_request();
	}

	/**
	 * Filters shared by the list and its CSV export.
	 *
	 * @return array<string,mixed>
	 */
	public static function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$range = Arp_Ui::date_range_from_request();
		return array(
			'status_id'         => isset( $_GET['status_id'] ) ? absint( $_GET['status_id'] ) : 0,
			'service_id'        => isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0,
			'affiliate_user_id' => isset( $_GET['affiliate_user_id'] ) ? absint( $_GET['affiliate_user_id'] ) : 0,
			'search'            => isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '',
			'date_from'         => $range['date_from'],
			'date_to'           => $range['date_to'],
			'from'              => $range['from'],
			'to'                => $range['to'],
		);
		// phpcs:enable
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'patient'    => 'بیمار',
			'phone'      => 'تلفن',
			'affiliate'  => 'همکار',
			'service'    => 'خدمت',
			'status'     => 'وضعیت',
			'commission' => 'پورسانت',
			'created_at' => 'تاریخ ثبت',
		);
	}

	protected function get_sortable_columns(): array {
		return array( 'created_at' => array( 'created_at', true ) );
	}

	protected function get_primary_column_name(): string {
		return 'patient';
	}

	protected function get_views(): array {
		$counts  = Arp_Referrals_Repo::count_by_status();
		$current = (int) $this->filters['status_id'];
		$base    = Arp_Admin_Ui::url( 'arp-referrals' );

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>همه <span class="count">(%3$s)</span></a>',
				esc_url( $base ),
				$current ? '' : ' class="current" aria-current="page"',
				esc_html( Arp_Format::fa( number_format_i18n( array_sum( $counts ) ) ) )
			),
		);
		foreach ( $this->statuses as $id => $st ) {
			$views[ 'status-' . $id ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'status_id', $id, $base ) ),
				$current === $id ? ' class="current" aria-current="page"' : '',
				esc_html( (string) $st['name'] ),
				esc_html( Arp_Format::fa( number_format_i18n( $counts[ $id ] ?? 0 ) ) )
			);
		}
		return $views;
	}

	/**
	 * Bulk moves are limited to statuses that never create a commission (those need a per-referral amount).
	 */
	protected function get_bulk_actions(): array {
		$group = array();
		foreach ( $this->statuses as $id => $st ) {
			if ( empty( $st['triggers_commission'] ) ) {
				$group[ 'status_' . $id ] = (string) $st['name'];
			}
		}
		return $group ? array( 'تغییر وضعیت به' => $group ) : array();
	}

	public function process_bulk_action(): void {
		$action = $this->current_action();
		if ( ! $action || 0 !== strpos( $action, 'status_' ) ) {
			return;
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$status_id = absint( substr( $action, 7 ) );
		if ( ! isset( $this->statuses[ $status_id ] ) || ! empty( $this->statuses[ $status_id ]['triggers_commission'] ) ) {
			return;
		}

		$ids  = isset( $_REQUEST['referral'] ) ? array_filter( array_map( 'absint', (array) $_REQUEST['referral'] ) ) : array();
		$done = 0;
		foreach ( $ids as $id ) {
			$before = Arp_Referrals_Repo::get( $id );
			if ( $before && (int) $before['status_id'] !== $status_id && true === Arp_Commission_Engine::on_status_change( $id, $status_id ) ) {
				++$done;
			}
		}

		$back = remove_query_arg( array( 'action', 'action2', 'referral', '_wpnonce', '_wp_http_referer', 'arp_notice', 'arp_count' ), wp_get_referer() ?: Arp_Admin_Ui::url( 'arp-referrals' ) );
		wp_safe_redirect( add_query_arg( array( 'arp_notice' => 'bulk_updated', 'arp_count' => $done ), $back ) );
		exit;
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'arp_referrals_per_page', 20 );
		$args     = $this->filters;
		$args['order'] = ( isset( $_GET['order'] ) && 'asc' === $_GET['order'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$total = Arp_Referrals_Repo::count( $args );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged = min( $this->get_pagenum(), $pages );

		$this->items = Arp_Referrals_Repo::query( $args + array( 'limit' => $per_page, 'offset' => ( $paged - 1 ) * $per_page ) );
		cache_users( array_map( 'intval', array_column( $this->items, 'affiliate_user_id' ) ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$f = $this->filters;
		echo '<div class="alignleft actions arp-filters">';
		if ( $f['status_id'] ) {
			printf( '<input type="hidden" name="status_id" value="%d" />', (int) $f['status_id'] );
		}
		Arp_Admin_Ui::row_select( 'service_id', Arp_Services_Repo::all(), (int) $f['service_id'], 'همه خدمات' );
		Arp_Admin_Ui::user_select( 'affiliate_user_id', Arp_Referrals_Repo::affiliate_ids(), (int) $f['affiliate_user_id'], 'همه همکاران' );
		Arp_Admin_Ui::date_input( 'arp_from', $f['from'], 'از تاریخ', 'از ۱۴۰۵/۰۱/۰۱' );
		Arp_Admin_Ui::date_input( 'arp_to', $f['to'], 'تا تاریخ', 'تا ۱۴۰۵/۱۲/۲۹' );
		submit_button( 'فیلتر', '', 'filter_action', false );
		echo '</div>';

		$export = array_filter(
			array(
				'arp_export'        => 'referrals',
				'_wpnonce'          => wp_create_nonce( 'arp_export' ),
				'status_id'         => $f['status_id'],
				'service_id'        => $f['service_id'],
				'affiliate_user_id' => $f['affiliate_user_id'],
				's'                 => $f['search'],
				'arp_from'          => $f['from'],
				'arp_to'            => $f['to'],
			)
		);
		printf(
			'<div class="alignleft actions"><a class="button" href="%s"><span class="dashicons dashicons-download" aria-hidden="true"></span> خروجی CSV</a></div>',
			esc_url( Arp_Admin_Ui::url( 'arp-referrals', $export ) )
		);
	}

	public function no_items(): void {
		echo 'معرفی‌ای با این مشخصات پیدا نشد.';
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="arp-ref-%1$d">انتخاب %2$s</label><input type="checkbox" id="arp-ref-%1$d" name="referral[]" value="%1$d" />',
			(int) $item['id'],
			esc_html( (string) $item['patient_name'] )
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_patient( $item ): string {
		$edit = Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => (int) $item['id'] ) );
		$out  = sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong><span class="arp-muted">#%3$s</span>',
			esc_url( $edit ),
			esc_html( (string) $item['patient_name'] ),
			esc_html( Arp_Format::fa( (int) $item['id'] ) )
		);
		if ( '' !== (string) $item['description'] ) {
			$out .= '<p class="arp-excerpt">' . esc_html( wp_html_excerpt( (string) $item['description'], 80, '…' ) ) . '</p>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		return $this->row_actions(
			array(
				'edit' => sprintf( '<a href="%s">مدیریت</a>', esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => (int) $item['id'] ) ) ) ),
				'call' => sprintf( '<a href="tel:%s">تماس</a>', esc_attr( (string) $item['patient_phone'] ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_phone( $item ): string {
		return sprintf( '<a href="tel:%1$s" dir="ltr">%2$s</a>', esc_attr( (string) $item['patient_phone'] ), esc_html( Arp_Format::fa( (string) $item['patient_phone'] ) ) );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_affiliate( $item ): string {
		$uid = (int) $item['affiliate_user_id'];
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'affiliate_user_id' => $uid ) ) ),
			esc_html( Arp_Admin_Ui::user_name( $uid ) )
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_service( $item ): string {
		return esc_html( (string) ( $item['service_name'] ?? '—' ) );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_status( $item ): string {
		return Arp_Admin_Ui::status_badge( $item );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_commission( $item ): string {
		if ( null === $item['commission_amount'] ) {
			return '<span class="arp-muted">—</span>';
		}
		return esc_html( Arp_Admin_Ui::money( $item['commission_amount'] ) ) . '<br />' . Arp_Admin_Ui::commission_badge( (string) $item['commission_state'] );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_created_at( $item ): string {
		return sprintf(
			'%1$s<br /><span class="arp-muted">%2$s</span>',
			esc_html( Arp_Admin_Ui::date( (string) $item['created_at'], true ) ),
			esc_html( Arp_Admin_Ui::ago( (string) $item['created_at'] ) )
		);
	}
}
