<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Settlements_Repo {

	public static function create( int $affiliate_user_id, float $amount, string $note, int $created_by, array $commission_ids ): int {
		global $wpdb;
		$wpdb->insert(
			Arp_Db::settlements(),
			array(
				'affiliate_user_id' => $affiliate_user_id,
				'amount'            => $amount,
				'note'              => $note,
				'created_by'        => $created_by,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%d', '%s' )
		);
		$settlement_id = (int) $wpdb->insert_id;

		foreach ( $commission_ids as $cid ) {
			$wpdb->insert(
				Arp_Db::settlement_items(),
				array(
					'settlement_id' => $settlement_id,
					'commission_id' => (int) $cid,
				),
				array( '%d', '%d' )
			);
		}

		return $settlement_id;
	}

	/**
	 * @param array{affiliate_user_id?:int,limit?:int,offset?:int} $args Filters.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$se = Arp_Db::settlements();
		$si = Arp_Db::settlement_items();

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['affiliate_user_id'] ) ) {
			$where   .= ' AND s.affiliate_user_id = %d';
			$params[] = (int) $args['affiliate_user_id'];
		}
		$params[] = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;
		$params[] = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, (SELECT COUNT(*) FROM {$si} i WHERE i.settlement_id = s.id) AS items
				FROM {$se} s WHERE {$where}
				ORDER BY s.created_at DESC, s.id DESC
				LIMIT %d OFFSET %d", // phpcs:ignore
				$params
			),
			ARRAY_A
		);
	}

	public static function count( int $affiliate_user_id = 0 ): int {
		global $wpdb;
		if ( $affiliate_user_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Arp_Db::settlements() . ' WHERE affiliate_user_id = %d', $affiliate_user_id )
			);
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Arp_Db::settlements() ); // phpcs:ignore
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::settlements() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}
}
