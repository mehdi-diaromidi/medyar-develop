<?php
defined( 'ABSPATH' ) || exit;

/**
 * Specialist private notes about a patient. Never sent as chat messages.
 * Visible to the author (across all of their sessions with the patient) and to mcp_view_medical users.
 */
final class MCP_Notes {

	private const MAX_LENGTH = 5000;

	private static function table(): string {
		return MCP_Install::table( 'notes' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_session( int $session_id, int $viewer_id, bool $supervisor ): array {
		global $wpdb;
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return array();
		}
		$patient = (int) $session['user_2_id'];

		if ( $supervisor && 'specialist' !== MCP_Theme::role_in_session( $session, $viewer_id ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE patient_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 200', $patient ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE patient_id = %d AND author_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 200', $patient, $viewer_id ),
				ARRAY_A
			);
		}
		return array_map( static fn( $r ) => self::format( $r, $viewer_id, $session_id ), (array) $rows );
	}

	/** @param array<string,mixed> $row */
	public static function format( array $row, int $viewer_id, int $current_session = 0 ): array {
		return array(
			'id'           => (int) $row['id'],
			'session_id'   => (int) $row['session_id'],
			'this_session' => (int) $row['session_id'] === $current_session,
			'author_id'    => (int) $row['author_id'],
			'author'       => MCP_Theme::display_name( (int) $row['author_id'] ),
			'body'         => (string) $row['body'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
			'editable'     => (int) $row['author_id'] === $viewer_id,
		);
	}

	/** @return array<string,mixed>|WP_Error */
	public static function create( int $session_id, int $author_id, string $body ) {
		global $wpdb;
		$body = self::clean( $body );
		if ( '' === $body ) {
			return new WP_Error( 'mcp_empty_note', __( 'متن یادداشت خالی است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$session = MCP_Theme::get_session( $session_id );
		$now     = MCP_Theme::now();
		$wpdb->insert(
			self::table(),
			array(
				'session_id' => $session_id,
				'patient_id' => (int) $session['user_2_id'],
				'author_id'  => $author_id,
				'body'       => $body,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		MCP_Audit::log( 'note_created', $session_id, array(), 'note', $id );
		return self::format( self::get( $id ), $author_id, $session_id );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function update( int $note_id, int $author_id, string $body, int $session_id ) {
		global $wpdb;
		$note = self::get( $note_id );
		if ( ! $note || (int) $note['author_id'] !== $author_id || ! empty( $note['deleted_at'] ) ) {
			return MCP_Access::forbidden();
		}
		$body = self::clean( $body );
		if ( '' === $body ) {
			return new WP_Error( 'mcp_empty_note', __( 'متن یادداشت خالی است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$wpdb->update( self::table(), array( 'body' => $body, 'updated_at' => MCP_Theme::now() ), array( 'id' => $note_id ) );
		MCP_Audit::log( 'note_updated', (int) $note['session_id'], array(), 'note', $note_id );
		return self::format( self::get( $note_id ), $author_id, $session_id );
	}

	/** Soft delete keeps the record for audit/recovery. @return true|WP_Error */
	public static function delete( int $note_id, int $author_id ) {
		global $wpdb;
		$note = self::get( $note_id );
		if ( ! $note || (int) $note['author_id'] !== $author_id || ! empty( $note['deleted_at'] ) ) {
			return MCP_Access::forbidden();
		}
		$wpdb->update( self::table(), array( 'deleted_at' => MCP_Theme::now() ), array( 'id' => $note_id ) );
		MCP_Audit::log( 'note_deleted', (int) $note['session_id'], array(), 'note', $note_id );
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get( int $note_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $note_id ), ARRAY_A );
		return $row ?: null;
	}

	private static function clean( string $body ): string {
		return mb_substr( trim( sanitize_textarea_field( $body ) ), 0, self::MAX_LENGTH );
	}
}
