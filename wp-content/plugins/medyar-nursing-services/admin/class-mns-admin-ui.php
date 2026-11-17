<?php
/**
 * Shared admin UI helpers (ARP-standard shell).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Ui {

	/** Notice key => [type, message]. */
	private const NOTICES = array(
		'saved'               => array( 'success', 'تغییرات ذخیره شد.' ),
		'assigned'            => array( 'success', 'پرستار تخصیص داده شد.' ),
		'cancelled'           => array( 'success', 'سفارش لغو شد.' ),
		'created'             => array( 'success', 'سفارش ثبت شد.' ),
		'approved'            => array( 'success', 'پرستار تأیید شد.' ),
		'rejected'            => array( 'success', 'درخواست رد شد.' ),
		'suspended'           => array( 'success', 'پرستار تعلیق شد.' ),
		'reinstated'          => array( 'success', 'تعلیق برداشته شد.' ),
		'missing_fields'      => array( 'error', 'فیلدهای ضروری را کامل کنید.' ),
		'not_found'           => array( 'error', 'مورد یافت نشد.' ),
		'forbidden'           => array( 'error', 'دسترسی ندارید.' ),
		'bad_state'           => array( 'error', 'این عملیات در وضعیت فعلی مجاز نیست.' ),
		'error'               => array( 'error', 'خطایی رخ داد. دوباره تلاش کنید.' ),
		'migrated'            => array( 'success', 'مهاجرت انجام شد.' ),
		'reset_done'          => array( 'success', 'بازنشانی انجام شد.' ),
		'wallet_unavailable'  => array( 'error', 'افزونه کیف پول فعال نیست.' ),
		'invoice_issued'      => array( 'success', 'فاکتور صادر شد.' ),
		'payment_recorded'    => array( 'success', 'پرداخت ثبت شد.' ),
		'started'             => array( 'success', 'خدمت شروع شد.' ),
		'ended'               => array( 'success', 'خدمت پایان یافت.' ),
		'step_unlocked'       => array( 'success', 'مرحله باز شد.' ),
		'bulk_updated'        => array( 'success', '%d مورد به‌روزرسانی شد.' ),
	);

	/**
	 * @param array<string,scalar> $args Query args.
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param array<string,scalar> $args Extra query args.
	 */
	public static function redirect( string $page, string $notice, array $args = array() ): void {
		wp_safe_redirect( self::url( $page, array_merge( $args, array( 'mns_notice' => $notice ) ) ) );
		exit;
	}

	public static function notices(): void {
		echo '<div class="mns-notices" aria-live="polite">';
		$key = isset( $_GET['mns_notice'] ) ? sanitize_key( wp_unslash( $_GET['mns_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( self::NOTICES[ $key ] ) ) {
			list( $type, $message ) = self::NOTICES[ $key ];
			if ( false !== strpos( $message, '%d' ) ) {
				$message = sprintf( $message, isset( $_GET['mns_count'] ) ? absint( $_GET['mns_count'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			printf(
				'<div class="notice notice-%1$s inline is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( class_exists( 'MNS_Format', true ) ? MNS_Format::digits( $message ) : $message )
			);
		}
		echo '</div>';
	}

	/**
	 * @param array<string,string> $tabs slug => label.
	 */
	public static function tabs( string $page, array $tabs, string $current ): void {
		echo '<nav class="nav-tab-wrapper wp-clearfix mns-tabs" aria-label="' . esc_attr__( 'منوی فرعی', 'medyar-nursing-services' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $page, array( 'tab' => $slug ) ) ),
				$slug === $current ? ' nav-tab-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * @param array<string,string> $tabs slug => label.
	 */
	public static function current_tab( array $tabs ): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	public static function badge( string $label, string $color = '#64748B' ): string {
		return sprintf(
			'<span class="mns-badge" style="--mns-c:%1$s">%2$s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	public static function status_badge( string $status ): string {
		$map = array(
			'submitted'     => array( 'ثبت‌شده', '#0EA5E9' ),
			'assigned'      => array( 'تخصیص‌یافته', '#0272B1' ),
			'in_progress'   => array( 'در حال انجام', '#CA8A04' ),
			'service_ended' => array( 'پایان خدمت', '#0F766E' ),
			'needs_review'  => array( 'نیازمند بررسی', '#EA580C' ),
			'completed'     => array( 'تکمیل‌شده', '#16A34A' ),
			'cancelled'     => array( 'لغوشده', '#E11D48' ),
			'invoiced'      => array( 'فاکتور شده', '#7C3AED' ),
			'paid'          => array( 'پرداخت‌شده', '#059669' ),
			'pending'       => array( 'در انتظار', '#0EA5E9' ),
			'approved'      => array( 'تأییدشده', '#16A34A' ),
			'rejected'      => array( 'ردشده', '#E11D48' ),
			'suspended'     => array( 'تعلیق', '#EA580C' ),
		);
		$row = $map[ $status ] ?? array( $status ? $status : '—', '#64748B' );
		return self::badge( $row[0], $row[1] );
	}

	public static function money( $amount ): string {
		if ( null === $amount || '' === $amount ) {
			return '—';
		}
		$n = (float) $amount;
		$fmt = number_format_i18n( $n, 0 );
		$fmt = class_exists( 'MNS_Format', true ) ? MNS_Format::digits( $fmt ) : $fmt;
		return $fmt . ' تومان';
	}

	public static function date( ?string $datetime, bool $with_time = false ): string {
		if ( ! $datetime || 0 === strpos( $datetime, '0000' ) ) {
			return '—';
		}
		if ( class_exists( 'MNS_Format', true ) && method_exists( 'MNS_Format', 'jdate' ) ) {
			$out = MNS_Format::jdate( $datetime );
		} else {
			$ts  = strtotime( $datetime );
			$out = $ts ? wp_date( 'Y/m/d', $ts ) : $datetime;
		}
		if ( $with_time && preg_match( '/\s(\d{2}:\d{2})/', $datetime, $m ) ) {
			$out .= ' ' . $m[1];
		}
		return class_exists( 'MNS_Format', true ) ? MNS_Format::digits( (string) $out ) : (string) $out;
	}

	public static function ago( string $datetime ): string {
		$ts = strtotime( get_gmt_from_date( $datetime ) . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		$txt = sprintf( '%s پیش', human_time_diff( $ts ) );
		return class_exists( 'MNS_Format', true ) ? MNS_Format::digits( $txt ) : $txt;
	}

	public static function user_name( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : ( $user_id ? '#' . $user_id : '—' );
	}

	public static function fa_n( $n ): string {
		$s = number_format_i18n( (float) $n );
		return class_exists( 'MNS_Format', true ) ? MNS_Format::digits( $s ) : $s;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows id/name rows.
	 */
	public static function row_select( string $name, array $rows, int $selected, string $all_label ): void {
		printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%1$s" id="%1$s">', esc_attr( $name ), esc_html( $all_label ) );
		printf( '<option value="0">%s</option>', esc_html( $all_label ) );
		foreach ( $rows as $row ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $row['id'],
				selected( $selected, (int) $row['id'], false ),
				esc_html( (string) ( $row['name'] ?? '' ) )
			);
		}
		echo '</select>';
	}

	/**
	 * Open wrap shell header.
	 *
	 * @param array<int,array{label:string,url:string}> $actions Optional page-title-action links.
	 */
	public static function header( string $title, array $actions = array() ): void {
		echo '<div class="wrap mns-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		foreach ( $actions as $a ) {
			printf(
				'<a href="%1$s" class="page-title-action">%2$s</a>',
				esc_url( (string) ( $a['url'] ?? '#' ) ),
				esc_html( (string) ( $a['label'] ?? '' ) )
			);
		}
		echo '<hr class="wp-header-end" />';
		self::notices();
	}

	public static function footer(): void {
		echo '</div>';
	}
}
