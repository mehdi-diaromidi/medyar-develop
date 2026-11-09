<?php
defined( 'ABSPATH' ) || exit;

/**
 * Drains the queue outside the user's request.
 *
 * Triggers, in order of speed:
 *  1. Non-blocking loopback to admin-ajax (spawned at shutdown of the request that enqueued).
 *  2. Real server crontab hitting the `msq_cron` endpoint every minute.
 *  3. WP-Cron every minute (depends on site traffic; last resort).
 * Rows are claimed atomically, so concurrent workers never double-send.
 */
final class MSQ_Worker {

	public const AJAX_ACTION = 'msq_worker';
	public const CRON_ACTION = 'msq_cron';
	public const CRON_HOOK   = 'msq_cron_tick';
	public const PURGE_HOOK  = 'msq_purge';
	public const DIAG_OPTION = 'msq_diag';

	private const TOKEN_SALT   = 'msq_worker';
	private const TIME_BUDGET  = 45;
	private const MAX_PER_RUN  = 40;

	/** @var bool */
	private static $spawn_requested = false;

	/** @var bool */
	private static $spawned = false;

	/** @var bool */
	private static $draining = false;

	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_loopback' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_loopback' ) );
		add_action( 'wp_ajax_' . self::CRON_ACTION, array( __CLASS__, 'handle_system_cron' ) );
		add_action( 'wp_ajax_nopriv_' . self::CRON_ACTION, array( __CLASS__, 'handle_system_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'handle_wp_cron' ) );
		add_action( self::PURGE_HOOK, array( 'MSQ_Queue', 'purge' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['msq_every_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Medyar SMS queue)',
		);
		return $schedules;
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) || ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			self::schedule_events();
		}
	}

	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'msq_every_minute', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	public static function unschedule_events(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}

	/** Spawn one loopback at the end of this request (after DB transactions have committed). */
	public static function request_spawn(): void {
		if ( self::$spawn_requested || self::is_worker_request() ) {
			return;
		}
		self::$spawn_requested = true;
		if ( did_action( 'shutdown' ) ) {
			self::spawn();
			return;
		}
		add_action( 'shutdown', array( __CLASS__, 'spawn' ), 1 );
	}

	/** Fire-and-forget loopback; tries server IP / localhost before the public (CDN) URL. */
	public static function spawn( bool $force = false ): void {
		if ( self::$spawned && ! $force ) {
			return;
		}
		self::$spawned = true;

		$host = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' );
		$urls = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'msq_worker_urls', self::worker_urls() ) ) ) );
		self::diag( array( 'spawn_at' => time() ) );

		foreach ( $urls as $url ) {
			$args = array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => self::AJAX_ACTION,
					'token'  => self::token(),
				),
				'headers'   => array(),
			);
			$target = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
			if ( $host && $target && 0 !== strcasecmp( $host, $target ) ) {
				$args['headers']['Host'] = $host;
			}
			wp_remote_post( $url, (array) apply_filters( 'msq_worker_request_args', $args, $url ) );
		}
	}

	/** @return string[] */
	private static function worker_urls(): array {
		$public = admin_url( 'admin-ajax.php' );
		$parts  = wp_parse_url( $public );
		$path   = (string) ( $parts['path'] ?? '/wp-admin/admin-ajax.php' );
		$urls   = array();
		$ip     = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_ADDR'] ) ) : '';
		if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$urls[] = 'http://' . $ip . $path;
			$urls[] = 'https://' . $ip . $path;
		}
		$urls[] = 'http://127.0.0.1' . $path;
		$urls[] = $public;
		return array_values( array_unique( $urls ) );
	}

	public static function token( ?int $bucket = null ): string {
		if ( null === $bucket ) {
			$bucket = (int) floor( time() / 300 );
		}
		return hash_hmac( 'sha256', self::TOKEN_SALT . '|' . $bucket, wp_salt( 'nonce' ) );
	}

	private static function token_valid( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		$bucket = (int) floor( time() / 300 );
		foreach ( array( $bucket, $bucket - 1 ) as $b ) {
			if ( hash_equals( self::token( $b ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_worker_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing check only.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		return in_array( $action, array( self::AJAX_ACTION, self::CRON_ACTION ), true );
	}

	public static function handle_loopback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- HMAC token below.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		if ( ! self::token_valid( $token ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		self::diag( array( 'worker_at' => time() ) );
		self::drain( 'loopback' );
		wp_die( '1', '', array( 'response' => 200 ) );
	}

	public static function handle_system_cron(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shared secret below.
		$key = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['key'] ) ) : '';
		if ( '' === $key || ! hash_equals( MSQ_Install::cron_key(), $key ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		self::diag( array( 'system_cron_at' => time() ) );
		$ran = self::drain( 'system-cron' );
		wp_send_json_success( array( 'processed' => $ran ) );
	}

	public static function handle_wp_cron(): void {
		self::diag( array( 'wp_cron_at' => time() ) );
		self::drain( 'wp-cron' );
	}

	/**
	 * Process due jobs within a time budget.
	 *
	 * @return int Jobs processed.
	 */
	public static function drain( string $source = 'manual' ): int {
		if ( self::$draining || ! MSQ_Install::is_installed() ) {
			return 0;
		}
		self::$draining = true;
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$worker = substr( $source . ':' . wp_generate_password( 8, false, false ), 0, 40 );
		$start  = time();
		$ran    = 0;
		try {
			MSQ_Queue::release_stale();
			while ( $ran < self::MAX_PER_RUN && ( time() - $start ) < self::TIME_BUDGET ) {
				$ids = MSQ_Queue::due_ids( 10 );
				if ( ! $ids ) {
					break;
				}
				$progress = false;
				foreach ( $ids as $id ) {
					if ( ( time() - $start ) >= self::TIME_BUDGET ) {
						break;
					}
					if ( ! MSQ_Queue::claim( $id, $worker ) ) {
						continue;
					}
					$progress = true;
					++$ran;
					self::process( $id );
				}
				if ( ! $progress ) {
					break;
				}
			}
		} finally {
			self::$draining = false;
		}

		if ( $ran > 0 ) {
			self::diag(
				array(
					'drain_at'   => time(),
					'drain_src'  => $source,
					'drain_jobs' => $ran,
				)
			);
		}
		// Budget ran out with work left: chain one more loopback instead of waiting for cron.
		if ( 'loopback' === $source && MSQ_Queue::has_due() ) {
			self::spawn( true );
		}
		return $ran;
	}

	private static function process( int $id ): void {
		$row = MSQ_Queue::get( $id );
		if ( ! $row ) {
			return;
		}
		$job       = self::decode( $row );
		$transport = MSQ_Transports::get( (string) $job['transport'] );
		if ( ! $transport ) {
			MSQ_Queue::mark_failed( $id, 'unknown_transport:' . $job['transport'], true );
			return;
		}

		try {
			$result = (array) call_user_func( $transport, $job );
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'     => false,
				'detail' => 'exception:' . $e->getMessage(),
				'retry'  => true,
			);
		}

		$ok     = ! empty( $result['ok'] );
		$detail = (string) ( $result['detail'] ?? '' );
		if ( $ok ) {
			MSQ_Queue::mark_sent( $id, 'success' === $detail ? '' : $detail );
			$job['status'] = MSQ_Queue::STATUS_SENT;
			do_action( 'msq_job_sent', $job, $result );
			if ( '' !== $job['channel'] ) {
				do_action( 'msq_job_sent_' . $job['channel'], $job, $result );
			}
			return;
		}

		$retry  = (bool) apply_filters( 'msq_should_retry', (bool) ( $result['retry'] ?? true ), $job, $result );
		$status = MSQ_Queue::mark_failed( $id, '' !== $detail ? $detail : 'fail', $retry );
		error_log( sprintf( '[MSQ] job=%d %s/%s phone=%s failed attempt=%d status=%s detail=%s', $id, $job['channel'], $job['event'], $job['phone'], (int) $job['attempts'], $status, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( MSQ_Queue::STATUS_DEAD === $status ) {
			$job['status'] = $status;
			do_action( 'msq_job_dead', $job, $result );
		}
	}

	/**
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	public static function decode( array $row ): array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$meta    = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$row['id']       = (int) $row['id'];
		$row['attempts'] = (int) $row['attempts'];
		$row['payload']  = is_array( $payload ) ? $payload : array();
		$row['meta']     = is_array( $meta ) ? $meta : array();
		$row['channel']  = (string) $row['channel'];
		$row['event']    = (string) $row['event'];
		$row['body']     = (string) $row['body'];
		return $row;
	}

	/**
	 * @param array<string,int|string> $fields
	 */
	public static function diag( array $fields ): void {
		$diag = get_option( self::DIAG_OPTION, array() );
		update_option( self::DIAG_OPTION, array_merge( is_array( $diag ) ? $diag : array(), $fields ), false );
	}

	/** @return array<string,int|string> */
	public static function diag_snapshot(): array {
		$diag = get_option( self::DIAG_OPTION, array() );
		return is_array( $diag ) ? $diag : array();
	}
}
