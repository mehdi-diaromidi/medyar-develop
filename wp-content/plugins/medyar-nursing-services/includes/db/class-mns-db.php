<?php
/**
 * Database helpers for MNS tables (T-0.2).
 *
 * Only entry point for table names, transactions, and row locks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_DB {

	/**
	 * Short table names (without prefix / mns_).
	 *
	 * @var array<int,string>
	 */
	public const TABLES = array(
		'contract_versions',
		'files',
		'nurses',
		'patients',
		'nurse_services',
		'nurse_regions',
		'nurse_week',
		'nurse_day_slots',
		'services',
		'orders',
		'order_log',
		'order_edits',
		'notifications',
		'invoices',
		'payments',
		'surveys',
		'loyalty_stamps',
		'recurring_plans',
		'payment_attempts',
	);

	/** @var array<int,string> Allowed PK columns for lock_row(). */
	private const LOCK_PK = array( 'id', 'user_id' );

	/** @var int Nested transaction depth. */
	private static $tx_depth = 0;

	/**
	 * Full table name: {$wpdb->prefix}mns_{$name}.
	 *
	 * @param string $name Short name from TABLES.
	 * @throws \InvalidArgumentException When name is unknown.
	 */
	public static function table( string $name ): string {
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown MNS table short name: %s', $name )
			);
		}

		global $wpdb;
		return $wpdb->prefix . 'mns_' . $name;
	}

	/**
	 * Charset / collate clause for CREATE TABLE.
	 */
	public static function charset_collate(): string {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}

	/**
	 * Whether a transaction started by tx() is open.
	 */
	public static function in_tx(): bool {
		return self::$tx_depth > 0;
	}

	/**
	 * Run $fn inside a (possibly nested) transaction.
	 *
	 * Outer call: START / COMMIT or ROLLBACK.
	 * Nested call: just run $fn; outer handles commit/rollback.
	 *
	 * @param callable $fn Callback; return value is returned.
	 * @return mixed
	 * @throws \Throwable Re-throws after ROLLBACK on the outermost frame.
	 */
	public static function tx( callable $fn ) {
		global $wpdb;

		self::$tx_depth++;
		$outer = ( 1 === self::$tx_depth );

		try {
			if ( $outer ) {
				$wpdb->query( 'START TRANSACTION' );
			}

			$result = call_user_func( $fn );

			if ( $outer ) {
				$wpdb->query( 'COMMIT' );
			}

			return $result;
		} catch ( \Throwable $e ) {
			if ( $outer ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $e;
		} finally {
			self::$tx_depth--;
		}
	}

	/**
	 * Alias used by newer call sites (M4+).
	 *
	 * @param callable $fn Callback.
	 * @return mixed
	 */
	public static function transaction( callable $fn ) {
		return self::tx( $fn );
	}

	/**
	 * SELECT … FOR UPDATE. Must be called inside tx().
	 *
	 * @param string     $table_name Full name from table().
	 * @param string     $pk_column  Only id|user_id.
	 * @param int|string $id         Primary key value.
	 * @return array<string,mixed>|null
	 * @throws \LogicException           Outside a transaction.
	 * @throws \InvalidArgumentException Bad table or pk.
	 */
	public static function lock_row( string $table_name, string $pk_column, $id ): ?array {
		if ( ! self::in_tx() ) {
			throw new \LogicException( 'MNS_DB::lock_row() requires an open transaction.' );
		}

		if ( ! in_array( $pk_column, self::LOCK_PK, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'lock_row pk column not allowed: %s', $pk_column )
			);
		}

		if ( ! self::is_known_table( $table_name ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'lock_row table not an MNS table: %s', $table_name )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/pk from allowlists.
		$sql = "SELECT * FROM {$table_name} WHERE {$pk_column} = %d FOR UPDATE";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, (int) $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Last $wpdb error string (for logging).
	 */
	public static function last_error(): string {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	/**
	 * Whether $full is exactly one of table() results.
	 */
	private static function is_known_table( string $full ): bool {
		foreach ( self::TABLES as $short ) {
			if ( self::table( $short ) === $full ) {
				return true;
			}
		}
		return false;
	}
}
