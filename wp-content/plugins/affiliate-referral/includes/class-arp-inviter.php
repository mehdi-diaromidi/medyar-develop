<?php
/**
 * Affiliate inviter codes: per-user codes, cookie capture, share links, admin breakdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Inviter {

	public const QUERY_ARG   = 'arp_inv';
	public const COOKIE_NAME = 'arp_inviter_code';
	public const COOKIE_TTL  = 30 * DAY_IN_SECONDS;

	/** Meta on invitee (applicant). */
	public const META_CODE    = 'arp_inviter_code';
	public const META_USER_ID = 'arp_inviter_user_id';

	/** Meta on the inviter themselves (their share code). */
	public const META_OWN_CODE = 'arp_own_inviter_code';

	public const CODE_LENGTH = 8;

	/** One-time: regenerate own codes that are not exactly 8 lowercase letters. */
	public const CANONICAL_MIGRATE_OPTION = 'arp_inviter_codes_canonical_v1';

	public const OPTION_SPECIAL_IDS = 'arp_special_inviter_ids';

	/** @var int[] Default special inviters when the option has never been saved. */
	private const DEFAULT_SPECIAL_IDS = array( 118 );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'capture_from_request' ), 1 );
		add_action( 'init', array( __CLASS__, 'maybe_canonicalize_own_codes' ), 2 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20 );
	}

	public static function on_user_register( int $user_id ): void {
		self::ensure_code( $user_id );
	}

	/**
	 * One-time: replace non-canonical own codes (e.g. legacy danpartner) with 8 a–z letters.
	 */
	public static function maybe_canonicalize_own_codes(): void {
		if ( get_option( self::CANONICAL_MIGRATE_OPTION ) ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::META_OWN_CODE
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$uid  = (int) ( $row['user_id'] ?? 0 );
			$code = self::normalize( (string) ( $row['meta_value'] ?? '' ) );
			if ( $uid <= 0 || self::is_canonical_code( $code ) ) {
				continue;
			}
			delete_user_meta( $uid, self::META_OWN_CODE );
			self::ensure_code( $uid );
		}
		update_option( self::CANONICAL_MIGRATE_OPTION, '1', true );
	}

	/**
	 * @return int[]
	 */
	public static function special_inviter_ids(): array {
		$raw = get_option( self::OPTION_SPECIAL_IDS, null );
		if ( null === $raw ) {
			return self::DEFAULT_SPECIAL_IDS;
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	public static function is_special_inviter( int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::special_inviter_ids(), true );
	}

	public static function is_canonical_code( string $code ): bool {
		$code = self::normalize( $code );
		return 1 === preg_match( '/^[a-z]{' . self::CODE_LENGTH . '}$/', $code );
	}

	/**
	 * Ensure the user has an own inviter code; generate if missing or non-canonical.
	 */
	public static function ensure_code( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$existing = self::normalize( (string) get_user_meta( $user_id, self::META_OWN_CODE, true ) );
		if ( '' !== $existing && self::is_canonical_code( $existing ) ) {
			return $existing;
		}
		if ( '' !== $existing ) {
			delete_user_meta( $user_id, self::META_OWN_CODE );
		}
		$code = self::generate_unique_code();
		if ( '' === $code ) {
			return '';
		}
		update_user_meta( $user_id, self::META_OWN_CODE, $code );
		return $code;
	}

	public static function code_for_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		return self::ensure_code( $user_id );
	}

	public static function is_inviter( int $user_id ): bool {
		return '' !== self::code_for_user( $user_id );
	}

	/**
	 * Inviter map for admin tabs: only users who already referred at least one applicant.
	 *
	 * @return array<int,string> user_id => code
	 */
	public static function codes(): array {
		return self::active_inviters();
	}

	/**
	 * @return array<int,string>
	 */
	public static function active_inviters(): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY CAST(meta_value AS UNSIGNED) ASC",
				self::META_USER_ID
			)
		);
		$out = array();
		foreach ( (array) $ids as $uid ) {
			$uid  = (int) $uid;
			$code = self::code_for_user( $uid );
			if ( $uid > 0 && '' !== $code ) {
				$out[ $uid ] = $code;
			}
		}
		return $out;
	}

	public static function user_id_for_code( string $code ): int {
		$code = self::normalize( $code );
		if ( '' === $code || ! self::is_canonical_code( $code ) ) {
			return 0;
		}
		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => 'ID',
				'meta_key'   => self::META_OWN_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $users ? (int) $users[0] : 0;
	}

	public static function is_valid( string $code ): bool {
		return self::user_id_for_code( $code ) > 0;
	}

	public static function normalize( string $code ): string {
		$code = strtolower( trim( $code ) );
		$code = preg_replace( '/[^a-z0-9]/', '', $code ) ?? '';
		return $code;
	}

	/** Partner signup URL with inviter query arg. */
	public static function share_url( int $user_id ): string {
		$code = self::code_for_user( $user_id );
		if ( '' === $code ) {
			return '';
		}
		return add_query_arg( self::QUERY_ARG, $code, Arp_Pages::dashboard_url() );
	}

	/**
	 * Valid inviter code from cookie (if any).
	 */
	public static function cookie_code(): string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_scalar( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}
		$code = self::normalize( wp_unslash( (string) $_COOKIE[ self::COOKIE_NAME ] ) );
		return self::is_valid( $code ) ? $code : '';
	}

	/**
	 * Capture ?arp_inv=CODE, set cookie, redirect to clean URL.
	 */
	public static function capture_from_request(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( empty( $_GET[ self::QUERY_ARG ] ) || ! is_scalar( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$raw  = wp_unslash( (string) $_GET[ self::QUERY_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = self::normalize( $raw );
		if ( ! self::is_valid( $code ) ) {
			return;
		}

		self::set_cookie( $code );

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$args  = array();
		if ( '' !== $query ) {
			parse_str( $query, $args );
		}
		unset( $args[ self::QUERY_ARG ] );

		$target = home_url( $path );
		if ( $args ) {
			$target = add_query_arg( $args, $target );
		}

		wp_safe_redirect( $target, 302 );
		exit;
	}

	public static function set_cookie( string $code ): void {
		$code = self::normalize( $code );
		if ( ! self::is_valid( $code ) ) {
			return;
		}
		$expire = time() + self::COOKIE_TTL;
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';
		$secure = is_ssl();

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE_NAME,
				$code,
				array(
					'expires'  => $expire,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE_NAME, $code, $expire, $path, $domain, $secure, true );
		}
		$_COOKIE[ self::COOKIE_NAME ] = $code;
	}

	/**
	 * Resolve submitted (or cookie-locked) code for signup.
	 *
	 * @return array{code:string,user_id:int,locked:bool}|WP_Error
	 */
	public static function resolve_for_signup( string $submitted ) {
		$locked = self::cookie_code();
		$code   = '' !== $locked ? $locked : self::normalize( $submitted );

		if ( '' === $code ) {
			return array(
				'code'    => '',
				'user_id' => 0,
				'locked'  => false,
			);
		}

		$user_id = self::user_id_for_code( $code );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'inviter', 'کد معرف معتبر نیست.', array( 'field' => 'inviter_code' ) );
		}

		if ( '' !== $locked && self::normalize( $submitted ) !== '' && self::normalize( $submitted ) !== $locked ) {
			return new WP_Error( 'inviter', 'کد معرف معتبر نیست.', array( 'field' => 'inviter_code' ) );
		}

		return array(
			'code'    => $code,
			'user_id' => $user_id,
			'locked'  => '' !== $locked,
		);
	}

	public static function persist_for_user( int $applicant_id, string $code, int $inviter_user_id ): void {
		if ( $inviter_user_id <= 0 || '' === $code ) {
			delete_user_meta( $applicant_id, self::META_CODE );
			delete_user_meta( $applicant_id, self::META_USER_ID );
			return;
		}
		update_user_meta( $applicant_id, self::META_CODE, $code );
		update_user_meta( $applicant_id, self::META_USER_ID, $inviter_user_id );
	}

	public static function referred_count( int $inviter_user_id = 0 ): int {
		$meta_query = array(
			array(
				'key'     => self::META_USER_ID,
				'compare' => 'EXISTS',
			),
		);
		if ( $inviter_user_id > 0 ) {
			$meta_query = array(
				array(
					'key'     => self::META_USER_ID,
					'value'   => $inviter_user_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			);
		}

		$query = new WP_User_Query(
			array(
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
				'meta_query'  => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $query->get_total();
	}

	public static function inviter_label( int $user_id ): string {
		$code = self::code_for_user( $user_id );
		if ( '' === $code ) {
			return '';
		}
		$user = get_userdata( $user_id );
		$name = $user ? (string) $user->display_name : sprintf( 'کاربر #%d', $user_id );
		return sprintf( '%s (%s)', $name, $code );
	}

	/**
	 * @return array{items:WP_User[],total:int}
	 */
	public static function referred_affiliates( int $inviter_user_id, int $page = 1, int $per_page = 20 ): array {
		if ( $inviter_user_id <= 0 ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( 50, $per_page ) );

		$query = new WP_User_Query(
			array(
				'number'     => $per_page,
				'paged'      => $page,
				'orderby'    => 'meta_value',
				'order'      => 'DESC',
				'meta_key'   => Arp_Affiliate_Applications::META_APPLIED_AT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::META_USER_ID,
						'value'   => $inviter_user_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return array(
			'items' => $query->get_results(),
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * Invitee user IDs for an inviter.
	 *
	 * @return int[]
	 */
	public static function referred_user_ids( int $inviter_user_id ): array {
		if ( $inviter_user_id <= 0 ) {
			return array();
		}
		$query = new WP_User_Query(
			array(
				'number'     => -1,
				'fields'     => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::META_USER_ID,
						'value'   => $inviter_user_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return array_map( 'intval', (array) $query->get_results() );
	}

	/**
	 * Admin: commission-bearing rows created by affiliates invited by $inviter_user_id.
	 *
	 * @return array{
	 *   invitee_count:int,
	 *   commission_count:int,
	 *   amount_total:float,
	 *   by_affiliate:array<int,array{payable:float,paid:float,earned:float}>,
	 *   rows:array<int,array<string,mixed>>
	 * }
	 */
	public static function commission_breakdown( int $inviter_user_id, int $limit = 200 ): array {
		$empty = array(
			'invitee_count'    => 0,
			'commission_count' => 0,
			'amount_total'     => 0.0,
			'by_affiliate'     => array(),
			'rows'             => array(),
		);
		if ( $inviter_user_id <= 0 ) {
			return $empty;
		}

		$affiliate_ids = self::referred_user_ids( $inviter_user_id );
		$empty['invitee_count'] = count( $affiliate_ids );
		if ( ! $affiliate_ids ) {
			return $empty;
		}

		$totals = Arp_Commissions_Repo::totals_by_affiliates( $affiliate_ids );
		$by     = array();
		$sum    = 0.0;
		foreach ( $affiliate_ids as $aid ) {
			$payable = (float) ( $totals[ $aid ]['payable'] ?? 0 );
			$paid    = (float) ( $totals[ $aid ]['paid'] ?? 0 );
			$earned  = $payable + $paid;
			$by[ $aid ] = array(
				'payable' => $payable,
				'paid'    => $paid,
				'earned'  => $earned,
			);
			$sum += $earned;
		}

		$rows = Arp_Commissions_Repo::query(
			array(
				'affiliate_user_ids' => $affiliate_ids,
				'states'             => array( 'approved', 'paid' ),
				'limit'              => max( 1, $limit ),
				'offset'             => 0,
			)
		);

		return array(
			'invitee_count'    => count( $affiliate_ids ),
			'commission_count' => count( $rows ),
			'amount_total'     => $sum,
			'by_affiliate'     => $by,
			'rows'             => $rows,
		);
	}

	public static function user_mobile_raw( int $user_id ): string {
		$mobile = (string) get_user_meta( $user_id, 'mobile', true );
		if ( '' === $mobile ) {
			$mobile = (string) get_user_meta( $user_id, 'billing_phone', true );
		}
		return Arp_Format::iran_mobile( $mobile );
	}

	/** Iranian mobile with middle four digits masked (0912****6789). */
	public static function mask_mobile_display( string $raw ): string {
		$mobile = Arp_Format::iran_mobile( $raw );
		if ( '' === $mobile ) {
			return '—';
		}
		return Arp_Format::fa( substr( $mobile, 0, 4 ) . '****' . substr( $mobile, 8 ) );
	}

	public static function membership_date_display( int $user_id ): string {
		$raw = (string) get_user_meta( $user_id, 'arp_registered_at', true );
		if ( '' === $raw ) {
			$raw = (string) get_user_meta( $user_id, Arp_Affiliate_Applications::META_APPLIED_AT, true );
		}
		if ( '' === $raw ) {
			return '—';
		}
		$jalali = Arp_Format::to_jalali( $raw );
		return '' !== $jalali ? Arp_Format::fa( $jalali ) : '—';
	}

	private static function generate_unique_code(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz';
		$max      = 40;
		for ( $i = 0; $i < $max; $i++ ) {
			$code = '';
			for ( $j = 0; $j < self::CODE_LENGTH; $j++ ) {
				$code .= $alphabet[ random_int( 0, 25 ) ];
			}
			if ( ! self::code_taken( $code ) ) {
				return $code;
			}
		}
		return '';
	}

	private static function code_taken( string $code ): bool {
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return true;
		}
		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => 'ID',
				'meta_key'   => self::META_OWN_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (bool) $users;
	}
}
