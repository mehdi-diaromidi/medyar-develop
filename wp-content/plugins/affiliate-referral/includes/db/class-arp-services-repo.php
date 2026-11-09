<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Services_Repo {

	public static function all( bool $active_only = false ): array {
		global $wpdb;
		$table = Arp_Db::services();
		$sql   = "SELECT * FROM {$table}";
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY name ASC';
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::services() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function insert( string $name, int $is_active = 1, ?float $price = null ): int {
		global $wpdb;
		$wpdb->insert(
			Arp_Db::services(),
			array(
				'name'      => $name,
				'is_active' => $is_active,
				'price'     => $price,
			),
			array( '%s', '%d', '%f' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, string $name, int $is_active, ?float $price = null ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			Arp_Db::services(),
			array(
				'name'      => $name,
				'is_active' => $is_active,
				'price'     => $price,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%f' ),
			array( '%d' )
		);
	}

	public static function set_active( int $id, int $is_active ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			Arp_Db::services(),
			array( 'is_active' => $is_active ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Suggested commission for a service: price × active rule percentage, else the rule's fixed default amount.
	 *
	 * @return array{price:?float,percentage:?float,amount:?float}
	 */
	public static function commission_suggestion( int $service_id ): array {
		$service    = self::get( $service_id );
		$rule       = Arp_Commission_Rules_Repo::get_active_for_service( $service_id );
		$price      = ( $service && null !== $service['price'] ) ? (float) $service['price'] : null;
		$percentage = $rule ? (float) $rule['percentage'] : null;
		$amount     = null;
		if ( null !== $price && $price > 0 && $percentage ) {
			$amount = round( $price * $percentage / 100 );
		} elseif ( $rule && null !== $rule['default_amount'] && (float) $rule['default_amount'] > 0 ) {
			$amount = (float) $rule['default_amount'];
		}
		return array(
			'price'      => $price,
			'percentage' => $percentage,
			'amount'     => $amount,
		);
	}
}
