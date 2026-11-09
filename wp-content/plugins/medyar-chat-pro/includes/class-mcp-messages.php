<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-message extensions stored beside the theme's message table:
 * reply target, idempotency key, system kind (referral), file facts and reactions.
 */
final class MCP_Messages {

	private const PENDING_TTL = 60;

	private static function table(): string {
		return MCP_Install::table( 'message_meta' );
	}

	private static function reactions_table(): string {
		return MCP_Install::table( 'reactions' );
	}

	public static function sanitize_uid( $uid ): string {
		$uid = is_string( $uid ) ? trim( $uid ) : '';
		return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) ? $uid : '';
	}

	/**
	 * Claims an idempotency key before the theme stores the message.
	 *
	 * @return array{state:string,row_id?:int,message_id?:int}
	 *         state: new | done (already stored) | pending (another request is storing it).
	 */
	public static function reserve( int $session_id, int $sender_id, string $uid, int $reply_to = 0, string $kind = '', int $ref_id = 0 ): array {
		global $wpdb;
		$table = self::table();

		$extra = self::nullable_sql( $reply_to, $kind, $ref_id );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (session_id, sender_id, client_uid, reply_to, kind, ref_id, created_at) VALUES (%d, %d, %s, {$extra}, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$session_id,
					$sender_id,
					$uid,
					MCP_Theme::now()
				)
			);
			if ( $inserted ) {
				return array(
					'state'  => 'new',
					'row_id' => (int) $wpdb->insert_id,
				);
			}

			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, message_id, created_at FROM {$table} WHERE sender_id = %d AND client_uid = %s", $sender_id, $uid ),
				ARRAY_A
			);
			if ( ! $row ) {
				continue;
			}
			if ( ! empty( $row['message_id'] ) ) {
				return array(
					'state'      => 'done',
					'message_id' => (int) $row['message_id'],
				);
			}
			if ( strtotime( MCP_Theme::now() ) - strtotime( (string) $row['created_at'] ) > self::PENDING_TTL ) {
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				continue;
			}
			return array( 'state' => 'pending' );
		}
		return array( 'state' => 'pending' );
	}

	public static function attach( int $row_id, int $message_id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'message_id' => $message_id ), array( 'id' => $row_id ), array( '%d' ), array( '%d' ) );
	}

	public static function release( int $row_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $row_id ), array( '%d' ) );
	}

	/** Stores meta for messages sent without an idempotency key. */
	public static function record( int $message_id, int $session_id, int $sender_id, int $reply_to = 0, string $kind = '', int $ref_id = 0 ): void {
		global $wpdb;
		if ( ! $reply_to && '' === $kind ) {
			return;
		}
		$extra = self::nullable_sql( $reply_to, $kind, $ref_id );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . " (session_id, sender_id, message_id, reply_to, kind, ref_id, created_at) VALUES (%d, %d, %d, {$extra}, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id,
				$sender_id,
				$message_id,
				MCP_Theme::now()
			)
		);
	}

	/** SQL fragment "reply_to, kind, ref_id" with real NULLs (prepare() cannot emit NULL). */
	private static function nullable_sql( int $reply_to, string $kind, int $ref_id ): string {
		global $wpdb;
		return implode(
			', ',
			array(
				$reply_to > 0 ? (string) $reply_to : 'NULL',
				'' !== $kind ? $wpdb->prepare( '%s', sanitize_key( $kind ) ) : 'NULL',
				$ref_id > 0 ? (string) $ref_id : 'NULL',
			)
		);
	}

	/** True when $message_id is a message of $session_id. */
	public static function belongs_to( int $message_id, int $session_id ): bool {
		$message = MCP_Theme::get_message( $message_id );
		return $message && (int) $message['session_id'] === $session_id;
	}

	/* ---------------------------------------------------------------------
	 * Reactions
	 * ------------------------------------------------------------------- */

	/**
	 * Toggles: same reaction removes it, a different one replaces it (one reaction per user per message).
	 *
	 * @return string Current reaction of the user ('' when removed).
	 */
	public static function react( int $session_id, int $message_id, int $user_id, string $reaction ): string {
		global $wpdb;
		$table   = self::reactions_table();
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reaction FROM {$table} WHERE message_id = %d AND user_id = %d", $message_id, $user_id ) );

		if ( $current === $reaction || '' === $reaction ) {
			$wpdb->delete( $table, array( 'message_id' => $message_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
			return '';
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (session_id, message_id, user_id, reaction, created_at) VALUES (%d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), created_at = VALUES(created_at)",
				$session_id,
				$message_id,
				$user_id,
				$reaction,
				MCP_Theme::now()
			)
		);
		return $reaction;
	}

	/* ---------------------------------------------------------------------
	 * Meta map for the UI
	 * ------------------------------------------------------------------- */

	/**
	 * @param int[] $only_ids Restrict to these message ids (empty = whole session).
	 * @return array<int,array<string,mixed>> message_id => meta.
	 */
	public static function map( int $session_id, int $viewer_id, array $only_ids = array() ): array {
		global $wpdb;
		$out      = array();
		$only_ids = array_values( array_filter( array_map( 'intval', $only_ids ) ) );
		$in_sql   = $only_ids ? ' AND message_id IN (' . implode( ',', $only_ids ) . ')' : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT message_id, reply_to, kind, ref_id FROM ' . self::table() . " WHERE session_id = %d AND message_id IS NOT NULL{$in_sql}", $session_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['message_id'];
			if ( ! empty( $row['reply_to'] ) ) {
				$out[ $id ]['reply_to'] = self::excerpt( (int) $row['reply_to'], $session_id );
			}
			if ( ! empty( $row['kind'] ) ) {
				$out[ $id ]['kind'] = (string) $row['kind'];
				if ( 'referral' === $row['kind'] && ! empty( $row['ref_id'] ) ) {
					$out[ $id ]['referral'] = MCP_Referrals::public_card( (int) $row['ref_id'] );
				}
			}
		}

		$reactions = $wpdb->get_results(
			$wpdb->prepare( 'SELECT message_id, user_id, reaction FROM ' . self::reactions_table() . " WHERE session_id = %d{$in_sql}", $session_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$grouped = array();
		foreach ( (array) $reactions as $r ) {
			$id = (int) $r['message_id'];
			$grouped[ $id ][ $r['reaction'] ]['count'] = ( $grouped[ $id ][ $r['reaction'] ]['count'] ?? 0 ) + 1;
			if ( (int) $r['user_id'] === $viewer_id ) {
				$grouped[ $id ][ $r['reaction'] ]['mine'] = true;
			}
		}
		foreach ( $grouped as $id => $items ) {
			foreach ( $items as $emoji => $info ) {
				$out[ $id ]['reactions'][] = array(
					'r'     => (string) $emoji,
					'count' => (int) $info['count'],
					'mine'  => ! empty( $info['mine'] ),
				);
			}
		}

		$in_msg = $only_ids ? ' AND id IN (' . implode( ',', $only_ids ) . ')' : '';
		$files  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, message, type, file_url FROM ' . MCP_Theme::messages_table() . " WHERE session_id = %d AND type <> 'text' AND file_url <> ''{$in_msg}", $session_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		foreach ( (array) $files as $f ) {
			$info = self::file_info( $f );
			if ( $info ) {
				$out[ (int) $f['id'] ]['file'] = $info;
			}
		}

		return $out;
	}

	/** @return array<string,mixed>|null */
	public static function excerpt( int $message_id, int $session_id ): ?array {
		$m = MCP_Theme::get_message( $message_id );
		if ( ! $m || (int) $m['session_id'] !== $session_id ) {
			return null;
		}
		if ( 'voice' === $m['type'] ) {
			$text = __( 'پیام صوتی', 'medyar-chat-pro' );
		} elseif ( 'text' !== $m['type'] ) {
			$text = '📎 ' . wp_basename( (string) $m['message'] );
		} else {
			$text = wp_trim_words( wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ' ', (string) $m['message'] ) ), 18, '…' );
		}
		return array(
			'id'        => (int) $m['id'],
			'sender_id' => (int) $m['sender_id'],
			'type'      => (string) $m['type'],
			'text'      => $text,
		);
	}

	/**
	 * @param array<string,mixed> $message Theme message row (id, message, type, file_url).
	 * @return array<string,mixed>|null
	 */
	public static function file_info( array $message ): ?array {
		global $wpdb;
		$id   = (int) $message['id'];
		$path = MCP_Theme::file_path_from_url( (string) $message['file_url'] );
		if ( '' === $path ) {
			return array(
				'missing' => true,
				'name'    => wp_basename( (string) $message['message'] ),
				'kind'    => 'other',
			);
		}

		$cached = $wpdb->get_row( $wpdb->prepare( 'SELECT file_name, file_size, file_mime FROM ' . self::table() . ' WHERE message_id = %d', $id ), ARRAY_A );
		if ( $cached && ! empty( $cached['file_mime'] ) ) {
			$name = (string) $cached['file_name'];
			$size = (int) $cached['file_size'];
			$mime = (string) $cached['file_mime'];
		} else {
			$name = sanitize_file_name( wp_basename( (string) $message['message'] ) );
			if ( '' === $name || 'voice' === $message['type'] ) {
				$name = wp_basename( $path );
			}
			$size = (int) filesize( $path );
			$mime = MCP_Files::detect_mime( $path );
			if ( $cached ) {
				$wpdb->update( self::table(), array( 'file_name' => $name, 'file_size' => $size, 'file_mime' => $mime ), array( 'message_id' => $id ) );
			} else {
				$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM ' . MCP_Theme::messages_table() . ' WHERE id = %d', $id ) );
				$sender  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT sender_id FROM ' . MCP_Theme::messages_table() . ' WHERE id = %d', $id ) );
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO ' . self::table() . ' (session_id, sender_id, message_id, file_name, file_size, file_mime, created_at) VALUES (%d, %d, %d, %s, %d, %s, %s)',
						$session,
						$sender,
						$id,
						$name,
						$size,
						$mime,
						MCP_Theme::now()
					)
				);
			}
		}

		return array(
			'name'     => $name,
			'size'     => $size,
			'mime'     => $mime,
			'ext'      => strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ?: pathinfo( $path, PATHINFO_EXTENSION ) ),
			'kind'     => 'voice' === $message['type'] ? 'audio' : MCP_Files::kind( $mime ),
			'url'      => MCP_Files::url( $id ),
			'download' => MCP_Files::url( $id, true ),
		);
	}
}
