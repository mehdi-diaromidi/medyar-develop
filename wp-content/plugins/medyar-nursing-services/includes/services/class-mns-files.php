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
		if ( (int) $row['owner_user_id'] === $user_id ) {
			return true;
		}
		// T-C.3 / R-PRV-1: assigned nurse may read prescription only while assigned/in_progress.
		$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
		if ( 'prescription' === $kind && ! empty( $row['order_id'] ) ) {
			return self::nurse_may_read_prescription( (int) $row['order_id'], $user_id );
		}
		return false;
	}

	/**
	 * Assigned nurse + order still active (T-C.3).
	 */
	private static function nurse_may_read_prescription( int $order_id, int $nurse_user_id ): bool {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		if ( (int) ( $order['nurse_user_id'] ?? 0 ) !== $nurse_user_id ) {
			return false;
		}
		$status = (string) ( $order['status'] ?? '' );
		return in_array( $status, array( 'assigned', 'in_progress' ), true );
	}

	/**
	 * Link a previously uploaded file to an order (after create).
	 */
	public static function attach_order( int $file_id, int $order_id, int $owner_user_id ): void {
		if ( $file_id <= 0 || $order_id <= 0 ) {
			return;
		}
		$row = self::get( $file_id );
		if ( ! $row || (int) $row['owner_user_id'] !== $owner_user_id ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			MNS_DB::table( 'files' ),
			array( 'order_id' => $order_id ),
			array( 'id' => $file_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Insert a private file from raw bytes (self-test / internal only).
	 *
	 * @return int File id.
	 */
	public static function store_bytes( string $bytes, string $ext, int $owner_id, string $kind, ?int $order_id = null ): int {
		$ext = strtolower( sanitize_key( $ext ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) || '' === $bytes ) {
			throw MNS_Error::validation( 'فایل معتبر نیست.', 'file' );
		}
		$base = self::ensure_dir();
		$ym   = gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$dir  = $base . '/' . $ym;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$rand = bin2hex( random_bytes( 16 ) );
		$rel  = $ym . '/' . $rand . '.' . $ext;
		$dest = $base . '/' . $rel;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $dest, $bytes ) ) {
			throw MNS_Error::server( 'ذخیرهٔ فایل ممکن نشد.' );
		}
		$sha = hash_file( 'sha256', $dest );
		global $wpdb;
		$row = array(
			'owner_user_id' => $owner_id,
			'kind'          => $kind,
			'rel_path'      => $rel,
			'mime'          => 'image/' . ( 'jpg' === $ext ? 'jpeg' : $ext ),
			'size'          => (int) strlen( $bytes ),
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

	public const CRON_ORPHANS = 'mns_files_purge_orphans';

	public static function schedule_orphan_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_ORPHANS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_ORPHANS );
		}
		add_action( self::CRON_ORPHANS, array( __CLASS__, 'purge_orphan_prescriptions' ) );
	}

	public static function unschedule_orphan_cron(): void {
		$ts = wp_next_scheduled( self::CRON_ORPHANS );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_ORPHANS );
		}
	}

	/**
	 * Delete prescription files older than 24h with no order_id (T-C.2).
	 */
	public static function purge_orphan_prescriptions(): void {
		global $wpdb;
		$table = MNS_DB::table( 'files' );
		$cut   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, rel_path FROM {$table} WHERE kind = %s AND (order_id IS NULL OR order_id = 0) AND created_at < %s AND deleted_at IS NULL LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'prescription',
				$cut
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			$path = self::abs_path( $row );
			if ( $path ) {
				wp_delete_file( $path );
			}
			$wpdb->update(
				$table,
				array( 'deleted_at' => MNS_Time::now_utc() ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
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
