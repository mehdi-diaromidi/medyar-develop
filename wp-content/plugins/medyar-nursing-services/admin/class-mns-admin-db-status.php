<?php
/**
 * Tools → وضعیت دیتابیس MNS (T-0.2 / AC-M0-2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Db_Status {

	public const PAGE_SLUG = 'mns-db-status';

	/**
	 * Register menu and admin-post handlers.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_mns_db_migrate', array( __CLASS__, 'handle_migrate' ) );
		add_action( 'admin_post_mns_db_txtest', array( __CLASS__, 'handle_txtest' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			__( 'وضعیت دیتابیس MNS', 'medyar-nursing-services' ),
			__( 'وضعیت دیتابیس MNS', 'medyar-nursing-services' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Manual migration button.
	 */
	public static function handle_migrate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'mns_db_migrate' );

		$result = MNS_Migrations::run();
		set_transient(
			'mns_db_migrate_flash_' . get_current_user_id(),
			$result,
			60
		);

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Three transaction self-tests (rollback / commit / lock_row guard).
	 */
	public static function handle_txtest(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'mns_db_txtest' );

		$results = self::run_tx_selftest();
		set_transient(
			'mns_db_txtest_flash_' . get_current_user_id(),
			$results,
			60
		);

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * @return array{rollback:array{ok:bool,detail:string},commit:array{ok:bool,detail:string},lock:array{ok:bool,detail:string}}
	 */
	private static function run_tx_selftest(): array {
		global $wpdb;

		$out = array(
			'rollback' => array(
				'ok'     => false,
				'detail' => '',
			),
			'commit'   => array(
				'ok'     => false,
				'detail' => '',
			),
			'lock'     => array(
				'ok'     => false,
				'detail' => '',
			),
		);

		if ( ! MNS_Migrations::tables_ready() ) {
			$msg = 'tables_not_ready';
			$out['rollback']['detail'] = $msg;
			$out['commit']['detail']   = $msg;
			$out['lock']['detail']     = $msg;
			return $out;
		}

		$table = MNS_DB::table( 'notifications' );
		$uid   = get_current_user_id();

		// (a) insert then throw ⇒ rollback; count unchanged.
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		try {
			MNS_DB::tx(
				static function () use ( $wpdb, $table, $uid ) {
					$wpdb->insert(
						$table,
						array(
							'user_id'    => $uid,
							'type'       => 'selftest',
							'payload'    => '{}',
							'created_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( '%d', '%s', '%s', '%s' )
					);
					throw new \RuntimeException( 'mns_tx_selftest_rollback' );
				}
			);
			$out['rollback']['detail'] = 'exception_not_thrown';
		} catch ( \RuntimeException $e ) {
			if ( 'mns_tx_selftest_rollback' !== $e->getMessage() ) {
				$out['rollback']['detail'] = $e->getMessage();
			} else {
				$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $count_after === $count_before ) {
					$out['rollback']['ok']     = true;
					$out['rollback']['detail'] = 'count_unchanged';
				} else {
					$out['rollback']['detail'] = sprintf(
						'count_changed %d→%d',
						$count_before,
						$count_after
					);
				}
			}
		} catch ( \Throwable $e ) {
			$out['rollback']['detail'] = $e->getMessage();
		}

		// (b) insert succeeds ⇒ row exists; then delete that row only.
		try {
			$insert_id = MNS_DB::tx(
				static function () use ( $wpdb, $table, $uid ) {
					$ok = $wpdb->insert(
						$table,
						array(
							'user_id'    => $uid,
							'type'       => 'selftest',
							'payload'    => '{}',
							'created_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( '%d', '%s', '%s', '%s' )
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'insert_failed: ' . MNS_DB::last_error() );
					}
					return (int) $wpdb->insert_id;
				}
			);

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, type FROM {$table} WHERE id = %d AND type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$insert_id,
					'selftest'
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) ) {
				$out['commit']['detail'] = 'row_missing_after_commit';
			} else {
				$deleted = $wpdb->delete(
					$table,
					array(
						'id'   => $insert_id,
						'type' => 'selftest',
					),
					array( '%d', '%s' )
				);
				if ( 1 === (int) $deleted ) {
					$out['commit']['ok']     = true;
					$out['commit']['detail'] = 'insert_and_cleanup_ok';
				} else {
					$out['commit']['detail'] = 'cleanup_failed';
				}
			}
		} catch ( \Throwable $e ) {
			$out['commit']['detail'] = $e->getMessage();
		}

		// (c) lock_row outside tx ⇒ LogicException.
		try {
			MNS_DB::lock_row( $table, 'id', 1 );
			$out['lock']['detail'] = 'no_exception';
		} catch ( \LogicException $e ) {
			$out['lock']['ok']     = true;
			$out['lock']['detail'] = 'logic_exception';
		} catch ( \Throwable $e ) {
			$out['lock']['detail'] = get_class( $e ) . ': ' . $e->getMessage();
		}

		return $out;
	}

	/**
	 * Render Tools page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ),
				'',
				array( 'response' => 403 )
			);
		}

		global $wpdb;

		$current = MNS_Migrations::current_version();
		$latest  = MNS_Schema::LATEST;
		$uid     = get_current_user_id();

		$migrate_flash = get_transient( 'mns_db_migrate_flash_' . $uid );
		if ( false !== $migrate_flash ) {
			delete_transient( 'mns_db_migrate_flash_' . $uid );
		}

		$txtest_flash = get_transient( 'mns_db_txtest_flash_' . $uid );
		if ( false !== $txtest_flash ) {
			delete_transient( 'mns_db_txtest_flash_' . $uid );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'وضعیت دیتابیس MNS', 'medyar-nursing-services' ) . '</h1>';

		echo '<p>';
		echo esc_html(
			sprintf(
				/* translators: 1: current schema version, 2: latest */
				__( 'نسخهٔ schema: %1$d / آخرین: %2$d', 'medyar-nursing-services' ),
				$current,
				$latest
			)
		);
		echo '</p>';

		if ( is_array( $migrate_flash ) ) {
			$ok = ! empty( $migrate_flash['ok'] );
			echo $ok ? '<div class="notice notice-success"><p>' : '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: from version, 2: to version */
					__( 'نتیجهٔ migration: from=%1$d to=%2$d', 'medyar-nursing-services' ),
					isset( $migrate_flash['from'] ) ? (int) $migrate_flash['from'] : 0,
					isset( $migrate_flash['to'] ) ? (int) $migrate_flash['to'] : 0
				)
			);
			if ( ! empty( $migrate_flash['errors'] ) && is_array( $migrate_flash['errors'] ) ) {
				echo ' — ' . esc_html( implode( '; ', $migrate_flash['errors'] ) );
			}
			echo '</p></div>';
		}

		if ( is_array( $txtest_flash ) ) {
			echo '<div class="notice notice-info"><p><strong>';
			echo esc_html__( 'نتیجهٔ تست تراکنش', 'medyar-nursing-services' );
			echo '</strong></p><ul>';
			foreach ( array( 'rollback', 'commit', 'lock' ) as $key ) {
				$row = isset( $txtest_flash[ $key ] ) && is_array( $txtest_flash[ $key ] )
					? $txtest_flash[ $key ]
					: array();
				$pass = ! empty( $row['ok'] );
				$detail = isset( $row['detail'] ) ? (string) $row['detail'] : '';
				echo '<li>';
				echo esc_html( $key . ': ' . ( $pass ? 'PASS' : 'FAIL' ) );
				if ( '' !== $detail ) {
					echo ' — ' . esc_html( $detail );
				}
				echo '</li>';
			}
			echo '</ul></div>';
		}

		echo '<p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-inline-end:12px;">';
		wp_nonce_field( 'mns_db_migrate' );
		echo '<input type="hidden" name="action" value="mns_db_migrate" />';
		submit_button( __( 'اجرای migration', 'medyar-nursing-services' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		wp_nonce_field( 'mns_db_txtest' );
		echo '<input type="hidden" name="action" value="mns_db_txtest" />';
		submit_button( __( 'تست تراکنش', 'medyar-nursing-services' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'جدول‌ها', 'medyar-nursing-services' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'نام', 'medyar-nursing-services' ) . '</th>';
		echo '<th>' . esc_html__( 'وجود', 'medyar-nursing-services' ) . '</th>';
		echo '<th>' . esc_html__( 'موتور', 'medyar-nursing-services' ) . '</th>';
		echo '<th>' . esc_html__( 'ردیف‌ها', 'medyar-nursing-services' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( MNS_DB::TABLES as $short ) {
			$table = MNS_DB::table( $short );
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
			);
			$exists = ( $found === $table );

			$engine = '';
			$rows   = '—';
			$create = '';

			if ( $exists ) {
				$engine = (string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
						DB_NAME,
						$table
					)
				);
				$rows = (string) (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$create_row = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( is_array( $create_row ) && isset( $create_row[1] ) ) {
					$create = (string) $create_row[1];
				}
			}

			echo '<tr>';
			echo '<td><code>' . esc_html( $table ) . '</code></td>';
			echo '<td>' . esc_html( $exists ? 'yes' : 'no' ) . '</td>';
			echo '<td>' . esc_html( $engine !== '' ? $engine : '—' ) . '</td>';
			echo '<td>' . esc_html( $rows ) . '</td>';
			echo '</tr>';

			if ( $exists && '' !== $create ) {
				echo '<tr><td colspan="4">';
				echo '<details><summary>' . esc_html__( 'SHOW CREATE TABLE', 'medyar-nursing-services' ) . '</summary>';
				echo '<pre style="white-space:pre-wrap;direction:ltr;text-align:left;">';
				echo esc_html( $create );
				echo '</pre></details>';
				echo '</td></tr>';
			}
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'لاگ migration (۲۰ تای آخر)', 'medyar-nursing-services' ) . '</h2>';
		$log = MNS_Migrations::log();
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'لاگی نیست.', 'medyar-nursing-services' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'نسخه', 'medyar-nursing-services' ) . '</th>';
			echo '<th>' . esc_html__( 'زمان', 'medyar-nursing-services' ) . '</th>';
			echo '<th>' . esc_html__( 'ok', 'medyar-nursing-services' ) . '</th>';
			echo '<th>' . esc_html__( 'جداول', 'medyar-nursing-services' ) . '</th>';
			echo '<th>' . esc_html__( 'خطا', 'medyar-nursing-services' ) . '</th>';
			echo '</tr></thead><tbody>';

			$reversed = array_reverse( $log );
			foreach ( $reversed as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$ver  = isset( $row['version'] ) ? (int) $row['version'] : 0;
				$time = isset( $row['time'] ) ? (int) $row['time'] : 0;
				$ok   = ! empty( $row['ok'] ) ? 'yes' : 'no';
				$tabs = isset( $row['tables_created'] ) && is_array( $row['tables_created'] )
					? implode( ', ', $row['tables_created'] )
					: '';
				$err  = isset( $row['error'] ) ? (string) $row['error'] : '';

				echo '<tr>';
				echo '<td>' . esc_html( (string) $ver ) . '</td>';
				echo '<td>' . esc_html( $time ? wp_date( 'Y-m-d H:i:s', $time ) : '—' ) . '</td>';
				echo '<td>' . esc_html( $ok ) . '</td>';
				echo '<td><code>' . esc_html( $tabs ) . '</code></td>';
				echo '<td>' . esc_html( $err ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
