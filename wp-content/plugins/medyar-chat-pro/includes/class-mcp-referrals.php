<?php
defined( 'ABSPATH' ) || exit;

/**
 * Specialist-to-specialist referral. The patient sees it as a chat message (rendered as a referral card);
 * it is written silently, so no SMS or other notification is sent.
 */
final class MCP_Referrals {

	private static function table(): string {
		return MCP_Install::table( 'referrals' );
	}

	/**
	 * Active specialists matching a name, excluding the current one.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function search_specialists( string $term, int $exclude_user ): array {
		global $wpdb;
		$term = trim( $term );
		if ( mb_strlen( $term ) < 2 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.user_id, s.post_id, s.name, s.subtitle FROM {$wpdb->prefix}drplus_specialists s
				LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
				WHERE s.status = 'active' AND s.user_id <> %d AND (s.name LIKE %s OR s.subtitle LIKE %s OR u.display_name LIKE %s)
				ORDER BY s.name ASC LIMIT 15",
				$exclude_user,
				$like,
				$like,
				$like
			),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array(
				'user_id'  => (int) $r['user_id'],
				'name'     => '' !== trim( (string) $r['name'] ) ? (string) $r['name'] : MCP_Theme::display_name( (int) $r['user_id'] ),
				'subtitle' => wp_strip_all_tags( (string) $r['subtitle'] ),
				'avatar'   => get_avatar_url( (int) $r['user_id'], array( 'size' => 64 ) ),
			),
			(array) $rows
		);
	}

	/** @return array<string,mixed>|WP_Error */
	public static function create( int $session_id, int $from_user, int $to_user, string $reason ) {
		global $wpdb;
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'mcp_not_found', __( 'مکالمه پیدا نشد.', 'medyar-chat-pro' ), array( 'status' => 404 ) );
		}
		if ( ! MCP_Theme::accepts_messages( $session ) ) {
			return new WP_Error( 'mcp_chat_closed', __( 'این مکالمه بسته است و امکان ارجاع از داخل آن وجود ندارد.', 'medyar-chat-pro' ), array( 'status' => 409 ) );
		}
		if ( $to_user === $from_user || ! MCP_Theme::is_specialist( $to_user ) ) {
			return new WP_Error( 'mcp_invalid_target', __( 'متخصص انتخاب‌شده معتبر نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$reason = mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, 1000 );

		$wpdb->insert(
			self::table(),
			array(
				'session_id'   => $session_id,
				'patient_id'   => (int) $session['user_2_id'],
				'from_user_id' => $from_user,
				'to_user_id'   => $to_user,
				'reason'       => $reason,
				'created_at'   => MCP_Theme::now(),
			)
		);
		$referral_id = (int) $wpdb->insert_id;

		$specialist = MCP_Theme::specialist_by_user( $to_user );
		$name       = MCP_Theme::display_name( $to_user );
		$lines      = array(
			/* translators: 1: specialist name, 2: specialty */
			sprintf( __( 'برای ادامه بررسی، شما به %1$s (%2$s) ارجاع داده شدید.', 'medyar-chat-pro' ), $name, wp_strip_all_tags( (string) ( $specialist['subtitle'] ?? '' ) ) ),
		);
		if ( '' !== $reason ) {
			/* translators: %s: referral reason */
			$lines[] = sprintf( __( 'علت ارجاع: %s', 'medyar-chat-pro' ), $reason );
		}
		$url = MCP_Theme::specialist_profile_url( $to_user );
		if ( '' !== $url ) {
			/* translators: %s: profile url */
			$lines[] = sprintf( __( 'مشاهده پروفایل و رزرو نوبت: %s', 'medyar-chat-pro' ), $url );
		}

		// Referral is shown in the patient's chat only; no SMS / notification to anyone.
		$message_id = MCP_Theme::insert_message_silently( $session_id, $from_user, implode( "\n", $lines ) );
		if ( $message_id ) {
			MCP_Sessions::on_message( $session_id, $from_user );
			MCP_Messages::record( $message_id, $session_id, $from_user, 0, 'referral', $referral_id );
			$wpdb->update( self::table(), array( 'message_id' => $message_id ), array( 'id' => $referral_id ) );
		}

		MCP_Audit::log( 'referral_created', $session_id, array( 'to_user' => $to_user ), 'referral', $referral_id );
		do_action( 'mcp_referral_created', $referral_id, $session_id, $from_user, $to_user );

		return array(
			'id'         => $referral_id,
			'message_id' => $message_id,
			'card'       => self::public_card( $referral_id ),
		);
	}

	/** Data safe to show to both sides of the chat. @return array<string,mixed>|null */
	public static function public_card( int $referral_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $referral_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$to         = (int) $row['to_user_id'];
		$specialist = MCP_Theme::specialist_by_user( $to );
		return array(
			'id'       => (int) $row['id'],
			'name'     => MCP_Theme::display_name( $to ),
			'subtitle' => wp_strip_all_tags( (string) ( $specialist['subtitle'] ?? '' ) ),
			'avatar'   => get_avatar_url( $to, array( 'size' => 96 ) ),
			'url'      => MCP_Theme::specialist_profile_url( $to ),
			'reason'   => (string) $row['reason'],
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_session( int $session_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE session_id = %d ORDER BY id DESC', $session_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$card = self::public_card( (int) $r['id'] );
			if ( $card ) {
				$out[] = $card;
			}
		}
		return $out;
	}

	/**
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public static function search( int $page = 1, int $per_page = 30 ): array {
		global $wpdb;
		$table = self::table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}
}
