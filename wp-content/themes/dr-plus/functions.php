<?php

if( !defined( 'ABSPATH' ) ) exit;

// Define constants
if( !defined( 'DRPLUS_DIR' ) ) {
	define( 'DRPLUS_DIR', trailingslashit( get_template_directory() ) );
}

if( !defined( 'DRPLUS_URI' ) ) {
	define( 'DRPLUS_URI', trailingslashit( get_template_directory_uri() ) );
}

if( !defined( 'DRPLUS_VERSION' ) ) {
	define( 'DRPLUS_VERSION', "2.9.1.2" );
}

if( !defined( 'DRPLUS_DEV' ) ) {
	define( 'DRPLUS_DEV', false );
}

if( !defined( 'DRPLUS_IS_LOCAL' ) ) {
	define( 'DRPLUS_IS_LOCAL', !empty( $_SERVER['SERVER_NAME'] ) && $_SERVER['SERVER_NAME'] == 'localhost' );
}

include( DRPLUS_DIR . "functions/init.php" );
include( DRPLUS_DIR . "functions/widgets.php" );
include( DRPLUS_DIR . "functions/elementor.php" );
include( DRPLUS_DIR . "functions/comments.php" );
include( DRPLUS_DIR . "functions/archive.php" );
include( DRPLUS_DIR . "functions/search.php" );
include( DRPLUS_DIR . "functions/hospitals.php" );
include( DRPLUS_DIR . "functions/users.php" );
include( DRPLUS_DIR . "functions/auth.php" );
include( DRPLUS_DIR . "functions/onboard.php" );
include( DRPLUS_DIR . "functions/specialists.php" );
include( DRPLUS_DIR . "functions/booking.php" );
include( DRPLUS_DIR . "functions/singles.php" );
include( DRPLUS_DIR . "functions/cache.php" );
include( DRPLUS_DIR . "functions/chat.php" );
include( DRPLUS_DIR . "functions/wallet.php" );
include( DRPLUS_DIR . "functions/theme.php" );

// رفع خطای Class "Chat" not found در فرآیند رزرو چت
if ( ! function_exists( 'drplus_chat_alias_fix' ) ) {
	function drplus_chat_alias_fix() {
		if ( class_exists( 'Chat', false ) ) return;
		foreach ( [ 'DrPlusUtilsChat', 'DrPlus\\Utils\\Chat' ] as $c ) {
			if ( class_exists( $c ) ) { class_alias( $c, 'Chat' ); return; }
		}
	}
}
drplus_chat_alias_fix();
add_action( 'after_setup_theme', 'drplus_chat_alias_fix', 99 );
add_action( 'init', 'drplus_chat_alias_fix', 0 );