<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Statuses_Repo {

	/** Partner-dashboard bucket: counted as «در حال پیگیری». */
	public const GROUP_OPEN = 'open';

	/** Partner-dashboard bucket: counted as «موفق». */
	public const GROUP_SUCCESS = 'success';

	/** Referrals of a deleted status are moved here. */
	public const FALLBACK_SLUG = 'failed_contact';

	/**
	 * @return array<string,string> group => label ('' = not counted).
	 */
	public static function dashboard_groups(): array {
		return array(
			self::GROUP_OPEN    => 'در حال پیگیری',
			self::GROUP_SUCCESS => 'موفق',
			''                  => 'هیچ‌کدام',
		);
	}

	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT * FROM ' . Arp_Db::referral_statuses() . ' ORDER BY sort_order ASC, id ASC',
			ARRAY_A
		); // phpcs:ignore
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::referral_statuses() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function get_by_slug( string $slug ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::referral_statuses() . ' WHERE slug = %s LIMIT 1', $slug ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function get_default(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT * FROM ' . Arp_Db::referral_statuses() . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1',
			ARRAY_A
		); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * @param array<string,mixed> $data Status fields.
	 * @return array<string,mixed>
	 */
	private static function row( array $data ): array {
		$group = (string) ( $data['dashboard_group'] ?? '' );
		return array(
			'name'                => $data['name'],
			'slug'                => $data['slug'],
			'sort_order'          => (int) $data['sort_order'],
			'is_default'          => (int) $data['is_default'],
			'triggers_commission' => (int) $data['triggers_commission'],
			'is_terminal'         => (int) $data['is_terminal'],
			'color'               => (string) ( $data['color'] ?? '' ),
			'dashboard_group'     => isset( self::dashboard_groups()[ $group ] ) ? $group : '',
		);
	}

	private const FORMATS = array( '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' );

	public static function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( Arp_Db::referral_statuses(), self::row( $data ), self::FORMATS );
		$id = (int) $wpdb->insert_id;
		if ( ! empty( $data['is_default'] ) ) {
			self::clear_other_defaults( $id );
		}
		return $id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$ok = false !== $wpdb->update( Arp_Db::referral_statuses(), self::row( $data ), array( 'id' => $id ), self::FORMATS, array( '%d' ) );
		if ( $ok && ! empty( $data['is_default'] ) ) {
			self::clear_other_defaults( $id );
		}
		return $ok;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Arp_Db::referral_statuses(), array( 'id' => $id ), array( '%d' ) );
	}

	private static function clear_other_defaults( int $keep_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Arp_Db::referral_statuses() . ' SET is_default = 0 WHERE id != %d',
				$keep_id
			)
		);
	}
}
