<?php
/**
 * Site-wide default avatars by gender when the user has no custom photo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Avatars {

	public static function init(): void {
		// After theme drplus_change_avatar / drplus_change_avatar_url.
		add_filter( 'get_avatar', array( __CLASS__, 'filter_avatar' ), 20, 6 );
		add_filter( 'get_avatar_url', array( __CLASS__, 'filter_avatar_url' ), 20, 3 );
	}

	/**
	 * Default avatar URL for a user (male/female placeholder).
	 */
	public static function default_url( int $user_id = 0 ): string {
		$file = ( 'female' === self::gender( $user_id ) ) ? 'woman-avatar.webp' : 'man-avatar.webp';
		return ARP_PLUGIN_URL . 'assets/img/' . $file;
	}

	/**
	 * Whether the user has an uploaded custom avatar (theme meta `avatar`).
	 */
	public static function has_custom( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( class_exists( '\DrPlus\Utils\User' ) ) {
			return (bool) \DrPlus\Utils\User::get_avatar_id( $user_id );
		}
		return (bool) absint( get_user_meta( $user_id, 'avatar', true ) );
	}

	/**
	 * @return 'male'|'female'
	 */
	public static function gender( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'male';
		}
		if ( class_exists( '\DrPlus\Utils\User' ) ) {
			$g = (string) \DrPlus\Utils\User::get_gender( $user_id );
			return ( 'female' === $g ) ? 'female' : 'male';
		}
		$g = (string) get_user_meta( $user_id, 'gender', true );
		return ( 'female' === $g ) ? 'female' : 'male';
	}

	/**
	 * Resolve WP user ID from get_avatar id_or_email argument.
	 */
	public static function resolve_user_id( $id_or_email ): int {
		if ( is_a( $id_or_email, 'WP_User' ) ) {
			return (int) $id_or_email->ID;
		}
		if ( is_a( $id_or_email, 'WP_Comment' ) ) {
			return ! empty( $id_or_email->user_id ) ? (int) $id_or_email->user_id : 0;
		}
		if ( is_a( $id_or_email, 'WP_Post' ) ) {
			return (int) $id_or_email->post_author;
		}
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}

	/**
	 * @param string             $avatar      HTML.
	 * @param mixed              $id_or_email ID/email/comment.
	 * @param int                $size        Size.
	 * @param string             $default     Default.
	 * @param string             $alt         Alt text.
	 * @param array<string,mixed> $args       Args.
	 */
	public static function filter_avatar( $avatar, $id_or_email, $size, $default, $alt, $args ) {
		$user_id = self::resolve_user_id( $id_or_email );
		if ( self::has_custom( $user_id ) ) {
			return $avatar;
		}

		$url  = self::default_url( $user_id );
		$size = absint( $size );
		if ( $size < 1 ) {
			$size = 96;
		}
		$alt  = is_string( $alt ) ? $alt : '';
		$class = 'avatar avatar-' . $size . ' photo arp-avatar-default';
		if ( ! empty( $args['class'] ) ) {
			$class .= ' ' . ( is_array( $args['class'] ) ? implode( ' ', $args['class'] ) : (string) $args['class'] );
		}

		return sprintf(
			'<img src="%s" alt="%s" width="%d" height="%d" class="%s" loading="lazy" decoding="async" />',
			esc_url( $url ),
			esc_attr( $alt ),
			$size,
			$size,
			esc_attr( $class )
		);
	}

	/**
	 * @param string             $url         URL.
	 * @param mixed              $id_or_email ID/email/comment.
	 * @param array<string,mixed> $args       Args.
	 */
	public static function filter_avatar_url( $url, $id_or_email, $args ) {
		$user_id = self::resolve_user_id( $id_or_email );
		if ( self::has_custom( $user_id ) ) {
			return $url;
		}
		return self::default_url( $user_id );
	}
}
