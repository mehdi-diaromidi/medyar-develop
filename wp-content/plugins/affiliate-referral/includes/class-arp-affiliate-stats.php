<?php
/**
 * Aggregated per-affiliate figures for the partner dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Affiliate_Stats {

	public const TREND_MONTHS = 6;

	/**
	 * @return array{
	 *   payable: float,
	 *   month_earned: float,
	 *   total: int,
	 *   open: int,
	 *   success: int,
	 *   statuses: array<int,array{label:string,count:int,color:string}>,
	 *   trend: array{labels:string[],values:int[]}
	 * }
	 */
	public static function dashboard( int $user_id ): array {
		list( $jy, $jm ) = Arp_Format::jalali_today();

		$counts   = Arp_Referrals_Repo::count_by_status_for_user( $user_id );
		$total    = array_sum( $counts );
		$open     = 0;
		$success  = 0;
		$statuses = array();

		$all_statuses = Arp_Statuses_Repo::all();
		$group_slugs  = array(
			Arp_Statuses_Repo::GROUP_OPEN    => array(),
			Arp_Statuses_Repo::GROUP_SUCCESS => array(),
		);
		foreach ( $all_statuses as $status ) {
			if ( isset( $group_slugs[ $status['dashboard_group'] ] ) ) {
				$group_slugs[ $status['dashboard_group'] ][] = (string) $status['slug'];
			}
		}
		/** Status slugs counted as "in progress" on the dashboard (default: set per status in settings). */
		$open_slugs = (array) apply_filters( 'arp_dashboard_open_status_slugs', $group_slugs[ Arp_Statuses_Repo::GROUP_OPEN ] );
		/** Status slugs counted as "successful" on the dashboard (default: set per status in settings). */
		$success_slugs = (array) apply_filters( 'arp_dashboard_success_status_slugs', $group_slugs[ Arp_Statuses_Repo::GROUP_SUCCESS ] );

		foreach ( $all_statuses as $i => $status ) {
			$n    = $counts[ (int) $status['id'] ] ?? 0;
			$slug = (string) $status['slug'];
			if ( in_array( $slug, $open_slugs, true ) ) {
				$open += $n;
			}
			if ( in_array( $slug, $success_slugs, true ) ) {
				$success += $n;
			}
			$statuses[] = array(
				'label' => (string) $status['name'],
				'count' => $n,
				'color' => Arp_Ui::status_color( $status, (int) $i ),
			);
		}

		$month_start = Arp_Format::jalali_month_start();
		$payable     = Arp_Commissions_Repo::sum_approved_unpaid( $user_id );
		if ( Arp_Wallet_Bridge::available() ) {
			$bal = Arp_Wallet_Bridge::user_balance( $user_id );
			if ( null !== $bal ) {
				$payable = (float) $bal;
			}
		}

		return array(
			'payable'      => $payable,
			'month_earned' => Arp_Commissions_Repo::sum_earned_since( $user_id, $month_start ),
			'total'        => $total,
			'open'         => $open,
			'success'      => $success,
			'statuses'     => $statuses,
			'trend'        => self::trend( $user_id, $jy, $jm ),
		);
	}

	/**
	 * Referral counts for the last TREND_MONTHS Jalali months (oldest first).
	 *
	 * @return array{labels:string[],values:int[]}
	 */
	private static function trend( int $user_id, int $jy, int $jm ): array {
		$buckets = array();
		for ( $k = self::TREND_MONTHS - 1; $k >= 0; $k-- ) {
			$m = $jm - $k;
			$y = $jy;
			while ( $m < 1 ) {
				$m += 12;
				--$y;
			}
			$buckets[ sprintf( '%04d/%02d', $y, $m ) ] = array(
				'label' => Arp_Format::month_name( $m ),
				'count' => 0,
			);
		}

		$first = (string) array_key_first( $buckets );
		$daily = Arp_Referrals_Repo::daily_counts_since( $user_id, Arp_Format::jalali_to_date( $first . '/01' ) );

		foreach ( $daily as $date => $n ) {
			$key = substr( Arp_Format::to_jalali( $date ), 0, 7 );
			if ( isset( $buckets[ $key ] ) ) {
				$buckets[ $key ]['count'] += $n;
			}
		}

		return array(
			'labels' => array_column( $buckets, 'label' ),
			'values' => array_column( $buckets, 'count' ),
		);
	}
}
