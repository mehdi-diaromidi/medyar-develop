<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dr Plus theme reserve SMS (book / book_canceled / reminder) → queue.
 *
 * Requires the theme patch in DrPlus\SMS\SMS::send() that applies `drplus/sms/pre_send`
 * (see docs/THEME-PATCHES-AFTER-UPDATE.md). Without it, reserve SMS keep sending inline.
 * The worker replays the exact theme send, so gateway, pattern and variable mapping stay theme-owned.
 */
final class MSQ_Integration_DrPlus {

	public const TRANSPORT = 'drplus';
	private const PREFIX   = 'reserve_notification.';

	public static function init(): void {
		add_filter( 'drplus/sms/pre_send', array( __CLASS__, 'intercept' ), 10, 4 );
		add_action( 'msq_register_transports', array( __CLASS__, 'register_transport' ) );
	}

	public static function register_transport(): void {
		MSQ_Transports::register( self::TRANSPORT, array( __CLASS__, 'send_job' ) );
	}

	/**
	 * @param mixed        $pre       Null to let the theme send inline.
	 * @param array|string $to        Recipient(s).
	 * @param string       $type      Theme SMS key, e.g. reserve_notification.patient.book.
	 * @param mixed        $variables Template variables.
	 * @return mixed True when queued; $pre otherwise.
	 */
	public static function intercept( $pre, $to, $type, $variables = array() ) {
		$type = (string) $type;
		if ( null !== $pre || 0 !== strpos( $type, self::PREFIX ) || MSQ_Client::is_replaying() || ! MSQ_Client::is_ready() ) {
			return $pre;
		}
		if ( ! apply_filters( 'msq_drplus_intercept', true, $type, $to ) ) {
			return $pre;
		}

		$vars   = self::to_array( $variables );
		$event  = 'reserve.' . substr( $type, strlen( self::PREFIX ) );
		$queued = 0;
		foreach ( (array) $to as $raw ) {
			$phone = MSQ_Phone::normalize( (string) $raw );
			if ( '' === $phone ) {
				continue;
			}
			$fingerprint = implode(
				'|',
				array(
					$type,
					$phone,
					(string) ( $vars['book_id'] ?? '' ),
					(string) ( $vars['visit_date'] ?? '' ),
					(string) ( $vars['visit_time'] ?? '' ),
					'' === (string) ( $vars['book_id'] ?? '' ) ? wp_json_encode( $vars ) : '',
				)
			);
			$id = MSQ_Client::enqueue(
				array(
					'channel'    => 'booking',
					'event'      => $event,
					'transport'  => self::TRANSPORT,
					'phone'      => $phone,
					'body'       => self::summary( $type, $vars ),
					'data'       => array(
						'to'   => (string) $raw,
						'type' => $type,
						'vars' => $vars,
					),
					'meta'       => array( 'book_id' => (string) ( $vars['book_id'] ?? '' ) ),
					'priority'   => MSQ_Client::PRIORITY_NORMAL,
					'dedupe_key' => 'booking:' . md5( $fingerprint ),
				)
			);
			if ( $id > 0 ) {
				++$queued;
			}
		}
		return $queued > 0 ? true : $pre;
	}

	/**
	 * Worker: run the original theme send with the mu-plugin bypass flags set.
	 *
	 * @param array<string,mixed> $job
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	public static function send_job( array $job ): array {
		if ( ! class_exists( '\DrPlus\SMS\SMS' ) ) {
			return array(
				'ok'     => false,
				'detail' => 'drplus_sms_class_missing',
				'retry'  => true,
			);
		}
		$data = isset( $job['payload']['data'] ) && is_array( $job['payload']['data'] ) ? $job['payload']['data'] : array();
		$type = (string) ( $data['type'] ?? '' );
		$to   = (string) ( $data['to'] ?? '' );
		$vars = self::to_array( $data['vars'] ?? array() );
		if ( '' === $type ) {
			return array(
				'ok'     => false,
				'detail' => 'missing_type',
				'retry'  => false,
			);
		}
		if ( '' === $to ) {
			$to = (string) $job['phone'];
		}

		$flag       = self::bypass_flag( $type );
		$had_flag   = array_key_exists( $flag, $GLOBALS );
		$prev_flag  = $had_flag ? $GLOBALS[ $flag ] : null;
		$had_block  = array_key_exists( 'medyar_block_sms_http', $GLOBALS );
		$prev_block = $had_block ? $GLOBALS['medyar_block_sms_http'] : null;

		$GLOBALS[ $flag ]                 = true;
		$GLOBALS['medyar_block_sms_http'] = false;
		try {
			$ret = MSQ_Client::replay(
				static function () use ( $to, $type, $vars ) {
					return \DrPlus\SMS\SMS::send( $to, $type, $vars );
				}
			);
		} finally {
			if ( $had_flag ) {
				$GLOBALS[ $flag ] = $prev_flag;
			} else {
				unset( $GLOBALS[ $flag ] );
			}
			if ( $had_block ) {
				$GLOBALS['medyar_block_sms_http'] = $prev_block;
			} else {
				unset( $GLOBALS['medyar_block_sms_http'] );
			}
		}

		return self::evaluate( $ret );
	}

	/** mu-plugin globals that let an intentional reserve send pass the duplicate/booking HTTP blockers. */
	private static function bypass_flag( string $type ): string {
		if ( false !== strpos( $type, '.book_canceled' ) ) {
			return 'medyar_reserve_sending_cancel';
		}
		if ( false !== strpos( $type, '.reminder' ) ) {
			return 'medyar_reserve_sending_reminder';
		}
		return 'medyar_reserve_sending_book';
	}

	/**
	 * Same failure rules as Booking::gateway_send_failed().
	 *
	 * @param mixed $ret Theme gateway return.
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	private static function evaluate( $ret ): array {
		if ( is_wp_error( $ret ) ) {
			return array(
				'ok'     => false,
				'detail' => 'wp_error:' . $ret->get_error_code() . ':' . $ret->get_error_message(),
				'retry'  => true,
			);
		}
		if ( empty( $ret ) ) {
			return array(
				'ok'     => false,
				'detail' => 'empty_gateway_result',
				'retry'  => true,
			);
		}
		if ( is_object( $ret ) ) {
			try {
				$ref = new \ReflectionObject( $ret );
				while ( $ref && ! $ref->hasProperty( 'wp_error' ) ) {
					$ref = $ref->getParentClass();
				}
				if ( $ref ) {
					$prop = $ref->getProperty( 'wp_error' );
					$prop->setAccessible( true );
					$err = $prop->getValue( $ret );
					if ( is_wp_error( $err ) ) {
						return array(
							'ok'     => false,
							'detail' => 'gateway_error:' . $err->get_error_code() . ':' . $err->get_error_message(),
							'retry'  => true,
						);
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Unknown gateway shape: treat as sent, like the theme does.
			}
		}
		return array(
			'ok'     => true,
			'detail' => 'success',
			'retry'  => false,
		);
	}

	/**
	 * @param mixed $variables
	 * @return array<string,mixed>
	 */
	private static function to_array( $variables ): array {
		if ( is_object( $variables ) ) {
			$variables = json_decode( (string) wp_json_encode( $variables ), true );
		}
		return is_array( $variables ) ? $variables : array();
	}

	/** Human-readable line for the admin queue log (the real text is rendered by the theme gateway). */
	private static function summary( string $type, array $vars ): string {
		$parts = array();
		if ( ! empty( $vars['book_id'] ) ) {
			$parts[] = 'رزرو #' . $vars['book_id'];
		}
		if ( ! empty( $vars['patient_name'] ) ) {
			$parts[] = 'بیمار: ' . $vars['patient_name'];
		}
		if ( ! empty( $vars['specialist_name'] ) ) {
			$parts[] = 'متخصص: ' . $vars['specialist_name'];
		}
		if ( ! empty( $vars['visit_date'] ) ) {
			$parts[] = trim( $vars['visit_date'] . ' ' . ( $vars['visit_time'] ?? '' ) );
		}
		return '[' . $type . '] ' . implode( ' · ', $parts );
	}
}
