<?php
/**
 * Affiliate panel applications: pending review, optional document upload, admin approve/reject.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Affiliate_Applications {

	public const META_STATUS      = 'arp_affiliate_status';
	public const META_DOCS        = 'arp_affiliate_docs';
	public const META_APPLIED_AT  = 'arp_affiliate_applied_at';
	public const META_REVIEWED_AT = 'arp_affiliate_reviewed_at';
	public const META_REJECT_NOTE = 'arp_affiliate_reject_note';

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	private const DOC_MAX_FILES = 3;
	private const DOC_MAX_BYTES = 5 * MB_IN_BYTES;
	private const DOC_MIMES     = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
	);

	public static function init(): void {
		add_action( 'admin_post_arp_affiliate_approve', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_arp_affiliate_reject', array( __CLASS__, 'handle_reject' ) );
	}

	public static function status( int $user_id ): string {
		$status = (string) get_user_meta( $user_id, self::META_STATUS, true );
		if ( in_array( $status, array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED ), true ) ) {
			return $status;
		}
		// Legacy affiliates (role already granted before review queue).
		if ( Arp_Roles::user_is_affiliate( $user_id ) ) {
			return self::STATUS_APPROVED;
		}
		return '';
	}

	public static function is_pending( int $user_id ): bool {
		return self::STATUS_PENDING === self::status( $user_id );
	}

	public static function is_rejected( int $user_id ): bool {
		return self::STATUS_REJECTED === self::status( $user_id );
	}

	/**
	 * @return int[] Attachment IDs.
	 */
	public static function document_ids( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_DOCS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$aid = absint( $id );
			if ( $aid && 'attachment' === get_post_type( $aid ) ) {
				$ids[] = $aid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function pending_count(): int {
		$q = new WP_User_Query(
			array(
				'number'     => 1,
				'fields'     => 'ID',
				'count_total'=> true,
				'meta_key'   => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => self::STATUS_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $q->get_total();
	}

	/**
	 * Store optional documents from $_FILES['arp_documents'].
	 *
	 * @param array<string,mixed> $files $_FILES.
	 * @return int[]|WP_Error Attachment IDs.
	 */
	public static function store_documents( int $user_id, array $files ) {
		if ( empty( $files['arp_documents'] ) || ! is_array( $files['arp_documents'] ) ) {
			return array();
		}
		$bag = $files['arp_documents'];
		if ( empty( $bag['name'] ) ) {
			return array();
		}

		$names = is_array( $bag['name'] ) ? $bag['name'] : array( $bag['name'] );
		$tmps  = is_array( $bag['tmp_name'] ) ? $bag['tmp_name'] : array( $bag['tmp_name'] );
		$sizes = is_array( $bag['size'] ) ? $bag['size'] : array( $bag['size'] );
		$errs  = is_array( $bag['error'] ) ? $bag['error'] : array( $bag['error'] );

		$entries = array();
		$count   = count( $names );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) ( $names[ $i ] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$error = (int) ( $errs[ $i ] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== $error ) {
				return new WP_Error( 'docs_fail', 'آپلود یکی از مدارک ناموفق بود. دوباره تلاش کنید.', array( 'field' => 'arp_documents' ) );
			}
			$entries[] = array(
				'name'     => $name,
				'tmp_name' => (string) ( $tmps[ $i ] ?? '' ),
				'size'     => (int) ( $sizes[ $i ] ?? 0 ),
				'error'    => $error,
			);
		}

		if ( ! $entries ) {
			return array();
		}
		if ( count( $entries ) > self::DOC_MAX_FILES ) {
			return new WP_Error( 'docs_count', sprintf( 'حداکثر %d فایل می‌توانید آپلود کنید.', self::DOC_MAX_FILES ), array( 'field' => 'arp_documents' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = array();
		foreach ( $entries as $entry ) {
			if ( $entry['size'] > self::DOC_MAX_BYTES ) {
				self::cleanup_attachments( $ids );
				return new WP_Error( 'docs_size', 'حجم هر مدرک نباید بیشتر از ۵ مگابایت باشد.', array( 'field' => 'arp_documents' ) );
			}
			if ( ! is_uploaded_file( $entry['tmp_name'] ) ) {
				self::cleanup_attachments( $ids );
				return new WP_Error( 'docs_fail', 'آپلود یکی از مدارک ناموفق بود. دوباره تلاش کنید.', array( 'field' => 'arp_documents' ) );
			}
			$check = wp_check_filetype_and_ext( $entry['tmp_name'], $entry['name'], self::DOC_MIMES );
			if ( empty( $check['type'] ) || ! in_array( $check['type'], self::DOC_MIMES, true ) ) {
				self::cleanup_attachments( $ids );
				return new WP_Error( 'docs_type', 'فقط فایل‌های JPG، PNG، WEBP یا PDF مجاز است.', array( 'field' => 'arp_documents' ) );
			}

			$_FILES['arp_document_single'] = array(
				'name'     => $entry['name'],
				'type'     => (string) $check['type'],
				'tmp_name' => $entry['tmp_name'],
				'error'    => UPLOAD_ERR_OK,
				'size'     => $entry['size'],
			);

			$attachment_id = media_handle_upload(
				'arp_document_single',
				0,
				array(
					'post_title'  => sprintf( 'مدرک درخواست همکار #%d', $user_id ),
					'post_author' => $user_id,
				),
				array(
					'test_form' => false,
					'mimes'     => self::DOC_MIMES,
				)
			);
			unset( $_FILES['arp_document_single'] );

			if ( is_wp_error( $attachment_id ) ) {
				self::cleanup_attachments( $ids );
				return new WP_Error( 'docs_fail', 'آپلود یکی از مدارک ناموفق بود. دوباره تلاش کنید.', array( 'field' => 'arp_documents' ) );
			}

			// Keep docs private from public media library browsing where possible.
			wp_update_post(
				array(
					'ID'          => (int) $attachment_id,
					'post_status' => 'private',
				)
			);
			update_post_meta( (int) $attachment_id, '_arp_affiliate_doc_user', $user_id );
			$ids[] = (int) $attachment_id;
		}

		return $ids;
	}

	/**
	 * @param int[] $ids Attachment IDs.
	 */
	private static function cleanup_attachments( array $ids ): void {
		foreach ( $ids as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
	}

	public static function approve( int $user_id, int $admin_id = 0 ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || self::STATUS_PENDING !== self::status( $user_id ) ) {
			return false;
		}

		$user->add_role( Arp_Roles::ROLE );
		if ( '' === Arp_Referral_Code::get_for_user( $user_id ) ) {
			update_user_meta( $user_id, 'arp_referral_code', Arp_Referral_Code::generate_unique() );
		}
		if ( ! get_user_meta( $user_id, 'arp_registered_at', true ) ) {
			update_user_meta( $user_id, 'arp_registered_at', current_time( 'mysql' ) );
		}
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_APPROVED );
		update_user_meta( $user_id, self::META_REVIEWED_AT, current_time( 'mysql' ) );
		delete_user_meta( $user_id, self::META_REJECT_NOTE );

		Arp_Faraz_Sms_Gateway::enqueue(
			'applicant_decision',
			array(
				'user_id'  => $user_id,
				'decision' => 'approved',
				'note'     => '',
			)
		);

		/**
		 * Fires after an affiliate application is approved.
		 *
		 * @param int $user_id  Applicant user ID.
		 * @param int $admin_id Reviewing admin user ID.
		 */
		do_action( 'arp_affiliate_application_approved', $user_id, $admin_id );

		return true;
	}

	public static function reject( int $user_id, string $note = '', int $admin_id = 0 ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || self::STATUS_PENDING !== self::status( $user_id ) ) {
			return false;
		}

		$user->remove_role( Arp_Roles::ROLE );
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_REJECTED );
		update_user_meta( $user_id, self::META_REVIEWED_AT, current_time( 'mysql' ) );
		update_user_meta( $user_id, self::META_REJECT_NOTE, sanitize_textarea_field( $note ) );
		// Allow a future re-application to send pending/admin signup SMS once.
		Arp_Faraz_Sms_Gateway::clear_signup_sms_markers( $user_id );

		Arp_Faraz_Sms_Gateway::enqueue(
			'applicant_decision',
			array(
				'user_id'  => $user_id,
				'decision' => 'rejected',
				'note'     => $note,
			)
		);

		/**
		 * Fires after an affiliate application is rejected.
		 *
		 * @param int    $user_id  Applicant user ID.
		 * @param int    $admin_id Reviewing admin user ID.
		 * @param string $note     Optional note.
		 */
		do_action( 'arp_affiliate_application_rejected', $user_id, $admin_id, $note );

		return true;
	}

	public static function handle_approve(): void {
		if ( ! current_user_can( Arp_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'affiliate-referral' ) );
		}
		check_admin_referer( 'arp_affiliate_approve' );
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$ok      = $user_id ? self::approve( $user_id, get_current_user_id() ) : false;
		wp_safe_redirect(
			Arp_Admin_Ui::url(
				'arp-applications',
				array(
					'arp_notice' => $ok ? 'approved' : 'approve_fail',
				)
			)
		);
		exit;
	}

	public static function handle_reject(): void {
		if ( ! current_user_can( Arp_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'affiliate-referral' ) );
		}
		check_admin_referer( 'arp_affiliate_reject' );
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;
		$note    = isset( $_REQUEST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_REQUEST['note'] ) ) : '';
		$ok      = $user_id ? self::reject( $user_id, $note, get_current_user_id() ) : false;
		wp_safe_redirect(
			Arp_Admin_Ui::url(
				'arp-applications',
				array(
					'arp_notice' => $ok ? 'rejected' : 'reject_fail',
				)
			)
		);
		exit;
	}
}
