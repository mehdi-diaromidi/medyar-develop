<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end wiring. On the single chat page the theme's script handle "drplus-chat" is kept
 * (so the template's wp_localize_script('drplus-chat', 'drplusChat') still applies) but its
 * source is pointed at the chat-pro client. Deactivating the plugin restores the theme client.
 */
final class MCP_Frontend {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1000 );
		add_filter( 'drplus/chats/list_sessions', array( __CLASS__, 'list_sessions' ), 10, 3 );
	}

	/**
	 * Chat list (theme template patch): newest conversation first, time shown = conversation start (open_at),
	 * not the booking/reservation time.
	 *
	 * @param array<int,array<string,mixed>> $chats
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_sessions( $chats, $user_id = 0, $view_type = '' ): array {
		global $wpdb;
		$chats = is_array( $chats ) ? array_values( $chats ) : array();
		$ids   = array_filter( array_map( static fn( $c ) => (int) ( $c['id'] ?? 0 ), $chats ) );
		if ( ! $ids ) {
			return $chats;
		}
		$rows = $wpdb->get_results(
			'SELECT id, open_at, created_at FROM ' . MCP_Theme::sessions_table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$start = array();
		foreach ( (array) $rows as $row ) {
			$open = (string) $row['open_at'];
			$start[ (int) $row['id'] ] = ( '' !== $open && '0000-00-00 00:00:00' !== $open ) ? $open : (string) $row['created_at'];
		}
		foreach ( $chats as $i => $chat ) {
			$id = (int) ( $chat['id'] ?? 0 );
			if ( ! empty( $start[ $id ] ) ) {
				$chats[ $i ]['list_time'] = $start[ $id ];
			}
		}
		usort(
			$chats,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) ( $b['list_time'] ?? $b['created_at'] ?? '' ), (string) ( $a['list_time'] ?? $a['created_at'] ?? '' ) );
				return 0 !== $cmp ? $cmp : ( (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
			}
		);
		return $chats;
	}

	private static function page(): string {
		global $wp;
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return '';
		}
		if ( isset( $wp->query_vars['chats'] ) ) {
			return '' !== (string) $wp->query_vars['chats'] && absint( $wp->query_vars['chats'] ) > 0 ? 'single' : 'list';
		}
		if ( isset( $wp->query_vars['specialist-dashboard'] ) ) {
			$parts = explode( '/', trim( (string) $wp->query_vars['specialist-dashboard'], '/' ) );
			if ( 'specialist-chats' === ( $parts[0] ?? '' ) ) {
				return ! empty( $parts[1] ) && absint( $parts[1] ) > 0 ? 'single' : 'list';
			}
		}
		return '';
	}

	public static function enqueue(): void {
		$page = self::page();
		if ( '' === $page ) {
			return;
		}
		wp_enqueue_style( 'mcp-chat', MCP_PLUGIN_URL . 'assets/css/mcp-chat.css', array(), self::ver( 'assets/css/mcp-chat.css' ) );

		if ( 'list' === $page ) {
			wp_enqueue_script( 'mcp-chat-list', MCP_PLUGIN_URL . 'assets/js/mcp-chat-list.js', array(), self::ver( 'assets/js/mcp-chat-list.js' ), true );
			wp_localize_script( 'mcp-chat-list', 'mcpChatList', self::config( false ) );
			return;
		}

		$scripts = wp_scripts();
		if ( ! isset( $scripts->registered['drplus-chat'] ) || ! wp_script_is( 'drplus-chat', 'enqueued' ) ) {
			return;
		}
		$deps = array( 'jquery', 'wp-util', 'drplus-chat-api' );

		// Jalali picker of Persian WooCommerce (same as affiliate-referral filters). The theme's babakhani
		// picker registers the same jQuery plugin name / persianDate global, so it is unloaded on this page.
		if ( function_exists( 'PW' ) && defined( 'PW_VERSION' ) ) {
			wp_enqueue_script( 'pw-datepicker-js', PW()->plugin_url( 'assets/js/persian-datepicker.min.js' ), array( 'jquery' ), PW_VERSION, true );
			wp_dequeue_script( 'drplus-pdp' );
			wp_dequeue_script( 'drplus-pd' );
			wp_dequeue_style( 'drplus-pdp' );
			$deps[] = 'pw-datepicker-js';
		}

		$handle       = $scripts->registered['drplus-chat'];
		$handle->src  = MCP_PLUGIN_URL . 'assets/js/mcp-chat.js';
		$handle->ver  = self::ver( 'assets/js/mcp-chat.js' );
		$handle->deps = array_values( array_unique( array_merge( (array) $handle->deps, $deps ) ) );

		wp_localize_script( 'drplus-chat', 'mcpChat', self::config( true ) );
	}

	/** Asset version busts the CDN edge cache on every deploy. */
	public static function ver( string $relative ): string {
		$mtime = @filemtime( MCP_PLUGIN_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return MCP_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/** @return array<string,mixed> */
	private static function config( bool $full ): array {
		$config = array(
			'restBase' => esc_url_raw( rest_url( MCP_REST::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'statuses' => MCP_Sessions::labels(),
			'i18n'     => array(
				'followUpDue' => __( 'پیگیری سررسید', 'medyar-chat-pro' ),
				'followUp'    => __( 'پیگیری', 'medyar-chat-pro' ),
				'waiting'     => __( 'انتظار', 'medyar-chat-pro' ),
				'days'        => __( 'روز', 'medyar-chat-pro' ),
				'hours'       => __( 'ساعت', 'medyar-chat-pro' ),
				'minutes'     => __( 'دقیقه', 'medyar-chat-pro' ),
			),
		);
		if ( ! $full ) {
			return $config;
		}

		$config['emergency']  = (string) MCP_Settings::get( 'emergency_notice' );
		$config['reactions']  = MCP_Settings::reactions_enabled() ? array_values( (array) MCP_Settings::get( 'reactions' ) ) : array();
		$config['maxBytes']   = MCP_Files::max_bytes();
		$config['allowedExt'] = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'pdf', 'zip', 'rar', 'gz', 'mp3', 'wav', 'ogg', 'oga', 'webm', 'm4a', 'mp4', 'avi' );
		$config['i18n']       = array_merge(
			$config['i18n'],
			array(
				'reply'             => __( 'پاسخ', 'medyar-chat-pro' ),
				'react'             => __( 'واکنش', 'medyar-chat-pro' ),
				'copy'              => __( 'کپی متن', 'medyar-chat-pro' ),
				'copied'            => __( 'متن کپی شد', 'medyar-chat-pro' ),
				'replyingTo'        => __( 'در پاسخ به', 'medyar-chat-pro' ),
				'cancelReply'       => __( 'لغو پاسخ', 'medyar-chat-pro' ),
				'you'               => __( 'شما', 'medyar-chat-pro' ),
				'otherSide'         => __( 'طرف مقابل', 'medyar-chat-pro' ),
				'voice'             => __( 'پیام صوتی', 'medyar-chat-pro' ),
				'play'              => __( 'پخش', 'medyar-chat-pro' ),
				'pause'             => __( 'توقف', 'medyar-chat-pro' ),
				'speed'             => __( 'سرعت پخش', 'medyar-chat-pro' ),
				'seek'              => __( 'جابه‌جایی در صوت', 'medyar-chat-pro' ),
				'back5'             => __( '۵ ثانیه عقب', 'medyar-chat-pro' ),
				'fwd5'              => __( '۵ ثانیه جلو', 'medyar-chat-pro' ),
				'audioError'        => __( 'پخش این صوت ممکن نیست.', 'medyar-chat-pro' ),
				'download'          => __( 'دانلود', 'medyar-chat-pro' ),
				'preview'           => __( 'پیش‌نمایش', 'medyar-chat-pro' ),
				'close'             => __( 'بستن', 'medyar-chat-pro' ),
				'zoomIn'            => __( 'بزرگ‌نمایی', 'medyar-chat-pro' ),
				'zoomOut'           => __( 'کوچک‌نمایی', 'medyar-chat-pro' ),
				'fileMissing'       => __( 'فایل در دسترس نیست', 'medyar-chat-pro' ),
				'sendFileTitle'     => __( 'ارسال فایل', 'medyar-chat-pro' ),
				'send'              => __( 'ارسال', 'medyar-chat-pro' ),
				'cancel'            => __( 'انصراف', 'medyar-chat-pro' ),
				'retry'             => __( 'تلاش مجدد', 'medyar-chat-pro' ),
				'remove'            => __( 'حذف', 'medyar-chat-pro' ),
				'uploading'         => __( 'در حال آپلود…', 'medyar-chat-pro' ),
				'preparing'         => __( 'در حال آماده‌سازی…', 'medyar-chat-pro' ),
				'typing'            => __( 'در حال نوشتن…', 'medyar-chat-pro' ),
				'uploadCancelled'   => __( 'آپلود لغو شد', 'medyar-chat-pro' ),
				'uploadFailed'      => __( 'آپلود ناموفق بود', 'medyar-chat-pro' ),
				'fileTooLarge'      => __( 'حجم فایل بیش از حد مجاز است', 'medyar-chat-pro' ),
				'fileTypeInvalid'   => __( 'این نوع فایل مجاز نیست', 'medyar-chat-pro' ),
				'sending'           => __( 'در حال ارسال…', 'medyar-chat-pro' ),
				'pending'           => __( 'در انتظار اتصال…', 'medyar-chat-pro' ),
				'sendFailed'        => __( 'ارسال نشد', 'medyar-chat-pro' ),
				'offline'           => __( 'اتصال اینترنت برقرار نیست. پیام‌ها پس از برقراری اتصال ارسال می‌شوند.', 'medyar-chat-pro' ),
				'rateLimited'       => __( 'تعداد پیام‌ها زیاد است؛ چند لحظه بعد دوباره تلاش کنید.', 'medyar-chat-pro' ),
				'chatClosed'        => __( 'این مکالمه بسته شده است.', 'medyar-chat-pro' ),
				'networkError'      => __( 'خطا در ارتباط با سرور', 'medyar-chat-pro' ),
				'emergencyTitle'    => __( 'توجه', 'medyar-chat-pro' ),
				'hide'              => __( 'پنهان کردن', 'medyar-chat-pro' ),
				'status'            => __( 'وضعیت مکالمه', 'medyar-chat-pro' ),
				'changeStatus'      => __( 'تغییر وضعیت', 'medyar-chat-pro' ),
				'patientProfile'    => __( 'پرونده بیمار', 'medyar-chat-pro' ),
				'privateNotes'      => __( 'یادداشت‌های خصوصی', 'medyar-chat-pro' ),
				'refer'             => __( 'ارجاع به متخصص دیگر', 'medyar-chat-pro' ),
				'followUpSet'       => __( 'علامت‌گذاری برای پیگیری', 'medyar-chat-pro' ),
				'quickReplies'      => __( 'پاسخ‌های آماده', 'medyar-chat-pro' ),
				'manageQuick'       => __( 'مدیریت پاسخ‌های آماده', 'medyar-chat-pro' ),
				'addQuick'          => __( 'افزودن پاسخ آماده', 'medyar-chat-pro' ),
				'quickPlaceholder'  => __( 'متن پاسخ آماده…', 'medyar-chat-pro' ),
				'edit'              => __( 'ویرایش', 'medyar-chat-pro' ),
				'save'              => __( 'ذخیره', 'medyar-chat-pro' ),
				'delete'            => __( 'حذف', 'medyar-chat-pro' ),
				'confirmDelete'     => __( 'از حذف این مورد مطمئن هستید؟', 'medyar-chat-pro' ),
				'empty'             => __( 'موردی ثبت نشده است.', 'medyar-chat-pro' ),
				'loading'           => __( 'در حال بارگذاری…', 'medyar-chat-pro' ),
				'loadFailed'        => __( 'بارگذاری اطلاعات ناموفق بود.', 'medyar-chat-pro' ),
				'tabProfile'        => __( 'مشخصات', 'medyar-chat-pro' ),
				'tabHistory'        => __( 'سوابق', 'medyar-chat-pro' ),
				'tabDocs'           => __( 'مدارک', 'medyar-chat-pro' ),
				'tabMedical'        => __( 'پرونده پزشکی', 'medyar-chat-pro' ),
				'tabNotes'          => __( 'یادداشت‌ها', 'medyar-chat-pro' ),
				'age'               => __( 'سن', 'medyar-chat-pro' ),
				'years'             => __( 'سال', 'medyar-chat-pro' ),
				'memberSince'       => __( 'عضویت از', 'medyar-chat-pro' ),
				'phone'             => __( 'تلفن', 'medyar-chat-pro' ),
				'email'             => __( 'ایمیل', 'medyar-chat-pro' ),
				'reason'            => __( 'علت مراجعه', 'medyar-chat-pro' ),
				'consultType'       => __( 'نوع مشاوره', 'medyar-chat-pro' ),
				'relationship'      => __( 'تاریخچه ارتباط', 'medyar-chat-pro' ),
				'sessions'          => __( 'مکالمات', 'medyar-chat-pro' ),
				'closedSessions'    => __( 'بسته‌شده', 'medyar-chat-pro' ),
				'bookings'          => __( 'نوبت‌ها', 'medyar-chat-pro' ),
				'cancelled'         => __( 'لغوشده', 'medyar-chat-pro' ),
				'firstContact'      => __( 'اولین ارتباط', 'medyar-chat-pro' ),
				'thisSession'       => __( 'مکالمه فعلی', 'medyar-chat-pro' ),
				'messages'          => __( 'پیام', 'medyar-chat-pro' ),
				'open'              => __( 'مشاهده', 'medyar-chat-pro' ),
				'fromPatient'       => __( 'ارسالی بیمار', 'medyar-chat-pro' ),
				'fromSpecialist'    => __( 'ارسالی متخصص', 'medyar-chat-pro' ),
				'bloodGroup'        => __( 'گروه خونی', 'medyar-chat-pro' ),
				'allergies'         => __( 'آلرژی‌ها', 'medyar-chat-pro' ),
				'drugs'             => __( 'داروهای مصرفی', 'medyar-chat-pro' ),
				'chronic_diseases'  => __( 'بیماری‌های مزمن', 'medyar-chat-pro' ),
				'surgeries'         => __( 'سوابق جراحی', 'medyar-chat-pro' ),
				'vaccinations'      => __( 'واکسن‌ها', 'medyar-chat-pro' ),
				'records'           => __( 'سوابق ویزیت', 'medyar-chat-pro' ),
				'noMedical'         => __( 'پرونده پزشکی برای این بیمار ثبت نشده یا اشتراک‌گذاری آن غیرفعال است.', 'medyar-chat-pro' ),
				'notesHint'         => __( 'این یادداشت‌ها فقط برای شما و مدیران مجاز قابل مشاهده است و برای بیمار ارسال نمی‌شود.', 'medyar-chat-pro' ),
				'notePlaceholder'   => __( 'یادداشت درباره بیمار یا روند مشاوره…', 'medyar-chat-pro' ),
				'addNote'           => __( 'ثبت یادداشت', 'medyar-chat-pro' ),
				'otherSessionNote'  => __( 'از مکالمه دیگر', 'medyar-chat-pro' ),
				'searchSpecialist'  => __( 'نام یا تخصص متخصص را جستجو کنید…', 'medyar-chat-pro' ),
				'referReason'       => __( 'علت ارجاع (برای بیمار نمایش داده می‌شود)', 'medyar-chat-pro' ),
				'referSubmit'       => __( 'ارجاع و اطلاع به بیمار', 'medyar-chat-pro' ),
				'referDone'         => __( 'ارجاع ثبت شد و به بیمار اطلاع داده شد.', 'medyar-chat-pro' ),
				'referralCard'      => __( 'ارجاع به متخصص', 'medyar-chat-pro' ),
				'viewProfile'       => __( 'مشاهده پروفایل و رزرو', 'medyar-chat-pro' ),
				'noResults'         => __( 'نتیجه‌ای پیدا نشد.', 'medyar-chat-pro' ),
				'followUpDate'      => __( 'تاریخ پیگیری', 'medyar-chat-pro' ),
				'followUpTime'      => __( 'ساعت', 'medyar-chat-pro' ),
				'followUpNote'      => __( 'توضیح کوتاه (اختیاری)', 'medyar-chat-pro' ),
				'followUpClear'     => __( 'حذف پیگیری', 'medyar-chat-pro' ),
				'saved'             => __( 'ذخیره شد', 'medyar-chat-pro' ),
				'closeConfirm'      => __( 'با بستن مکالمه، امکان ارسال پیام از هر دو طرف غیرفعال می‌شود. ادامه می‌دهید؟', 'medyar-chat-pro' ),
				'actionFailed'      => __( 'عملیات انجام نشد.', 'medyar-chat-pro' ),
				'newMessages'       => __( 'پیام جدید', 'medyar-chat-pro' ),
				'timingPatientWarn' => __( 'زمان مشاوره شما رو به اتمام است؛ تا ۵ دقیقه دیگر گفت‌وگوی شما با متخصص به‌طور خودکار بسته می‌شود.', 'medyar-chat-pro' ),
				'timingSpecWarn'    => __( 'متخصص گرامی، مدت زمان مشاوره شما تا ۵ دقیقه دیگر به اتمام می‌رسد؛ در صورت صلاحدید می‌توانید مدت زمان مشاوره را تمدید کنید (تمدید گفت‌وگو به هزینه مشاوره اضافه نخواهد کرد).', 'medyar-chat-pro' ),
				'timingExtendMins'  => __( '%d دقیقه', 'medyar-chat-pro' ),
				'timingDecline'     => __( 'مایل نیستم', 'medyar-chat-pro' ),
				'timingExtended'    => __( 'مشاوره %d دقیقه تمدید شد.', 'medyar-chat-pro' ),
				'timingPatientExt'  => __( 'متخصص مدت زمان مشاوره را %d دقیقه تمدید کرد. تمدید هزینه‌ای برای شما ندارد.', 'medyar-chat-pro' ),
				'timingDismiss'     => __( 'متوجه شدم', 'medyar-chat-pro' ),
			)
		);
		return $config;
	}
}
