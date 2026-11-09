<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin options (single autoloaded array).
 */
final class MCP_Settings {

	public const OPTION = 'mcp_settings';

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'emergency_notice'          => '',
			'reactions'                 => array( '👍', '❤️', '🙏', '✅', '😊', '😢' ),
			'share_medical_summary'     => 1,
			'max_file_mb'               => 20,
			'daily_upload_mb'           => 200,
			'messages_per_hour'         => 120,
			'scan_mode'                 => 'auto',
			'default_quick_replies'     => array(
				__( 'لطفاً آزمایش خود را ارسال کنید.', 'medyar-chat-pro' ),
				__( 'برای بررسی دقیق‌تر به تصویر واضح‌تری نیاز دارم.', 'medyar-chat-pro' ),
				__( 'لطفاً علائم خود را با جزئیات بیشتری توضیح دهید.', 'medyar-chat-pro' ),
				__( 'مدارک شما بررسی شد.', 'medyar-chat-pro' ),
			),
			'audit_retention_days'      => 0,
		);
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/** @return mixed */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * @param array<string,mixed> $input Raw form input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$d   = self::defaults();
		$out = array();

		$out['emergency_notice']      = sanitize_textarea_field( (string) ( $input['emergency_notice'] ?? $d['emergency_notice'] ) );
		$out['share_medical_summary'] = empty( $input['share_medical_summary'] ) ? 0 : 1;
		$out['max_file_mb']           = max( 1, min( 512, absint( $input['max_file_mb'] ?? $d['max_file_mb'] ) ) );
		$out['daily_upload_mb']       = max( 1, min( 10240, absint( $input['daily_upload_mb'] ?? $d['daily_upload_mb'] ) ) );
		$out['messages_per_hour']     = max( 10, min( 2000, absint( $input['messages_per_hour'] ?? $d['messages_per_hour'] ) ) );
		$out['audit_retention_days']  = min( 3650, absint( $input['audit_retention_days'] ?? 0 ) );

		$scan              = sanitize_key( (string) ( $input['scan_mode'] ?? 'auto' ) );
		$out['scan_mode']  = in_array( $scan, array( 'auto', 'clamav', 'heuristic' ), true ) ? $scan : 'auto';

		$reactions = is_array( $input['reactions'] ?? null ) ? $input['reactions'] : preg_split( '/\s+/u', (string) ( $input['reactions'] ?? '' ) );
		$reactions = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_reaction' ), (array) $reactions ) ) ) );
		$out['reactions'] = $reactions ? array_slice( $reactions, 0, 8 ) : $d['reactions'];

		$lines = is_array( $input['default_quick_replies'] ?? null )
			? $input['default_quick_replies']
			: preg_split( '/\r\n|\r|\n/', (string) ( $input['default_quick_replies'] ?? '' ) );
		$lines = array_values( array_filter( array_map( static fn( $l ) => trim( sanitize_text_field( (string) $l ) ), (array) $lines ) ) );
		$out['default_quick_replies'] = array_slice( $lines, 0, 50 );

		return $out;
	}

	/** Emoji reactions were removed from the UI on request; re-enable only via this filter. */
	public static function reactions_enabled(): bool {
		return (bool) apply_filters( 'mcp_enable_reactions', false );
	}

	public static function sanitize_reaction( $value ): string {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( '' === $value || mb_strlen( $value ) > 8 ) {
			return '';
		}
		return $value;
	}

	/** @param array<string,mixed> $values */
	public static function save( array $values ): void {
		update_option( self::OPTION, self::sanitize( $values ), true );
		self::$cache = null;
	}
}
