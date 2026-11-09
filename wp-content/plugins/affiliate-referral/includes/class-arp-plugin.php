<?php
/**
 * Main plugin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		Arp_Activator::maybe_upgrade();
		Arp_Roles::register();

		if ( is_admin() ) {
			Arp_Admin::instance()->init();
		}

		Arp_Public::instance()->init();
		Arp_Ajax::init();
		Arp_Page_Templates::instance()->init();
		Arp_Affiliate_Access::instance()->init();
		Arp_Avatars::init();
		Arp_Faraz_Sms_Gateway::init();
		Arp_Admin_Pending_Digest::init();
		Arp_Affiliate_Applications::init();
		Arp_Inviter::init();
		Arp_MyAccount_Invited::init();
		Arp_Wallet_Bridge::init();
	}
}
