<?php
defined( 'ABSPATH' ) || exit;

/**
 * Append-only activity log for sensitive / management actions.
 */
final class MCP_Audit {

	/** @return array<string,string> */
	public static function labels(): array {
		return array(
			'status_changed'     => __( 'تغییر وضعیت مکالمه', 'medyar-chat-pro' ),
			'session_closed'     => __( 'بستن مکالمه', 'medyar-chat-pro' ),
			'session_reopened'   => __( 'بازگشایی مکالمه', 'medyar-chat-pro' ),
			'follow_up_set'      => __( 'ثبت پیگیری', 'medyar-chat-pro' ),
			'follow_up_cleared'  => __( 'حذف پیگیری', 'medyar-chat-pro' ),
			'referral_created'   => __( 'ارجاع بیمار', 'medyar-chat-pro' ),
			'note_created'       => __( 'ثبت یادداشت خصوصی', 'medyar-chat-pro' ),
			'note_updated'       => __( 'ویرایش یادداشت خصوصی', 'medyar-chat-pro' ),
			'note_deleted'       => __( 'حذف یادداشت خصوصی', 'medyar-chat-pro' ),
			'profile_viewed'     => __( 'مشاهده پروفایل بیمار', 'medyar-chat-pro' ),
			'transcript_viewed'  => __( 'مشاهده متن مکالمه توسط مدیر', 'medyar-chat-pro' ),
			'session_exported'   => __( 'خروجی گرفتن از مکالمه', 'medyar-chat-pro' ),
			'file_accessed'      => __( 'دسترسی مدیر به فایل', 'medyar-chat-pro' ),
			'file_denied'        => __( 'تلاش غیرمجاز برای دسترسی به فایل', 'medyar-chat-pro' ),
			'upload_rejected'    => __( 'رد فایل آپلودی', 'medyar-chat-pro' ),
			'message_rejected'   => __( 'رد پیام', 'medyar-chat-pro' ),
			'rate_limited'       => __( 'محدودیت تعداد درخواست', 'medyar-chat-pro' ),
			'settings_updated'   => __( 'تغییر تنظیمات', 'medyar-chat-pro' ),
			'metrics_recomputed' => __( 'بازمحاسبه آمار', 'medyar-chat-pro' ),
		);
	}

	public static function label( string $action ): string {
		$labels = self::labels();
		return $labels[ $action ] ?? $action;
	}

	/**
	 * @param array<string,mixed> $details Never put message bodies or medical content here.
	 */
	public static function log( string $action, ?int $session_id = null, array $details = array(), string $object_type = '', int $object_id = 0, ?int $actor_id = null ): void {
		global $wpdb;
		$wpdb->insert(
			MCP_Install::table( 'audit' ),
			array(
				'session_id'  => $session_id ?: null,
				'actor_id'    => null === $actor_id ? get_current_user_id() : $actor_id,
				'action'      => substr( sanitize_key( $action ), 0, 64 ),
				'object_type' => '' !== $object_type ? substr( sanitize_key( $object_type ), 0, 32 ) : null,
				'object_id'   => $object_id ?: null,
				'details'     => $details ? wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) : null,
				'ip'          => self::ip(),
				'created_at'  => MCP_Theme::now(),
			)
		);
	}

	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * @param array<string,mixed> $filters session_id, actor_id, action, from, to.
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public static function search( array $filters, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$table = MCP_Install::table( 'audit' );
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['session_id'] ) ) {
			$where[] = 'session_id = %d';
			$args[]  = (int) $filters['session_id'];
		}
		if ( ! empty( $filters['actor_id'] ) ) {
			$where[] = 'actor_id = %d';
			$args[]  = (int) $filters['actor_id'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[] = 'action = %s';
			$args[]  = (string) $filters['action'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	public static function purge_expired(): void {
		global $wpdb;
		$days = (int) MCP_Settings::get( 'audit_retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MCP_Install::table( 'audit' ) . ' WHERE created_at < %s', $cutoff ) );
	}
}
