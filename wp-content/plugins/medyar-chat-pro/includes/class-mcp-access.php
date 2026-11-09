<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side authorization for every chat-pro capability.
 *
 * Roles inside a session: specialist (user_1_id) and patient (user_2_id).
 * System roles: mcp_manage_chats (supervision / reports), mcp_view_medical (full patient record).
 */
final class MCP_Access {

	public static function can_manage(): bool {
		return current_user_can( MCP_Install::CAP_MANAGE ) || current_user_can( 'manage_options' );
	}

	public static function can_view_medical(): bool {
		return current_user_can( MCP_Install::CAP_MEDICAL ) || current_user_can( 'manage_options' );
	}

	public static function role( int $session_id, int $user_id = 0 ): string {
		$user_id = $user_id ?: get_current_user_id();
		return MCP_Theme::role_in_session( MCP_Theme::get_session( $session_id ), $user_id );
	}

	public static function is_participant( int $session_id, int $user_id = 0 ): bool {
		return '' !== self::role( $session_id, $user_id );
	}

	public static function is_session_specialist( int $session_id, int $user_id = 0 ): bool {
		return 'specialist' === self::role( $session_id, $user_id );
	}

	/** Read access to session-level data (messages, files, status). */
	public static function can_read_session( int $session_id ): bool {
		return self::is_participant( $session_id ) || self::can_manage();
	}

	/** Status, follow-up, referral: the session's specialist or a supervisor. */
	public static function can_manage_session( int $session_id ): bool {
		return self::is_session_specialist( $session_id ) || self::can_manage();
	}

	/** Patient profile panel: never the patient side, never other specialists. */
	public static function can_view_patient_profile( int $session_id ): bool {
		return self::is_session_specialist( $session_id ) || self::can_view_medical();
	}

	/**
	 * Private notes are written by the session specialist only.
	 * Supervisors may read (never edit) them.
	 */
	public static function can_write_notes( int $session_id ): bool {
		return self::is_session_specialist( $session_id );
	}

	public static function can_read_notes( int $session_id ): bool {
		return self::is_session_specialist( $session_id ) || self::can_view_medical();
	}

	public static function is_specialist_user( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		return MCP_Theme::is_specialist( $user_id );
	}

	public static function forbidden( string $message = '' ): WP_Error {
		return new WP_Error(
			'mcp_forbidden',
			'' !== $message ? $message : __( 'شما به این بخش دسترسی ندارید.', 'medyar-chat-pro' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}
}
