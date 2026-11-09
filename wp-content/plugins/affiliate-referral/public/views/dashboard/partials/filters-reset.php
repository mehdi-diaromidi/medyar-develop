<?php
/**
 * "Clear filters" link for a panel list; JS keeps it in sync after AJAX filtering.
 *
 * @var array<string,mixed> $list From Arp_Panel_Lists (needs `has_filters`).
 * @var string              $arp_list_view referrals|commissions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="arp-refs-filters__reset" data-arp-filters-reset <?php echo empty( $list['has_filters'] ) ? 'hidden' : ''; ?>>
	<a href="<?php echo esc_url( Arp_Pages::page_url( $arp_list_view ) ); ?>">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
		حذف همه فیلترها
	</a>
</div>
