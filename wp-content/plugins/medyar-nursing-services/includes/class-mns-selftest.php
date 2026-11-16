<?php
/**
 * Self-test registry (T-0.6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest {

	/** @var array<string,array{title:string,callback:callable}> */
	private static $registry = array();

	/**
	 * @param string   $ac_id    AC id.
	 * @param string   $title    Title.
	 * @param callable $callback Returns array{ok:bool,detail:string}.
	 */
	public static function register( string $ac_id, string $title, callable $callback ): void {
		self::$registry[ $ac_id ] = array(
			'title'    => $title,
			'callback' => $callback,
		);
	}

	/**
	 * @return array<string,array{title:string,callback:callable}>
	 */
	public static function all(): array {
		return self::$registry;
	}

	/**
	 * Run all (or selected) scenarios.
	 *
	 * @param array<int,string>|null $only Optional AC ids.
	 * @return array{pass:int,fail:int,rows:array<int,array<string,mixed>>}
	 */
	public static function run( ?array $only = null ): array {
		MNS_Selftest_Scenarios::register_all();

		$pass = 0;
		$fail = 0;
		$rows = array();

		foreach ( self::$registry as $id => $meta ) {
			if ( is_array( $only ) && ! in_array( $id, $only, true ) ) {
				continue;
			}
			$ok     = false;
			$detail = '';
			try {
				$result = call_user_func( $meta['callback'] );
				if ( is_array( $result ) ) {
					$ok     = ! empty( $result['ok'] );
					$detail = isset( $result['detail'] ) ? (string) $result['detail'] : '';
				} else {
					$detail = 'bad_return';
				}
			} catch ( \Throwable $e ) {
				$ok     = false;
				$detail = get_class( $e ) . ': ' . $e->getMessage();
			}
			if ( $ok ) {
				$pass++;
			} else {
				$fail++;
			}
			$rows[] = array(
				'id'     => $id,
				'title'  => $meta['title'],
				'ok'     => $ok,
				'detail' => $detail,
			);
		}

		return array(
			'pass' => $pass,
			'fail' => $fail,
			'rows' => $rows,
		);
	}
}
