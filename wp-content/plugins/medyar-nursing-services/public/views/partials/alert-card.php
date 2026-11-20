<?php
/**
 * Action alert card — screenshot layout (desktop + mobile).
 *
 * Desktop: [icon + chip/title/context] …… [amount + CTA]
 * Mobile:  chip+icon → title/context → full-width CTA
 *
 * @var string $tone
 * @var string $title
 * @var string $text
 * @var string $context
 * @var string $amount_html Escaped amount markup (optional).
 * @var string $cta_label
 * @var string $cta_url
 * @var string $icon
 * @var string $chip
 *
 * @package medyar-nursing-services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tone        = isset( $tone ) ? sanitize_key( (string) $tone ) : 'info';
$title       = isset( $title ) ? (string) $title : '';
$text        = isset( $text ) ? (string) $text : '';
$context     = isset( $context ) ? (string) $context : '';
$amount_html = isset( $amount_html ) ? (string) $amount_html : '';
$cta_label   = isset( $cta_label ) ? (string) $cta_label : '';
$cta_url     = isset( $cta_url ) ? (string) $cta_url : '';
$icon        = isset( $icon ) ? (string) $icon : 'alert';
$chip        = isset( $chip ) ? (string) $chip : '';

$tone_map = array(
	'unpaid'   => 'warning',
	'survey'   => 'info',
	'new_user' => 'success',
	'active'   => 'info',
	'none'     => 'info',
);
$mod = $tone_map[ $tone ] ?? $tone;

if ( '' === $chip && 'unpaid' === $tone ) {
	$chip = __( 'نیازمند اقدام', 'medyar-nursing-services' );
}

// New patient: a welcome hero with the three steps of an order, so the first screen explains itself.
$steps = array();
if ( 'new_user' === $tone ) {
	if ( '' === $chip ) {
		$chip = __( 'خوش آمدید', 'medyar-nursing-services' );
	}
	$steps = array(
		__( 'انتخاب خدمت', 'medyar-nursing-services' ),
		__( 'تاریخ و ساعت', 'medyar-nursing-services' ),
		__( 'پرستار به نشانی شما می‌آید', 'medyar-nursing-services' ),
	);
}
?>
<section class="mns-alert-card mns-alert-card--<?php echo esc_attr( $tone ); ?>" data-mns-alert-card>
	<div class="mns-alert-card__content">
		<?php if ( 'new_user' !== $tone ) : ?>
			<span class="mns-alert-card__icon mns-alert-card__icon--<?php echo esc_attr( $mod ); ?>" aria-hidden="true">
				<?php echo mns_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		<?php endif; ?>
		<div class="mns-alert-card__body">
			<?php if ( '' !== $chip ) : ?>
				<span class="mns-alert-card__chip"><?php echo esc_html( $chip ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $title ) : ?>
				<h2 class="mns-alert-card__title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $context ) : ?>
				<p class="mns-alert-card__context"><?php echo esc_html( $context ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $text ) : ?>
				<p class="mns-alert-card__text"><?php echo esc_html( $text ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $steps ) ) : ?>
				<ol class="mns-alert-card__steps">
					<?php foreach ( $steps as $i => $step_label ) : ?>
						<li><b><?php echo esc_html( MNS_Format::digits( (string) ( $i + 1 ) ) ); ?></b><span><?php echo esc_html( $step_label ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
	</div>
	<?php if ( '' !== $cta_url && '' !== $cta_label ) : ?>
		<div class="mns-alert-card__action">
			<?php if ( '' !== $amount_html ) : ?>
				<p class="mns-alert-card__amount">
					<span class="mns-alert-card__amount-label"><?php echo esc_html__( 'مبلغ قابل پرداخت', 'medyar-nursing-services' ); ?></span>
					<?php echo $amount_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			<?php endif; ?>
			<a class="mns-submit-btn mns-alert-card__cta" href="<?php echo esc_url( $cta_url ); ?>">
				<span class="mns-alert-card__cta-chevron" aria-hidden="true"><?php echo mns_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="mns-alert-card__cta-label"><?php echo esc_html( $cta_label ); ?></span>
			</a>
		</div>
	<?php endif; ?>
</section>
