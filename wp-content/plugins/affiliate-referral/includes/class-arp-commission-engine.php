<?php
/**
 * Sole mutator of commission amount/state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Commission_Engine {

	/**
	 * Handle referral status change (and/or an admin note), logging the change.
	 *
	 * @param int        $referral_id   Referral ID.
	 * @param int        $new_status_id New status.
	 * @param float|null $amount        Required when the new status creates a commission.
	 * @param string     $note          Optional note stored in the referral history.
	 * @return true|WP_Error
	 */
	public static function on_status_change( int $referral_id, int $new_status_id, ?float $amount = null, string $note = '' ) {
		$referral = Arp_Referrals_Repo::get( $referral_id );
		if ( ! $referral ) {
			return new WP_Error( 'arp_not_found', 'معرفی یافت نشد.' );
		}

		$old_status_id = (int) $referral['status_id'];
		$old_status    = Arp_Statuses_Repo::get( $old_status_id );
		$new_status    = Arp_Statuses_Repo::get( $new_status_id );
		if ( ! $new_status ) {
			return new WP_Error( 'arp_bad_status', 'وضعیت نامعتبر است.' );
		}

		$changed      = $old_status_id !== $new_status_id;
		$old_triggers = $old_status && ! empty( $old_status['triggers_commission'] );
		$new_triggers = ! empty( $new_status['triggers_commission'] );
		$creates      = $changed && $new_triggers && ! $old_triggers && ! Arp_Commissions_Repo::get_active_for_referral( $referral_id );

		if ( $creates && ( null === $amount || $amount <= 0 ) ) {
			return new WP_Error( 'arp_amount_required', 'ثبت پورسانت نیازمند مبلغ است.' );
		}
		if ( ! $changed && '' === $note ) {
			return true;
		}

		if ( $changed ) {
			Arp_Referrals_Repo::update_status( $referral_id, $new_status_id );
			if ( $creates ) {
				self::create_paid( $referral, (float) $amount );
			} elseif ( ! $new_triggers && $old_triggers ) {
				self::reverse_active( $referral_id );
			}
		}

		Arp_Referral_Logs_Repo::insert( $referral_id, get_current_user_id(), $old_status_id, $new_status_id, $note );

		if ( $changed ) {
			/**
			 * Fires after a referral moves to another status.
			 *
			 * @param int $referral_id   Referral ID.
			 * @param int $new_status_id New status.
			 * @param int $old_status_id Previous status.
			 */
			do_action( 'arp_referral_status_changed', $referral_id, $new_status_id, $old_status_id );
		}

		return true;
	}

	/**
	 * Granting a commission-triggering status means the commission is paid immediately via wallet.
	 *
	 * @param array<string,mixed> $referral Referral row.
	 */
	private static function create_paid( array $referral, float $amount ): void {
		$rule       = Arp_Commission_Rules_Repo::get_active_for_service( (int) $referral['service_id'] );
		$percentage = $rule ? (float) $rule['percentage'] : null;
		$now        = current_time( 'mysql' );

		$commission_id = Arp_Commissions_Repo::insert(
			array(
				'referral_id'        => (int) $referral['id'],
				'affiliate_user_id'  => (int) $referral['affiliate_user_id'],
				'service_id'         => (int) $referral['service_id'],
				'percentage_applied' => $percentage,
				'amount'             => $amount,
				'state'              => 'paid',
				'paid_at'            => $now,
			)
		);

		$track = 'ARP-C-' . $commission_id;
		Arp_Commissions_Repo::update_state(
			$commission_id,
			'paid',
			array(
				'paid_at'       => $now,
				'tracking_code' => $track,
			)
		);

		/**
		 * Fires after a commission is created and paid to the affiliate wallet.
		 *
		 * @param int   $referral_id       Referral ID.
		 * @param float $amount            Amount.
		 * @param int   $affiliate_user_id Affiliate user ID.
		 * @param int   $commission_id     New commission row ID.
		 */
		do_action(
			'arp_commission_created',
			(int) $referral['id'],
			$amount,
			(int) $referral['affiliate_user_id'],
			(int) $commission_id
		);
	}

	private static function reverse_active( int $referral_id ): void {
		$active = Arp_Commissions_Repo::get_active_for_referral( $referral_id );
		if ( ! $active ) {
			return;
		}
		$cid = (int) $active['id'];
		Arp_Commissions_Repo::update_state( $cid, 'reversed' );
		/**
		 * Fires after a commission is reversed (approved or paid).
		 *
		 * @param int                 $commission_id Commission ID.
		 * @param array<string,mixed> $commission    Commission row before reverse.
		 */
		do_action( 'arp_commission_reversed', $cid, $active );
	}
}
