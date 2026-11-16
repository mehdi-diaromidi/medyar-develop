<?php
/**
 * Versioned, idempotent schema migrations (T-0.2).
 *
 * Uses option mns_schema_version (not the legacy mns_db_version).
 * Never runs on frontend — caller must gate with admin + manage_options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Migrations {

	public const OPTION_VERSION = 'mns_schema_version';
	public const OPTION_LOG     = 'mns_migration_log';
	public const LOCK_NAME      = 'mns_migrate';

	/**
	 * Current applied schema version (0 if never migrated).
	 */
	public static function current_version(): int {
		$v = get_option( self::OPTION_VERSION, 0 );
		return max( 0, (int) $v );
	}

	/**
	 * Whether schema is behind LATEST.
	 */
	public static function needs_run(): bool {
		return self::current_version() < MNS_Schema::LATEST;
	}

	/**
	 * Cheap check for later cards: schema at or past LATEST.
	 */
	public static function tables_ready(): bool {
		return self::current_version() >= MNS_Schema::LATEST;
	}

	/**
	 * Run pending migrations under GET_LOCK.
	 *
	 * @return array{ok:bool,from:int,to:int,errors:array<int,string>,error?:string}
	 */
	public static function run(): array {
		global $wpdb;

		$from = self::current_version();
		$to   = $from;

		$got = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, 0 )
		);

		if ( 1 !== $got ) {
			return array(
				'ok'     => false,
				'from'   => $from,
				'to'     => $from,
				'errors' => array( 'locked' ),
				'error'  => 'locked',
			);
		}

		$errors         = array();
		$tables_created = array();

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			for ( $v = $from + 1; $v <= MNS_Schema::LATEST; $v++ ) {
				$statements = MNS_Schema::sql( $v );
				if ( empty( $statements ) ) {
					$errors[] = sprintf( 'No SQL for schema version %d', $v );
					break;
				}

				foreach ( $statements as $sql ) {
					dbDelta( $sql );
				}

				$verify = self::verify_tables( MNS_Schema::tables_for( $v ) );
				if ( ! empty( $verify['errors'] ) ) {
					foreach ( $verify['errors'] as $err ) {
						$errors[] = $err;
					}
					self::append_log(
						array(
							'version'        => $v,
							'time'           => time(),
							'ok'             => false,
							'tables_created' => $verify['present'],
							'error'          => implode( '; ', $verify['errors'] ),
						)
					);
					break;
				}

				update_option( self::OPTION_VERSION, $v, false );
				$to             = $v;
				$tables_created = $verify['present'];

				self::append_log(
					array(
						'version'        => $v,
						'time'           => time(),
						'ok'             => true,
						'tables_created' => $tables_created,
						'error'          => '',
					)
				);
			}
		} catch ( \Throwable $e ) {
			$errors[] = $e->getMessage();
			self::append_log(
				array(
					'version'        => $to + 1,
					'time'           => time(),
					'ok'             => false,
					'tables_created' => $tables_created,
					'error'          => $e->getMessage(),
				)
			);
		} finally {
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME )
			);
		}

		return array(
			'ok'     => empty( $errors ),
			'from'   => $from,
			'to'     => $to,
			'errors' => $errors,
		);
	}

	/**
	 * Migration log (newest last; max 20).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function log(): array {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Confirm each short table exists and is InnoDB.
	 *
	 * @param array<int,string> $shorts Short names.
	 * @return array{present:array<int,string>,errors:array<int,string>}
	 */
	private static function verify_tables( array $shorts ): array {
		global $wpdb;

		$present = array();
		$errors  = array();

		foreach ( $shorts as $short ) {
			$table = MNS_DB::table( $short );

			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
			);

			if ( $found !== $table ) {
				$errors[] = sprintf( 'Table missing after dbDelta: %s', $table );
				continue;
			}

			$present[] = $table;

			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$table
				)
			);

			if ( is_string( $engine ) && '' !== $engine && 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
				$errors[] = sprintf( 'Table %s engine is %s (expected InnoDB)', $table, $engine );
			}
		}

		return array(
			'present' => $present,
			'errors'  => $errors,
		);
	}

	/**
	 * @param array<string,mixed> $row Log row.
	 */
	private static function append_log( array $row ): void {
		$log = self::log();
		$log[] = $row;
		if ( count( $log ) > 20 ) {
			$log = array_slice( $log, -20 );
		}
		update_option( self::OPTION_LOG, $log, false );
	}
}
