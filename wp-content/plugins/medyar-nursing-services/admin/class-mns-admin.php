<?php
/**
 * Admin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		MNS_Admin_Menu::instance()->init();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * @param string[] $args Removable query args.
	 * @return string[]
	 */
	public function removable_query_args( array $args ): array {
		return array_merge( $args, array( 'mns_notice', 'mns_count' ) );
	}

	/**
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! MNS_Admin_Menu::instance()->is_mns_screen( $hook_suffix ) ) {
			return;
		}
		MNS_Assets::enqueue_admin();
	}
}
