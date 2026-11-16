<?php
/**
 * Safe boot for backend modules (T-0.1 / AC-M0-1).
 *
 * Loads modules inside try/catch (\Throwable) so a broken module
 * cannot white-screen the site. Kill-switch: MNS_BACKEND_DISABLED
 * constant or option mns_backend_disabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend {

	/** @var bool Whether boot() has run. */
	private static $booted = false;

	/** @var array<string,bool> slug => ok. */
	private static $module_ok = array();

	/** @var array<int,array<string,mixed>> Errors recorded this request. */
	private static $errors = array();

	/** @var bool Whether admin hooks were registered. */
	private static $hooks_registered = false;

	/**
	 * Boot backend modules safely. Called at the end of MNS_Plugin::init().
	 */
	public static function boot(): void {
		try {
			self::register_hooks();

			if ( ! self::is_active() ) {
				self::$booted = true;
				return;
			}

			$modules = self::modules();
			foreach ( $modules as $module ) {
				self::boot_module( $module );
			}

			self::$booted = true;
		} catch ( \Throwable $e ) {
			self::record_error( 'boot', $e );
			self::$booted = true;
		}
	}

	/**
	 * Whether the backend is allowed to load modules.
	 */
	public static function is_active(): bool {
		if ( defined( 'MNS_BACKEND_DISABLED' ) && MNS_BACKEND_DISABLED ) {
			return false;
		}

		$disabled = get_option( 'mns_backend_disabled', false );
		if ( $disabled ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a module booted successfully this request.
	 *
	 * @param string $slug Module slug.
	 */
	public static function module_ok( string $slug ): bool {
		return ! empty( self::$module_ok[ $slug ] );
	}

	/**
	 * Recent backend errors (option + this request).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function errors(): array {
		$stored = get_option( 'mns_backend_errors', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		if ( empty( self::$errors ) ) {
			return array_values( $stored );
		}

		$merged = self::$errors;
		$fps    = array();
		foreach ( $merged as $row ) {
			if ( isset( $row['fingerprint'] ) ) {
				$fps[ $row['fingerprint'] ] = true;
			}
		}
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$fp = isset( $row['fingerprint'] ) ? $row['fingerprint'] : '';
			if ( $fp && isset( $fps[ $fp ] ) ) {
				continue;
			}
			$merged[] = $row;
		}

		return array_values( $merged );
	}

	/**
	 * Module descriptors. Later cards append entries here.
	 *
	 * @return array<int,array{slug:string,file:string,class:string}>
	 */
	private static function modules(): array {
		$list = array(
			array(
				'slug'  => 'core',
				'file'  => 'includes/backend/class-mns-backend-core.php',
				'class' => 'MNS_Backend_Core',
			),
			array(
				'slug'  => 'db',
				'file'  => 'includes/backend/class-mns-backend-db.php',
				'class' => 'MNS_Backend_Db',
			),
		);

		if ( self::should_load_selftest_broken() ) {
			$list[] = array(
				'slug'  => 'selftest_broken',
				'file'  => 'includes/backend/_selftest/broken-syntax.php',
				'class' => 'MNS_Backend_Selftest_Broken',
			);
		}

		return $list;
	}

	/**
	 * Intentional broken module only for AC-M0-1 (admin + query flag).
	 */
	private static function should_load_selftest_broken(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only self-test flag.
		$raw = isset( $_GET['mns_selftest_broken'] ) ? wp_unslash( $_GET['mns_selftest_broken'] ) : '';
		$flag = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		return ( '1' === $flag );
	}

	/**
	 * Load one module file then call Class::boot().
	 *
	 * @param array{slug:string,file:string,class:string} $module Descriptor.
	 */
	private static function boot_module( array $module ): void {
		$slug  = isset( $module['slug'] ) ? (string) $module['slug'] : '';
		$file  = isset( $module['file'] ) ? (string) $module['file'] : '';
		$class = isset( $module['class'] ) ? (string) $module['class'] : '';

		if ( '' === $slug || '' === $file || '' === $class ) {
			return;
		}

		$path = MNS_PLUGIN_DIR . $file;

		try {
			require_once $path;
		} catch ( \Throwable $e ) {
			self::$module_ok[ $slug ] = false;
			self::record_error( $slug, $e );
			return;
		}

		try {
			if ( ! class_exists( $class, false ) ) {
				throw new \RuntimeException(
					sprintf( 'Backend module class %s not found after require.', $class )
				);
			}
			if ( ! is_callable( array( $class, 'boot' ) ) ) {
				throw new \RuntimeException(
					sprintf( 'Backend module class %s has no boot() method.', $class )
				);
			}
			call_user_func( array( $class, 'boot' ) );
			self::$module_ok[ $slug ] = true;
		} catch ( \Throwable $e ) {
			self::$module_ok[ $slug ] = false;
			self::record_error( $slug, $e );
		}
	}

	/**
	 * Persist and log a module failure. Deduped (fingerprint + 1h).
	 *
	 * @param string     $slug Module slug.
	 * @param \Throwable $e    Exception / Error / ParseError.
	 */
	private static function record_error( string $slug, \Throwable $e ): void {
		$file = basename( (string) $e->getFile() );
		$line = (int) $e->getLine();
		$msg  = $e->getMessage();
		$type = get_class( $e );
		$fp   = md5( $slug . $msg . $file . (string) $line );
		$now  = time();

		$record = array(
			'module'      => $slug,
			'message'     => $msg,
			'file'        => $file,
			'line'        => $line,
			'type'        => $type,
			'time'        => $now,
			'fingerprint' => $fp,
		);

		self::$errors[] = $record;

		error_log(
			sprintf(
				'[MNS] backend module "%s" failed: %s in %s:%d (%s)',
				$slug,
				$msg,
				$file,
				$line,
				$type
			)
		);

		$stored = get_option( 'mns_backend_errors', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$should_write = true;
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_fp = isset( $row['fingerprint'] ) ? (string) $row['fingerprint'] : '';
			if ( $row_fp !== $fp ) {
				continue;
			}
			$row_time = isset( $row['time'] ) ? (int) $row['time'] : 0;
			if ( ( $now - $row_time ) < HOUR_IN_SECONDS ) {
				$should_write = false;
			}
			break;
		}

		if ( ! $should_write ) {
			return;
		}

		$stored[] = $record;
		if ( count( $stored ) > 20 ) {
			$stored = array_slice( $stored, -20 );
		}

		update_option( 'mns_backend_errors', $stored, false );
	}

	/**
	 * Admin notices + clear-errors handler.
	 */
	private static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_post_mns_backend_clear_errors', array( __CLASS__, 'handle_clear_errors' ) );
	}

	/**
	 * Kill-switch info notice and module-error notice (manage_options only).
	 */
	public static function render_admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_active() ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'بک‌اند MNS غیرفعال است', 'medyar-nursing-services' );
			echo '</p></div>';
		}

		$errors = self::errors();
		if ( empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'خطا در بارگذاری ماژول‌های بک‌اند MNS', 'medyar-nursing-services' );
		echo '</strong></p><ul>';

		foreach ( $errors as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$module  = isset( $row['module'] ) ? (string) $row['module'] : '';
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';
			$file    = isset( $row['file'] ) ? basename( (string) $row['file'] ) : '';
			$line    = isset( $row['line'] ) ? (int) $row['line'] : 0;
			$type    = isset( $row['type'] ) ? (string) $row['type'] : '';
			$time    = isset( $row['time'] ) ? (int) $row['time'] : 0;
			$when    = $time ? wp_date( 'Y-m-d H:i:s', $time ) : '';

			// Shorten very long messages for the notice.
			if ( function_exists( 'mb_substr' ) ) {
				$short = mb_substr( $message, 0, 200 );
			} else {
				$short = substr( $message, 0, 200 );
			}
			if ( $short !== $message ) {
				$short .= '…';
			}

			$line_txt = sprintf(
				/* translators: 1: module slug, 2: exception type, 3: short message, 4: file:line, 5: time */
				__( 'ماژول %1$s — %2$s — %3$s — %4$s — %5$s', 'medyar-nursing-services' ),
				$module,
				$type,
				$short,
				$file . ':' . $line,
				$when
			);

			echo '<li>' . esc_html( $line_txt ) . '</li>';
		}

		echo '</ul><p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		wp_nonce_field( 'mns_backend_clear_errors' );
		echo '<input type="hidden" name="action" value="mns_backend_clear_errors" />';
		submit_button(
			__( 'پاک‌کردن', 'medyar-nursing-services' ),
			'secondary',
			'submit',
			false
		);
		echo '</form></p></div>';
	}

	/**
	 * Clear stored backend errors (nonce + manage_options).
	 */
	public static function handle_clear_errors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'mns_backend_clear_errors' );

		delete_option( 'mns_backend_errors' );
		self::$errors = array();

		$ref = wp_get_referer();
		// Drop the self-test flag from the return URL, otherwise the broken module loads again and the error reappears.
		wp_safe_redirect( remove_query_arg( 'mns_selftest_broken', $ref ? $ref : admin_url() ) );
		exit;
	}
}
