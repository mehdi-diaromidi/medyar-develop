<?php
/**
 * Admin affiliates list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Arp_Affiliates_Table extends WP_List_Table {

	/** @var array<int,int> */
	private $referral_counts = array();

	/** @var array<int,array{payable:float,paid:float}> */
	private $totals = array();

	/** @var array<int,string> job type id => name */
	private $job_names = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'affiliate',
				'plural'   => 'affiliates',
				'ajax'     => false,
			)
		);
		foreach ( Arp_Job_Types_Repo::all() as $job ) {
			$this->job_names[ (int) $job['id'] ] = (string) $job['name'];
		}
	}

	public function get_columns(): array {
		return array(
			'name'       => 'همکار',
			'mobile'     => 'موبایل',
			'job'        => 'نوع شغل',
			'inviter'    => 'معرف',
			'code'       => 'کد همکاری',
			'referrals'  => 'معرفی‌ها',
			'payable'    => 'موجودی کیف پول',
			'paid'       => 'پورسانت پرداخت‌شده',
			'registered' => 'عضویت',
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'name'       => array( 'display_name', false ),
			'registered' => array( 'registered', true ),
		);
	}

	protected function get_primary_column_name(): string {
		return 'name';
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$per_page   = $this->get_items_per_page( 'arp_affiliates_per_page', 20 );
		$search     = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$job_id     = isset( $_GET['job_type_id'] ) ? absint( $_GET['job_type_id'] ) : 0;
		$inviter_id = isset( $_GET['inviter_user_id'] ) ? absint( $_GET['inviter_user_id'] ) : 0;
		$orderby    = ( isset( $_GET['orderby'] ) && 'display_name' === $_GET['orderby'] ) ? 'display_name' : 'registered';
		$order      = ( isset( $_GET['order'] ) && 'asc' === $_GET['order'] ) ? 'ASC' : 'DESC';
		// phpcs:enable

		$args = array(
			'role'    => Arp_Roles::ROLE,
			'number'  => $per_page,
			'paged'   => $this->get_pagenum(),
			'orderby' => $orderby,
			'order'   => $order,
		);

		$meta = array();
		if ( $inviter_id > 0 ) {
			$meta[] = array(
				'key'     => Arp_Inviter::META_USER_ID,
				'value'   => $inviter_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}
		if ( $job_id ) {
			$meta[] = array(
				'key'   => 'arp_job_type_id',
				'value' => $job_id,
			);
		}
		if ( '' !== $search ) {
			$digits = preg_replace( '/\D+/', '', Arp_Format::en( $search ) );
			if ( '' !== $digits && strlen( $digits ) >= 3 && $digits === Arp_Format::en( $search ) ) {
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => 'mobile',
						'value'   => $digits,
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'billing_phone',
						'value'   => $digits,
						'compare' => 'LIKE',
					),
				);
			} else {
				$args['search']         = '*' . $search . '*';
				$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
			}
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$query       = new WP_User_Query( $args );
		$this->items = $query->get_results();

		$ids = wp_list_pluck( $this->items, 'ID' );
		cache_users( $ids );
		$this->referral_counts = Arp_Referrals_Repo::count_by_affiliates( $ids );
		$this->totals          = Arp_Commissions_Repo::totals_by_affiliates( $ids );

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->get_total(),
				'per_page'    => $per_page,
			)
		);
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$job_id = isset( $_GET['job_type_id'] ) ? absint( $_GET['job_type_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = array();
		foreach ( $this->job_names as $id => $name ) {
			$rows[] = array( 'id' => $id, 'name' => $name );
		}
		echo '<div class="alignleft actions">';
		Arp_Admin_Ui::row_select( 'job_type_id', $rows, $job_id, 'همه مشاغل' );
		submit_button( 'فیلتر', '', 'filter_action', false );
		echo '</div>';

		printf(
			'<div class="alignleft actions"><a class="button" href="%s"><span class="dashicons dashicons-download" aria-hidden="true"></span> خروجی CSV</a></div>',
			esc_url( Arp_Admin_Ui::url( 'arp-affiliates', array( 'arp_export' => 'affiliates', '_wpnonce' => wp_create_nonce( 'arp_export' ) ) ) )
		);
	}

	public function no_items(): void {
		echo 'همکاری پیدا نشد.';
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_name( $item ): string {
		return sprintf(
			'<div class="arp-user-cell">%1$s<div><strong><a class="row-title" href="%2$s">%3$s</a></strong><span class="arp-muted">%4$s</span></div></div>',
			get_avatar( $item->ID, 32 ),
			esc_url( get_edit_user_link( $item->ID ) ),
			esc_html( $item->display_name ),
			esc_html( $item->user_email )
		);
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		$actions = array(
			'edit'      => sprintf( '<a href="%s">ویرایش کاربر</a>', esc_url( get_edit_user_link( $item->ID ) ) ),
			'referrals' => sprintf( '<a href="%s">معرفی‌ها</a>', esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'affiliate_user_id' => $item->ID ) ) ) ),
		);
		if ( Arp_Wallet_Bridge::available() ) {
			$actions['wallet'] = sprintf(
				'<a href="%s">کیف پول</a>',
				esc_url( Arp_Wallet_Bridge::user_insights_url( (int) $item->ID ) )
			);
		}
		return $this->row_actions( $actions );
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_mobile( $item ): string {
		$mobile = (string) get_user_meta( $item->ID, 'mobile', true );
		if ( '' === $mobile ) {
			$mobile = (string) get_user_meta( $item->ID, 'billing_phone', true );
		}
		return '' === $mobile ? '<span class="arp-muted">—</span>' : sprintf( '<a href="tel:%1$s" dir="ltr">%2$s</a>', esc_attr( $mobile ), esc_html( Arp_Format::fa( $mobile ) ) );
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_job( $item ): string {
		$id = (int) get_user_meta( $item->ID, 'arp_job_type_id', true );
		return esc_html( $this->job_names[ $id ] ?? '—' );
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_inviter( $item ): string {
		$inviter_id = (int) get_user_meta( $item->ID, Arp_Inviter::META_USER_ID, true );
		if ( $inviter_id <= 0 ) {
			return '<span class="arp-muted">—</span>';
		}
		$inviter = get_userdata( $inviter_id );
		$code    = (string) get_user_meta( $item->ID, Arp_Inviter::META_CODE, true );
		$name    = $inviter ? $inviter->display_name : ( '#' . $inviter_id );
		$link    = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Admin_Ui::url( 'arp-invited', array( 'inviter_user_id' => $inviter_id ) ) ),
			esc_html( $name )
		);
		if ( '' !== $code ) {
			$link .= ' <code dir="ltr">' . esc_html( $code ) . '</code>';
		}
		return $link;
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_code( $item ): string {
		$code = Arp_Referral_Code::get_for_user( $item->ID );
		return '' === $code ? '—' : '<code>' . esc_html( $code ) . '</code>';
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_referrals( $item ): string {
		$n = $this->referral_counts[ $item->ID ] ?? 0;
		if ( ! $n ) {
			return '<span class="arp-muted">۰</span>';
		}
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'affiliate_user_id' => $item->ID ) ) ),
			esc_html( Arp_Format::fa( number_format_i18n( $n ) ) )
		);
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_payable( $item ): string {
		if ( ! Arp_Wallet_Bridge::available() ) {
			$v = $this->totals[ $item->ID ]['payable'] ?? 0.0;
			return $v > 0 ? esc_html( Arp_Admin_Ui::money( $v ) ) : '<span class="arp-muted">—</span>';
		}
		$bal = Arp_Wallet_Bridge::user_balance( (int) $item->ID );
		if ( null === $bal ) {
			return '<span class="arp-muted">—</span>';
		}
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Wallet_Bridge::user_insights_url( (int) $item->ID ) ),
			esc_html( Arp_Admin_Ui::money( $bal ) )
		);
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_paid( $item ): string {
		$v = $this->totals[ $item->ID ]['paid'] ?? 0.0;
		return $v > 0 ? esc_html( Arp_Admin_Ui::money( $v ) ) : '<span class="arp-muted">—</span>';
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_registered( $item ): string {
		return esc_html( Arp_Admin_Ui::date( get_date_from_gmt( $item->user_registered ) ) );
	}
}
