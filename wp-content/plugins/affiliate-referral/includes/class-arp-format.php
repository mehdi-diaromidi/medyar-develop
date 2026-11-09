<?php
/**
 * Formatting helpers: digits, Jalali calendar, money.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Format {

	private const FA_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	private const AR_DIGITS = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );

	private const JALALI_MONTHS = array(
		1  => 'فروردین',
		2  => 'اردیبهشت',
		3  => 'خرداد',
		4  => 'تیر',
		5  => 'مرداد',
		6  => 'شهریور',
		7  => 'مهر',
		8  => 'آبان',
		9  => 'آذر',
		10 => 'دی',
		11 => 'بهمن',
		12 => 'اسفند',
	);

	/**
	 * @param string|int|float $value Value.
	 */
	public static function fa( $value ): string {
		return str_replace( range( 0, 9 ), self::FA_DIGITS, (string) $value );
	}

	public static function en( string $value ): string {
		$value = str_replace( self::FA_DIGITS, range( 0, 9 ), $value );
		return str_replace( self::AR_DIGITS, range( 0, 9 ), $value );
	}

	/**
	 * Persian-digit thousands-separated amount (no currency).
	 *
	 * @param float|int|string $amount Amount.
	 */
	public static function money( $amount ): string {
		return self::fa( number_format( (float) $amount ) );
	}

	/**
	 * Iranian mobile in canonical 09XXXXXXXXX form, or '' when invalid.
	 */
	public static function iran_mobile( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', self::en( $raw ) );
		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( 10 === strlen( $digits ) && '9' === $digits[0] ) {
			$digits = '0' . $digits;
		}
		return preg_match( '/^09\d{9}$/', $digits ) ? $digits : '';
	}

	public static function month_name( int $jm ): string {
		return self::JALALI_MONTHS[ $jm ] ?? '';
	}

	/**
	 * @return int[] [jy, jm, jd]
	 */
	public static function gregorian_to_jalali( int $gy, int $gm, int $gd ): array {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
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
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	/**
	 * @return int[] [gy, gm, gd]
	 */
	public static function jalali_to_gregorian( int $jy, int $jm, int $jd ): array {
		$jy  += 1595;
		$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd
			+ ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
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

	/**
	 * MySQL datetime (site-local) to Jalali "Y/m/d" with ASCII digits.
	 */
	public static function to_jalali( string $datetime ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $datetime, $m ) ) {
			return '';
		}
		list( $jy, $jm, $jd ) = self::gregorian_to_jalali( (int) $m[1], (int) $m[2], (int) $m[3] );
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/**
	 * Jalali "Y/m/d" (Persian or ASCII digits) to Gregorian "Y-m-d", or '' when invalid.
	 */
	public static function jalali_to_date( string $jalali ): string {
		if ( ! preg_match( '#^(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})$#', trim( self::en( $jalali ) ), $m ) ) {
			return '';
		}
		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];
		if ( $jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || ( $jm > 6 && $jd > 30 ) ) {
			return '';
		}
		list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $jy, $jm, $jd );
		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/**
	 * Current Jalali [jy, jm, jd] in site timezone.
	 *
	 * @return int[]
	 */
	public static function jalali_today(): array {
		list( $gy, $gm, $gd ) = array_map( 'intval', explode( '-', current_time( 'Y-m-d' ) ) );
		return self::gregorian_to_jalali( $gy, $gm, $gd );
	}

	/**
	 * Gregorian "Y-m-d" of the first day of the current Jalali month.
	 */
	public static function jalali_month_start(): string {
		list( $jy, $jm ) = self::jalali_today();
		return self::jalali_to_date( sprintf( '%04d/%02d/01', $jy, $jm ) );
	}
}
