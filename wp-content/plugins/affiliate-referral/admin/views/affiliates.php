<?php
/**
 * @var Arp_Affiliates_Table $table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table->prepare_items();
?>
<div class="wrap arp-wrap">
	<h1 class="wp-heading-inline">همکاران</h1>
	<a href="<?php echo esc_url( admin_url( 'users.php?role=' . Arp_Roles::ROLE ) ); ?>" class="page-title-action">مدیریت در کاربران وردپرس</a>
	<hr class="wp-header-end" />
	<?php Arp_Admin_Ui::notices(); ?>

	<?php
	$arp_inviter_filter = isset( $_GET['inviter_user_id'] ) ? absint( $_GET['inviter_user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$arp_inviter_user   = $arp_inviter_filter > 0 ? get_userdata( $arp_inviter_filter ) : false;
	if ( $arp_inviter_user ) :
		?>
		<p class="description">
			<?php echo esc_html( sprintf( 'فقط همکاران دعوت‌شده توسط %s', $arp_inviter_user->display_name ) ); ?>
			· <a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-invited', array( 'inviter_user_id' => $arp_inviter_filter ) ) ); ?>">جزئیات پورسانت دعوت</a>
			· <a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-affiliates' ) ); ?>">حذف فیلتر</a>
		</p>
	<?php endif; ?>

	<form method="get">
		<input type="hidden" name="page" value="arp-affiliates" />
		<?php if ( $arp_inviter_user ) : ?>
			<input type="hidden" name="inviter_user_id" value="<?php echo esc_attr( (string) $arp_inviter_filter ); ?>" />
		<?php endif; ?>
		<?php
		$table->search_box( 'جستجوی همکار', 'arp-affiliate' );
		$table->display();
		?>
	</form>
</div>
