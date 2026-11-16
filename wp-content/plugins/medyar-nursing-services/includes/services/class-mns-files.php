<?php
/**
 * Private file storage (T-0.7 / R-PRV-4 / 05 §7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Files {

	/**
	 * Ensure private upload root + deny rules.
	 */
	public static function ensure_dir(): string {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'mns-private';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		$ht = $base . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents(
				$ht,
				"Require all denied\nDeny from all\n"
			);
		}
		$idx = $base . '/index.php';
		if ( ! file_exists( $idx ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
		}
		return $base;
	}

	/**
	 * Store an uploaded file privately.
	 *
	 * @param array<string,mixed> $file      $_FILES element.
	 * @param int                 $owner_id  Owner user.
	 * @param string              $kind      Kind enum.
	 * @param int|null            $order_id  Optional order.
	 * @param bool                $is_photo  Photo rules (no pdf).
	 * @return int File id.
	 * @throws MNS_Error On validation failure.
	 */
	public static function store_upload( array $file, int $owner_id, string $kind, ?int $order_id = null, bool $is_photo = false ): int {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw MNS_Error::validation( 'فایل معتبر نیست.', 'file' );
		}

		$max = $is_photo ? (int) MNS_Settings::get( 'fil_photo_max' ) : (int) MNS_Settings::get( 'fil_doc_max' );
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > $max ) {
			throw new MNS_Error( 'ERR_FILE_SIZE', 'حجم فایل بیش از حد مجاز است.', 422, 'file' );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], isset( $file['name'] ) ? (string) $file['name'] : '' );
		$ext   = isset( $check['ext'] ) ? (string) $check['ext'] : '';
		$type  = isset( $check['type'] ) ? (string) $check['type'] : '';
		$allowed_doc   = array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' );
		$allowed_photo = array( 'jpg', 'jpeg', 'png', 'webp' );
		$allowed       = $is_photo ? $allowed_photo : $allowed_doc;
		if ( '' === $ext || ! in_array( strtolower( $ext ), $allowed, true ) ) {
			throw new MNS_Error( 'ERR_FILE_TYPE', 'نوع فایل مجاز نیست.', 422, 'file' );
		}

		$base = self::ensure_dir();
		$ym   = gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$dir  = $base . '/' . $ym;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$rand = bin2hex( random_bytes( 16 ) );
		$rel  = $ym . '/' . $rand . '.' . strtolower( $ext );
		$dest = $base . '/' . $rel;

		$tmp = $file['tmp_name'];
		if ( in_array( strtolower( $ext ), array( 'jpg', 'jpeg' ), true ) ) {
			self::strip_exif_resize( $tmp, $dest );
		} else {
			if ( ! move_uploaded_file( $tmp, $dest ) ) {
				throw MNS_Error::server( 'ذخیرهٔ فایل ممکن نشد.' );
			}
		}

		$sha = hash_file( 'sha256', $dest );
		$final_size = (int) filesize( $dest );

		global $wpdb;
		$row = array(
			'owner_user_id' => $owner_id,
			'kind'          => $kind,
			'rel_path'      => $rel,
			'mime'          => $type,
			'size'          => $final_size,
			'sha256'        => is_string( $sha ) ? $sha : '',
			'created_at'    => MNS_Time::now_utc(),
		);
		$fmt = array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' );
		if ( null !== $order_id ) {
			$row['order_id'] = $order_id;
			$fmt[]           = '%d';
		}
		$ok = $wpdb->insert( MNS_DB::table( 'files' ), $row, $fmt );
		if ( false === $ok ) {
			wp_delete_file( $dest );
			throw MNS_Error::server( 'ثبت فایل در پایگاه داده ممکن نشد.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Absolute path for a file row (or null).
	 *
	 * @param array<string,mixed> $row File row.
	 */
	public static function abs_path( array $row ): ?string {
		if ( empty( $row['rel_path'] ) ) {
			return null;
		}
		$base = self::ensure_dir();
		$path = $base . '/' . ltrim( (string) $row['rel_path'], '/' );
		return is_readable( $path ) ? $path : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = MNS_DB::table( 'files' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether current user may read the file.
	 *
	 * @param array<string,mixed> $row File row.
	 */
	public static function can_read( array $row, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, MNS_Roles::CAP_MANAGE_NURSES ) ) {
			return true;
		}
		return (int) $row['owner_user_id'] === $user_id;
	}

	/**
	 * Strip EXIF/GPS and cap longest side at 2048px for JPEG.
	 */
	private static function strip_exif_resize( string $src, string $dest ): void {
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			if ( ! move_uploaded_file( $src, $dest ) ) {
				throw MNS_Error::server( 'ذخیرهٔ تصویر ممکن نشد.' );
			}
			return;
		}
		// Validate JPEG header before GD to avoid warnings without using @.
		$hdr = file_get_contents( $src, false, null, 0, 3 );
		if ( ! is_string( $hdr ) || "\xFF\xD8\xFF" !== $hdr ) {
			throw new MNS_Error( 'ERR_FILE_TYPE', 'تصویر قابل خواندن نیست.', 422, 'file' );
		}
		$img = imagecreatefromjpeg( $src );
		if ( false === $img ) {
			throw new MNS_Error( 'ERR_FILE_TYPE', 'تصویر قابل خواندن نیست.', 422, 'file' );
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		$max = 2048;
		if ( $w > $max || $h > $max ) {
			$scale = min( $max / max( 1, $w ), $max / max( 1, $h ) );
			$nw = max( 1, (int) floor( $w * $scale ) );
			$nh = max( 1, (int) floor( $h * $scale ) );
			$dst = imagecreatetruecolor( $nw, $nh );
			imagecopyresampled( $dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
			imagedestroy( $img );
			$img = $dst;
		}
		imagejpeg( $img, $dest, 85 );
		imagedestroy( $img );
	}
}
