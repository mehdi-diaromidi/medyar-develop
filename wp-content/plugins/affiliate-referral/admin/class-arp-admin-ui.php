<?php
/**
 * Shared admin rendering helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Admin_Ui {

	/** Notice key => [type, message]. Only whitelisted keys are ever printed. */
	private const NOTICES = array(
		'saved'               => array( 'success', 'تغییرات ذخیره شد.' ),
		'status_updated'      => array( 'success', 'وضعیت معرفی به‌روزرسانی شد.' ),
		'note_added'          => array( 'success', 'یادداشت ثبت شد.' ),
		'bulk_updated'        => array( 'success', 'وضعیت %d معرفی تغییر کرد.' ),
		'wallet_synced'       => array( 'success', 'همگام‌سازی کیف پول انجام شد (%d مورد بررسی/واریز).' ),
		'wallet_migrated'     => array( 'success', '%d پورسانت قدیمی به کیف پول واریز و پرداخت‌شده شد.' ),
		'arp_wallet_unavailable' => array( 'error', 'افزونه کیف پول تومان مدیار فعال نیست.' ),
		'sms_sent'            => array( 'success', 'پیامک آزمایشی ارسال شد.' ),
		'sms_sent_pattern'    => array( 'success', 'پیامک آزمایشی با پترن فراز ارسال شد.' ),
		'sms_pattern_failed'  => array( 'error', 'پترن فراز تنظیم شده ولی ارسال پترن ناموفق بود (متن آزاد جایگزین نشد). کد پترن و متغیرها را در پنل فراز بررسی کنید.' ),
		'sms_failed'          => array( 'error', 'ارسال پیامک ناموفق بود. تنظیمات Faraz SMS و لاگ خطای سرور را بررسی کنید.' ),
		'digest_sent'         => array( 'success', 'یادآوری شبانه صف‌های در انتظار برای ادمین‌ها ارسال شد.' ),
		'digest_empty'        => array( 'success', 'الان موردی در صف بررسی نیست؛ پیامک ارسال نشد.' ),
		'digest_failed'       => array( 'error', 'ارسال یادآوری شبانه ناموفق بود. گیرندگان، موبایل و Faraz را بررسی کنید.' ),
		'bad_phone'           => array( 'error', 'شماره موبایل معتبر نیست.' ),
		'missing_fields'      => array( 'error', 'فیلدهای ضروری را کامل کنید.' ),
		'slug_taken'          => array( 'error', 'این نامک قبلاً استفاده شده است.' ),
		'status_deleted'      => array( 'success', 'وضعیت حذف شد.' ),
		'status_is_default'   => array( 'error', 'وضعیت پیش‌فرض قابل حذف نیست؛ ابتدا وضعیت دیگری را پیش‌فرض کنید.' ),
		'status_in_use'       => array( 'error', '%d معرفی به وضعیت «تماس ناموفق» منتقل نشد؛ وضعیت حذف نشد. دوباره تلاش کنید.' ),
		'status_deleted_moved' => array( 'success', 'وضعیت حذف شد و %d معرفی به وضعیت «تماس ناموفق» منتقل شد.' ),
		'status_is_fallback'  => array( 'error', 'وضعیت «تماس ناموفق» قابل حذف نیست؛ معرفی‌های وضعیت‌های حذف‌شده به آن منتقل می‌شوند.' ),
		'status_no_fallback'  => array( 'error', 'وضعیت «تماس ناموفق» (failed_contact) یافت نشد؛ معرفی‌های این وضعیت جایی برای انتقال ندارند.' ),
		'arp_amount_required' => array( 'error', 'این وضعیت پورسانت ایجاد می‌کند؛ مبلغ پورسانت را وارد کنید.' ),
		'arp_not_found'       => array( 'error', 'معرفی یافت نشد.' ),
		'arp_bad_status'      => array( 'error', 'وضعیت نامعتبر است.' ),
	);

	private const TONE_COLORS = array(
		'waiting'  => '#0891B2',
		'progress' => '#0272B1',
		'accepted' => '#16A34A',
		'done'     => '#0F766E',
		'cancel'   => '#E11D48',
		'failed'   => '#EA580C',
	);

	/**
	 * @param array<string,scalar> $args Query args.
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Notices are "inline" so core's common.js does not move them out of the container.
	 */
	public static function notices(): void {
		echo '<div class="arp-notices" aria-live="polite">';
		$key = isset( $_GET['arp_notice'] ) ? sanitize_key( wp_unslash( $_GET['arp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( self::NOTICES[ $key ] ) ) {
			list( $type, $message ) = self::NOTICES[ $key ];
			if ( false !== strpos( $message, '%d' ) ) {
				$message = sprintf( $message, isset( $_GET['arp_count'] ) ? absint( $_GET['arp_count'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			$extra = '';
			if ( in_array( $key, array( 'sms_sent_pattern', 'sms_pattern_failed', 'sms_failed' ), true ) ) {
				$meta = get_transient( 'arp_last_test_sms_meta_' . get_current_user_id() );
				if ( is_array( $meta ) ) {
					$bits = array();
					if ( ! empty( $meta['which'] ) ) {
						$bits[] = 'نوع: ' . (string) $meta['which'];
					}
					if ( ! empty( $meta['mode'] ) ) {
						$bits[] = 'حالت: ' . (string) $meta['mode'];
					}
					if ( ! empty( $meta['pattern'] ) ) {
						$bits[] = 'پترن: ' . (string) $meta['pattern'];
					}
					if ( ! empty( $meta['detail'] ) ) {
						$bits[] = 'جزئیات: ' . (string) $meta['detail'];
					}
					if ( $bits ) {
						$extra = ' (' . implode( ' | ', $bits ) . ')';
					}
				}
			}
			printf(
				'<div class="notice notice-%1$s inline is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( Arp_Format::fa( $message . $extra ) )
			);
		}
		echo '</div>';
	}

	/**
	 * Redirect back to an admin screen with a whitelisted notice.
	 *
	 * @param array<string,scalar> $args Extra query args.
	 */
	public static function redirect( string $page, string $notice, array $args = array() ): void {
		wp_safe_redirect( self::url( $page, array_merge( $args, array( 'arp_notice' => $notice ) ) ) );
		exit;
	}

	/**
	 * @param array<string,string> $tabs slug => label.
	 */
	public static function tabs( string $page, array $tabs, string $current ): void {
		echo '<nav class="nav-tab-wrapper wp-clearfix arp-tabs" aria-label="منوی فرعی">';
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

	/**
	 * slug => color for every status (fallback palette follows sort order).
	 *
	 * @return array<string,string>
	 */
	public static function status_colors(): array {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( Arp_Statuses_Repo::all() as $i => $st ) {
				$map[ (string) $st['slug'] ] = Arp_Ui::status_color( $st, (int) $i );
			}
		}
		return $map;
	}

	public static function badge( string $label, string $color ): string {
		return sprintf(
			'<span class="arp-badge" style="--arp-c:%1$s">%2$s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	/**
	 * @param array<string,mixed> $row Status row, or referral row with status_* keys.
	 */
	public static function status_badge( array $row ): string {
		$slug   = (string) ( $row['slug'] ?? $row['status_slug'] ?? '' );
		$label  = (string) ( $row['name'] ?? $row['status_name'] ?? '' );
		$colors = self::status_colors();
		return self::badge( '' !== $label ? $label : '—', $colors[ $slug ] ?? '#64748B' );
	}

	public static function commission_badge( string $state ): string {
		return self::badge( Arp_Ui::commission_label( $state ), self::TONE_COLORS[ Arp_Ui::commission_tone( $state ) ] ?? '#64748B' );
	}

	public static function money( $amount ): string {
		return Arp_Format::money( $amount ) . ' تومان';
	}

	/**
	 * Site-local MySQL datetime to Persian-digit Jalali date (optionally with time).
	 */
	public static function date( ?string $datetime, bool $with_time = false ): string {
		if ( ! $datetime || 0 === strpos( $datetime, '0000' ) ) {
			return '—';
		}
		$out = Arp_Format::to_jalali( $datetime );
		if ( $with_time && preg_match( '/\s(\d{2}:\d{2})/', $datetime, $m ) ) {
			$out .= ' ' . $m[1];
		}
		return Arp_Format::fa( $out );
	}

	/**
	 * "۳ ساعت پیش" style age of a site-local datetime.
	 */
	public static function ago( string $datetime ): string {
		$ts = strtotime( get_gmt_from_date( $datetime ) . ' UTC' );
		return $ts ? Arp_Format::fa( sprintf( '%s پیش', human_time_diff( $ts ) ) ) : '';
	}

	public static function user_name( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : ( $user_id ? '#' . $user_id : '—' );
	}

	/**
	 * Jalali (placeholder) date input bound to arp_from / arp_to.
	 */
	public static function date_input( string $name, string $value, string $label, string $placeholder ): void {
		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" class="arp-date-input" inputmode="numeric" autocomplete="off" dir="ltr" />',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * <select> of users (id => name) with an "all" option.
	 *
	 * @param int[] $user_ids Users to list.
	 */
	public static function user_select( string $name, array $user_ids, int $selected, string $all_label ): void {
		if ( ! $user_ids ) {
			return;
		}
		$users = get_users(
			array(
				'include' => $user_ids,
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%1$s" id="%1$s">', esc_attr( $name ), esc_html( $all_label ) );
		printf( '<option value="0">%s</option>', esc_html( $all_label ) );
		foreach ( $users as $u ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $u->ID, selected( $selected, (int) $u->ID, false ), esc_html( $u->display_name ) );
		}
		echo '</select>';
	}

	/**
	 * @param array<int,array<string,mixed>> $rows     id/name rows.
	 */
	public static function row_select( string $name, array $rows, int $selected, string $all_label ): void {
		printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%1$s" id="%1$s">', esc_attr( $name ), esc_html( $all_label ) );
		printf( '<option value="0">%s</option>', esc_html( $all_label ) );
		foreach ( $rows as $row ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $row['id'], selected( $selected, (int) $row['id'], false ), esc_html( (string) $row['name'] ) );
		}
		echo '</select>';
	}
}
