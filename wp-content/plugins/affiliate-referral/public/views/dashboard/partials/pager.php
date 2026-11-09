<?php
/**
 * Table footer: range info + windowed pagination.
 *
 * @var int      $total    Total rows matching filters.
 * @var int      $per      Rows per page.
 * @var int      $page_num Current page (1-based, clamped).
 * @var int      $pages    Page count.
 * @var int      $offset   Current offset.
 * @var callable $page_url fn(int $n): string.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $total < 1 ) {
	return;
}

$arp_pager_sets = array(
	'full'    => Arp_Ui::pager_items( $page_num, $pages, 2 ),
	'compact' => Arp_Ui::pager_items( $page_num, $pages, 1 ),
);
?>
<div class="arp-refs-pager">
	<p class="arp-refs-pager__info">
		<?php if ( $pages <= 1 ) : ?>
			<?php echo esc_html( Arp_Format::fa( $total ) ); ?> مورد
		<?php else : ?>
			نمایش <?php echo esc_html( Arp_Format::fa( $offset + 1 ) ); ?> تا <?php echo esc_html( Arp_Format::fa( min( $offset + $per, $total ) ) ); ?> از <?php echo esc_html( Arp_Format::fa( $total ) ); ?> مورد
		<?php endif; ?>
	</p>
	<?php if ( $pages > 1 ) : ?>
		<nav class="arp-refs-pager__nav" aria-label="صفحه‌بندی">
			<?php if ( $page_num > 1 ) : ?>
				<a class="arp-refs-pager__btn arp-refs-pager__btn--arrow" href="<?php echo esc_url( $page_url( $page_num - 1 ) ); ?>" aria-label="صفحه قبل"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a>
			<?php else : ?>
				<span class="arp-refs-pager__btn arp-refs-pager__btn--arrow is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></span>
			<?php endif; ?>

			<?php foreach ( $arp_pager_sets as $arp_variant => $arp_items ) : ?>
				<span class="arp-refs-pager__pages arp-refs-pager__pages--<?php echo esc_attr( $arp_variant ); ?>">
					<?php foreach ( $arp_items as $arp_item ) : ?>
						<?php if ( '…' === $arp_item ) : ?>
							<span class="arp-refs-pager__ellipsis" aria-hidden="true">…</span>
						<?php elseif ( (int) $arp_item === $page_num ) : ?>
							<span class="arp-refs-pager__btn is-current" aria-current="page"><?php echo esc_html( Arp_Format::fa( $arp_item ) ); ?></span>
						<?php else : ?>
							<a class="arp-refs-pager__btn" href="<?php echo esc_url( $page_url( (int) $arp_item ) ); ?>"><?php echo esc_html( Arp_Format::fa( $arp_item ) ); ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
				</span>
			<?php endforeach; ?>

			<?php if ( $page_num < $pages ) : ?>
				<a class="arp-refs-pager__btn arp-refs-pager__btn--arrow" href="<?php echo esc_url( $page_url( $page_num + 1 ) ); ?>" aria-label="صفحه بعد"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></a>
			<?php else : ?>
				<span class="arp-refs-pager__btn arp-refs-pager__btn--arrow is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg></span>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
