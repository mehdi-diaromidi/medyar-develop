<?php
defined( 'ABSPATH' ) || exit;

final class MSQ_Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'plugins_loaded', array( 'MSQ_Install', 'maybe_upgrade' ), 1 );
		MSQ_Worker::init();
		MSQ_Integration_DrPlus::init();
		if ( is_admin() && class_exists( 'MSQ_Admin' ) ) {
			MSQ_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'msq process',
				static function () {
					$n = MSQ_Worker::drain( 'cli' );
					\WP_CLI::success( sprintf( 'Processed %d SMS job(s).', $n ) );
				}
			);
		}
	}
}
