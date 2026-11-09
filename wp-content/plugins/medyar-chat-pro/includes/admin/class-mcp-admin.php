<?php
defined( 'ABSPATH' ) || exit;

/**
 * Supervision panel: conversation list with filters/sorting, per-specialist workload and
 * response times, session detail, referrals, activity log and settings.
 */
final class MCP_Admin {

	public const PAGE   = 'mcp-chats';
	public const ACTION = 'mcp_admin_action';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'load-toplevel_page_appointments', array( __CLASS__, 'log_transcript_view' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'گفت‌وگوهای پزشکی', 'medyar-chat-pro' ),
			__( 'گفت‌وگوهای پزشکی', 'medyar-chat-pro' ),
			MCP_Install::CAP_MANAGE,
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			57
		);
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'mcp-admin', MCP_PLUGIN_URL . 'assets/css/mcp-admin.css', array(), MCP_Frontend::ver( 'assets/css/mcp-admin.css' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_url( string $do, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => self::ACTION, 'do' => $do ), $args ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $do );
	}

	public static function log_transcript_view(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$session_id = isset( $_GET['show_chat'] ) ? absint( $_GET['show_chat'] ) : 0;
		if ( $session_id && ! MCP_Access::is_participant( $session_id ) ) {
			MCP_Audit::log( 'transcript_viewed', $session_id );
		}
	}

	public static function handle(): void {
		if ( ! MCP_Access::can_manage() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-chat-pro' ), '', array( 'response' => 403 ) );
		}
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['do'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $do );
		$id     = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		$notice = 'done';

		switch ( $do ) {
			case 'status':
				$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
				$result = MCP_Sessions::set_status( $id, $status, get_current_user_id() );
				$notice = is_wp_error( $result ) ? 'error' : 'status';
				wp_safe_redirect( self::url( array( 'view' => 'session', 'id' => $id, 'mcp_notice' => $notice ) ) );
				exit;
			case 'follow_up':
				$date   = isset( $_POST['follow_up_date'] ) ? MCP_Jalali::to_gregorian( sanitize_text_field( wp_unslash( (string) $_POST['follow_up_date'] ) ) ) : '';
				$time   = isset( $_POST['follow_up_time'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['follow_up_time'] ) ) : '';
				$time   = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', MCP_Jalali::en( $time ) ) ? MCP_Jalali::en( $time ) : '09:00';
				$at     = '' !== $date ? $date . ' ' . $time . ':00' : '';
				$note   = isset( $_POST['follow_up_note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['follow_up_note'] ) ) : '';
				$result = MCP_Sessions::set_follow_up( $id, '' === $at || ! empty( $_POST['clear'] ) ? null : $at, $note );
				wp_safe_redirect( self::url( array( 'view' => 'session', 'id' => $id, 'mcp_notice' => is_wp_error( $result ) ? 'error' : 'saved' ) ) );
				exit;
			case 'recompute':
				MCP_Sessions::recompute( $id );
				MCP_Audit::log( 'metrics_recomputed', $id );
				wp_safe_redirect( self::url( array( 'view' => 'session', 'id' => $id, 'mcp_notice' => 'recomputed' ) ) );
				exit;
			case 'recompute_all':
				$n = MCP_Sessions::backfill_metrics_only();
				MCP_Audit::log( 'metrics_recomputed', null, array( 'sessions' => $n ) );
				$notice = 'recomputed';
				break;
			case 'export':
				MCP_Export::session( $id );
				exit;
			case 'settings':
				$input = isset( $_POST['mcp'] ) && is_array( $_POST['mcp'] ) ? wp_unslash( $_POST['mcp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in MCP_Settings::sanitize().
				MCP_Settings::save( $input );
				MCP_Audit::log( 'settings_updated' );
				wp_safe_redirect( self::url( array( 'tab' => 'settings', 'mcp_notice' => 'saved' ) ) );
				exit;
		}
		wp_safe_redirect( self::url( array( 'mcp_notice' => $notice ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! MCP_Access::can_manage() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screens.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'sessions';
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : '';
		$notice = isset( $_GET['mcp_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['mcp_notice'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap mcp-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'گفت‌وگوهای پزشکی', 'medyar-chat-pro' ) . '</h1>';
		self::notice( $notice );

		if ( ! MCP_Theme::is_available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'جداول چت قالب Dr Plus پیدا نشد.', 'medyar-chat-pro' ) . '</p></div></div>';
			return;
		}

		$tabs = array(
			'sessions'  => __( 'مکالمات و گزارش', 'medyar-chat-pro' ),
			'referrals' => __( 'ارجاع‌ها', 'medyar-chat-pro' ),
			'audit'     => __( 'تاریخچه فعالیت‌ها', 'medyar-chat-pro' ),
			'settings'  => __( 'تنظیمات', 'medyar-chat-pro' ),
		);
		echo '<nav class="nav-tab-wrapper mcp-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$key === $tab && 'session' !== $view ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'session' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_session( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		} elseif ( 'audit' === $tab ) {
			self::render_audit();
		} elseif ( 'referrals' === $tab ) {
			self::render_referrals();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_sessions();
		}
		echo '</div>';
	}

	private static function notice( string $notice ): void {
		$map = array(
			'status'     => array( 'success', __( 'وضعیت مکالمه به‌روزرسانی شد.', 'medyar-chat-pro' ) ),
			'saved'      => array( 'success', __( 'تغییرات ذخیره شد.', 'medyar-chat-pro' ) ),
			'recomputed' => array( 'success', __( 'آمار بازمحاسبه شد.', 'medyar-chat-pro' ) ),
			'error'      => array( 'error', __( 'عملیات انجام نشد.', 'medyar-chat-pro' ) ),
		);
		if ( isset( $map[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $notice ][0] ), esc_html( $map[ $notice ][1] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Formatting helpers
	 * ------------------------------------------------------------------- */

	public static function duration( $secs ): string {
		if ( null === $secs || '' === $secs ) {
			return '—';
		}
		$secs = (int) round( (float) $secs );
		if ( $secs < 60 ) {
			/* translators: %s: seconds */
			return sprintf( __( '%s ثانیه', 'medyar-chat-pro' ), number_format_i18n( $secs ) );
		}
		$days  = intdiv( $secs, DAY_IN_SECONDS );
		$hours = intdiv( $secs % DAY_IN_SECONDS, HOUR_IN_SECONDS );
		$mins  = intdiv( $secs % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		$parts = array();
		if ( $days ) {
			/* translators: %s: days */
			$parts[] = sprintf( __( '%s روز', 'medyar-chat-pro' ), number_format_i18n( $days ) );
		}
		if ( $hours ) {
			/* translators: %s: hours */
			$parts[] = sprintf( __( '%s ساعت', 'medyar-chat-pro' ), number_format_i18n( $hours ) );
		}
		if ( $mins && ! $days ) {
			/* translators: %s: minutes */
			$parts[] = sprintf( __( '%s دقیقه', 'medyar-chat-pro' ), number_format_i18n( $mins ) );
		}
		return implode( ' و ', $parts );
	}

	public static function date( ?string $local ): string {
		if ( empty( $local ) || 0 === strpos( $local, '0000' ) ) {
			return '—';
		}
		return date_i18n( 'Y/m/d H:i', strtotime( $local ) );
	}

	public static function status_badge( string $status ): string {
		$labels = MCP_Sessions::labels();
		return sprintf( '<span class="mcp-badge mcp-badge--%s">%s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
	}

	public static function user_link( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '—';
		}
		return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $user_id ) ), esc_html( MCP_Theme::display_name( $user_id ) ) );
	}

	/** @return array<int,string> user_id => name of everyone who acted as a chat specialist. */
	private static function specialists(): array {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT DISTINCT user_1_id FROM ' . MCP_Theme::sessions_table() );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[ (int) $id ] = MCP_Theme::display_name( (int) $id );
		}
		asort( $out );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------- */

	private static function render_sessions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$dates   = self::date_range();
		$filters = array(
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '',
			'specialist' => isset( $_GET['specialist'] ) ? absint( $_GET['specialist'] ) : 0,
			'from'       => MCP_Jalali::to_gregorian( $dates['from'] ),
			'to'         => MCP_Jalali::to_gregorian( $dates['to'] ),
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '',
			'follow_up'  => ! empty( $_GET['follow_up'] ) ? 1 : 0,
		);
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'last_activity';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ? 'ASC' : 'DESC';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$overview = MCP_Sessions::overview( $filters );
		$per_spec = MCP_Sessions::per_specialist( $filters );
		$result   = MCP_Sessions::search( $filters, $orderby, $order, $paged, 30 );
		$pages    = max( 1, (int) ceil( $result['total'] / 30 ) );
		$labels   = MCP_Sessions::labels();
		$base     = array_filter( array_merge( array( 'tab' => 'sessions' ), $filters, $dates ) );

		include MCP_PLUGIN_DIR . 'includes/admin/views/sessions.php';
	}

	private static function render_session( int $session_id ): void {
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			echo '<p>' . esc_html__( 'مکالمه پیدا نشد.', 'medyar-chat-pro' ) . '</p>';
			return;
		}
		$meta      = MCP_Sessions::ensure_meta( $session_id );
		$state     = MCP_Sessions::state( $session_id );
		$notes     = MCP_Notes::for_session( $session_id, get_current_user_id(), MCP_Access::can_view_medical() );
		$referrals = MCP_Referrals::for_session( $session_id );
		$audit     = MCP_Audit::search( array( 'session_id' => $session_id ), 1, 100 );
		$booking   = MCP_Theme::booking_data( (int) $session['context_id'] );
		$counts    = self::message_counts( $session_id, $session );

		include MCP_PLUGIN_DIR . 'includes/admin/views/session.php';
	}

	/** @param array<string,mixed> $session */
	private static function message_counts( int $session_id, array $session ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT sender_id, type, COUNT(*) AS c FROM ' . MCP_Theme::messages_table() . ' WHERE session_id = %d GROUP BY sender_id, type', $session_id ),
			ARRAY_A
		);
		$out = array(
			'patient'    => 0,
			'specialist' => 0,
			'files'      => 0,
		);
		foreach ( (array) $rows as $r ) {
			$who = (int) $r['sender_id'] === (int) $session['user_1_id'] ? 'specialist' : 'patient';
			$out[ $who ] += (int) $r['c'];
			if ( 'text' !== $r['type'] ) {
				$out['files'] += (int) $r['c'];
			}
		}
		return $out;
	}

	private static function render_audit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$dates   = self::date_range();
		$filters = array(
			'session_id' => isset( $_GET['session_id'] ) ? absint( $_GET['session_id'] ) : 0,
			'actor_id'   => isset( $_GET['actor_id'] ) ? absint( $_GET['actor_id'] ) : 0,
			'action'     => isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['log_action'] ) ) : '',
			'from'       => MCP_Jalali::to_gregorian( $dates['from'] ),
			'to'         => MCP_Jalali::to_gregorian( $dates['to'] ),
		);
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$result = MCP_Audit::search( $filters, $paged, 50 );
		$pages  = max( 1, (int) ceil( $result['total'] / 50 ) );
		include MCP_PLUGIN_DIR . 'includes/admin/views/audit.php';
	}

	private static function render_referrals(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = MCP_Referrals::search( $paged, 30 );
		$pages  = max( 1, (int) ceil( $result['total'] / 30 ) );
		include MCP_PLUGIN_DIR . 'includes/admin/views/referrals.php';
	}

	private static function render_settings(): void {
		$s = MCP_Settings::all();
		include MCP_PLUGIN_DIR . 'includes/admin/views/settings.php';
	}

	/** @return array{from:string,to:string} Normalized ASCII Jalali values of ?from= / ?to= ('' when invalid). */
	private static function date_range(): array {
		$out = array();
		foreach ( array( 'from', 'to' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			$raw         = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
			$out[ $key ] = MCP_Jalali::normalize( $raw );
		}
		return $out;
	}

	/** Jalali text date input, same markup/look as affiliate-referral's admin filters. */
	public static function date_input( string $name, string $value, string $label, string $placeholder, string $id = '' ): void {
		$id = '' !== $id ? $id : 'mcp_' . $name;
		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s" class="mcp-date-input" inputmode="numeric" autocomplete="off" dir="ltr" />',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( '' !== $value ? MCP_Jalali::fa( $value ) : '' ),
			esc_attr( $placeholder )
		);
	}

	/** @param array<string,mixed> $base */
	public static function pagination( int $paged, int $pages, array $base ): void {
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', self::url( $base ) ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);
		echo '</div></div>';
	}

	/** @param array<string,mixed> $base */
	public static function sort_link( string $label, string $key, string $orderby, string $order, array $base ): string {
		$next  = $orderby === $key && 'DESC' === $order ? 'asc' : 'desc';
		$arrow = $orderby === $key ? ( 'DESC' === $order ? ' ↓' : ' ↑' ) : '';
		return sprintf( '<a href="%s">%s%s</a>', esc_url( self::url( array_merge( $base, array( 'orderby' => $key, 'order' => $next ) ) ) ), esc_html( $label ), esc_html( $arrow ) );
	}
}
