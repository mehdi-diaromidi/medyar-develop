<?php
namespace DrPlus\Updates;

class V2_9_1_0 {
	public static $run_at_install = true;

	public static function update() {
		// Change users display name for RTL
		if( file_exists( ABSPATH . "/wp-content/themes/dr-plus-not-encoded.zip" ) ) {
			@unlink( ABSPATH . "/wp-content/themes/dr-plus-not-encoded.zip" );
		}
	}
}