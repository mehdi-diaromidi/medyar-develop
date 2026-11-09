<?php
/**
 * @var Arp_Commissions_Table $table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table->prepare_items();
$summary = $table->summary();
$wallet_ready = Arp_Wallet_Bridge::available();
?>
<div class="arp-summary">
	<?php foreach ( array( 'approved', 'paid', 'reversed' ) as $state ) : ?>
		<div class="arp-summary__item">
			<?php echo Arp_Admin_Ui::commission_badge( $state ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
			<strong><?php echo esc_html( Arp_Admin_Ui::money( $summary[ $state ]['total'] ?? 0 ) ); ?></strong>
			<span class="arp-muted"><?php echo esc_html( Arp_Format::fa( number_format_i18n( $summary[ $state ]['count'] ?? 0 ) ) ); ?> مورد</span>
		</div>
	<?php endforeach; ?>
</div>
<?php if ( $wallet_ready ) : ?>
	<p class="description" style="margin-top:0;">
		پرداخت پورسانت فقط با واریز به کیف پول انجام می‌شود (کد <code>ARP-C-{id}</code>).
		<a href="<?php echo esc_url( Arp_Wallet_Bridge::transactions_url( 'ARP-C-' ) ); ?>">تراکنش‌های کیف پول</a>
		·
		<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'wallet' ) ) ); ?>">مرکز کیف پول</a>
		·
		<a href="<?php echo esc_url( Arp_Wallet_Bridge::withdrawals_url() ); ?>">برداشت‌ها</a>
	</p>
<?php endif; ?>

<?php $table->views(); ?>
<form method="get">
	<input type="hidden" name="page" value="arp-finance" />
	<?php $table->display(); ?>
</form>
