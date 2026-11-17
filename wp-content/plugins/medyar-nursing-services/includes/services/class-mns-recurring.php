<?php
/**
 * Recurring care plans (M7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Recurring {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_account( int $account_user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'recurring_plans' ) . ' WHERE account_user_id = %d ORDER BY id DESC LIMIT 50', // phpcs:ignore WordPress.DB.PreparedSQL
				$account_user_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
