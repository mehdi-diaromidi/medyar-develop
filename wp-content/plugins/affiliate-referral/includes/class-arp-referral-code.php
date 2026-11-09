<?php
/**
 * Unique referral code generator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Referral_Code {

	private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	private const LENGTH   = 6;
	private const MAX_TRY  = 20;

	public static function generate_unique(): string {
		for ( $i = 0; $i < self::MAX_TRY; $i++ ) {
			$code = self::random_code();
			if ( ! self::exists( $code ) ) {
				return $code;
			}
		}
		// Fallback with longer entropy.
		return strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 ) );
	}

	public static function exists( string $code ): bool {
		$users = get_users(
			array(
				'meta_key'   => 'arp_referral_code',
				'meta_value' => $code,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return ! empty( $users );
	}

	private static function random_code(): string {
		$max  = strlen( self::ALPHABET ) - 1;
		$code = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= self::ALPHABET[ wp_rand( 0, $max ) ];
		}
		return $code;
	}

	public static function get_for_user( int $user_id ): string {
		return (string) get_user_meta( $user_id, 'arp_referral_code', true );
	}
}
