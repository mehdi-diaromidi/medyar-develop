<?php
/**
 * CSV export (Excel-friendly UTF-8 BOM), honouring the list-screen filters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Export_Csv {

	private const BATCH = 500;

	public static function maybe_export(): void {
		if ( empty( $_GET['arp_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! Arp_Admin::can_manage() ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'arp_export' ) ) {
			wp_die( esc_html__( 'لینک خروجی منقضی شده است؛ صفحه را تازه کنید.', 'affiliate-referral' ), '', array( 'response' => 403 ) );
		}

		$type = sanitize_key( wp_unslash( $_GET['arp_export'] ) );
		if ( ! in_array( $type, array( 'referrals', 'commissions', 'affiliates' ), true ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=arp-' . $type . '-' . current_time( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		call_user_func( array( __CLASS__, 'export_' . $type ), $out );
		fclose( $out );
		exit;
	}

	/**
	 * @param resource $out Stream.
	 */
	private static function export_referrals( $out ): void {
		self::row( $out, array( 'شناسه', 'بیمار', 'تلفن', 'همکار', 'کد همکار', 'خدمت', 'وضعیت', 'تاریخ ثبت', 'ساعت', 'پورسانت (تومان)', 'وضعیت پورسانت', 'توضیحات' ) );
		$filters = Arp_Referrals_Table::filters_from_request();

		for ( $offset = 0; ; $offset += self::BATCH ) {
			$rows = Arp_Referrals_Repo::query( $filters + array( 'limit' => self::BATCH, 'offset' => $offset ) );
			if ( ! $rows ) {
				break;
			}
			cache_users( array_map( 'intval', array_column( $rows, 'affiliate_user_id' ) ) );
			foreach ( $rows as $r ) {
				$uid = (int) $r['affiliate_user_id'];
				self::row(
					$out,
					array(
						$r['id'],
						$r['patient_name'],
						$r['patient_phone'],
						Arp_Admin_Ui::user_name( $uid ),
						Arp_Referral_Code::get_for_user( $uid ),
						$r['service_name'] ?? '',
						$r['status_name'] ?? '',
						Arp_Format::to_jalali( (string) $r['created_at'] ),
						substr( (string) $r['created_at'], 11, 5 ),
						$r['commission_amount'] ?? '',
						null !== $r['commission_state'] ? Arp_Ui::commission_label( (string) $r['commission_state'] ) : '',
						$r['description'],
					)
				);
			}
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}
	}

	/**
	 * @param resource $out Stream.
	 */
	private static function export_commissions( $out ): void {
		self::row( $out, array( 'شناسه', 'شناسه معرفی', 'بیمار', 'همکار', 'خدمت', 'درصد راهنما', 'مبلغ (تومان)', 'وضعیت', 'کد پیگیری', 'تاریخ پرداخت', 'تاریخ ثبت' ) );
		$filters = Arp_Commissions_Table::filters_from_request();

		for ( $offset = 0; ; $offset += self::BATCH ) {
			$rows = Arp_Commissions_Repo::query( $filters + array( 'limit' => self::BATCH, 'offset' => $offset ) );
			if ( ! $rows ) {
				break;
			}
			cache_users( array_map( 'intval', array_column( $rows, 'affiliate_user_id' ) ) );
			foreach ( $rows as $r ) {
				self::row(
					$out,
					array(
						$r['id'],
						$r['referral_id'],
						$r['patient_name'] ?? '',
						Arp_Admin_Ui::user_name( (int) $r['affiliate_user_id'] ),
						$r['service_name'] ?? '',
						$r['percentage_applied'] ?? '',
						$r['amount'],
						Arp_Ui::commission_label( (string) $r['state'] ),
						$r['tracking_code'] ?? '',
						$r['paid_at'] ? Arp_Format::to_jalali( (string) $r['paid_at'] ) : '',
						Arp_Format::to_jalali( (string) $r['created_at'] ),
					)
				);
			}
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}
	}

	/**
	 * @param resource $out Stream.
	 */
	private static function export_affiliates( $out ): void {
		self::row( $out, array( 'شناسه کاربر', 'نام', 'ایمیل', 'موبایل', 'نوع شغل', 'کد همکاری', 'تعداد معرفی', 'قابل پرداخت (تومان)', 'پرداخت‌شده (تومان)', 'تاریخ عضویت' ) );
		$jobs = array_column( Arp_Job_Types_Repo::all(), 'name', 'id' );

		for ( $page = 1; ; $page++ ) {
			$users = get_users(
				array(
					'role'    => Arp_Roles::ROLE,
					'orderby' => 'registered',
					'order'   => 'DESC',
					'number'  => self::BATCH,
					'paged'   => $page,
				)
			);
			if ( ! $users ) {
				break;
			}
			$ids    = wp_list_pluck( $users, 'ID' );
			$counts = Arp_Referrals_Repo::count_by_affiliates( $ids );
			$totals = Arp_Commissions_Repo::totals_by_affiliates( $ids );
			foreach ( $users as $u ) {
				$mobile = (string) get_user_meta( $u->ID, 'mobile', true );
				self::row(
					$out,
					array(
						$u->ID,
						$u->display_name,
						$u->user_email,
						'' !== $mobile ? $mobile : (string) get_user_meta( $u->ID, 'billing_phone', true ),
						$jobs[ (int) get_user_meta( $u->ID, 'arp_job_type_id', true ) ] ?? '',
						Arp_Referral_Code::get_for_user( $u->ID ),
						$counts[ $u->ID ] ?? 0,
						$totals[ $u->ID ]['payable'] ?? 0,
						$totals[ $u->ID ]['paid'] ?? 0,
						Arp_Format::to_jalali( get_date_from_gmt( $u->user_registered ) ),
					)
				);
			}
			if ( count( $users ) < self::BATCH ) {
				break;
			}
		}
	}

	/**
	 * Write a row, neutralising cells a spreadsheet would evaluate as a formula.
	 *
	 * @param resource          $out   Stream.
	 * @param array<int,mixed>  $cells Values.
	 */
	private static function row( $out, array $cells ): void {
		foreach ( $cells as &$cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && ! is_numeric( $cell ) && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$cell = "'" . $cell;
			}
		}
		unset( $cell );
		fputcsv( $out, $cells );
	}
}
