<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read/write adapter for the Dr Plus theme chat (encrypted Chat class + its tables).
 * All theme coupling lives here so a theme update only needs this file re-checked.
 */
final class MCP_Theme {

	public const SESSION_TABLE = 'drplus_chat_sessions';
	public const MESSAGE_TABLE = 'drplus_chat_messages';

	/** @var array<int,array<string,mixed>|null> */
	private static array $sessions = array();

	public static function chat_class(): string {
		foreach ( array( 'DrPlusUtilsChat', 'DrPlus\\Utils\\Chat', 'Chat' ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'send_message' ) ) {
				return $class;
			}
		}
		return '';
	}

	public static function is_available(): bool {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$table = $wpdb->prefix . self::SESSION_TABLE;
			$ok    = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		return $ok;
	}

	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::SESSION_TABLE;
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::MESSAGE_TABLE;
	}

	public static function now(): string {
		return current_time( 'mysql' );
	}

	/** @return array<string,mixed>|null */
	public static function get_session( int $session_id, bool $fresh = false ): ?array {
		global $wpdb;
		if ( $session_id <= 0 ) {
			return null;
		}
		if ( $fresh || ! array_key_exists( $session_id, self::$sessions ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE id = %d', $session_id ),
				ARRAY_A
			);
			self::$sessions[ $session_id ] = $row ?: null;
		}
		return self::$sessions[ $session_id ];
	}

	/**
	 * user_1_id = specialist, user_2_id = patient (theme model contract).
	 *
	 * @param array<string,mixed>|null $session
	 */
	public static function role_in_session( ?array $session, int $user_id ): string {
		if ( ! $session || $user_id <= 0 ) {
			return '';
		}
		if ( (int) $session['user_1_id'] === $user_id ) {
			return 'specialist';
		}
		if ( (int) $session['user_2_id'] === $user_id ) {
			return 'patient';
		}
		return '';
	}

	/** @param array<string,mixed> $session */
	public static function is_closed( array $session ): bool {
		return ! empty( $session['is_closed'] );
	}

	/** @param array<string,mixed> $session */
	public static function is_not_open_yet( array $session ): bool {
		$open_at = (string) ( $session['open_at'] ?? '' );
		return '' !== $open_at && '0000-00-00 00:00:00' !== $open_at && $open_at > self::now();
	}

	/** @param array<string,mixed> $session */
	public static function accepts_messages( array $session ): bool {
		return ! self::is_closed( $session ) && ! self::is_not_open_yet( $session );
	}

	/** @return array<string,mixed>|null */
	public static function get_message( int $message_id ): ?array {
		global $wpdb;
		if ( $message_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::messages_table() . ' WHERE id = %d', $message_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Sends through the theme so its own hooks (SMS notification etc.) keep working.
	 *
	 * @return int Message id, 0 on failure.
	 */
	public static function send_message( int $session_id, int $user_id, string $message, string $type = 'text', string $file_url = '' ): int {
		$class = self::chat_class();
		if ( '' === $class ) {
			return 0;
		}
		$id = (int) call_user_func( array( $class, 'send_message' ), $session_id, $user_id, $message, $type, $file_url );
		if ( $id > 0 ) {
			do_action( 'drplus/chat/message_sent', $session_id, $user_id, 'web' );
		}
		return $id;
	}

	/**
	 * Stores a text message in the theme table without the theme's send path,
	 * so no drplus/chat/message_sent listener (SMS notification) runs. Used for system notices.
	 *
	 * @return int Message id, 0 on failure.
	 */
	public static function insert_message_silently( int $session_id, int $user_id, string $message ): int {
		global $wpdb;
		$now = self::now();
		$ok  = $wpdb->insert(
			self::messages_table(),
			array(
				'session_id' => $session_id,
				'sender_id'  => $user_id,
				'message'    => $message,
				'type'       => 'text',
				'file_url'   => '',
				'is_seen'    => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Mirrors the theme's "close chat" link (templates/chats/template-chats-single.php). */
	public static function close_session( int $session_id ): bool {
		global $wpdb;
		$session = self::get_session( $session_id, true );
		if ( ! $session ) {
			return false;
		}
		if ( ! self::is_closed( $session ) ) {
			$wpdb->update(
				self::sessions_table(),
				array(
					'is_closed'  => 1,
					'closed_at'  => self::now(),
					'updated_at' => self::now(),
				),
				array( 'id' => $session_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
			$order_id = self::booking_order_id( (int) $session['context_id'] );
			if ( $order_id && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order && ! $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) ) {
					$order->update_status( 'completed' );
				}
			}
		}
		self::get_session( $session_id, true );
		return true;
	}

	public static function reopen_session( int $session_id ): bool {
		global $wpdb;
		$done = false !== $wpdb->update(
			self::sessions_table(),
			array(
				'is_closed'  => 0,
				'updated_at' => self::now(),
			),
			array( 'id' => $session_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		self::get_session( $session_id, true );
		return $done;
	}

	/** @return array<string,mixed>|null */
	public static function booking( int $book_id ): ?array {
		global $wpdb;
		if ( $book_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drplus_booking WHERE book_id = %d", $book_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function booking_order_id( int $book_id ): int {
		$booking = self::booking( $book_id );
		return $booking ? (int) $booking['order_id'] : 0;
	}

	/** @return array<string,mixed> Booking form data stored on the order (`_booking_data`). */
	public static function booking_data( int $book_id ): array {
		$order_id = self::booking_order_id( $book_id );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$order = wc_get_order( $order_id );
		$data  = $order ? $order->get_meta( '_booking_data' ) : array();
		return is_array( $data ) ? $data : array();
	}

	/** @return array<string,mixed>|null Row of drplus_specialists. */
	public static function specialist_by_user( int $user_id ): ?array {
		global $wpdb;
		static $cache = array();
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, $cache ) ) {
			$row                = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, user_id, post_id, name, subtitle, status FROM {$wpdb->prefix}drplus_specialists WHERE user_id = %d", $user_id ),
				ARRAY_A
			);
			$cache[ $user_id ] = $row ?: null;
		}
		return $cache[ $user_id ];
	}

	public static function is_specialist( int $user_id ): bool {
		$row = self::specialist_by_user( $user_id );
		return $row && 'active' === $row['status'];
	}

	public static function display_name( int $user_id ): string {
		$specialist = self::specialist_by_user( $user_id );
		if ( $specialist && '' !== trim( (string) $specialist['name'] ) ) {
			return (string) $specialist['name'];
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'کاربر نامشخص', 'medyar-chat-pro' );
		}
		$full = trim( $user->first_name . ' ' . $user->last_name );
		return '' !== $full ? $full : (string) $user->display_name;
	}

	public static function specialist_profile_url( int $user_id ): string {
		$specialist = self::specialist_by_user( $user_id );
		if ( $specialist && (int) $specialist['post_id'] > 0 ) {
			return (string) get_permalink( (int) $specialist['post_id'] );
		}
		return '';
	}

	public static function chat_url( int $session_id, string $role ): string {
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return '';
		}
		if ( 'specialist' === $role ) {
			return trailingslashit( wc_get_account_endpoint_url( 'specialist-dashboard' ) ) . 'specialist-chats/' . $session_id . '/';
		}
		return trailingslashit( wc_get_account_endpoint_url( 'chats' ) ) . $session_id . '/';
	}

	public static function admin_transcript_url( int $session_id ): string {
		$session = self::get_session( $session_id );
		if ( ! $session || empty( $session['context_id'] ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'page'      => 'appointments',
				'tab'       => 'view',
				'book_id'   => (int) $session['context_id'],
				'show_chat' => $session_id,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function uploads_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'chat-uploads';
	}

	/** Maps a stored theme file_url to an absolute path inside chat-uploads, or ''. */
	public static function file_path_from_url( string $file_url ): string {
		$file_url = trim( $file_url );
		if ( '' === $file_url || false === strpos( $file_url, '/chat-uploads/' ) ) {
			return '';
		}
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $file_url, PHP_URL_PATH ) ) );
		if ( '' === $name || '.' === $name[0] ) {
			return '';
		}
		$base = realpath( self::uploads_dir() );
		$path = realpath( self::uploads_dir() . '/' . $name );
		if ( ! $base || ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			return '';
		}
		return $path;
	}
}
