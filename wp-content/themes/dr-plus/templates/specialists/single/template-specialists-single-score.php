<?php
use DrPlus\Utils;
use DrPlus\Utils\UI;

extract($args);
$avg_score = (float) Utils::get_post_avg( get_the_ID(), 1, $comments_count );
$avg_label = $comments_count > 0 ? number_format_i18n( $avg_score, 1 ) : '';
?>
<div class="<?php echo $prefix ?>sidebar-comments-wrap">
	<?php UI::stars( $avg_score, 5 ); ?>
	<?php if ( $avg_label !== '' ) : ?>
		<span class="specialist_sidebar-avg-score" dir="ltr"><?php echo esc_html( $avg_label ); ?></span>
	<?php endif; ?>
	<?php if ( $avg_label !== '' && ! empty( $args['show_score_text'] ) ) : ?>
		<span class="specialist_sidebar-score-sep" aria-hidden="true"></span>
	<?php endif; ?>
	<?php if( !empty( $args['show_score_text'] ) ) { ?>
		<div class="sidebar-comments-count"><?php echo $comments_count == 0 ? esc_html__( "No comment", 'drplus' ) : sprintf( esc_html__( "%d comments", 'drplus' ), $comments_count ) ?></div>
	<?php } ?>
</div>
