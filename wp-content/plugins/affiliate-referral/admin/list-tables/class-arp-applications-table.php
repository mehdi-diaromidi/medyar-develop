<?php
/**
 * Admin list of pending affiliate applications.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Arp_Applications_Table extends WP_List_Table {

	/** @var array<int,string> */
	private $job_names = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'application',
				'plural'   => 'applications',
				'ajax'     => false,
			)
		);
		foreach ( Arp_Job_Types_Repo::all() as $job ) {
			$this->job_names[ (int) $job['id'] ] = (string) $job['name'];
		}
	}

	public function get_columns(): array {
		return array(
			'name'     => 'متقاضی',
			'mobile'   => 'موبایل',
			'job'      => 'نوع شغل',
			'docs'     => 'مدارک',
			'applied'  => 'زمان درخواست',
			'actions'  => 'عملیات',
		);
	}

	protected function get_primary_column_name(): string {
		return 'name';
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'arp_applications_per_page', 20 );
		$paged    = $this->get_pagenum();

		$query = new WP_User_Query(
			array(
				'number'     => $per_page,
				'paged'      => $paged,
				'orderby'    => 'meta_value',
				'order'      => 'DESC',
				'meta_key'   => Arp_Affiliate_Applications::META_APPLIED_AT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => Arp_Affiliate_Applications::META_STATUS,
						'value' => Arp_Affiliate_Applications::STATUS_PENDING,
					),
				),
			)
		);

		$this->items = $query->get_results();
		cache_users( wp_list_pluck( $this->items, 'ID' ) );

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->get_total(),
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items(): void {
		echo 'درخواست در انتظاری وجود ندارد.';
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
	protected function column_docs( $item ): string {
		$ids = Arp_Affiliate_Applications::document_ids( (int) $item->ID );
		if ( ! $ids ) {
			return '<span class="arp-muted">بدون مدرک</span>';
		}
		$links = array();
		foreach ( $ids as $i => $aid ) {
			$url = wp_get_attachment_url( $aid );
			if ( ! $url ) {
				continue;
			}
			$links[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">فایل %2$s</a>',
				esc_url( $url ),
				esc_html( Arp_Format::fa( (string) ( $i + 1 ) ) )
			);
		}
		return $links ? implode( ' · ', $links ) : '<span class="arp-muted">—</span>';
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_applied( $item ): string {
		$raw = (string) get_user_meta( $item->ID, Arp_Affiliate_Applications::META_APPLIED_AT, true );
		return '' === $raw ? '—' : esc_html( Arp_Admin_Ui::date( $raw ) );
	}

	/**
	 * @param WP_User $item User.
	 */
	protected function column_actions( $item ): string {
		$approve = wp_nonce_url(
			admin_url( 'admin-post.php?action=arp_affiliate_approve&user_id=' . (int) $item->ID ),
			'arp_affiliate_approve'
		);
		$reject  = wp_nonce_url(
			admin_url( 'admin-post.php?action=arp_affiliate_reject&user_id=' . (int) $item->ID ),
			'arp_affiliate_reject'
		);
		return sprintf(
			'<a class="button button-primary" href="%1$s">تأیید</a> <a class="button" href="%2$s" onclick="return confirm(%3$s);">رد</a>',
			esc_url( $approve ),
			esc_url( $reject ),
			esc_attr( wp_json_encode( 'این درخواست رد شود؟' ) )
		);
	}
}
