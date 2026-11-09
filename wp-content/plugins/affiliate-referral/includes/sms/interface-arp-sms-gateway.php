<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Arp_Sms_Gateway {
	/**
	 * @param string $phone   Recipient.
	 * @param string $message Body.
	 */
	public function send( string $phone, string $message ): bool;
}
