<?php
defined( 'ABSPATH' ) || exit;

/**
 * Transport registry. A transport receives one decoded job and performs the real send.
 *
 * Callback signature: function ( array $job ): array{ok:bool, detail?:string, retry?:bool}
 * Integrations register on the `msq_register_transports` action (fired lazily before first use).
 */
final class MSQ_Transports {

	/** @var array<string,callable> */
	private static $map = array();

	/** @var bool */
	private static $booted = false;

	public static function register( string $id, callable $callback ): void {
		self::$map[ sanitize_key( $id ) ] = $callback;
	}

	public static function get( string $id ): ?callable {
		self::boot();
		$id = sanitize_key( $id );
		return self::$map[ $id ] ?? null;
	}

	/** @return string[] */
	public static function ids(): array {
		self::boot();
		return array_keys( self::$map );
	}

	private static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::register( 'faraz', array( 'MSQ_Transport_Faraz', 'send_job' ) );
		/**
		 * Register additional SMS transports.
		 *
		 * Example: MSQ_Transports::register( 'my_transport', 'my_callback' );
		 */
		do_action( 'msq_register_transports' );
	}
}
