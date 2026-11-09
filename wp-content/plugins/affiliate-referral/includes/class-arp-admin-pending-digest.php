<?php
/**
 * Nightly digest SMS for admin-pending queues (ARP + Medyar wallet).
 * Runs around 22:00 Asia/Tehran; recipients = arp_admin_sms_recipient_ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Admin_Pending_Digest {

	public const HOOK          = 'arp_admin_pending_digest_tick';
	public const OPTION_SENT   = 'arp_admin_digest_last_sent_ymd';
	public const OPTION_ENABLE = 'arp_admin_digest_sms_enabled';
	public const OPTION_TPL    = 'arp_admin_digest_sms_template';
	public const OPTION_PAT    = 'arp_admin_digest_sms_pattern';

	public const DEFAULT_TEMPLATE = "یادآوری شبانه مدیار — هنوز در صف بررسی است:\nبرداشت: {withdraw}\nدرخواست همکار: {affiliate}\nمعرفی بیمار: {referral}\nجمع: {total}\nلطفاً در پیشخوان بررسی کنید.\n{url}";

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_template' ), 21 );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}

	/** Drop legacy silver-trade lines from stored digest template. */
	public static function maybe_migrate_template(): void {
		$tpl = (string) get_option( self::OPTION_TPL, '' );
		if ( '' === $tpl ) {
			return;
		}
		if ( false === strpos( $tpl, '{trade}' ) && false === strpos( $tpl, '{buy}' ) && false === strpos( $tpl, '{sell}' ) && false === strpos( $tpl, 'معاملات نقره' ) ) {
			return;
		}
		update_option( self::OPTION_TPL, self::DEFAULT_TEMPLATE, false );
	}

	/** Hourly tick; send once when Tehran local hour is 22. */
	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function tick(): void {
		if ( '1' !== (string) get_option( self::OPTION_ENABLE, '1' ) ) {
			return;
		}
		if ( '1' !== (string) get_option( 'arp_admin_sms_enabled', '1' ) ) {
			return;
		}

		try {
			$tz  = new DateTimeZone( 'Asia/Tehran' );
			$now = new DateTime( 'now', $tz );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return;
		}

		if ( 22 !== (int) $now->format( 'G' ) ) {
			return;
		}

		$ymd = $now->format( 'Y-m-d' );
		if ( (string) get_option( self::OPTION_SENT, '' ) === $ymd ) {
			return;
		}

		$result = self::send_digest();
		if ( ! empty( $result['sent'] ) || ! empty( $result['skipped_empty'] ) ) {
			update_option( self::OPTION_SENT, $ymd, false );
		}
	}

	/**
	 * Collect pending counts for admin-review queues.
	 *
	 * @return array{
	 *   withdraw:int,affiliate:int,referral:int,docs:int,commission:int,total:int,
	 *   url:string,date:string,lines:string
	 * }
	 */
	public static function collect_counts(): array {
		$withdraw   = self::count_pending_withdrawals();
		$affiliate  = class_exists( 'Arp_Affiliate_Applications' ) ? Arp_Affiliate_Applications::pending_count() : 0;
		$referral   = self::count_pending_referrals();
		$docs       = self::count_pending_documents();
		$commission = self::count_legacy_commissions();
		$total      = $withdraw + $affiliate + $referral + $docs + $commission;

		$date = '';
		try {
			$date = ( new DateTime( 'now', new DateTimeZone( 'Asia/Tehran' ) ) )->format( 'Y/m/d' );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$date = gmdate( 'Y/m/d' );
		}

		$lines = self::build_summary_lines(
			array(
				'withdraw'   => $withdraw,
				'affiliate'  => $affiliate,
				'referral'   => $referral,
				'docs'       => $docs,
				'commission' => $commission,
			)
		);

		return array(
			'withdraw'   => $withdraw,
			'affiliate'  => $affiliate,
			'referral'   => $referral,
			'docs'       => $docs,
			'commission' => $commission,
			'total'      => $total,
			'url'        => admin_url( 'admin.php?page=arp-dashboard' ),
			'date'       => $date,
			'lines'      => $lines,
		);
	}

	/**
	 * @param array<string,int> $counts
	 */
	private static function build_summary_lines( array $counts ): string {
		$map = array(
			'withdraw'   => 'برداشت',
			'affiliate'  => 'درخواست همکار',
			'referral'   => 'معرفی بیمار',
			'docs'       => 'مدارک',
			'commission' => 'پورسانت قدیمی',
		);
		$parts = array();
		foreach ( $map as $key => $label ) {
			$n = (int) ( $counts[ $key ] ?? 0 );
			if ( $n > 0 ) {
				$parts[] = $label . ': ' . $n;
			}
		}
		return implode( ' | ', $parts );
	}

	private static function count_pending_withdrawals(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wallet_transactions';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			WHERE balance_type='toman' AND operation_type='withdraw'
			AND status IN ('pending','processing')"
		);
	}

	private static function count_pending_referrals(): int {
		if ( ! class_exists( 'Arp_Statuses_Repo' ) || ! class_exists( 'Arp_Referrals_Repo' ) ) {
			return 0;
		}
		$default = Arp_Statuses_Repo::get_default();
		return $default ? Arp_Referrals_Repo::count( array( 'status_id' => (int) $default['id'] ) ) : 0;
	}

	private static function count_pending_documents(): int {
		if ( ! function_exists( 'medyar_count_document_submissions_for_admin' ) ) {
			return 0;
		}
		$count = medyar_count_document_submissions_for_admin(
			array(
				'status' => 'pending_review',
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	private static function count_legacy_commissions(): int {
		if ( ! class_exists( 'Arp_Commissions_Repo' ) ) {
			return 0;
		}
		$rows = Arp_Commissions_Repo::payable_affiliates();
		$n    = 0;
		foreach ( $rows as $row ) {
			$n += (int) ( $row['count'] ?? 0 );
		}
		return $n;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return is_string( $found ) && $found === $table;
	}

	/**
	 * @param array<string,mixed> $vars
	 */
	public static function render_message( array $vars ): string {
		$template = (string) get_option( self::OPTION_TPL, self::DEFAULT_TEMPLATE );
		if ( '' === trim( $template ) ) {
			$template = self::DEFAULT_TEMPLATE;
		}
		$replace = array();
		foreach ( $vars as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$replace[ '{' . $key . '}' ] = (string) $value;
		}
		return trim( strtr( $template, $replace ) );
	}

	/**
	 * @return array{sent:int,skipped_empty:bool,total:int,message:string}
	 */
	public static function send_digest(): array {
		$vars = self::collect_counts();
		$out  = array(
			'sent'          => 0,
			'skipped_empty' => false,
			'total'         => (int) $vars['total'],
			'message'       => '',
		);

		if ( (int) $vars['total'] <= 0 ) {
			$out['skipped_empty'] = true;
			return $out;
		}

		$message = self::render_message( $vars );
		$out['message'] = $message;
		$pattern        = Arp_Faraz_Sms_Gateway::pattern_code( self::OPTION_PAT );

		/**
		 * Filters the nightly admin pending-digest SMS body.
		 *
		 * @param string              $message Rendered message.
		 * @param array<string,mixed> $vars    Counts and helpers.
		 */
		$message = (string) apply_filters( 'arp_admin_pending_digest_sms', $message, $vars );

		$out['sent'] = Arp_Faraz_Sms_Gateway::notify_admins(
			$message,
			$pattern,
			array(
				'withdraw'   => (string) $vars['withdraw'],
				'affiliate'  => (string) $vars['affiliate'],
				'referral'   => (string) $vars['referral'],
				'docs'       => (string) $vars['docs'],
				'commission' => (string) $vars['commission'],
				'total'      => (string) $vars['total'],
				'date'       => (string) $vars['date'],
				'url'        => (string) $vars['url'],
				'lines'      => (string) $vars['lines'],
			)
		);

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[ARP digest] total=%d sent=%d pattern=%s',
				(int) $vars['total'],
				(int) $out['sent'],
				'' !== $pattern ? $pattern : '-'
			)
		);

		return $out;
	}
}
