<?php
namespace DrPlus\Utils;

use MJ\Whitebox\Utils\Date as WhiteboxDate;

class Date extends WhiteboxDate {
	public static function get_time_period( int $hour ) {
		if( $hour >= 0 && $hour < 12 ) {
			$time_name = esc_html_x( 'Morning', 'book time slot label', 'drplus' );
		} elseif( $hour >= 12 && $hour < 16 ) {
			$time_name = esc_html_x( 'Noon', 'book time slot label', 'drplus' );
		} elseif( $hour >= 16 && $hour < 20 ) {
			$time_name = esc_html_x( 'Evening', 'book time slot label', 'drplus' );
		} else {
			$time_name = esc_html_x( 'Night', 'book time slot label', 'drplus' );
		}
		return $time_name;
	}
}