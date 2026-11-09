<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ARP_PLUGIN_DIR . 'includes/sms/interface-arp-sms-gateway.php';

class Arp_Faraz_Sms_Gateway implements Arp_Sms_Gateway {

	public const AFFILIATE_SMS_TEMPLATE = "همکار گرامی، وضعیت معرفی بیمار {patient_name} ({service}) به «{status}» تغییر کرد.\nمدیار";

	public const COMMISSION_SMS_TEMPLATE = "همکار گرامی {affiliate_name}، پورسانت معرفی بیمار {patient_name} به مبلغ {amount} تومان به کیف پول شما واریز شد.\nمدیار";

	public const OUTBOX_OPTION = 'arp_sms_outbox';
	public const OUTBOX_HOOK   = 'arp_sms_outbox_tick';
	public const OUTBOX_LOCK   = 'arp_sms_outbox_lock';

	/** admin-ajax action for the non-blocking loopback worker. */
	public const ASYNC_ACTION = 'arp_process_sms_queue_async';

	/** Nonce / HMAC action string for the async worker. */
	public const ASYNC_NONCE = 'arp_process_sms_queue';

	/** One-shot SMS markers (unique user meta). Cleared on reject so re-apply can notify again. */
	public const META_APPLICANT_PENDING_SENT = 'arp_sms_applicant_pending_sent';
	public const META_ADMIN_SIGNUP_SENT      = 'arp_sms_admin_signup_sent';

	/** @var bool Guard re-entrant outbox processing in this PHP process. */
	private static $outbox_busy = false;

	/** @var bool At most one loopback spawn per originating request. */
	private static $async_spawned = false;

	/** Last spawn / worker / drain timestamps for the admin settings page. */
	public const DIAG_OPTION = 'arp_sms_outbox_diag';

	/** @var string Who is draining the outbox in this request: cron|loopback. */
	private static $drain_source = 'cron';

	/**
	 * While set, deliver() writes each rendered SMS to the Medyar SMS Queue instead of calling Faraz.
	 *
	 * @var array{type:string,coalesce:?string,priority:int}|null
	 */
	private static $capture = null;

	/** Staff notifications run before user/patient SMS. */
	private const ADMIN_JOB_TYPES = array( 'admin_new_referral', 'admin_affiliate_signup' );

	/** Medyar SMS Queue plugin is active and its table exists. */
	public static function msq_ready(): bool {
		return class_exists( 'MSQ_Client' ) && MSQ_Client::is_ready();
	}

	public static function init(): void {
		add_action( 'arp_referral_status_changed', array( __CLASS__, 'queue_affiliate_status_sms' ), 10, 2 );
		add_action( 'arp_commission_created', array( __CLASS__, 'queue_commission_sms' ), 20, 4 );
		add_action( self::OUTBOX_HOOK, array( __CLASS__, 'process_outbox' ) );
		add_action( 'init', array( __CLASS__, 'maybe_halt_signup_sms_flood' ), 1 );
		add_action( 'wp_ajax_' . self::ASYNC_ACTION, array( __CLASS__, 'handle_async_worker' ) );
		add_action( 'wp_ajax_nopriv_' . self::ASYNC_ACTION, array( __CLASS__, 'handle_async_worker' ) );
	}

	/**
	 * Emergency stop for signup SMS flood (re-runnable via version bump).
	 * Empties outbox, kills cron ticks, and seals known flooded applicants.
	 */
	public static function maybe_halt_signup_sms_flood(): void {
		// v2: v1 could mark itself done before draining a still-growing outbox.
		if ( get_option( 'arp_sms_flood_halt_v2' ) ) {
			return;
		}

		$q = get_option( self::OUTBOX_OPTION, array() );
		if ( ! is_array( $q ) ) {
			$q = array();
		}

		$dropped = 0;
		foreach ( $q as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$type = (string) ( $job['type'] ?? '' );
			$uid  = (int) ( $job['args']['user_id'] ?? 0 );
			if ( in_array( $type, array( 'applicant_pending', 'admin_affiliate_signup' ), true ) ) {
				++$dropped;
				if ( $uid > 0 ) {
					update_user_meta( $uid, self::META_APPLICANT_PENDING_SENT, (string) time() );
					update_user_meta( $uid, self::META_ADMIN_SIGNUP_SENT, (string) time() );
					delete_user_meta( $uid, 'arp_signup_inflight' );
				}
			}
		}

		// Hard wipe — do not leave any queued SMS that could keep firing.
		update_option( self::OUTBOX_OPTION, array(), false );

		while ( ( $ts = wp_next_scheduled( self::OUTBOX_HOOK ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			wp_unschedule_event( $ts, self::OUTBOX_HOOK );
		}
		delete_option( self::OUTBOX_LOCK );
		delete_transient( self::OUTBOX_LOCK );

		// Seal the reported flooded mobile even if outbox jobs already drained.
		self::seal_applicant_sms_for_mobile( '09932208665' );

		update_option( 'arp_sms_flood_halt_v2', (string) time(), false );
		error_log( '[ARP SMS] flood halt v2: wiped outbox; signup jobs dropped≈' . $dropped ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Mark pending/admin signup SMS as already sent for every user with this mobile.
	 */
	public static function seal_applicant_sms_for_mobile( string $mobile ): void {
		$phone = class_exists( 'Arp_Format' ) ? Arp_Format::iran_mobile( $mobile ) : preg_replace( '/\D+/', '', $mobile );
		if ( '' === $phone || ! preg_match( '/^09\d{9}$/', $phone ) ) {
			return;
		}
		$variants = array_values(
			array_unique(
				array(
					$phone,
					substr( $phone, 1 ), // 9XXXXXXXXX
					'98' . substr( $phone, 1 ),
					'+98' . substr( $phone, 1 ),
				)
			)
		);
		$users = get_users(
			array(
				'fields'     => 'ID',
				'number'     => 20,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'mobile',
						'value'   => $variants,
						'compare' => 'IN',
					),
					array(
						'key'     => 'billing_phone',
						'value'   => $variants,
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $users as $uid ) {
			$uid = (int) $uid;
			update_user_meta( $uid, self::META_APPLICANT_PENDING_SENT, (string) time() );
			update_user_meta( $uid, self::META_ADMIN_SIGNUP_SENT, (string) time() );
			delete_user_meta( $uid, 'arp_signup_inflight' );
			error_log( '[ARP SMS] sealed signup SMS markers for user_id=' . $uid . ' phone=' . $phone ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Persist a serializable SMS job, fire a non-blocking loopback worker, schedule WP-Cron backup.
	 * Faraz HTTP must NOT run in the user-facing request (or on its shutdown) — that blocks the success notice.
	 *
	 * @param array<string,mixed> $args Job args (must be serializable).
	 */
	public static function enqueue( string $type, array $args ): void {
		// Refuse one-shot signup SMS if already claimed/sent for this user.
		if ( 'applicant_pending' === $type ) {
			$uid = (int) ( $args['user_id'] ?? 0 );
			if ( $uid > 0 && get_user_meta( $uid, self::META_APPLICANT_PENDING_SENT, true ) ) {
				return;
			}
		}
		if ( 'admin_affiliate_signup' === $type ) {
			$uid = (int) ( $args['user_id'] ?? 0 );
			if ( $uid > 0 && get_user_meta( $uid, self::META_ADMIN_SIGNUP_SENT, true ) ) {
				return;
			}
		}

		// Preferred: render now, persist one row per recipient in the Medyar SMS Queue (retry + log).
		if ( self::msq_ready() ) {
			$prev          = self::$capture;
			self::$capture = array(
				'type'     => $type,
				'coalesce' => self::outbox_coalesce_key( $type, $args ),
				'priority' => in_array( $type, self::ADMIN_JOB_TYPES, true ) ? MSQ_Client::PRIORITY_ADMIN : MSQ_Client::PRIORITY_NORMAL,
			);
			try {
				self::run_outbox_job( $type, $args );
			} catch ( \Throwable $e ) {
				error_log( '[ARP SMS] queue render failed type=' . $type . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} finally {
				self::$capture = $prev;
			}
			return;
		}

		$q = get_option( self::OUTBOX_OPTION, array() );
		if ( ! is_array( $q ) ) {
			$q = array();
		}

		// Coalesce one-shot / last-wins jobs so retries never stack duplicate SMS.
		$coalesce_key = self::outbox_coalesce_key( $type, $args );
		if ( null !== $coalesce_key ) {
			$q = array_values(
				array_filter(
					$q,
					static function ( $job ) use ( $type, $coalesce_key ) {
						if ( ! is_array( $job ) || ( $job['type'] ?? '' ) !== $type ) {
							return true;
						}
						return self::outbox_coalesce_key( $type, isset( $job['args'] ) && is_array( $job['args'] ) ? $job['args'] : array() ) !== $coalesce_key;
					}
				)
			);
		}

		$q[] = array(
			'type' => $type,
			'args' => $args,
			't'    => time(),
		);
		update_option( self::OUTBOX_OPTION, $q, false );

		// Plan B: give the loopback ~25s head start; avoid racing the same lock at t+5.
		if ( ! wp_next_scheduled( self::OUTBOX_HOOK ) ) {
			wp_schedule_single_event( time() + 25, self::OUTBOX_HOOK );
		}

		self::spawn_async_worker();
	}

	/**
	 * Fire-and-forget loopback so Faraz runs in a separate request after the client already got success.
	 * Tries localhost / server-IP first so WCDN does not sit in front of the worker request.
	 */
	public static function spawn_async_worker(): void {
		if ( self::$async_spawned || self::is_async_worker_request() ) {
			return;
		}
		self::$async_spawned = true;

		$token = self::async_token();
		$host  = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' );
		$urls  = self::async_worker_urls();
		/**
		 * Filters the list of URLs tried for the non-blocking SMS outbox loopback.
		 *
		 * @param string[] $urls Candidate URLs (localhost/IP first, then public admin-ajax).
		 */
		$urls = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'arp_sms_async_worker_urls', $urls ) ) ) );

		self::diag( array( 'spawn_at' => time() ) );

		foreach ( $urls as $url ) {
			$args = array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => self::ASYNC_ACTION,
					'token'  => $token,
				),
				'headers'   => array(),
			);
			$target_host = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
			if ( $host && $target_host && 0 !== strcasecmp( $host, $target_host ) ) {
				$args['headers']['Host'] = $host;
			}
			/**
			 * Filters wp_remote_post args for one SMS outbox loopback attempt.
			 *
			 * @param array<string,mixed> $args Request args.
			 * @param string              $url  Target URL.
			 */
			$args = (array) apply_filters( 'arp_sms_async_worker_request_args', $args, $url );
			wp_remote_post( $url, $args );
		}
	}

	/**
	 * Public admin-ajax last; prefer paths that skip the public CDN edge.
	 *
	 * @return string[]
	 */
	private static function async_worker_urls(): array {
		$public = admin_url( 'admin-ajax.php' );
		$parts  = wp_parse_url( $public );
		$path   = (string) ( $parts['path'] ?? '/wp-admin/admin-ajax.php' );
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}

		$urls = array();
		$ip   = isset( $_SERVER['SERVER_ADDR'] ) ? (string) wp_unslash( $_SERVER['SERVER_ADDR'] ) : '';
		if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$urls[] = 'http://' . $ip . $path;
			$urls[] = 'https://' . $ip . $path;
		}
		$urls[] = 'http://127.0.0.1' . $path;
		$urls[] = $public;

		/**
		 * Filters / replaces the default public loopback URL (kept for BC).
		 *
		 * @param string $public Default admin_url( admin-ajax.php ).
		 */
		$filtered = (string) apply_filters( 'arp_sms_async_worker_url', $public );
		if ( $filtered && $filtered !== $public ) {
			array_unshift( $urls, $filtered );
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * @param array<string,int|string> $fields
	 */
	private static function diag( array $fields ): void {
		$diag = get_option( self::DIAG_OPTION, array() );
		update_option( self::DIAG_OPTION, array_merge( is_array( $diag ) ? $diag : array(), $fields ), false );
	}

	/**
	 * @return array{spawn_at?:int,worker_at?:int,drain_at?:int,drain_src?:string,drain_jobs?:int,queued:int}
	 */
	public static function diag_snapshot(): array {
		$diag = get_option( self::DIAG_OPTION, array() );
		$q    = get_option( self::OUTBOX_OPTION, array() );
		return array_merge( is_array( $diag ) ? $diag : array(), array( 'queued' => is_array( $q ) ? count( $q ) : 0 ) );
	}

	/** HMAC token (current + previous 5-minute window accepted by the worker). */
	public static function async_token( ?int $bucket = null ): string {
		if ( null === $bucket ) {
			$bucket = (int) floor( time() / 300 );
		}
		return hash_hmac( 'sha256', self::ASYNC_NONCE . '|' . $bucket, wp_salt( 'nonce' ) );
	}

	private static function async_token_valid( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		$bucket = (int) floor( time() / 300 );
		foreach ( array( $bucket, $bucket - 1 ) as $b ) {
			if ( hash_equals( self::async_token( $b ), $token ) ) {
				return true;
			}
		}
		// Also accept a standard WP nonce (logged-in loopback with cookies).
		return (bool) wp_verify_nonce( $token, self::ASYNC_NONCE );
	}

	private static function is_async_worker_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing check only.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		return self::ASYNC_ACTION === $action;
	}

	/**
	 * Separate request: drain outbox without holding the original user's HTTP response open.
	 */
	public static function handle_async_worker(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via async_token_valid().
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) ) : '';
		if ( ! self::async_token_valid( $token ) ) {
			status_header( 403 );
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::diag( array( 'worker_at' => time() ) );
		self::$drain_source = 'loopback';
		self::process_outbox();
		wp_die( '1', '', array( 'response' => 200 ) );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return string|null Stable key for coalescing, or null if type is not coalesced.
	 */
	private static function outbox_coalesce_key( string $type, array $args ): ?string {
		switch ( $type ) {
			case 'affiliate_status':
				$rid = (int) ( $args['referral_id'] ?? 0 );
				return $rid > 0 ? 'r:' . $rid : null;
			case 'applicant_pending':
			case 'admin_affiliate_signup':
				$uid = (int) ( $args['user_id'] ?? 0 );
				return $uid > 0 ? 'u:' . $uid : null;
			case 'applicant_decision':
				$uid = (int) ( $args['user_id'] ?? 0 );
				$dec = (string) ( $args['decision'] ?? '' );
				return $uid > 0 ? 'u:' . $uid . ':' . $dec : null;
			case 'admin_new_referral':
				$rid = (int) ( $args['referral_id'] ?? 0 );
				return $rid > 0 ? 'r:' . $rid : null;
			case 'commission':
				$cid = (int) ( $args['commission_id'] ?? 0 );
				return $cid > 0 ? 'c:' . $cid : null;
			default:
				return null;
		}
	}

	/**
	 * Drain persisted outbox (async loopback and/or cron). Transient lock prevents double-send.
	 */
	public static function process_outbox(): void {
		if ( self::$outbox_busy ) {
			return;
		}

		$now = time();
		// Transient is a fast TTL signal; add_option is the atomic cross-request claim.
		if ( false !== get_transient( self::OUTBOX_LOCK ) ) {
			return;
		}
		if ( ! add_option( self::OUTBOX_LOCK, (string) $now, '', 'no' ) ) {
			$lock_at = (int) get_option( self::OUTBOX_LOCK, 0 );
			if ( $lock_at && ( $now - $lock_at ) < 120 ) {
				return;
			}
			// Stale lock from a killed worker — take over.
			update_option( self::OUTBOX_LOCK, (string) $now, false );
		}
		set_transient( self::OUTBOX_LOCK, (string) $now, 120 );

		self::$outbox_busy = true;
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$guard = 0;
			$ran   = 0;
			while ( $guard < 20 ) {
				++$guard;
				$q = get_option( self::OUTBOX_OPTION, array() );
				if ( ! is_array( $q ) || ! $q ) {
					break;
				}
				// Prefer staff notifications over patient SMS when both sit in the queue.
				$pick = 0;
				foreach ( $q as $i => $candidate ) {
					if ( is_array( $candidate ) && in_array( (string) ( $candidate['type'] ?? '' ), array( 'admin_new_referral', 'admin_affiliate_signup' ), true ) ) {
						$pick = (int) $i;
						break;
					}
				}
				$job = $q[ $pick ];
				array_splice( $q, $pick, 1 );
				update_option( self::OUTBOX_OPTION, array_values( $q ), false );
				if ( ! is_array( $job ) || empty( $job['type'] ) ) {
					continue;
				}
				$args = isset( $job['args'] ) && is_array( $job['args'] ) ? $job['args'] : array();
				++$ran;
				try {
					self::run_outbox_job( (string) $job['type'], $args );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					error_log( '[ARP SMS] outbox job failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
			if ( $ran > 0 ) {
				self::diag(
					array(
						'drain_at'   => time(),
						'drain_src'  => self::$drain_source,
						'drain_jobs' => $ran,
					)
				);
			}

			$remaining = get_option( self::OUTBOX_OPTION, array() );
			if ( is_array( $remaining ) && $remaining && ! wp_next_scheduled( self::OUTBOX_HOOK ) ) {
				wp_schedule_single_event( time() + 20, self::OUTBOX_HOOK );
			}
		} finally {
			self::$outbox_busy = false;
			delete_transient( self::OUTBOX_LOCK );
			delete_option( self::OUTBOX_LOCK );
		}
	}

	/** Clear one-shot signup SMS markers so a later re-application can notify again. */
	public static function clear_signup_sms_markers( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		delete_user_meta( $user_id, self::META_APPLICANT_PENDING_SENT );
		delete_user_meta( $user_id, self::META_ADMIN_SIGNUP_SENT );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private static function run_outbox_job( string $type, array $args ): void {
		switch ( $type ) {
			case 'patient_referral':
				self::send_patient_referral_sms(
					(string) ( $args['phone'] ?? '' ),
					(string) ( $args['patient_name'] ?? '' ),
					(string) ( $args['affiliate_code'] ?? '' )
				);
				break;
			case 'admin_new_referral':
				self::notify_admins_new_referral(
					(int) ( $args['referral_id'] ?? 0 ),
					(int) ( $args['affiliate_user_id'] ?? 0 ),
					(string) ( $args['patient_name'] ?? '' ),
					(string) ( $args['patient_phone'] ?? '' ),
					(string) ( $args['service_name'] ?? '' )
				);
				break;
			case 'affiliate_status':
				self::send_affiliate_status_sms(
					(int) ( $args['referral_id'] ?? 0 ),
					(int) ( $args['status_id'] ?? 0 )
				);
				break;
			case 'commission':
				self::send_commission_sms(
					(int) ( $args['referral_id'] ?? 0 ),
					(float) ( $args['amount'] ?? 0 ),
					(int) ( $args['affiliate_user_id'] ?? 0 ),
					(int) ( $args['commission_id'] ?? 0 )
				);
				break;
			case 'admin_affiliate_signup':
				self::notify_admins_affiliate_signup(
					(int) ( $args['user_id'] ?? 0 ),
					(string) ( $args['full_name'] ?? '' ),
					(string) ( $args['mobile'] ?? '' ),
					(string) ( $args['job_name'] ?? '' )
				);
				break;
			case 'applicant_pending':
				self::notify_applicant_pending( (int) ( $args['user_id'] ?? 0 ) );
				break;
			case 'applicant_decision':
				self::notify_applicant_decision(
					(int) ( $args['user_id'] ?? 0 ),
					(string) ( $args['decision'] ?? '' ),
					(string) ( $args['note'] ?? '' )
				);
				break;
		}
	}

	/**
	 * @deprecated 1.2.1 Shutdown deferral removed; use enqueue() + async loopback.
	 *
	 * @param callable():void $callback Ignored — spawns async worker instead of running inline.
	 */
	public static function defer( callable $callback ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		self::spawn_async_worker();
	}

	/**
	 * Queue affiliate status SMS (coalesced per referral) via durable outbox.
	 *
	 * @param int $referral_id   Referral ID.
	 * @param int $new_status_id New status.
	 */
	public static function queue_affiliate_status_sms( $referral_id, $new_status_id ): void {
		if ( '1' !== (string) get_option( 'arp_affiliate_sms_enabled', '1' ) ) {
			return;
		}
		self::enqueue(
			'affiliate_status',
			array(
				'referral_id' => (int) $referral_id,
				'status_id'   => (int) $new_status_id,
			)
		);
	}

	/**
	 * @param int   $referral_id       Referral ID.
	 * @param float $amount            Commission amount.
	 * @param int   $affiliate_user_id Affiliate user ID.
	 * @param int   $commission_id     Commission row ID.
	 */
	public static function queue_commission_sms( $referral_id, $amount, $affiliate_user_id = 0, $commission_id = 0 ): void {
		if ( '1' !== (string) get_option( 'arp_commission_sms_enabled', '1' ) ) {
			return;
		}
		self::enqueue(
			'commission',
			array(
				'referral_id'       => (int) $referral_id,
				'amount'            => (float) $amount,
				'affiliate_user_id' => (int) $affiliate_user_id,
				'commission_id'     => (int) $commission_id,
			)
		);
	}

	public static function send_commission_sms( int $referral_id, float $amount, int $affiliate_user_id, int $commission_id ): bool {
		$referral = $referral_id > 0 ? Arp_Referrals_Repo::get( $referral_id ) : null;
		if ( $affiliate_user_id <= 0 && $referral ) {
			$affiliate_user_id = (int) $referral['affiliate_user_id'];
		}
		$phone = self::user_mobile( $affiliate_user_id );
		if ( '' === $phone || $amount <= 0 ) {
			error_log( '[ARP SMS] commission SMS skipped: phone/amount missing ref=' . $referral_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		$service = $referral ? Arp_Services_Repo::get( (int) $referral['service_id'] ) : null;
		$vars    = array(
			'affiliate_name' => self::user_name( $affiliate_user_id ),
			'patient_name'   => $referral ? (string) $referral['patient_name'] : '',
			'service'        => $service ? (string) $service['name'] : '',
			'amount'         => number_format( $amount ),
			'referral_id'    => (string) $referral_id,
			'commission_id'  => (string) $commission_id,
		);
		$message = self::commission_message( $vars );
		/**
		 * Filters the SMS sent to the affiliate when a commission is paid.
		 *
		 * @param string              $message Rendered message.
		 * @param array<string,mixed> $vars    Template vars.
		 * @param int                 $referral_id Referral ID.
		 */
		$message = (string) apply_filters( 'arp_commission_sms_message', $message, $vars, $referral_id );
		$ok      = ( new self() )->deliver(
			$phone,
			$message,
			self::pattern_code( 'arp_commission_sms_pattern' ),
			$vars
		);
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[ARP SMS] commission SMS ref=%d user=%d ok=%s', $referral_id, $affiliate_user_id, $ok ? '1' : '0' )
		);
		return $ok;
	}

	/**
	 * @param array{affiliate_name:string,patient_name:string,service:string,amount:string,referral_id:string,commission_id:string} $vars
	 */
	public static function commission_message( array $vars ): string {
		$template = (string) get_option( 'arp_commission_sms_template', self::COMMISSION_SMS_TEMPLATE );
		if ( '' === trim( $template ) ) {
			$template = self::COMMISSION_SMS_TEMPLATE;
		}
		$replace = array();
		foreach ( $vars as $key => $value ) {
			$replace[ '{' . $key . '}' ] = (string) $value;
		}
		return trim( strtr( $template, $replace ) );
	}

	public static function send_affiliate_status_sms( int $referral_id, int $status_id ): bool {
		$referral = Arp_Referrals_Repo::get( $referral_id );
		if ( ! $referral || (int) $referral['status_id'] !== $status_id ) {
			return false;
		}
		$status = Arp_Statuses_Repo::get( $status_id );
		$phone  = self::user_mobile( (int) $referral['affiliate_user_id'] );
		if ( ! $status || '' === $phone ) {
			return false;
		}
		$service = Arp_Services_Repo::get( (int) $referral['service_id'] );
		$vars    = array(
			'affiliate_name' => self::user_name( (int) $referral['affiliate_user_id'] ),
			'patient_name'   => (string) $referral['patient_name'],
			'service'        => $service ? (string) $service['name'] : '',
			'status'         => (string) $status['name'],
		);
		$message = self::affiliate_status_message( $vars );
		/**
		 * Filters the SMS sent to the affiliate when one of their referrals changes status.
		 *
		 * @param string              $message  Rendered message.
		 * @param array<string,mixed> $referral Referral row.
		 * @param array<string,mixed> $status   New status row.
		 */
		$message = (string) apply_filters( 'arp_affiliate_status_sms_message', $message, $referral, $status );
		$ok      = ( new self() )->deliver(
			$phone,
			$message,
			self::pattern_code( 'arp_affiliate_sms_pattern' ),
			$vars
		);
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[ARP SMS] affiliate status SMS ref=%d phone=%s ok=%s', $referral_id, $phone, $ok ? '1' : '0' )
		);
		return $ok;
	}

	/**
	 * @param array{affiliate_name:string,patient_name:string,service:string,status:string} $vars Template values.
	 */
	public static function affiliate_status_message( array $vars ): string {
		$template = (string) get_option( 'arp_affiliate_sms_template', self::AFFILIATE_SMS_TEMPLATE );
		$replace  = array();
		foreach ( $vars as $key => $value ) {
			$replace[ '{' . $key . '}' ] = $value;
		}
		return trim( strtr( $template, $replace ) );
	}

	private static function user_mobile( int $user_id ): string {
		foreach ( array( 'mobile', 'billing_phone', 'shipping_phone' ) as $key ) {
			$phone = Arp_Format::iran_mobile( (string) get_user_meta( $user_id, $key, true ) );
			if ( '' !== $phone ) {
				return $phone;
			}
		}
		if ( class_exists( '\DrPlus\Utils\User' ) ) {
			$phone = Arp_Format::iran_mobile( (string) \DrPlus\Utils\User::get_phone( $user_id ) );
			if ( '' !== $phone ) {
				return $phone;
			}
		}
		$user = get_userdata( $user_id );
		return $user ? Arp_Format::iran_mobile( (string) $user->user_login ) : '';
	}

	/** Real name for {affiliate_name}; masked-mobile display names fall back to "همکار". */
	private static function user_name( int $user_id ): string {
		$name = trim( get_user_meta( $user_id, 'first_name', true ) . ' ' . get_user_meta( $user_id, 'last_name', true ) );
		if ( '' === $name ) {
			$user = get_userdata( $user_id );
			$name = $user && preg_match( '/\p{L}/u', (string) $user->display_name ) ? (string) $user->display_name : '';
		}
		return '' !== $name ? $name : 'همکار';
	}

	/**
	 * Normalize Faraz pattern attribute keys (strip `{braces}`).
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,string>
	 */
	public static function pattern_attrs( array $vars ): array {
		$out = array();
		foreach ( $vars as $key => $value ) {
			$k = trim( (string) $key );
			$k = trim( $k, '{}' );
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = (string) $value;
		}
		return $out;
	}

	/** Optional Faraz pattern code from an option (empty = free-text). */
	public static function pattern_code( string $option_key ): string {
		return trim( (string) get_option( $option_key, '' ) );
	}

	/**
	 * Send Faraz pattern without the plugin's hexsafe key rewrite.
	 * Faraz's farazsms_pattern_hexsafe_name() renames keys like affiliate_name → vaffiliate_name
	 * (because "af" looks like hex), which breaks panel patterns that expect the original names.
	 *
	 * @param array<string,string> $attrs
	 * @return array{ok:bool,detail:string}
	 */
	private static function send_pattern( string $phone, string $pattern_code, array $attrs ): array {
		$phone        = class_exists( 'Arp_Format' ) ? Arp_Format::iran_mobile( $phone ) : preg_replace( '/\D+/', '', $phone );
		$pattern_code = trim( $pattern_code );
		if ( '' === $phone || '' === $pattern_code ) {
			return array( 'ok' => false, 'detail' => 'empty_phone_or_pattern' );
		}

		// Preferred: call Faraz HTTP helper with pristine attribute keys.
		if ( function_exists( 'farazsms_sms_request' ) && function_exists( 'farazsms_get_apikey' ) && function_exists( 'farazsms_sending_line' ) ) {
			if ( function_exists( 'farazsms_normalize_phone' ) ) {
				$phone = farazsms_normalize_phone( $phone );
			}
			$apikey = farazsms_get_apikey();
			$sender = farazsms_sending_line( '' );
			if ( '' === $apikey || '' === $sender ) {
				return array( 'ok' => false, 'detail' => 'missing_apikey_or_sender' );
			}
			if ( function_exists( 'farazsms_tr_num' ) ) {
				foreach ( $attrs as $k => $v ) {
					$attrs[ $k ] = farazsms_tr_num( (string) $v );
				}
			}
			if ( function_exists( 'farazsms_attr_placeholder_if_empty' ) ) {
				$attrs = array_map( 'farazsms_attr_placeholder_if_empty', $attrs );
			} else {
				foreach ( $attrs as $k => $v ) {
					if ( '' === trim( (string) $v ) ) {
						$attrs[ $k ] = '-';
					}
				}
			}
			$body     = array(
				'code'          => $pattern_code,
				'recipient'     => $phone,
				'attributes'    => $attrs,
				'line_number'   => $sender,
				'number_format' => 'english',
			);
			$response = farazsms_sms_request(
				'POST',
				'sms/pattern',
				array(
					'body'    => $body,
					'apikey'  => $apikey,
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				return array( 'ok' => false, 'detail' => 'curl_error:' . $response->get_error_message() );
			}
			$data = function_exists( 'farazsms_sms_decode' ) ? farazsms_sms_decode( $response ) : null;
			if ( is_array( $data ) && isset( $data['status'] ) && 'success' === $data['status'] ) {
				return array( 'ok' => true, 'detail' => 'success' );
			}
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_remote_retrieve_body( $response );
			if ( function_exists( 'farazsms_flatten_api_message' ) ) {
				$msg = farazsms_flatten_api_message( $msg );
			}
			return array( 'ok' => false, 'detail' => 'api_error:' . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) );
		}

		// Fallback: public helper (may mangle keys that start with hex digits).
		if ( ! function_exists( 'farazsms_send_pattern' ) ) {
			return array( 'ok' => false, 'detail' => 'farazsms_send_pattern_missing' );
		}
		$result = farazsms_send_pattern( $phone, $pattern_code, $attrs );
		$ok     = is_array( $result ) ? ! empty( $result['ok'] ) : (bool) $result;
		return array(
			'ok'     => $ok,
			'detail' => is_array( $result ) ? (string) ( $result['message'] ?? '' ) : (string) $result,
		);
	}

	/**
	 * Last deliver attempt meta (for admin test UI / diagnostics).
	 *
	 * @var array{mode?:string,pattern?:string,ok?:bool,detail?:string}
	 */
	private static $last_deliver = array();

	/** @return array{mode?:string,pattern?:string,ok?:bool,detail?:string} */
	public static function last_deliver_meta(): array {
		return self::$last_deliver;
	}

	/**
	 * Send SMS: when a Faraz pattern code is set, ALWAYS use pattern only (no free-text fallback).
	 * When pattern is empty, free-text via farazsms_send_sms.
	 *
	 * @param array<string,mixed> $pattern_vars Attribute map for the pattern (with or without braces).
	 */
	public function deliver( string $phone, string $message, string $pattern_code = '', array $pattern_vars = array() ): bool {
		$phone = class_exists( 'Arp_Format' )
			? Arp_Format::iran_mobile( $phone )
			: preg_replace( '/\D+/', '', $phone );
		$pattern_code = trim( $pattern_code );
		$message      = trim( $message );
		self::$last_deliver = array(
			'mode'    => '' !== $pattern_code ? 'pattern' : 'freetext',
			'pattern' => $pattern_code,
			'ok'      => false,
			'detail'  => '',
		);
		if ( '' === $phone ) {
			self::$last_deliver['detail'] = 'empty_phone';
			return false;
		}

		if ( null !== self::$capture ) {
			return self::queue_capture( $phone, $message, $pattern_code, $pattern_vars );
		}

		// Pattern configured → pattern API only (never silent free-text).
		if ( '' !== $pattern_code ) {
			$attrs  = self::pattern_attrs( $pattern_vars );
			$result = self::send_pattern( $phone, $pattern_code, $attrs );
			$ok     = ! empty( $result['ok'] );
			self::$last_deliver['ok']     = $ok;
			self::$last_deliver['detail'] = (string) ( $result['detail'] ?? '' );
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[ARP SMS] pattern send phone=%s code=%s ok=%s detail=%s',
					$phone,
					$pattern_code,
					$ok ? '1' : '0',
					self::$last_deliver['detail']
				)
			);
			return $ok;
		}

		$ok = $this->send( $phone, $message );
		self::$last_deliver['ok']     = $ok;
		self::$last_deliver['detail'] = $ok ? 'freetext_ok' : 'freetext_fail';
		return $ok;
	}

	/**
	 * @param array<string,mixed> $pattern_vars
	 */
	private static function queue_capture( string $phone, string $message, string $pattern_code, array $pattern_vars ): bool {
		$ctx  = (array) self::$capture;
		$type = (string) $ctx['type'];
		// Permanent IDs never repeat; signup/decision cycles may legitimately repeat after a re-application.
		$modes = array(
			'affiliate_status'   => 'replace',
			'commission'         => 'skip',
			'admin_new_referral' => 'skip',
		);
		$id = MSQ_Client::enqueue(
			array(
				'channel'    => 'arp',
				'event'      => $type,
				'phone'      => $phone,
				'body'       => $message,
				'pattern'    => $pattern_code,
				'vars'       => self::pattern_attrs( $pattern_vars ),
				'priority'   => (int) $ctx['priority'],
				'dedupe_key' => null !== $ctx['coalesce'] ? 'arp:' . $type . ':' . $ctx['coalesce'] . ':' . $phone : '',
				'dedupe'     => $modes[ $type ] ?? 'pending',
			)
		);
		self::$last_deliver['ok']     = $id > 0;
		self::$last_deliver['detail'] = $id > 0 ? 'queued:' . $id : 'queue_failed';
		return $id > 0;
	}

	public function send( string $phone, string $message ): bool {
		// Normalize FA/AR digits + 98… forms; bare preg_replace('/\D/') would wipe Persian digits.
		$phone = class_exists( 'Arp_Format' )
			? Arp_Format::iran_mobile( $phone )
			: preg_replace( '/\D+/', '', $phone );
		$message = trim( $message );
		if ( '' === $phone || '' === $message ) {
			return false;
		}

		if ( function_exists( 'farazsms_send_sms' ) ) {
			if ( function_exists( 'farazsms_is_ready' ) && ! farazsms_is_ready() ) {
				error_log( '[ARP SMS] Faraz not ready (api/sender); attempting send anyway to ' . $phone ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$result = farazsms_send_sms( $phone, $message );
			$ok     = is_array( $result ) ? ! empty( $result['ok'] ) : (bool) $result;
			if ( ! $ok ) {
				error_log( '[ARP SMS] Faraz send failed: ' . wp_json_encode( $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $ok;
		}

		error_log( '[ARP SMS] farazsms_send_sms missing; message not sent to ' . $phone ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return false;
	}

	/**
	 * @param bool $force Send even when patient SMS is switched off (admin test message).
	 */
	public static function send_patient_referral_sms( string $phone, string $patient_name, string $affiliate_code, bool $force = false ): bool {
		if ( ! $force && '1' !== (string) get_option( 'arp_patient_sms_enabled', '1' ) ) {
			return false;
		}
		$template = (string) get_option(
			'arp_patient_sms_template',
			'سلام {patient_name} عزیز، معرفی شما در مدیار ثبت شد. به زودی با شما تماس گرفته می‌شود.'
		);
		$message  = str_replace(
			array( '{patient_name}', '{affiliate_code}' ),
			array( $patient_name, $affiliate_code ),
			$template
		);
		$message = apply_filters( 'arp_patient_sms_message', $message, $phone );

		return ( new self() )->deliver(
			$phone,
			$message,
			self::pattern_code( 'arp_patient_sms_pattern' ),
			array(
				'patient_name'   => $patient_name,
				'affiliate_code' => $affiliate_code,
			)
		);
	}

	/**
	 * Mobile number for an administrator (settings UI + notify).
	 * Source of truth: WooCommerce Billing phone (`billing_phone` / id="billing_phone").
	 */
	public static function admin_mobile( int $user_id ): string {
		return Arp_Format::iran_mobile( (string) get_user_meta( $user_id, 'billing_phone', true ) );
	}

	/**
	 * Selected administrator user IDs that still have the administrator role.
	 *
	 * @return int[]
	 */
	public static function admin_recipient_ids(): array {
		$raw = get_option( 'arp_admin_sms_recipient_ids', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$uid = absint( $id );
			if ( ! $uid ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
				$ids[] = $uid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Send the same message to every configured admin recipient that has a mobile.
	 * Optional Faraz pattern: when $pattern_code is non-empty, pattern API is used.
	 *
	 * @param array<string,mixed> $pattern_vars
	 * @return int Number of successful sends.
	 */
	public static function notify_admins( string $message, string $pattern_code = '', array $pattern_vars = array() ): int {
		if ( '1' !== (string) get_option( 'arp_admin_sms_enabled', '1' ) ) {
			return 0;
		}
		$message      = trim( $message );
		$pattern_code = trim( $pattern_code );
		if ( '' === $message && '' === $pattern_code ) {
			return 0;
		}
		$ids = self::admin_recipient_ids();
		if ( ! $ids ) {
			error_log( '[ARP SMS] notify_admins: no recipient IDs selected' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}
		$gateway = new self();
		$sent    = 0;
		$seen    = array();
		foreach ( $ids as $uid ) {
			$phone = self::admin_mobile( $uid );
			if ( '' === $phone || isset( $seen[ $phone ] ) ) {
				continue;
			}
			$seen[ $phone ] = true;
			if ( $gateway->deliver( $phone, $message, $pattern_code, $pattern_vars ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Notify admins after a new affiliate application is queued for review.
	 */
	public static function notify_admins_affiliate_signup( int $user_id, string $full_name, string $mobile, string $job_name ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		// Claim send slot before HTTP so parallel outbox workers cannot multi-send.
		if ( ! add_user_meta( $user_id, self::META_ADMIN_SIGNUP_SENT, (string) time(), true ) ) {
			return 0;
		}

		$name = '' !== trim( $full_name ) ? trim( $full_name ) : self::user_name( $user_id );
		$job  = '' !== $job_name ? $job_name : '—';
		$mob  = '' !== $mobile ? $mobile : '—';
		$docs = class_exists( 'Arp_Affiliate_Applications' ) ? count( Arp_Affiliate_Applications::document_ids( $user_id ) ) : 0;
		$url  = admin_url( 'admin.php?page=arp-applications' );

		$message = sprintf(
			"درخواست طرح همکاران در صف بررسی است.\nنام: %s\nموبایل: %s\nشغل: %s\nمدارک: %s\nلطفاً در پیشخوان تأیید یا رد کنید.\n%s",
			$name,
			$mob,
			$job,
			$docs > 0 ? (string) $docs . ' فایل' : 'بدون مدرک',
			$url
		);

		/**
		 * Filters the SMS sent to admins when an affiliate applies.
		 *
		 * @param string $message Rendered message.
		 * @param int    $user_id Affiliate user ID.
		 */
		$message = (string) apply_filters( 'arp_admin_affiliate_signup_sms', $message, $user_id );

		return self::notify_admins(
			$message,
			self::pattern_code( 'arp_admin_signup_sms_pattern' ),
			array(
				'name'   => $name,
				'mobile' => $mob,
				'job'    => $job,
				'docs'   => $docs > 0 ? (string) $docs . ' فایل' : 'بدون مدرک',
				'url'    => $url,
			)
		);
	}

	/**
	 * SMS to the applicant when their request enters the review queue.
	 */
	public static function notify_applicant_pending( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		// Already sent/claimed for this application cycle — never retry (failed HTTP must not re-open the slot).
		if ( get_user_meta( $user_id, self::META_APPLICANT_PENDING_SENT, true ) ) {
			return true;
		}
		// Atomic claim BEFORE Faraz HTTP. Keep the claim even if deliver() returns false
		// (API may have accepted the SMS while PHP timed out — deleting meta caused the flood loop).
		if ( ! add_user_meta( $user_id, self::META_APPLICANT_PENDING_SENT, (string) time(), true ) ) {
			return true;
		}

		$phone = self::user_mobile( $user_id );
		if ( '' === $phone ) {
			return false;
		}
		$name    = self::user_name( $user_id );
		$message = sprintf(
			"%s عزیز، درخواست شما برای دسترسی به پنل طرح همکاران مدیار در صف بررسی قرار گرفت. نتیجه از طریق پیامک اطلاع‌رسانی می‌شود.\nمدیار",
			$name
		);
		$message = (string) apply_filters( 'arp_applicant_pending_sms', $message, $user_id );
		return ( new self() )->deliver(
			$phone,
			$message,
			self::pattern_code( 'arp_applicant_pending_sms_pattern' ),
			array( 'name' => $name )
		);
	}

	/**
	 * SMS to the applicant after approve/reject.
	 *
	 * @param string $decision approved|rejected
	 */
	public static function notify_applicant_decision( int $user_id, string $decision, string $note = '' ): bool {
		$phone = self::user_mobile( $user_id );
		if ( '' === $phone ) {
			return false;
		}
		$name = self::user_name( $user_id );
		if ( 'approved' === $decision ) {
			$message = sprintf(
				"%s عزیز، درخواست طرح همکاران شما تأیید شد. هم‌اکنون می‌توانید وارد پنل طرح همکاران شوید.\nمدیار",
				$name
			);
		} else {
			$extra   = '' !== trim( $note ) ? ( "\nتوضیح: " . trim( $note ) ) : '';
			$message = sprintf(
				"%s عزیز، متأسفانه درخواست طرح همکاران شما رد شد.%s\nبرای اطلاعات بیشتر با پشتیبانی مدیار تماس بگیرید.\nمدیار",
				$name,
				$extra
			);
		}
		$message = (string) apply_filters( 'arp_applicant_decision_sms', $message, $user_id, $decision, $note );
		$pattern = 'approved' === $decision
			? self::pattern_code( 'arp_applicant_approved_sms_pattern' )
			: self::pattern_code( 'arp_applicant_rejected_sms_pattern' );
		return ( new self() )->deliver(
			$phone,
			$message,
			$pattern,
			array(
				'name' => $name,
				'note' => trim( $note ),
			)
		);
	}

	/**
	 * Notify admins after a new patient referral is created.
	 */
	public static function notify_admins_new_referral( int $referral_id, int $affiliate_user_id, string $patient_name, string $patient_phone, string $service_name ): int {
		$aff_name = self::user_name( $affiliate_user_id );
		$code     = Arp_Referral_Code::get_for_user( $affiliate_user_id );
		$svc      = '' !== $service_name ? $service_name : '—';

		$message = sprintf(
			"معرفی جدید ثبت شد.\nهمکار: %s (%s)\nبیمار: %s\nموبایل بیمار: %s\nخدمت: %s\nشناسه: %d",
			$aff_name,
			$code ?: '—',
			$patient_name,
			$patient_phone,
			$svc,
			$referral_id
		);

		/**
		 * Filters the SMS sent to admins when a new referral is registered.
		 *
		 * @param string $message     Rendered message.
		 * @param int    $referral_id Referral ID.
		 */
		$message = (string) apply_filters( 'arp_admin_new_referral_sms', $message, $referral_id );

		$sent = self::notify_admins(
			$message,
			self::pattern_code( 'arp_admin_referral_sms_pattern' ),
			array(
				'affiliate_name' => $aff_name,
				'affiliate_code' => $code ?: '—',
				'patient_name'   => $patient_name,
				'patient_phone'  => $patient_phone,
				'service'        => $svc,
				'referral_id'    => (string) $referral_id,
			)
		);
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[ARP SMS] admin new-referral SMS ref=%d sent=%d recipients=%d', $referral_id, $sent, count( self::admin_recipient_ids() ) )
		);
		return $sent;
	}

	/** Sanitize Faraz pattern code from settings POST. */
	public static function sanitize_pattern_option( $raw ): string {
		$code = sanitize_text_field( (string) $raw );
		$code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $code );
		return is_string( $code ) ? $code : '';
	}
}
