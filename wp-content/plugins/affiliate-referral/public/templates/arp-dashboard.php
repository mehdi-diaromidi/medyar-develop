<?php
/**
 * Template Name: پنل طرح همکاران (مدیار)
 * Template Post Type: page
 *
 * @package AffiliateReferral
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="arp-primary" class="arp-page-template arp-page-template--dashboard">
	<div class="arp-page-template__inner">
		<?php Arp_Pages::render_dashboard(); ?>
	</div>
</main>
<?php
get_footer();
