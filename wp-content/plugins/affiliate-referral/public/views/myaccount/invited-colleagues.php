<?php
/**
 * Woo My Account: same view as the partner panel "invited" tab.
 *
 * @var WP_User $user
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Arp_Pages::set_invited_in_account( true );
include ARP_PLUGIN_DIR . 'public/views/dashboard/page-invited.php';
Arp_Pages::set_invited_in_account( false );
