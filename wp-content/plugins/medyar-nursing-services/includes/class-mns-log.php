<?php
/**
 * Rotating option log (T-0.4). Max 200 lines. autoload=false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Log {

	public const OPTION = 'mns_log';
	public const MAX    = 200;

	/**
	 * @param string               $level   info|warn|error.
	 * @param string               $message Message.
	 * @param array<string,mixed>  $context Context.
	 */
	public static function write( string $level, string $message, array $context = array() ): void {
		$row = array(
			'time'    => time(),
			'level'   => sanitize_key( $level ),
			'message' => (string) $message,
			'context' => $context,
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $row;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -1 * self::MAX );
		}
		update_option( self::OPTION, $log, false );

		error_log( sprintf( '[MNS][%s] %s', $row['level'], $row['message'] ) );
	}

	public static function info( string $message, array $context = array() ): void {
		self::write( 'info', $message, $context );
	}

	public static function warn( string $message, array $context = array() ): void {
		self::write( 'warn', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}
}
