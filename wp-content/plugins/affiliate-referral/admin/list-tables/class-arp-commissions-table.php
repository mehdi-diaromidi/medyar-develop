<?php
/**
 * Admin commissions list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Arp_Commissions_Table extends WP_List_Table {

	private const STATES = array( 'approved', 'paid', 'reversed' );

	/** @var array<string,mixed> */
	private $filters;

	/** @var array<string,array{count:int,total:float}> */
	private $summary = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'commission',
				'plural'   => 'commissions',
				'ajax'     => false,
			)
		);
		$this->filters = self::filters_from_request();
		$this->summary = Arp_Commissions_Repo::summary_by_state( $this->filters );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$range = Arp_Ui::date_range_from_request();
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		return array(
			'state'             => in_array( $state, self::STATES, true ) ? $state : '',
			'service_id'        => isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0,
			'affiliate_user_id' => isset( $_GET['affiliate_user_id'] ) ? absint( $_GET['affiliate_user_id'] ) : 0,
			'date_from'         => $range['date_from'],
			'date_to'           => $range['date_to'],
			'from'              => $range['from'],
			'to'                => $range['to'],
		);
		// phpcs:enable
	}

	/**
	 * @return array<string,array{count:int,total:float}>
	 */
	public function summary(): array {
		return $this->summary;
	}

	public function get_columns(): array {
		$cols = array(
			'referral'   => 'معرفی',
			'affiliate'  => 'همکار',
			'service'    => 'خدمت',
			'amount'     => 'مبلغ',
			'state'      => 'وضعیت',
			'tracking'   => 'کد پیگیری',
			'created_at' => 'تاریخ',
		);
		if ( Arp_Wallet_Bridge::available() ) {
			$cols = array_merge(
				array_slice( $cols, 0, 5, true ),
				array( 'wallet' => 'کیف پول' ),
				array_slice( $cols, 5, null, true )
			);
		}
		return $cols;
	}

	protected function get_primary_column_name(): string {
		return 'referral';
	}

	protected function get_views(): array {
		$current = (string) $this->filters['state'];
		$base    = Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'commissions' ) );
		$all     = array_sum( array_column( $this->summary, 'count' ) );

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>همه <span class="count">(%3$s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html( Arp_Format::fa( number_format_i18n( $all ) ) )
			),
		);
		foreach ( self::STATES as $state ) {
			$views[ $state ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'state', $state, $base ) ),
				$current === $state ? ' class="current" aria-current="page"' : '',
				esc_html( Arp_Ui::commission_label( $state ) ),
				esc_html( Arp_Format::fa( number_format_i18n( $this->summary[ $state ]['count'] ?? 0 ) ) )
			);
		}
		return $views;
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'arp_commissions_per_page', 20 );
		$total    = Arp_Commissions_Repo::count( $this->filters );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $this->get_pagenum(), $pages );

		$this->items = Arp_Commissions_Repo::query( $this->filters + array( 'limit' => $per_page, 'offset' => ( $paged - 1 ) * $per_page ) );
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
		echo '<input type="hidden" name="tab" value="commissions" />';
		if ( $f['state'] ) {
			printf( '<input type="hidden" name="state" value="%s" />', esc_attr( $f['state'] ) );
		}
		Arp_Admin_Ui::row_select( 'service_id', Arp_Services_Repo::all(), (int) $f['service_id'], 'همه خدمات' );
		Arp_Admin_Ui::user_select( 'affiliate_user_id', Arp_Commissions_Repo::affiliate_ids(), (int) $f['affiliate_user_id'], 'همه همکاران' );
		Arp_Admin_Ui::date_input( 'arp_from', $f['from'], 'از تاریخ', 'از ۱۴۰۵/۰۱/۰۱' );
		Arp_Admin_Ui::date_input( 'arp_to', $f['to'], 'تا تاریخ', 'تا ۱۴۰۵/۱۲/۲۹' );
		submit_button( 'فیلتر', '', 'filter_action', false );
		echo '</div>';

		$export = array_filter(
			array(
				'tab'               => 'commissions',
				'arp_export'        => 'commissions',
				'_wpnonce'          => wp_create_nonce( 'arp_export' ),
				'state'             => $f['state'],
				'service_id'        => $f['service_id'],
				'affiliate_user_id' => $f['affiliate_user_id'],
				'arp_from'          => $f['from'],
				'arp_to'            => $f['to'],
			)
		);
		printf(
			'<div class="alignleft actions"><a class="button" href="%s"><span class="dashicons dashicons-download" aria-hidden="true"></span> خروجی CSV</a></div>',
			esc_url( Arp_Admin_Ui::url( 'arp-finance', $export ) )
		);
	}

	public function no_items(): void {
		echo 'پورسانتی با این مشخصات پیدا نشد.';
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_referral( $item ): string {
		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong><span class="arp-muted">معرفی #%3$s</span>',
			esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'action' => 'edit', 'id' => (int) $item['referral_id'] ) ) ),
			esc_html( (string) ( $item['patient_name'] ?? '—' ) ),
			esc_html( Arp_Format::fa( (int) $item['referral_id'] ) )
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_affiliate( $item ): string {
		$uid = (int) $item['affiliate_user_id'];
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'commissions', 'affiliate_user_id' => $uid ) ) ),
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
	protected function column_amount( $item ): string {
		$out = '<strong>' . esc_html( Arp_Admin_Ui::money( $item['amount'] ) ) . '</strong>';
		if ( null !== $item['percentage_applied'] ) {
			$out .= '<br /><span class="arp-muted">' . esc_html( Arp_Format::fa( (float) $item['percentage_applied'] ) ) . '٪ راهنما</span>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_state( $item ): string {
		return Arp_Admin_Ui::commission_badge( (string) $item['state'] );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_wallet( $item ): string {
		if ( ! Arp_Wallet_Bridge::available() ) {
			return '<span class="arp-muted">—</span>';
		}
		$cid = (int) $item['id'];
		$tx  = Arp_Wallet_Bridge::get_commission_deposit( $cid );
		if ( ! $tx ) {
			if ( 'reversed' === (string) $item['state'] ) {
				return '<span class="arp-muted">—</span>';
			}
			return '<span class="arp-badge" style="--arp-c:#b45309">بدون واریز</span>';
		}
		$code = (string) ( $tx->transaction_code ?? Arp_Wallet_Bridge::deposit_code( $cid ) );
		$url  = Arp_Wallet_Bridge::transactions_url( $code );
		$status = (string) ( $tx->status ?? '' );
		$label  = 'completed' === $status ? 'واریز شده' : $status;
		return sprintf(
			'<a href="%1$s"><span class="arp-badge" style="--arp-c:#0f766e">%2$s</span></a><br /><code class="arp-muted">%3$s</code>',
			esc_url( $url ),
			esc_html( $label ),
			esc_html( $code )
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_tracking( $item ): string {
		if ( empty( $item['tracking_code'] ) ) {
			return '<span class="arp-muted">—</span>';
		}
		return '<code>' . esc_html( (string) $item['tracking_code'] ) . '</code><br /><span class="arp-muted">' . esc_html( Arp_Admin_Ui::date( (string) $item['paid_at'] ) ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_created_at( $item ): string {
		return esc_html( Arp_Admin_Ui::date( (string) $item['created_at'], true ) );
	}
}
