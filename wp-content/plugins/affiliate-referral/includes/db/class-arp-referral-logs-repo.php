<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status-change / note history of a referral.
 */
class Arp_Referral_Logs_Repo {

	public static function insert( int $referral_id, int $user_id, int $from_status_id, int $to_status_id, string $note = '' ): int {
		global $wpdb;
		$wpdb->insert(
			Arp_Db::referral_logs(),
			array(
				'referral_id'    => $referral_id,
				'user_id'        => $user_id,
				'from_status_id' => $from_status_id,
				'to_status_id'   => $to_status_id,
				'note'           => $note,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Newest first.
	 */
	public static function for_referral( int $referral_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Arp_Db::referral_logs() . ' WHERE referral_id = %d ORDER BY created_at DESC, id DESC',
				$referral_id
			),
			ARRAY_A
		);
	}
}
