<?php
defined( 'ABSPATH' ) || exit;

/**
 * Jalali date helpers for admin inputs (same "۱۴۰۵/۰۱/۰۱" text-input convention as affiliate-referral).
 */
final class MCP_Jalali {

	private const FA_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	private const AR_DIGITS = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );

	public static function en( string $value ): string {
		return str_replace( self::AR_DIGITS, range( 0, 9 ), str_replace( self::FA_DIGITS, range( 0, 9 ), $value ) );
	}

	public static function fa( string $value ): string {
		return str_replace( range( 0, 9 ), self::FA_DIGITS, $value );
	}

	/** Normalized ASCII Jalali "Y/m/d" from user input, or '' when invalid. */
	public static function normalize( string $raw ): string {
		if ( ! preg_match( '#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', trim( self::en( $raw ) ), $m ) ) {
			return '';
		}
		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];
		if ( $jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || ( $jm > 6 && $jd > 30 ) ) {
			return '';
		}
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/** Jalali "Y/m/d" (Persian or ASCII digits) to Gregorian "Y-m-d", or ''. */
	public static function to_gregorian( string $jalali ): string {
		$norm = self::normalize( $jalali );
		if ( '' === $norm ) {
			return '';
		}
		list( $jy, $jm, $jd ) = array_map( 'intval', explode( '/', $norm ) );
		list( $gy, $gm, $gd ) = self::j2g( $jy, $jm, $jd );
		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/** Site-local "Y-m-d[ H:i:s]" to ASCII Jalali "Y/m/d", or ''. */
	public static function from_gregorian( ?string $datetime ): string {
		if ( null === $datetime || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $datetime, $m ) || '0000' === $m[1] ) {
			return '';
		}
		list( $jy, $jm, $jd ) = self::g2j( (int) $m[1], (int) $m[2], (int) $m[3] );
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/** @return int[] */
	private static function g2j( int $gy, int $gm, int $gd ): array {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = $gm > 2 ? $gy + 1 : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			return array( $jy, 1 + intdiv( $days, 31 ), 1 + ( $days % 31 ) );
		}
		return array( $jy, 7 + intdiv( $days - 186, 30 ), 1 + ( ( $days - 186 ) % 30 ) );
	}

	/** @return int[] */
	private static function j2g( int $jy, int $jm, int $jd ): array {
		$jy  += 1595;
		$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd
			+ ( $jm < 7 ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
		$gy   = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			--$days;
			$gy  += 100 * intdiv( $days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				++$days;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		$gd    = $days + 1;
		$leap  = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
		$month = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		for ( $gm = 0; $gm < 13 && $gd > $month[ $gm ]; $gm++ ) {
			$gd -= $month[ $gm ];
		}
		return array( $gy, $gm, $gd );
	}
}
