<?php
defined( 'ABSPATH' ) || exit;

/**
 * Iranian mobile normalization (09XXXXXXXXX).
 */
final class MSQ_Phone {

	/**
	 * Canonical 09XXXXXXXXX or '' when invalid. Converts Persian/Arabic digits first.
	 */
	public static function normalize( $raw ): string {
		$value = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $raw
		);
		$digits = (string) preg_replace( '/\D+/', '', $value );
		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( 10 === strlen( $digits ) && '9' === $digits[0] ) {
			$digits = '0' . $digits;
		}
		return preg_match( '/^09\d{9}$/', $digits ) ? $digits : '';
	}
}
