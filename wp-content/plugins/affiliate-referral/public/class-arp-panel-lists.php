<?php
/**
 * Affiliate panel list state (filters, paging, rows) from the GET query.
 *
 * Shared by the full page views and the AJAX list endpoint so both render identically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Panel_Lists {

	public const VIEWS    = array( 'referrals', 'commissions', 'invited_commissions' );
	private const PER_PAGE = 10;

	/**
	 * @return array<string,mixed> Variables for views/dashboard/partials/referrals-results.php.
	 */
	public static function referrals( int $user_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters scoped to the current user.
		$f_status  = isset( $_GET['arp_status'] ) ? absint( $_GET['arp_status'] ) : 0;
		$f_service = isset( $_GET['arp_service'] ) ? absint( $_GET['arp_service'] ) : 0;
		$f_q       = isset( $_GET['arp_q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['arp_q'] ) ), 0, 100 ) : '';
		// phpcs:enable
		$range = Arp_Ui::date_range_from_request();

		$filters = array(
			'affiliate_user_id' => $user_id,
			'status_id'         => $f_status,
			'service_id'        => $f_service,
			'date_from'         => $range['date_from'],
			'date_to'           => $range['date_to'],
			'search'            => Arp_Format::en( $f_q ),
		);
		$query = array(
			'arp_status'  => $f_status,
			'arp_service' => $f_service,
			'arp_q'       => $f_q,
			'arp_from'    => $range['from'],
			'arp_to'      => $range['to'],
		);

		return array(
			'f_status'    => $f_status,
			'f_service'   => $f_service,
			'f_q'         => $f_q,
			'range'       => $range,
			'has_filters' => $f_status || $f_service || '' !== $f_q || '' !== $range['date_from'] || '' !== $range['date_to'],
		) + self::paginate(
			'referrals',
			$query,
			Arp_Referrals_Repo::count( $filters ),
			static function ( int $limit, int $offset ) use ( $filters ): array {
				return Arp_Referrals_Repo::query( $filters + array( 'limit' => $limit, 'offset' => $offset ) );
			}
		);
	}

	/**
	 * @return array<string,mixed> Variables for views/dashboard/partials/commissions-results.php.
	 */
	public static function commissions( int $user_id ): array {
		$states = Arp_Ui::commission_filter_states();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters scoped to the current user.
		$f_state   = isset( $_GET['arp_state'] ) ? sanitize_key( wp_unslash( $_GET['arp_state'] ) ) : '';
		$f_service = isset( $_GET['arp_service'] ) ? absint( $_GET['arp_service'] ) : 0;
		// phpcs:enable
		if ( ! isset( $states[ $f_state ] ) ) {
			$f_state = '';
		}
		$range = Arp_Ui::date_range_from_request();

		$filters = array(
			'affiliate_user_id' => $user_id,
			'state'             => $f_state,
			'service_id'        => $f_service,
			'date_from'         => $range['date_from'],
			'date_to'           => $range['date_to'],
		);
		$query = array(
			'arp_state'   => $f_state,
			'arp_service' => $f_service,
			'arp_from'    => $range['from'],
			'arp_to'      => $range['to'],
		);

		return array(
			'states'      => $states,
			'f_state'     => $f_state,
			'f_service'   => $f_service,
			'range'       => $range,
			'has_filters' => '' !== $f_state || $f_service || '' !== $range['date_from'] || '' !== $range['date_to'],
		) + self::paginate(
			'commissions',
			$query,
			Arp_Commissions_Repo::count( $filters ),
			static function ( int $limit, int $offset ) use ( $filters ): array {
				return Arp_Commissions_Repo::query( $filters + array( 'limit' => $limit, 'offset' => $offset ) );
			}
		);
	}

	/**
	 * Commission rows earned by affiliates invited by $inviter_id (special inviters only).
	 *
	 * @return array<string,mixed>
	 */
	public static function invited_commissions( int $inviter_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters scoped to the current user.
		$f_service = isset( $_GET['arp_service'] ) ? absint( $_GET['arp_service'] ) : 0;
		// phpcs:enable
		$range = Arp_Ui::date_range_from_request();

		$affiliate_ids = Arp_Inviter::referred_user_ids( $inviter_id );
		$filters       = array(
			'affiliate_user_ids' => $affiliate_ids ? $affiliate_ids : array( 0 ),
			'states'             => array( 'approved', 'paid' ),
			'service_id'         => $f_service,
			'date_from'          => $range['date_from'],
			'date_to'            => $range['date_to'],
		);

		$query = array(
			'arp_service' => $f_service,
			'arp_from'    => $range['from'],
			'arp_to'      => $range['to'],
		);

		$count = $affiliate_ids ? Arp_Commissions_Repo::count( $filters ) : 0;
		$sum   = $affiliate_ids ? Arp_Commissions_Repo::sum_amount( $filters ) : 0.0;

		return array(
			'f_service'     => $f_service,
			'range'         => $range,
			'invitee_count' => count( $affiliate_ids ),
			'amount_total'  => $sum,
			'has_filters'   => $f_service || '' !== $range['date_from'] || '' !== $range['date_to'],
		) + self::paginate(
			'invited',
			$query,
			$count,
			static function ( int $limit, int $offset ) use ( $filters, $affiliate_ids ): array {
				if ( ! $affiliate_ids ) {
					return array();
				}
				return Arp_Commissions_Repo::query( $filters + array( 'limit' => $limit, 'offset' => $offset ) );
			}
		);
	}

	/**
	 * Render a list's results block (table + pager) to a string.
	 *
	 * @param array<string,mixed> $vars From referrals() / commissions() / invited_commissions().
	 */
	public static function render( string $view, array $vars ): string {
		if ( ! in_array( $view, self::VIEWS, true ) ) {
			return '';
		}
		$partial = 'invited_commissions' === $view ? 'invited-commissions-results.php' : $view . '-results.php';
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		ob_start();
		include ARP_PLUGIN_DIR . 'public/views/dashboard/partials/' . $partial;
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,scalar> $query Current filters as panel query args.
	 * @param callable             $rows  fn(int $limit, int $offset): array.
	 * @return array<string,mixed>
	 */
	private static function paginate( string $page, array $query, int $total, callable $rows ): array {
		$req_page = isset( $_GET['arp_p'] ) ? absint( $_GET['arp_p'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page_num = min( $pages, max( 1, $req_page ) );
		$offset   = ( $page_num - 1 ) * self::PER_PAGE;

		return array(
			'per'      => self::PER_PAGE,
			'total'    => $total,
			'pages'    => $pages,
			'page_num' => $page_num,
			'offset'   => $offset,
			'visible'  => $total > 0 ? $rows( self::PER_PAGE, $offset ) : array(),
			'page_url' => static function ( int $n ) use ( $page, $query ): string {
				return Arp_Pages::page_url( $page, array_map( 'rawurlencode', array_filter( $query + array( 'arp_p' => $n > 1 ? $n : '' ) ) ) );
			},
		);
	}
}
