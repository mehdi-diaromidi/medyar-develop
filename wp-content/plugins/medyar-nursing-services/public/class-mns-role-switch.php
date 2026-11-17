<?php
/**
 * Develop-only role switcher for super-admins (admin ↔ nurse ↔ patient).
 *
 * Shows a collapsible bar at the bottom-right. After leaving administrator,
 * eligibility is kept via user meta so the user can switch back.
 *
 * @package medyar-nursing-services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Role_Switch {

	public const META_ORIGIN = 'mns_role_switch_origin';
	public const META_MODE   = 'mns_role_switch_mode';
	public const ACTION      = 'mns_role_switch';
	public const NONCE       = 'mns_role_switch';

	/** @var array<string,string> */
	private static $labels = array(
		'admin'   => 'ادمین',
		'nurse'   => 'پرستار',
		'patient' => 'بیمار',
	);

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'maybe_stamp_origin' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 40 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 40 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 99 );
		add_action( 'admin_footer', array( __CLASS__, 'render' ), 99 );
	}

	public static function host_allowed(): bool {
		if ( class_exists( 'MNS_E2E', true ) ) {
			return MNS_E2E::host_allowed();
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && false !== strpos( $host, 'develop.' );
	}

	public static function eligible(): bool {
		if ( ! self::host_allowed() || ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$origin = get_user_meta( get_current_user_id(), self::META_ORIGIN, true );
		return is_array( $origin ) && ! empty( $origin );
	}

	public static function maybe_stamp_origin(): void {
		if ( ! self::host_allowed() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid = get_current_user_id();
		$existing = get_user_meta( $uid, self::META_ORIGIN, true );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}
		$user = wp_get_current_user();
		$roles = array_values( array_filter( array_map( 'strval', (array) $user->roles ) ) );
		if ( empty( $roles ) ) {
			$roles = array( 'administrator' );
		}
		update_user_meta( $uid, self::META_ORIGIN, $roles );
		update_user_meta( $uid, self::META_MODE, 'admin' );
	}

	public static function current_mode(): string {
		$stored = sanitize_key( (string) get_user_meta( get_current_user_id(), self::META_MODE, true ) );
		if ( in_array( $stored, array( 'admin', 'nurse', 'patient' ), true ) ) {
			return $stored;
		}
		$user = wp_get_current_user();
		if ( user_can( $user, 'manage_options' ) || in_array( 'administrator', (array) $user->roles, true ) ) {
			return 'admin';
		}
		if ( in_array( MNS_Roles::ROLE_NURSE, (array) $user->roles, true ) ) {
			return 'nurse';
		}
		return 'patient';
	}

	public static function enqueue(): void {
		if ( ! self::eligible() ) {
			return;
		}
		$css = 'assets/css/role-switch.css';
		$js  = 'assets/js/role-switch.js';
		wp_enqueue_style(
			'mns-role-switch',
			MNS_PLUGIN_URL . $css,
			array(),
			self::asset_ver( $css )
		);
		wp_enqueue_script(
			'mns-role-switch',
			MNS_PLUGIN_URL . $js,
			array(),
			self::asset_ver( $js ),
			true
		);
	}

	public static function render(): void {
		if ( ! self::eligible() ) {
			return;
		}
		$mode = self::current_mode();
		$open = false;
		?>
		<div class="mns-rsw" id="mns-rsw" data-mns-rsw data-mode="<?php echo esc_attr( $mode ); ?>"<?php echo $open ? ' data-open="1"' : ''; ?>>
			<button type="button" class="mns-rsw__fab" data-mns-rsw-toggle aria-expanded="false" aria-controls="mns-rsw-panel" title="<?php echo esc_attr__( 'سوئیچ نقش تست', 'medyar-nursing-services' ); ?>">
				<span class="mns-rsw__fab-dot" aria-hidden="true"></span>
				<span class="mns-rsw__fab-label"><?php echo esc_html( self::$labels[ $mode ] ?? $mode ); ?></span>
				<span class="mns-rsw__fab-chev" aria-hidden="true"></span>
			</button>
			<div class="mns-rsw__panel" id="mns-rsw-panel" hidden>
				<div class="mns-rsw__head">
					<strong><?php echo esc_html__( 'سوئیچ نقش (تست)', 'medyar-nursing-services' ); ?></strong>
					<span class="mns-rsw__hint"><?php echo esc_html__( 'فقط develop / مدیرکل', 'medyar-nursing-services' ); ?></span>
				</div>
				<div class="mns-rsw__actions" role="group" aria-label="<?php echo esc_attr__( 'انتخاب نقش', 'medyar-nursing-services' ); ?>">
					<?php foreach ( self::$labels as $slug => $label ) : ?>
						<?php
						$url = wp_nonce_url(
							admin_url( 'admin-post.php?action=' . self::ACTION . '&mode=' . rawurlencode( $slug ) ),
							self::NONCE
						);
						$is_current = ( $slug === $mode );
						?>
						<a class="mns-rsw__btn<?php echo $is_current ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle(): void {
		if ( ! self::host_allowed() ) {
			wp_die( esc_html__( 'سوئیچ نقش فقط روی محیط develop فعال است.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		check_admin_referer( self::NONCE );

		$uid = get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) ) {
			$origin = get_user_meta( $uid, self::META_ORIGIN, true );
			if ( ! is_array( $origin ) || empty( $origin ) ) {
				wp_die( esc_html__( 'اجازهٔ سوئیچ نقش ندارید.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
			}
		} else {
			self::maybe_stamp_origin();
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( (string) $_GET['mode'] ) ) : '';
		if ( ! isset( self::$labels[ $mode ] ) ) {
			wp_die( esc_html__( 'نقش نامعتبر است.', 'medyar-nursing-services' ), '', array( 'response' => 400 ) );
		}

		self::apply_mode( $uid, $mode );
		update_user_meta( $uid, self::META_MODE, $mode );

		// Refresh caps for the rest of this request / cookie session.
		wp_set_current_user( $uid );
		clean_user_cache( $uid );

		wp_safe_redirect( self::redirect_for( $mode ) );
		exit;
	}

	private static function apply_mode( int $uid, string $mode ): void {
		$user = new WP_User( $uid );
		if ( ! $user->exists() ) {
			wp_die( esc_html__( 'کاربر یافت نشد.', 'medyar-nursing-services' ), '', array( 'response' => 404 ) );
		}

		if ( 'admin' === $mode ) {
			$origin = get_user_meta( $uid, self::META_ORIGIN, true );
			$roles  = is_array( $origin ) ? array_values( array_filter( array_map( 'strval', $origin ) ) ) : array();
			if ( empty( $roles ) ) {
				$roles = array( 'administrator' );
			}
			self::replace_roles( $user, $roles );
			return;
		}

		if ( 'nurse' === $mode ) {
			self::replace_roles( $user, array( MNS_Roles::ROLE_NURSE ) );
			self::ensure_nurse_profile( $uid );
			return;
		}

		// patient
		$patient_role = get_role( 'customer' ) ? 'customer' : 'subscriber';
		self::replace_roles( $user, array( $patient_role ) );
		$user->add_cap( MNS_Roles::CAP_PATIENT_PANEL );
		if ( class_exists( 'MNS_Patients', true ) ) {
			MNS_Patients::ensure_self( $uid );
		}
	}

	/**
	 * @param array<int,string> $roles Role slugs.
	 */
	private static function replace_roles( WP_User $user, array $roles ): void {
		$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );
		if ( empty( $roles ) ) {
			$roles = array( 'subscriber' );
		}
		$first = array_shift( $roles );
		$user->set_role( $first );
		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}
	}

	private static function ensure_nurse_profile( int $uid ): void {
		if ( ! class_exists( 'MNS_DB', true ) ) {
			return;
		}
		global $wpdb;

		if ( class_exists( 'MNS_Services_Seed', true ) ) {
			MNS_Services_Seed::ensure_slice1();
		}

		$serum = class_exists( 'MNS_Services', true ) ? MNS_Services::get( 'serum' ) : null;
		$inj   = class_exists( 'MNS_Services', true ) ? MNS_Services::get( 'injection' ) : null;
		$city  = class_exists( 'MNS_Locations', true ) ? MNS_Locations::city_id( 'THR', 'تهران' ) : 0;
		$service_ids = array();
		if ( is_array( $serum ) && ! empty( $serum['id'] ) ) {
			$service_ids[] = (int) $serum['id'];
		}
		if ( is_array( $inj ) && ! empty( $inj['id'] ) ) {
			$service_ids[] = (int) $inj['id'];
		}

		$nurses = MNS_DB::table( 'nurses' );
		$wpdb->replace(
			$nurses,
			array(
				'user_id'     => $uid,
				'status'      => 'approved',
				'gender'      => 'female',
				'national_id' => self::fake_national_id( $uid ),
				'accepting'   => 1,
				'applied_at'  => class_exists( 'MNS_Time', true ) ? MNS_Time::now_utc() : gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( ! empty( $service_ids ) ) {
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			foreach ( $service_ids as $sid ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_services' ),
					array(
						'nurse_id'   => $uid,
						'service_id' => (int) $sid,
					)
				);
			}
		}

		if ( $city > 0 ) {
			$wpdb->delete( MNS_DB::table( 'nurse_regions' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			foreach ( range( 1, 5 ) as $district ) {
				$wpdb->insert(
					MNS_DB::table( 'nurse_regions' ),
					array(
						'nurse_id'      => $uid,
						'province_code' => 'THR',
						'city_id'       => (int) $city,
						'district_no'   => (int) $district,
					)
				);
			}
		}

		$slots = class_exists( 'MNS_Settings', true ) ? MNS_Settings::get( 'slots' ) : null;
		if ( ! is_array( $slots ) && class_exists( 'MNS_Settings', true ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$open = array();
		if ( is_array( $slots ) ) {
			foreach ( $slots as $s ) {
				if ( is_array( $s ) && empty( $s['closed'] ) && ! empty( $s['id'] ) ) {
					$open[] = (string) $s['id'];
				}
			}
		}
		if ( ! empty( $open ) ) {
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			for ( $wd = 0; $wd <= 6; $wd++ ) {
				foreach ( $open as $slot ) {
					$wpdb->replace(
						MNS_DB::table( 'nurse_week' ),
						array(
							'nurse_id' => $uid,
							'weekday'  => $wd,
							'slot_id'  => $slot,
						)
					);
				}
			}
		}
	}

	private static function fake_national_id( int $seed ): string {
		$base = str_pad( (string) abs( $seed % 1000000000 ), 9, '0', STR_PAD_LEFT );
		$sum  = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $base[ $i ] * ( 10 - $i );
		}
		$r = $sum % 11;
		$c = $r < 2 ? $r : 11 - $r;
		return $base . (string) $c;
	}

	private static function redirect_for( string $mode ): string {
		if ( 'admin' === $mode ) {
			return admin_url( 'admin.php?page=mns-monitor' );
		}
		if ( 'nurse' === $mode ) {
			return home_url( '/nursing-nurse-panel/' );
		}
		return home_url( '/nursing-patient-panel/' );
	}

	private static function asset_ver( string $relative ): string {
		$path = MNS_PLUGIN_DIR . $relative;
		return is_readable( $path ) ? MNS_VERSION . '.' . (string) filemtime( $path ) : MNS_VERSION;
	}
}
