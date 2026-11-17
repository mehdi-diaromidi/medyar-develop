<?php
/**
 * Loyalty stamps (M6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Loyalty {

	/**
	 * @return array{filled:int,required:int,codes:array}
	 */
	public static function for_account( int $account_user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'loyalty_stamps' ) . ' WHERE account_user_id = %d', $account_user_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array(
				'filled'   => 0,
				'required' => 5,
				'codes'    => array(),
			);
		}
		return array(
			'filled'   => (int) $row['filled'],
			'required' => max( 1, (int) $row['required'] ),
			'codes'    => array(),
		);
	}

	public static function add_stamp( int $account_user_id ): void {
		global $wpdb;
		$now = MNS_Time::now_utc();
		$t   = MNS_DB::table( 'loyalty_stamps' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_user_id = %d", $account_user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			$wpdb->insert(
				$t,
				array(
					'account_user_id' => $account_user_id,
					'filled'          => 1,
					'required'        => 5,
					'updated_at'      => $now,
				)
			);
			return;
		}
		$filled = min( (int) $row['required'], (int) $row['filled'] + 1 );
		$wpdb->update( $t, array( 'filled' => $filled, 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
	}
}
