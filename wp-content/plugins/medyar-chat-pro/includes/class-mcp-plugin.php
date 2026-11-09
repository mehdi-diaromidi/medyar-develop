<?php
defined( 'ABSPATH' ) || exit;

final class MCP_Plugin {

	public const CRON_HOOK = 'mcp_daily_maintenance';

	public static function boot(): void {
		add_action( 'plugins_loaded', array( 'MCP_Install', 'maybe_upgrade' ) );
		add_action( 'init', array( 'MCP_Files', 'maybe_serve' ), 1 );
		add_action( self::CRON_HOOK, array( 'MCP_Audit', 'purge_expired' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );

		MCP_Bridge::init();
		MCP_Timing::init();
		MCP_REST::init();
		MCP_Frontend::init();

		if ( is_admin() ) {
			MCP_Admin::init();
		}
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}
}
