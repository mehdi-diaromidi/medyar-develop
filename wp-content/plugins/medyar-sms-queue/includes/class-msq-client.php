<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public API used by domain plugins (affiliate-referral, medyar-toman-wallet, booking adapters).
 *
 *     if ( class_exists( 'MSQ_Client' ) && MSQ_Client::is_ready() ) {
 *         MSQ_Client::enqueue( array(
 *             'channel'    => 'wallet',
 *             'event'      => 'withdraw_registered',
 *             'phone'      => '09121234567',
 *             'body'       => 'متن آزاد',
 *             'pattern'    => 'PatternCode',      // optional; when set the pattern API is used
 *             'vars'       => array( 'name' => 'علی' ),
 *             'priority'   => MSQ_Client::PRIORITY_ADMIN,
 *             'dedupe_key' => 'wallet:withdraw_registered:tx42',
 *             'meta'       => array( 'tx_id' => 42 ), // echoed back on msq_job_sent_{channel}
 *         ) );
 *     }
 */
final class MSQ_Client {

	public const PRIORITY_ADMIN  = 10;
	public const PRIORITY_NORMAL = 20;
	public const PRIORITY_LOW    = 30;

	/** @var int Nesting depth of replay() calls (worker re-running a captured send). */
	private static $replay_depth = 0;

	public static function is_ready(): bool {
		return MSQ_Install::is_installed();
	}

	/**
	 * Persist one SMS job and schedule a background worker.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $phone        Recipient (any Iranian format). Required.
	 *     @type string $body         Free text (also logged for pattern sends).
	 *     @type string $pattern      Faraz pattern code.
	 *     @type array  $vars         Pattern attributes (keys may include braces).
	 *     @type string $channel      Owner: arp|wallet|booking|…
	 *     @type string $event        Event slug shown in the admin log.
	 *     @type string $transport    Registered transport ID (default faraz).
	 *     @type array  $data         Extra transport payload (custom transports).
	 *     @type array  $meta         Domain data passed back to msq_job_sent hooks.
	 *     @type int    $priority     Lower runs first (PRIORITY_* constants).
	 *     @type string $dedupe_key   Prevents duplicates while a matching job is pending/sent.
	 *     @type string $dedupe       skip (default: never twice) | pending (only while one is still queued)
	 *                                | replace (last-wins while still pending).
	 *     @type int    $delay        Seconds before the job becomes due.
	 *     @type int    $max_attempts Default 3.
	 * }
	 * @return int Job ID (existing ID when deduplicated), 0 on failure.
	 */
	public static function enqueue( array $args ): int {
		if ( ! self::is_ready() ) {
			return 0;
		}
		$a = wp_parse_args(
			$args,
			array(
				'phone'        => '',
				'body'         => '',
				'pattern'      => '',
				'vars'         => array(),
				'channel'      => '',
				'event'        => '',
				'transport'    => 'faraz',
				'data'         => array(),
				'meta'         => array(),
				'priority'     => self::PRIORITY_NORMAL,
				'dedupe_key'   => '',
				'dedupe'       => 'skip',
				'delay'        => 0,
				'max_attempts' => 3,
			)
		);

		$phone   = MSQ_Phone::normalize( (string) $a['phone'] );
		$body    = trim( (string) $a['body'] );
		$pattern = trim( (string) $a['pattern'] );
		if ( '' === $phone ) {
			return 0;
		}
		if ( 'faraz' === $a['transport'] && '' === $body && '' === $pattern ) {
			return 0;
		}

		$fields = array(
			'phone'        => $phone,
			'body'         => $body,
			'pattern_code' => substr( $pattern, 0, 64 ),
			'payload'      => wp_json_encode(
				array(
					'vars' => self::normalize_vars( (array) $a['vars'] ),
					'data' => (array) $a['data'],
				),
				JSON_UNESCAPED_UNICODE
			),
			'meta'         => wp_json_encode( (array) $a['meta'], JSON_UNESCAPED_UNICODE ),
			'priority'     => max( 0, min( 255, (int) $a['priority'] ) ),
			'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, (int) $a['delay'] ) ),
		);

		$dedupe_key = substr( (string) $a['dedupe_key'], 0, 191 );
		if ( '' !== $dedupe_key ) {
			$existing = MSQ_Queue::find_active_by_dedupe( $dedupe_key );
			if ( $existing ) {
				$open = in_array( $existing['status'], array( MSQ_Queue::STATUS_PENDING, MSQ_Queue::STATUS_RESERVED ), true );
				if ( 'replace' === $a['dedupe'] && MSQ_Queue::STATUS_PENDING === $existing['status'] ) {
					MSQ_Queue::update_pending( (int) $existing['id'], $fields );
					MSQ_Worker::request_spawn();
					return (int) $existing['id'];
				}
				if ( 'skip' === $a['dedupe'] || ( 'pending' === $a['dedupe'] && $open ) ) {
					return (int) $existing['id'];
				}
			}
		}

		$id = MSQ_Queue::insert(
			array_merge(
				$fields,
				array(
					'channel'      => sanitize_key( (string) $a['channel'] ),
					'event'        => substr( (string) $a['event'], 0, 100 ),
					'transport'    => sanitize_key( (string) $a['transport'] ),
					'dedupe_key'   => '' !== $dedupe_key ? $dedupe_key : null,
					'max_attempts' => max( 1, min( 10, (int) $a['max_attempts'] ) ),
				)
			)
		);
		if ( $id > 0 ) {
			MSQ_Worker::request_spawn();
			do_action( 'msq_job_enqueued', $id, $a );
		} else {
			error_log( '[MSQ] enqueue failed channel=' . $a['channel'] . ' event=' . $a['event'] . ' phone=' . $phone ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $id;
	}

	/**
	 * Run a captured send for real (worker side). Interceptors must not re-queue while replaying.
	 *
	 * @param callable $callback
	 * @return mixed Callback result.
	 */
	public static function replay( callable $callback ) {
		++self::$replay_depth;
		try {
			return $callback();
		} finally {
			--self::$replay_depth;
		}
	}

	public static function is_replaying(): bool {
		return self::$replay_depth > 0;
	}

	/**
	 * Immediate Faraz send (admin tests only — user-facing flows must enqueue).
	 *
	 * @param array<string,mixed> $vars
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	public static function send_now( string $phone, string $body, string $pattern = '', array $vars = array() ): array {
		return MSQ_Transport_Faraz::send( $phone, $body, $pattern, $vars );
	}

	/**
	 * Strip `{braces}` from pattern keys and stringify values.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,string>
	 */
	public static function normalize_vars( array $vars ): array {
		$out = array();
		foreach ( $vars as $key => $value ) {
			$k = trim( trim( (string) $key ), '{}' );
			if ( '' === $k || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$out[ $k ] = (string) $value;
		}
		return $out;
	}
}
