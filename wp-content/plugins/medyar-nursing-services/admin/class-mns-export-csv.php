<?php
/**
 * Admin CSV exports (M8).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Export_Csv {

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_export' ) );
	}

	public static function maybe_export(): void {
		if ( empty( $_GET['mns_export'] ) || empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = sanitize_key( wp_unslash( (string) $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( MNS_Admin_Menu::SLUG_FINANCE !== $page && MNS_Admin_Menu::SLUG_MONITOR !== $page ) {
			return;
		}
		$kind = sanitize_key( wp_unslash( (string) $_GET['mns_export'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'invoices' === $kind ) {
			self::stream_invoices();
		} else {
			self::stream_orders();
		}
	}

	private static function stream_orders(): void {
		$pack = MNS_Admin_Stats::query_orders( array( 'per' => 500, 'p' => 1 ) );
		self::headers( 'mns-orders.csv' );
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			exit;
		}
		fputcsv( $out, array( 'id', 'code', 'status', 'service_date', 'slot_id', 'nurse_user_id', 'source', 'created_at' ) );
		foreach ( $pack['rows'] as $r ) {
			fputcsv(
				$out,
				array(
					$r['id'],
					$r['code'],
					$r['status'],
					$r['service_date'],
					$r['slot_id'],
					$r['nurse_user_id'] ?? '',
					$r['source'] ?? '',
					$r['created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	private static function stream_invoices(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' ORDER BY id DESC LIMIT 500', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::headers( 'mns-invoices.csv' );
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			exit;
		}
		fputcsv( $out, array( 'id', 'number', 'order_id', 'status', 'amount', 'nurse_share', 'issued_at', 'paid_at' ) );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				fputcsv(
					$out,
					array(
						$r['id'],
						$r['number'],
						$r['order_id'],
						$r['status'],
						$r['amount'],
						$r['nurse_share'],
						$r['issued_at'],
						$r['paid_at'] ?? '',
					)
				);
			}
		}
		fclose( $out );
		exit;
	}

	private static function headers( string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo "\xEF\xBB\xBF";
	}
}
