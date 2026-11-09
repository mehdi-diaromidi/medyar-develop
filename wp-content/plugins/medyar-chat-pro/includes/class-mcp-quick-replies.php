<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-specialist canned replies (user meta), seeded from the admin defaults on first use.
 */
final class MCP_Quick_Replies {

	private const META      = 'mcp_quick_replies';
	private const MAX_ITEMS = 100;
	private const MAX_LEN   = 1000;

	/** @return array<int,array{id:string,text:string}> */
	public static function all( int $user_id ): array {
		$items = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $items ) ) {
			$items = array();
			foreach ( (array) MCP_Settings::get( 'default_quick_replies' ) as $text ) {
				$items[] = array(
					'id'   => self::new_id(),
					'text' => (string) $text,
				);
			}
			update_user_meta( $user_id, self::META, $items );
		}
		return array_values(
			array_filter(
				$items,
				static fn( $i ) => is_array( $i ) && ! empty( $i['id'] ) && isset( $i['text'] )
			)
		);
	}

	/** @return array{id:string,text:string}|WP_Error */
	public static function add( int $user_id, string $text ) {
		$text = self::clean( $text );
		if ( '' === $text ) {
			return self::empty_error();
		}
		$items = self::all( $user_id );
		if ( count( $items ) >= self::MAX_ITEMS ) {
			return new WP_Error( 'mcp_limit', __( 'تعداد پاسخ‌های آماده به حداکثر رسیده است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$item    = array(
			'id'   => self::new_id(),
			'text' => $text,
		);
		$items[] = $item;
		update_user_meta( $user_id, self::META, $items );
		return $item;
	}

	/** @return array{id:string,text:string}|WP_Error */
	public static function update( int $user_id, string $id, string $text ) {
		$text = self::clean( $text );
		if ( '' === $text ) {
			return self::empty_error();
		}
		$items = self::all( $user_id );
		foreach ( $items as $k => $item ) {
			if ( $item['id'] === $id ) {
				$items[ $k ]['text'] = $text;
				update_user_meta( $user_id, self::META, $items );
				return $items[ $k ];
			}
		}
		return new WP_Error( 'mcp_not_found', __( 'پاسخ آماده پیدا نشد.', 'medyar-chat-pro' ), array( 'status' => 404 ) );
	}

	public static function delete( int $user_id, string $id ): bool {
		$items = self::all( $user_id );
		$left  = array_values( array_filter( $items, static fn( $i ) => $i['id'] !== $id ) );
		if ( count( $left ) === count( $items ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META, $left );
		return true;
	}

	/** @param string[] $ids New order. */
	public static function reorder( int $user_id, array $ids ): void {
		$items = self::all( $user_id );
		$byid  = array();
		foreach ( $items as $item ) {
			$byid[ $item['id'] ] = $item;
		}
		$sorted = array();
		foreach ( $ids as $id ) {
			if ( isset( $byid[ $id ] ) ) {
				$sorted[] = $byid[ $id ];
				unset( $byid[ $id ] );
			}
		}
		update_user_meta( $user_id, self::META, array_merge( $sorted, array_values( $byid ) ) );
	}

	private static function clean( string $text ): string {
		return mb_substr( trim( sanitize_textarea_field( $text ) ), 0, self::MAX_LEN );
	}

	private static function new_id(): string {
		return substr( md5( wp_generate_uuid4() ), 0, 12 );
	}

	private static function empty_error(): WP_Error {
		return new WP_Error( 'mcp_empty', __( 'متن پاسخ آماده خالی است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
	}
}
