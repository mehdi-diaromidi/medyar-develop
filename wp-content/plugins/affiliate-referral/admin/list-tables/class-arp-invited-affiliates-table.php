<?php
/**
 * Admin list of inviters with invitee gain (commission-bearing totals).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Arp_Invited_Affiliates_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'inviter',
				'plural'   => 'inviters',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'inviter'         => 'دعوت‌کننده',
			'code'            => 'کد دعوت',
			'invitees'        => 'همکاران دعوت‌شده',
			'commission_rows' => 'ردیف‌های پورسانت‌دار',
			'amount'          => 'جمع پورسانت دعوت‌شده‌ها',
		);
	}

	protected function get_primary_column_name(): string {
		return 'inviter';
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'arp_invited_per_page', 20 );
		$paged    = $this->get_pagenum();
		$map      = Arp_Inviter::active_inviters();
		cache_users( array_map( 'intval', array_keys( $map ) ) );

		$all = array();
		foreach ( $map as $uid => $code ) {
			$uid = (int) $uid;
			if ( ! get_userdata( $uid ) ) {
				continue;
			}
			$breakdown = Arp_Inviter::commission_breakdown( $uid, 1000 );
			$all[]     = (object) array(
				'ID'               => $uid,
				'code'             => (string) $code,
				'invitee_count'    => (int) $breakdown['invitee_count'],
				'commission_count' => (int) $breakdown['commission_count'],
				'amount_total'     => (float) $breakdown['amount_total'],
			);
		}

		usort(
			$all,
			static function ( $a, $b ) {
				if ( $a->amount_total !== $b->amount_total ) {
					return $b->amount_total <=> $a->amount_total;
				}
				return $b->invitee_count <=> $a->invitee_count;
			}
		);

		$total       = count( $all );
		$this->items = array_slice( $all, ( $paged - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items(): void {
		echo 'هنوز هیچ همکاری با کد دعوت ثبت‌نام نکرده است.';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_inviter( $item ): string {
		$uid  = (int) $item->ID;
		$user = get_userdata( $uid );
		$name = $user ? $user->display_name : ( '#' . $uid );
		$detail = Arp_Admin_Ui::url( 'arp-invited', array( 'inviter_user_id' => $uid ) );
		return sprintf(
			'<div class="arp-user-cell">%1$s<div><strong><a class="row-title" href="%2$s">%3$s</a></strong><span class="arp-muted">#%4$d</span></div></div>',
			$user ? get_avatar( $uid, 32 ) : '',
			esc_url( $detail ),
			esc_html( $name ),
			$uid
		);
	}

	/**
	 * @param object $item Row.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		$uid     = (int) $item->ID;
		$actions = array(
			'detail' => sprintf(
				'<a href="%s">جزئیات پورسانت دعوت</a>',
				esc_url( Arp_Admin_Ui::url( 'arp-invited', array( 'inviter_user_id' => $uid ) ) )
			),
			'edit'   => sprintf( '<a href="%s">ویرایش کاربر</a>', esc_url( get_edit_user_link( $uid ) ) ),
			'affs'   => sprintf(
				'<a href="%s">همکاران دعوت‌شده</a>',
				esc_url( Arp_Admin_Ui::url( 'arp-affiliates', array( 'inviter_user_id' => $uid ) ) )
			),
		);
		return $this->row_actions( $actions );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_code( $item ): string {
		$code = (string) $item->code;
		return '' === $code ? '<span class="arp-muted">—</span>' : '<code dir="ltr">' . esc_html( $code ) . '</code>';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_invitees( $item ): string {
		$n = (int) $item->invitee_count;
		if ( $n <= 0 ) {
			return '<span class="arp-muted">۰</span>';
		}
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Admin_Ui::url( 'arp-affiliates', array( 'inviter_user_id' => (int) $item->ID ) ) ),
			esc_html( Arp_Format::fa( number_format_i18n( $n ) ) )
		);
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_commission_rows( $item ): string {
		$n = (int) $item->commission_count;
		if ( $n <= 0 ) {
			return '<span class="arp-muted">۰</span>';
		}
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Arp_Admin_Ui::url( 'arp-invited', array( 'inviter_user_id' => (int) $item->ID ) ) ),
			esc_html( Arp_Format::fa( number_format_i18n( $n ) ) )
		);
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_amount( $item ): string {
		$v = (float) $item->amount_total;
		if ( $v <= 0 ) {
			return '<span class="arp-muted">—</span>';
		}
		return '<strong>' . esc_html( Arp_Admin_Ui::money( $v ) ) . '</strong>';
	}
}
