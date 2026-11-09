<?php
/**
 * Soft bridge: affiliate commissions pay out only via Medyar Toman Wallet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Wallet_Bridge {

	public static function init(): void {
		add_action( 'arp_commission_created', array( __CLASS__, 'on_commission_created' ), 10, 4 );
		add_action( 'arp_commission_reversed', array( __CLASS__, 'on_commission_reversed' ), 10, 2 );
	}

	public static function available(): bool {
		return class_exists( 'Wallet_Manager' ) && class_exists( 'Wallet_Transaction_Manager' );
	}

	public static function deposit_code( int $commission_id, int $referral_id = 0 ): string {
		if ( $commission_id > 0 ) {
			return 'ARP-C-' . $commission_id;
		}
		return $referral_id > 0 ? 'ARP-R-' . $referral_id : '';
	}

	/**
	 * @return object|null Wallet transaction row.
	 */
	public static function get_commission_deposit( int $commission_id ): ?object {
		if ( ! self::available() || $commission_id <= 0 ) {
			return null;
		}
		return self::find_tx_by_code( self::deposit_code( $commission_id ) );
	}

	/**
	 * @return object|null
	 */
	public static function find_tx_by_code( string $code ): ?object {
		if ( '' === $code || ! class_exists( 'Wallet_Transaction_Manager' ) ) {
			return null;
		}
		$rows = Wallet_Transaction_Manager::get_instance()->get_transactions_by_code( $code );
		if ( is_wp_error( $rows ) || empty( $rows ) ) {
			return null;
		}
		return is_array( $rows ) ? (object) $rows[0] : $rows[0];
	}

	public static function user_balance( int $user_id ): ?int {
		if ( ! self::available() || $user_id <= 0 ) {
			return null;
		}
		return (int) Wallet_Manager::get_instance()->get_balance( $user_id );
	}

	public static function user_insights_url( int $user_id ): string {
		return admin_url( 'admin.php?page=medyar-wallet-user-insights&user_id=' . absint( $user_id ) );
	}

	public static function withdrawals_url(): string {
		return admin_url( 'admin.php?page=medyar-wallet-withdrawals' );
	}

	public static function transactions_url( string $tx_q = '' ): string {
		$args = array( 'page' => 'medyar-wallet-transactions' );
		if ( '' !== $tx_q ) {
			$args['tx_q'] = $tx_q;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Credit wallet for a commission if not already deposited.
	 *
	 * @return true|WP_Error|null Null when wallet unavailable; true when already present or created.
	 */
	public static function ensure_commission_deposit( array $commission ) {
		if ( ! self::available() ) {
			return null;
		}
		$commission_id = (int) ( $commission['id'] ?? 0 );
		$user_id       = (int) ( $commission['affiliate_user_id'] ?? 0 );
		$referral_id   = (int) ( $commission['referral_id'] ?? 0 );
		$amount        = (float) ( $commission['amount'] ?? 0 );
		if ( $commission_id <= 0 || $user_id <= 0 || $amount <= 0 ) {
			return new WP_Error( 'arp_wallet_bad', 'پورسانت نامعتبر برای واریز کیف پول.' );
		}
		$code = self::deposit_code( $commission_id, $referral_id );
		if ( self::tx_exists( $code ) ) {
			return true;
		}
		return self::credit_deposit( $user_id, $amount, $referral_id, $commission_id, $code );
	}

	/**
	 * Credit wallet for every legacy approved commission and mark it paid.
	 *
	 * @return array{ok:int,failed:int}
	 */
	public static function migrate_all_approved(): array {
		$out = array( 'ok' => 0, 'failed' => 0 );
		if ( ! self::available() ) {
			return $out;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . Arp_Db::commissions() . " WHERE state = 'approved' ORDER BY id ASC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$now = current_time( 'mysql' );
		foreach ( (array) $rows as $row ) {
			$cid = (int) $row['id'];
			$res = self::ensure_commission_deposit( $row );
			if ( is_wp_error( $res ) ) {
				++$out['failed'];
				continue;
			}
			Arp_Commissions_Repo::update_state(
				$cid,
				'paid',
				array(
					'paid_at'       => $now,
					'tracking_code' => self::deposit_code( $cid, (int) $row['referral_id'] ),
				)
			);
			++$out['ok'];
		}
		return $out;
	}

	/**
	 * @param int   $referral_id        Referral ID.
	 * @param float $amount             Commission amount.
	 * @param int   $affiliate_user_id  Affiliate user ID.
	 * @param int   $commission_id      Commission row ID.
	 */
	public static function on_commission_created( $referral_id, $amount, $affiliate_user_id = 0, $commission_id = 0 ): void {
		$referral_id       = (int) $referral_id;
		$affiliate_user_id = (int) $affiliate_user_id;
		$commission_id     = (int) $commission_id;
		$amount            = (float) $amount;

		if ( $amount <= 0 || ! $affiliate_user_id || ! self::available() ) {
			return;
		}

		$code = self::deposit_code( $commission_id, $referral_id );
		if ( self::tx_exists( $code ) ) {
			return;
		}

		$result = self::credit_deposit( $affiliate_user_id, $amount, $referral_id, $commission_id, $code );
		if ( is_wp_error( $result ) ) {
			error_log( '[ARP Wallet] deposit failed for commission #' . $commission_id . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_errors
		}
	}

	/**
	 * @param int   $commission_id Commission ID.
	 * @param array $commission    Commission row.
	 */
	public static function on_commission_reversed( $commission_id, $commission = array() ): void {
		$commission_id = (int) $commission_id;
		if ( $commission_id <= 0 || ! is_array( $commission ) || ! self::available() ) {
			return;
		}

		$user_id = (int) ( $commission['affiliate_user_id'] ?? 0 );
		$amount  = (float) ( $commission['amount'] ?? 0 );
		$ref_id  = (int) ( $commission['referral_id'] ?? 0 );
		if ( $user_id <= 0 || $amount <= 0 ) {
			return;
		}

		$deposit_code = self::deposit_code( $commission_id, $ref_id );
		if ( ! self::tx_exists( $deposit_code ) ) {
			return;
		}

		$rev_code = $deposit_code . '-REV';
		if ( self::tx_exists( $rev_code ) ) {
			return;
		}

		$result = Wallet_Manager::get_instance()->reduce_balance(
			$user_id,
			$amount,
			'admin_reduce',
			array(
				'status'           => 'completed',
				'description'      => sprintf( 'برگشت پورسانت همکاری — معرفی #%d (پورسانت #%d)', $ref_id, $commission_id ),
				'transaction_code' => $rev_code,
				'p_info'           => array(
					'type'              => 'affiliate_commission_reversal',
					'referral_id'       => $ref_id,
					'commission_id'     => $commission_id,
					'affiliate_user_id' => $user_id,
					'linked_code'       => $deposit_code,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log( '[ARP Wallet] reverse failed for commission #' . $commission_id . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_errors
		}
	}

	/**
	 * @return true|WP_Error
	 */
	private static function credit_deposit( int $user_id, float $amount, int $referral_id, int $commission_id, string $code ) {
		$desc = sprintf( 'واریز پورسانت همکاری — معرفی #%d', $referral_id );
		if ( $commission_id ) {
			$desc .= sprintf( ' (پورسانت #%d)', $commission_id );
		}

		$result = Wallet_Manager::get_instance()->add_balance(
			$user_id,
			$amount,
			'admin_add',
			array(
				'status'           => 'completed',
				'description'      => $desc,
				'transaction_code' => $code,
				'p_info'           => array(
					'type'              => 'affiliate_commission',
					'referral_id'       => $referral_id,
					'commission_id'     => $commission_id,
					'affiliate_user_id' => $user_id,
				),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	private static function tx_exists( string $code ): bool {
		return null !== self::find_tx_by_code( $code );
	}
}
