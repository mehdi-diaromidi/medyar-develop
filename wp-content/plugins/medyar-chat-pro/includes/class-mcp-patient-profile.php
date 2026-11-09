<?php
defined( 'ABSPATH' ) || exit;

/**
 * Patient context shown to the session specialist (and supervisors) inside the chat:
 * identity, current consultation, relationship history, shared documents, medical summary.
 *
 * Scope rule: a specialist only sees sessions/documents they took part in;
 * medical summary only when enabled in settings (or the viewer holds mcp_view_medical).
 */
final class MCP_Patient_Profile {

	/** @return array<string,mixed>|WP_Error */
	public static function build( int $session_id, int $viewer_id ) {
		global $wpdb;
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'mcp_not_found', __( 'مکالمه پیدا نشد.', 'medyar-chat-pro' ), array( 'status' => 404 ) );
		}
		$patient_id    = (int) $session['user_2_id'];
		$specialist_id = (int) $session['user_1_id'];
		$is_specialist = $viewer_id === $specialist_id;
		$supervisor    = ! $is_specialist && MCP_Access::can_view_medical();

		$booking  = MCP_Theme::booking_data( (int) $session['context_id'] );
		$user     = get_userdata( $patient_id );
		$name     = trim( (string) ( $booking['first_name'] ?? '' ) . ' ' . (string) ( $booking['last_name'] ?? '' ) );
		$name     = '' !== $name ? $name : MCP_Theme::display_name( $patient_id );
		$birthday = ! empty( $booking['birthday'] ) && is_numeric( $booking['birthday'] ) ? (int) $booking['birthday'] : 0;
		if ( ! $birthday ) {
			$birth_meta = (string) get_user_meta( $patient_id, 'birth_date', true );
			$birthday   = $birth_meta ? (int) strtotime( $birth_meta ) : 0;
		}

		// Specialists only get the relationship with themselves; general patient facts are for supervisors.
		$patient = array(
			'id'     => $patient_id,
			'name'   => $name,
			'avatar' => get_avatar_url( $patient_id, array( 'size' => 96 ) ),
		);
		if ( $supervisor ) {
			$patient['age']          = $birthday > 0 ? max( 0, (int) floor( ( time() - $birthday ) / YEAR_IN_SECONDS ) ) : null;
			$patient['member_since'] = $user ? get_date_from_gmt( $user->user_registered, 'Y-m-d H:i:s' ) : null;
		}

		$profile = array(
			'patient' => $patient,
			'current' => array(
				'reason'     => (string) ( $booking['reason'] ?? '' ),
				'type'       => self::office_label( (string) ( $booking['office_id'] ?? '' ) ),
				'opened_at'  => (string) $session['open_at'],
				'created_at' => (string) $session['created_at'],
				'state'      => MCP_Sessions::state( $session_id ),
			),
		);

		if ( $supervisor ) {
			$profile['patient']['contact'] = array(
				'phone' => (string) ( $booking['phone'] ?? get_user_meta( $patient_id, 'billing_phone', true ) ),
				'email' => (string) ( $booking['email'] ?? ( $user ? $user->user_email : '' ) ),
			);
		}

		// Sessions visible to the viewer: the pair for specialists, every session of the patient for supervisors.
		$sessions = $supervisor
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MCP_Theme::sessions_table() . ' WHERE user_2_id = %d ORDER BY id DESC LIMIT 100', $patient_id ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MCP_Theme::sessions_table() . ' WHERE user_2_id = %d AND user_1_id = %d ORDER BY id DESC LIMIT 100', $patient_id, $specialist_id ), ARRAY_A );
		$sessions = (array) $sessions;
		$ids      = array_map( static fn( $s ) => (int) $s['id'], $sessions );

		$counts = array();
		if ( $ids ) {
			$rows = $wpdb->get_results( 'SELECT session_id, COUNT(*) AS c, MAX(created_at) AS last_at FROM ' . MCP_Theme::messages_table() . ' WHERE session_id IN (' . implode( ',', $ids ) . ') GROUP BY session_id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $r ) {
				$counts[ (int) $r['session_id'] ] = $r;
			}
		}

		$history = array();
		foreach ( $sessions as $s ) {
			$sid       = (int) $s['id'];
			$state     = MCP_Sessions::state( $sid );
			$history[] = array(
				'session_id'  => $sid,
				'current'     => $sid === $session_id,
				'specialist'  => $supervisor ? MCP_Theme::display_name( (int) $s['user_1_id'] ) : null,
				'created_at'  => (string) $s['created_at'],
				'last_at'     => (string) ( $counts[ $sid ]['last_at'] ?? $s['created_at'] ),
				'messages'    => (int) ( $counts[ $sid ]['c'] ?? 0 ),
				'status'      => $state['status'] ?? '',
				'status_label'=> $state['status_label'] ?? '',
				'url'         => $is_specialist ? MCP_Theme::chat_url( $sid, 'specialist' ) : MCP_Theme::admin_transcript_url( $sid ),
			);
		}

		$bookings = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, SUM(order_status IN ('completed','processing')) AS done, SUM(order_status = 'cancelled') AS cancelled, MIN(created_at) AS first_at
				FROM {$wpdb->prefix}drplus_booking b WHERE b.customer_id = %d" . ( $supervisor ? '' : ' AND b.specialist_id = %d' ),
				$supervisor ? array( $patient_id ) : array( $patient_id, (int) ( MCP_Theme::specialist_by_user( $specialist_id )['id'] ?? 0 ) )
			),
			ARRAY_A
		);

		$first_contact = $sessions ? (string) end( $sessions )['created_at'] : '';
		$profile['relationship'] = array(
			'sessions'        => count( $sessions ),
			'sessions_closed' => count( array_filter( $sessions, static fn( $s ) => ! empty( $s['is_closed'] ) ) ),
			'bookings'        => (int) ( $bookings['total'] ?? 0 ),
			'bookings_done'   => (int) ( $bookings['done'] ?? 0 ),
			'bookings_cancel' => (int) ( $bookings['cancelled'] ?? 0 ),
			'first_contact'   => $first_contact ?: ( $bookings['first_at'] ?? '' ),
		);
		$profile['history'] = $history;

		$documents = array();
		if ( $ids ) {
			$files = $wpdb->get_results(
				'SELECT id, session_id, sender_id, message, type, file_url, created_at FROM ' . MCP_Theme::messages_table() . " WHERE session_id IN (" . implode( ',', $ids ) . ") AND type <> 'text' AND file_url <> '' ORDER BY id DESC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			foreach ( (array) $files as $f ) {
				$info = MCP_Messages::file_info( $f );
				if ( ! $info || ! empty( $info['missing'] ) || 'audio' === $info['kind'] ) {
					continue;
				}
				$documents[] = array_merge(
					$info,
					array(
						'message_id' => (int) $f['id'],
						'session_id' => (int) $f['session_id'],
						'from'       => (int) $f['sender_id'] === $patient_id ? 'patient' : 'specialist',
						'date'       => (string) $f['created_at'],
					)
				);
			}
		}
		$profile['documents'] = $documents;

		if ( $supervisor || MCP_Settings::get( 'share_medical_summary' ) ) {
			$profile['medical'] = self::medical_summary( $patient_id );
		}

		$profile['referrals'] = MCP_Referrals::for_session( $session_id );

		MCP_Audit::log( 'profile_viewed', $session_id, array(), 'user', $patient_id );
		return $profile;
	}

	/** Reads the "medical-records" plugin data when present. @return array<string,mixed>|null */
	private static function medical_summary( int $patient_id ): ?array {
		global $wpdb;
		$lists = array();
		foreach ( array( 'allergies', 'drugs', 'chronic_diseases', 'surgeries', 'vaccinations' ) as $key ) {
			$values        = array_values( array_filter( array_map( 'trim', (array) get_user_meta( $patient_id, $key ) ) ) );
			$lists[ $key ] = array_map( 'sanitize_text_field', $values );
		}
		$summary = array(
			'blood_group' => sanitize_text_field( (string) get_user_meta( $patient_id, 'blood_group', true ) ),
			'birth_date'  => sanitize_text_field( (string) get_user_meta( $patient_id, 'birth_date', true ) ),
			'lists'       => $lists,
			'records'     => array(),
		);

		$table = $wpdb->prefix . 'medrec_records';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT visit_date, visit_reason, doctor, diagnosis_final, diagnosis_initial, medications FROM {$table} WHERE patient_id = %d ORDER BY id DESC LIMIT 20", $patient_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$summary['records'][] = array(
					'date'      => sanitize_text_field( (string) $r['visit_date'] ),
					'reason'    => sanitize_textarea_field( (string) $r['visit_reason'] ),
					'doctor'    => sanitize_text_field( (string) $r['doctor'] ),
					'diagnosis' => sanitize_textarea_field( (string) ( $r['diagnosis_final'] ?: $r['diagnosis_initial'] ) ),
				);
			}
		}

		$empty = '' === $summary['blood_group'] && ! array_filter( $lists ) && ! $summary['records'];
		return $empty ? null : $summary;
	}

	private static function office_label( string $office ): string {
		$labels = array(
			'chat_consultation'         => __( 'مشاوره گفت‌وگوی آنلاین', 'medyar-chat-pro' ),
			'instant_chat_consultation' => __( 'مشاوره گفت‌وگوی فوری', 'medyar-chat-pro' ),
			'phone_consultation'        => __( 'مشاوره تلفنی', 'medyar-chat-pro' ),
			'video_consultation'        => __( 'مشاوره ویدیویی', 'medyar-chat-pro' ),
		);
		return $labels[ $office ] ?? '';
	}
}
