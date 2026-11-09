<?php
defined( 'ABSPATH' ) || exit;

/**
 * Full export of one conversation (transcript, files, notes, referrals, audit) for records/backup.
 */
final class MCP_Export {

	public static function session( int $session_id ): void {
		global $wpdb;
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session || ! MCP_Access::can_manage() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-chat-pro' ), '', array( 'response' => 403 ) );
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . MCP_Theme::messages_table() . ' WHERE session_id = %d ORDER BY id ASC', $session_id ),
			ARRAY_A
		);
		$meta = MCP_Messages::map( $session_id, 0 );
		foreach ( $messages as $i => $m ) {
			$messages[ $i ]['sender']  = MCP_Theme::display_name( (int) $m['sender_id'] );
			$messages[ $i ]['role']    = MCP_Theme::role_in_session( $session, (int) $m['sender_id'] );
			$messages[ $i ]['extra']   = $meta[ (int) $m['id'] ] ?? null;
		}

		$payload = array(
			'exported_at' => MCP_Theme::now(),
			'exported_by' => get_current_user_id(),
			'session'     => $session,
			'state'       => MCP_Sessions::state( $session_id ),
			'metrics'     => MCP_Sessions::get_meta( $session_id ),
			'patient'     => MCP_Theme::display_name( (int) $session['user_2_id'] ),
			'specialist'  => MCP_Theme::display_name( (int) $session['user_1_id'] ),
			'messages'    => $messages,
			'notes'       => MCP_Access::can_view_medical() ? MCP_Notes::for_session( $session_id, get_current_user_id(), true ) : array(),
			'referrals'   => MCP_Referrals::for_session( $session_id ),
			'audit'       => MCP_Audit::search( array( 'session_id' => $session_id ), 1, 5000 )['rows'],
		);
		MCP_Audit::log( 'session_exported', $session_id );

		$base = 'chat-' . $session_id . '-' . gmdate( 'Ymd-His' );
		$json = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		nocache_headers();
		if ( ! class_exists( 'ZipArchive' ) ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $base . '.json"' );
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$tmp = wp_tempnam( $base . '.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'ساخت فایل ZIP ممکن نشد.', 'medyar-chat-pro' ) );
		}
		$zip->addFromString( 'conversation.json', $json );
		$zip->addFromString( 'transcript.html', self::html( $payload ) );
		foreach ( $messages as $m ) {
			if ( 'text' === $m['type'] ) {
				continue;
			}
			$path = MCP_Theme::file_path_from_url( (string) $m['file_url'] );
			if ( '' !== $path ) {
				$zip->addFile( $path, 'files/' . (int) $m['id'] . '-' . wp_basename( $path ) );
			}
		}
		$zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $base . '.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $tmp );
		exit;
	}

	/** @param array<string,mixed> $p */
	private static function html( array $p ): string {
		$rows = '';
		foreach ( $p['messages'] as $m ) {
			$body = 'text' === $m['type']
				? nl2br( esc_html( wp_strip_all_tags( str_replace( array( '<br>', '<br />' ), "\n", (string) $m['message'] ) ) ) )
				: '📎 ' . esc_html( (string) $m['message'] ) . ' → files/' . (int) $m['id'] . '-' . esc_html( wp_basename( (string) $m['file_url'] ) );
			$rows .= sprintf(
				'<tr class="%s"><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>',
				esc_attr( (string) $m['role'] ),
				esc_html( (string) $m['created_at'] ),
				esc_html( (string) $m['sender'] ),
				$body
			);
		}
		return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' . esc_html( 'Chat #' . (int) $p['session']['id'] ) . '</title>'
			. '<style>body{font-family:Tahoma,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #ddd;padding:8px;vertical-align:top}tr.specialist td{background:#f3f8ff}</style></head><body>'
			. '<h1>' . esc_html( sprintf( 'Chat #%d — %s / %s', (int) $p['session']['id'], $p['patient'], $p['specialist'] ) ) . '</h1>'
			. '<table>' . $rows . '</table></body></html>';
	}
}
