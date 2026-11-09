<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters on top of the object cache / transients.
 * Complements the theme's per-minute DrPlus\REST\RateLimiter with hourly/daily budgets.
 */
final class MCP_Rate_Limiter {

	/**
	 * @param int $amount Units to consume (1 per request, or bytes for upload quotas).
	 */
	public static function hit( string $bucket, int $user_id, int $limit, int $window, int $amount = 1 ): bool {
		if ( $limit <= 0 ) {
			return true;
		}
		$slot  = (int) floor( time() / max( 1, $window ) );
		$key   = 'mcp_rl_' . md5( $bucket . '|' . $user_id . '|' . $window . '|' . $slot );
		$count = (int) get_transient( $key );
		if ( $count + $amount > $limit ) {
			return false;
		}
		set_transient( $key, $count + $amount, $window + 5 );
		return true;
	}

	public static function error(): WP_Error {
		return new WP_Error(
			'mcp_rate_limited',
			__( 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.', 'medyar-chat-pro' ),
			array( 'status' => 429 )
		);
	}

	/** Per-endpoint guard for chat-pro REST routes. */
	public static function guard( string $bucket, int $per_minute ): ?WP_Error {
		$user_id = get_current_user_id();
		if ( ! self::hit( $bucket, $user_id, $per_minute, MINUTE_IN_SECONDS ) ) {
			MCP_Audit::log( 'rate_limited', null, array( 'bucket' => $bucket ) );
			return self::error();
		}
		return null;
	}
}
