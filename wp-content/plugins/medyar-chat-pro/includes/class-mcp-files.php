<?php
defined( 'ABSPATH' ) || exit;

/**
 * Upload hardening (type/size/content checks, optional ClamAV) and the
 * permission-checked file stream used for previews, audio seeking and downloads.
 */
final class MCP_Files {

	public const QUERY_VAR = 'mcp_file';

	/** Extension => accepted real (sniffed) MIME types. */
	private const TYPES = array(
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
		'gif'  => array( 'image/gif' ),
		'webp' => array( 'image/webp' ),
		'heic' => array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream' ),
		'heif' => array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream' ),
		'pdf'  => array( 'application/pdf' ),
		'zip'  => array( 'application/zip', 'application/x-zip-compressed' ),
		'rar'  => array( 'application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed' ),
		'gz'   => array( 'application/gzip', 'application/x-gzip' ),
		'mp3'  => array( 'audio/mpeg', 'audio/mp3' ),
		'wav'  => array( 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' ),
		'ogg'  => array( 'audio/ogg', 'application/ogg', 'video/ogg' ),
		'oga'  => array( 'audio/ogg', 'application/ogg' ),
		'webm' => array( 'video/webm', 'audio/webm' ),
		'm4a'  => array( 'audio/mp4', 'audio/x-m4a', 'video/mp4' ),
		'mp4'  => array( 'video/mp4', 'audio/mp4' ),
		'avi'  => array( 'video/x-msvideo', 'video/avi', 'video/msvideo' ),
	);

	private const DANGEROUS_EXT = 'php|php\d|phtml|phar|pht|shtml|asp|aspx|jsp|cgi|pl|py|rb|sh|bash|exe|com|bat|cmd|scr|msi|msp|dll|cpl|jar|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|hta|lnk|reg|apk|app|svg|html?|xhtml';

	private const INLINE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf' );

	public static function url( int $message_id, bool $download = false ): string {
		$args = array( self::QUERY_VAR => $message_id );
		if ( $download ) {
			$args['download'] = 1;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function detect_mime( string $path ): string {
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = (string) finfo_file( $finfo, $path );
				finfo_close( $finfo );
			}
		} elseif ( function_exists( 'mime_content_type' ) ) {
			$mime = (string) mime_content_type( $path );
		}
		if ( in_array( $mime, array( '', 'application/octet-stream' ), true ) && self::is_heif( $path ) ) {
			$mime = 'image/heic';
		}
		return '' !== $mime ? $mime : 'application/octet-stream';
	}

	public static function kind( string $mime ): string {
		if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return 'image';
		}
		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( preg_match( '#zip|rar|gzip#', $mime ) ) {
			return 'archive';
		}
		return 'other';
	}

	private static function is_heif( string $path ): bool {
		$head = (string) file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (bool) preg_match( '/^.{4}ftyp(heic|heix|hevc|hevx|mif1|msf1|heim|heis)/s', $head );
	}

	public static function max_bytes(): int {
		$mb = (int) MCP_Settings::get( 'max_file_mb' );
		if ( class_exists( '\DrPlus\Utils\Options' ) ) {
			$theme = \DrPlus\Utils\Options::get_options( array( 'chat-file-upload-max-size' => '100' ) );
			$mb    = min( $mb, max( 1, (int) $theme['chat-file-upload-max-size'] ) );
		}
		return $mb * MB_IN_BYTES;
	}

	/**
	 * Full validation of a $_FILES entry before the theme stores it.
	 *
	 * @param array<string,mixed> $file
	 * @return true|WP_Error
	 */
	public static function validate_upload( array $file, int $user_id ) {
		$reject = static function ( string $code, string $message, array $log = array() ) use ( $user_id ): WP_Error {
			MCP_Audit::log( 'upload_rejected', null, array_merge( array( 'reason' => $code ), $log ), '', 0, $user_id );
			return new WP_Error( $code, $message, array( 'status' => 400 ) );
		};

		if ( ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$code = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
			if ( in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return $reject( 'mcp_file_too_large', __( 'حجم فایل بیش از حد مجاز است.', 'medyar-chat-pro' ) );
			}
			return $reject( 'mcp_upload_error', __( 'فایل به‌درستی دریافت نشد. لطفاً دوباره تلاش کنید.', 'medyar-chat-pro' ), array( 'php_error' => $code ) );
		}

		$tmp = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			return $reject( 'mcp_upload_error', __( 'فایل به‌درستی دریافت نشد. لطفاً دوباره تلاش کنید.', 'medyar-chat-pro' ) );
		}

		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return $reject( 'mcp_empty_file', __( 'فایل خالی است.', 'medyar-chat-pro' ) );
		}
		if ( $size > self::max_bytes() ) {
			/* translators: %s: max size in MB */
			return $reject( 'mcp_file_too_large', sprintf( __( 'حداکثر حجم مجاز هر فایل %s مگابایت است.', 'medyar-chat-pro' ), number_format_i18n( self::max_bytes() / MB_IN_BYTES ) ), array( 'size' => $size ) );
		}

		$name = (string) $file['name'];
		if ( preg_match( '/\.(' . self::DANGEROUS_EXT . ')(\.|$)/i', $name ) ) {
			return $reject( 'mcp_file_type', __( 'این نوع فایل مجاز نیست.', 'medyar-chat-pro' ), array( 'ext' => 'dangerous' ) );
		}
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return $reject( 'mcp_file_type', __( 'این نوع فایل مجاز نیست.', 'medyar-chat-pro' ), array( 'ext' => $ext ) );
		}

		$mime = self::detect_mime( $tmp );
		if ( ! in_array( $mime, self::TYPES[ $ext ], true ) ) {
			return $reject( 'mcp_file_mismatch', __( 'محتوای فایل با پسوند آن مطابقت ندارد.', 'medyar-chat-pro' ), array( 'ext' => $ext, 'mime' => $mime ) );
		}

		$theme_types = self::theme_allowed_mimes();
		if ( $theme_types && ! array_intersect( array_merge( array( $mime ), self::TYPES[ $ext ] ), $theme_types ) ) {
			return $reject( 'mcp_file_type', __( 'این نوع فایل در تنظیمات چت مجاز نشده است.', 'medyar-chat-pro' ), array( 'mime' => $mime ) );
		}

		$scan = self::scan( $tmp, $ext, $mime );
		if ( is_wp_error( $scan ) ) {
			return $reject( $scan->get_error_code(), $scan->get_error_message(), array( 'mime' => $mime ) );
		}

		$daily = (int) MCP_Settings::get( 'daily_upload_mb' ) * MB_IN_BYTES;
		if ( ! MCP_Rate_Limiter::hit( 'upload_bytes', $user_id, $daily, DAY_IN_SECONDS, $size ) ) {
			return $reject( 'mcp_upload_quota', __( 'سهمیه آپلود روزانه شما به پایان رسیده است.', 'medyar-chat-pro' ) );
		}

		return true;
	}

	/** @return string[] */
	private static function theme_allowed_mimes(): array {
		$class = MCP_Theme::chat_class();
		if ( '' === $class || ! method_exists( $class, 'allowed_file_types' ) ) {
			return array();
		}
		return array_values( array_filter( (array) call_user_func( array( $class, 'allowed_file_types' ), 1 ) ) );
	}

	/**
	 * Content inspection. Blocks executables, script polyglots, active PDFs and dangerous archives.
	 *
	 * @return true|WP_Error
	 */
	public static function scan( string $path, string $ext, string $mime ) {
		$blocked = new WP_Error( 'mcp_file_unsafe', __( 'فایل به دلایل امنیتی پذیرفته نشد.', 'medyar-chat-pro' ) );

		$mode = (string) MCP_Settings::get( 'scan_mode' );
		if ( 'heuristic' !== $mode ) {
			$av = self::clamav( $path );
			if ( false === $av ) {
				return $blocked;
			}
			if ( null === $av && 'clamav' === $mode ) {
				return new WP_Error( 'mcp_scan_unavailable', __( 'سرویس اسکن فایل در دسترس نیست. لطفاً بعداً تلاش کنید.', 'medyar-chat-pro' ) );
			}
		}

		$size = (int) filesize( $path );
		$head = (string) file_get_contents( $path, false, null, 0, (int) min( $size, MB_IN_BYTES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$tail = $size > MB_IN_BYTES ? (string) file_get_contents( $path, false, null, $size - 65536, 65536 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( 0 === strpos( $head, 'MZ' ) || 0 === strpos( $head, "\x7fELF" ) || 0 === strpos( $head, '#!' ) ) {
			return $blocked;
		}
		if ( preg_match( '/<\?php|<\?=|<script[\s>]|<%@|eval\s*\(\s*base64_decode/i', $head . $tail ) ) {
			return $blocked;
		}

		$kind = self::kind( $mime );
		if ( 'image' === $kind && false === @getimagesize( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $blocked;
		}
		if ( 'pdf' === $kind ) {
			if ( 0 !== strpos( ltrim( substr( $head, 0, 1024 ) ), '%PDF-' ) ) {
				return $blocked;
			}
			if ( preg_match( '#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b#', $head . $tail ) ) {
				return new WP_Error( 'mcp_file_unsafe', __( 'فایل PDF شامل محتوای فعال (اسکریپت یا فایل جاسازی‌شده) است و پذیرفته نشد.', 'medyar-chat-pro' ) );
			}
		}
		if ( 'zip' === $ext && class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return $blocked;
			}
			$total = 0;
			$count = $zip->numFiles;
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! $stat ) {
					continue;
				}
				$total += (int) $stat['size'];
				if ( preg_match( '/\.(' . self::DANGEROUS_EXT . ')$/i', (string) $stat['name'] ) || false !== strpos( (string) $stat['name'], '..' ) ) {
					$zip->close();
					return new WP_Error( 'mcp_file_unsafe', __( 'فایل فشرده شامل فایل اجرایی یا اسکریپت است و پذیرفته نشد.', 'medyar-chat-pro' ) );
				}
			}
			$zip->close();
			if ( $count > 2000 || $total > 1024 * MB_IN_BYTES ) {
				return $blocked;
			}
		}

		/**
		 * Plug in an external scanner (cloud AV etc.). Return WP_Error to block.
		 *
		 * @param true   $result
		 * @param string $path
		 * @param string $mime
		 */
		$filtered = apply_filters( 'mcp_scan_file', true, $path, $mime );
		return is_wp_error( $filtered ) ? $filtered : true;
	}

	/**
	 * @return bool|null true = clean, false = infected, null = scanner unavailable/error.
	 */
	private static function clamav( string $path ): ?bool {
		if ( ! function_exists( 'exec' ) || in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
			return null;
		}
		foreach ( array( 'clamdscan --no-summary --fdpass', 'clamscan --no-summary' ) as $cmd ) {
			$bin = strtok( $cmd, ' ' );
			$out = array();
			$rc  = 127;
			@exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $out, $rc ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( 0 !== $rc ) {
				continue;
			}
			$out = array();
			@exec( $cmd . ' ' . escapeshellarg( $path ) . ' 2>&1', $out, $rc ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( 0 === $rc ) {
				return true;
			}
			if ( 1 === $rc ) {
				return false;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Secure streaming
	 * ------------------------------------------------------------------- */

	public static function maybe_serve(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$message_id = absint( $_GET[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$download   = ! empty( $_GET['download'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		if ( ! is_user_logged_in() ) {
			self::fail( 401 );
		}
		$message = MCP_Theme::get_message( $message_id );
		if ( ! $message || 'text' === $message['type'] ) {
			self::fail( 404 );
		}
		$session_id = (int) $message['session_id'];
		$is_member  = MCP_Access::is_participant( $session_id );
		if ( ! $is_member && ! MCP_Access::can_manage() ) {
			MCP_Audit::log( 'file_denied', $session_id, array(), 'message', $message_id );
			self::fail( 403 );
		}
		if ( ! $is_member ) {
			MCP_Audit::log( 'file_accessed', $session_id, array(), 'message', $message_id );
		}

		$path = MCP_Theme::file_path_from_url( (string) $message['file_url'] );
		if ( '' === $path ) {
			self::fail( 404 );
		}

		$info = MCP_Messages::file_info( $message );
		$mime = (string) ( $info['mime'] ?? self::detect_mime( $path ) );
		if ( 'voice' === $message['type'] && 'video/webm' === $mime ) {
			$mime = 'audio/webm';
		}
		$inline = ! $download && ( in_array( $mime, self::INLINE_MIMES, true ) || 0 === strpos( $mime, 'audio/' ) || 0 === strpos( $mime, 'video/' ) );
		$name   = (string) ( $info['name'] ?? wp_basename( $path ) );

		self::stream( $path, $mime, $name, $inline );
	}

	private static function fail( int $code ): void {
		status_header( $code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo esc_html( 401 === $code ? __( 'برای مشاهده فایل وارد حساب کاربری شوید.', 'medyar-chat-pro' ) : ( 403 === $code ? __( 'دسترسی به این فایل مجاز نیست.', 'medyar-chat-pro' ) : __( 'فایل پیدا نشد.', 'medyar-chat-pro' ) ) );
		exit;
	}

	private static function stream( string $path, string $mime, string $name, bool $inline ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$size  = (int) filesize( $path );
		$start = 0;
		$end   = $size - 1;

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $m ) ) {
			if ( '' === $m[1] && '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $end ) : $end;
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		} else {
			status_header( 200 );
		}

		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( sprintf( 'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', $ascii, rawurlencode( $name ) ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		if ( 'application/pdf' !== $mime ) {
			header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox" );
		}

		if ( 'HEAD' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			exit;
		}

		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			exit;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$chunk = fread( $fh, (int) min( 262144, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Validates a client-supplied file_url for a new file/voice message:
	 * must be a file this user uploaded (theme naming chat_{uid}_*) and not already used by another message.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_message_file_url( string $file_url, int $user_id ) {
		global $wpdb;
		$invalid = new WP_Error( 'mcp_invalid_file', __( 'فایل پیوست معتبر نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		$path    = MCP_Theme::file_path_from_url( $file_url );
		if ( '' === $path ) {
			return $invalid;
		}
		if ( 0 !== strpos( wp_basename( $path ), 'chat_' . $user_id . '_' ) ) {
			return $invalid;
		}
		$used = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . MCP_Theme::messages_table() . ' WHERE file_url LIKE %s', '%/' . $wpdb->esc_like( wp_basename( $path ) ) )
		);
		return $used > 0 ? $invalid : true;
	}
}
