<?php
/**
 * Presentation mapping for referral statuses and commission states.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Ui {

	/** Seeded status slug => [badge tone, default color]. */
	private const STATUS_STYLE = array(
		'waiting_contact'   => array( 'waiting', '#0EA5E9' ),
		'in_progress'       => array( 'progress', '#0272B1' ),
		'patient_accepted'  => array( 'accepted', '#16A34A' ),
		'completed'         => array( 'done', '#0F766E' ),
		'patient_withdrawn' => array( 'cancel', '#E11D48' ),
		'failed_contact'    => array( 'failed', '#EA580C' ),
	);

	/** Status colors admins can pick from: hex => label. Dark enough for text on a light tint. */
	public const STATUS_PALETTE = array(
		'#0272B1' => 'آبی مدیار',
		'#0EA5E9' => 'آبی آسمانی',
		'#06B6D4' => 'فیروزه‌ای',
		'#0D9488' => 'سبزآبی',
		'#0F766E' => 'سبز کله‌غازی',
		'#059669' => 'زمردی',
		'#16A34A' => 'سبز',
		'#65A30D' => 'سبز لیمویی',
		'#4D7C0F' => 'زیتونی',
		'#CA8A04' => 'خردلی',
		'#F59E0B' => 'کهربایی',
		'#EA580C' => 'نارنجی',
		'#DC2626' => 'قرمز',
		'#9F1239' => 'زرشکی',
		'#E11D48' => 'سرخابی',
		'#DB2777' => 'صورتی',
		'#C026D3' => 'ارغوانی',
		'#9333EA' => 'بنفش',
		'#7C3AED' => 'یاسی',
		'#4F46E5' => 'نیلی',
		'#2563EB' => 'آبی پررنگ',
		'#1E3A8A' => 'سرمه‌ای',
		'#92400E' => 'قهوه‌ای',
		'#475569' => 'خاکستری آبی',
		'#1F2937' => 'زغالی',
	);

	private const COMMISSION_STATES = array(
		'approved' => array( 'در انتظار پرداخت', 'progress' ),
		'paid'     => array( 'پرداخت شد', 'done' ),
		'reversed' => array( 'برگشت‌خورده', 'cancel' ),
		'pending'  => array( 'در انتظار تایید', 'waiting' ),
	);

	/**
	 * Badge tone for a status row (needs slug; uses flags for admin-added statuses).
	 *
	 * @param array<string,mixed> $status Status row or referral row with status_* keys.
	 */
	public static function status_tone( array $status ): string {
		$slug = (string) ( $status['slug'] ?? $status['status_slug'] ?? '' );
		if ( isset( self::STATUS_STYLE[ $slug ] ) ) {
			return self::STATUS_STYLE[ $slug ][0];
		}
		if ( ! empty( $status['triggers_commission'] ) ) {
			return 'done';
		}
		if ( ! empty( $status['is_terminal'] ) ) {
			return 'cancel';
		}
		return 'progress';
	}

	/**
	 * Admin-chosen color of a status, else the seeded/positional default.
	 *
	 * @param array<string,mixed> $status Status row, or referral row with status_* keys.
	 * @param int                 $index  Position among statuses (picks the fallback).
	 */
	public static function status_color( array $status, int $index = 0 ): string {
		$color = self::sanitize_color( (string) ( $status['color'] ?? $status['status_color'] ?? '' ) );
		if ( '' !== $color ) {
			return $color;
		}
		return self::default_status_color( (string) ( $status['slug'] ?? $status['status_slug'] ?? '' ), $index );
	}

	public static function default_status_color( string $slug, int $index ): string {
		if ( isset( self::STATUS_STYLE[ $slug ] ) ) {
			return self::STATUS_STYLE[ $slug ][1];
		}
		$palette = array_keys( self::STATUS_PALETTE );
		return $palette[ $index % count( $palette ) ];
	}

	/**
	 * Upper-case #RRGGBB, or '' when invalid.
	 */
	public static function sanitize_color( string $color ): string {
		$color = sanitize_hex_color( $color );
		if ( ! $color ) {
			return '';
		}
		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		return strtoupper( $color );
	}

	/**
	 * Inline custom property for a status pill (.arp-status).
	 *
	 * @param array<string,mixed> $status Status row, or referral row with status_* keys.
	 */
	public static function status_style( array $status ): string {
		return '--arp-status-c:' . self::status_color( $status );
	}

	/**
	 * States affiliates can filter by (engine never produces `pending`).
	 *
	 * @return array<string,string> state => label
	 */
	public static function commission_filter_states(): array {
		$out = array();
		foreach ( array( 'approved', 'paid', 'reversed' ) as $state ) {
			$out[ $state ] = self::COMMISSION_STATES[ $state ][0];
		}
		return $out;
	}

	public static function commission_label( string $state ): string {
		return self::COMMISSION_STATES[ $state ][0] ?? $state;
	}

	public static function commission_tone( string $state ): string {
		return self::COMMISSION_STATES[ $state ][1] ?? 'failed';
	}

	/**
	 * Jalali date-range filter from GET, normalised and ordered.
	 *
	 * @return array{from:string,to:string,date_from:string,date_to:string} Jalali (ASCII) + Gregorian.
	 */
	public static function date_range_from_request(): array {
		$out = array( 'from' => '', 'to' => '', 'date_from' => '', 'date_to' => '' );
		foreach ( array( 'from', 'to' ) as $key ) {
			$raw = isset( $_GET[ 'arp_' . $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'arp_' . $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$gregorian = Arp_Format::jalali_to_date( $raw );
			if ( '' !== $gregorian ) {
				$out[ $key ]          = Arp_Format::en( $raw );
				$out[ 'date_' . $key ] = $gregorian;
			}
		}
		if ( '' !== $out['date_from'] && '' !== $out['date_to'] && $out['date_from'] > $out['date_to'] ) {
			$out = array(
				'from'      => $out['to'],
				'to'        => $out['from'],
				'date_from' => $out['date_to'],
				'date_to'   => $out['date_from'],
			);
		}
		return $out;
	}

	/**
	 * Page numbers around the current page, with '…' gaps; always includes first and last.
	 *
	 * @return array<int,int|string>
	 */
	public static function pager_items( int $current, int $pages, int $radius ): array {
		$items = array();
		$prev  = 0;
		for ( $n = 1; $n <= $pages; $n++ ) {
			if ( 1 === $n || $pages === $n || abs( $n - $current ) <= $radius ) {
				if ( $prev && $n - $prev > 1 ) {
					$items[] = '…';
				}
				$items[] = $n;
				$prev    = $n;
			}
		}
		return $items;
	}
}
