<?php
/**
 * Shared add/edit + list screen for simple name/active lookups (services, job types).
 *
 * @var array<int,array<string,mixed>> $items
 * @var array<int,int>                 $usage       id => usage count
 * @var string                         $tab         Settings tab slug.
 * @var string                         $toggle_type arp_toggle type.
 * @var string                         $action      Submit/nonce name.
 * @var string                         $singular    e.g. «خدمت».
 * @var string                         $usage_label Usage column heading.
 * @var callable|null                  $extra_col   fn(array $row): string (escaped HTML) for an optional extra column.
 * @var string                         $extra_label Extra column heading.
 * @var string                         $help        Form description.
 * @var callable|null                  $extra_fields fn(?array $item): void, prints extra form fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$item    = null;
foreach ( $items as $row ) {
	if ( (int) $row['id'] === $edit_id ) {
		$item = $row;
	}
}
$cancel = Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => $tab ) );
?>
<div id="col-container" class="wp-clearfix">
	<div id="col-left">
		<div class="col-wrap">
			<div class="form-wrap">
				<h2><?php echo esc_html( ( $item ? 'ویرایش ' : 'افزودن ' ) . $singular ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( $action ); ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $item ? (string) $item['id'] : '0' ); ?>" />
					<div class="form-field form-required">
						<label for="arp-item-name">نام</label>
						<input type="text" name="name" id="arp-item-name" required aria-required="true" maxlength="191" value="<?php echo esc_attr( $item['name'] ?? '' ); ?>" />
						<p><?php echo esc_html( $help ); ?></p>
					</div>
					<?php
					if ( isset( $extra_fields ) && is_callable( $extra_fields ) ) {
						$extra_fields( $item );
					}
					?>
					<div class="form-field">
						<label><input type="checkbox" name="is_active" value="1" <?php checked( ! $item || ! empty( $item['is_active'] ) ); ?> /> فعال (قابل انتخاب در پنل همکاران)</label>
					</div>
					<p class="submit">
						<?php submit_button( $item ? 'به‌روزرسانی' : 'افزودن ' . $singular, 'primary', $action, false ); ?>
						<?php if ( $item ) : ?>
							<a class="button" href="<?php echo esc_url( $cancel ); ?>">انصراف</a>
						<?php endif; ?>
					</p>
				</form>
			</div>
		</div>
	</div>

	<div id="col-right">
		<div class="col-wrap">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="column-primary">نام</th>
						<th>وضعیت</th>
						<th><?php echo esc_html( $usage_label ); ?></th>
						<?php if ( $extra_col ) : ?>
							<th><?php echo esc_html( $extra_label ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $items ) : ?>
					<tr><td colspan="4">موردی ثبت نشده است.</td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $row ) : ?>
					<?php
					$id     = (int) $row['id'];
					$active = ! empty( $row['is_active'] );
					$toggle = wp_nonce_url(
						Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => $tab, 'arp_toggle' => $toggle_type, 'id' => $id ) ),
						'arp_toggle_' . $toggle_type . '_' . $id
					);
					?>
					<tr class="<?php echo $active ? '' : 'arp-row-inactive'; ?>">
						<td class="column-primary">
							<strong><a class="row-title" href="<?php echo esc_url( add_query_arg( 'edit', $id, $cancel ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( add_query_arg( 'edit', $id, $cancel ) ); ?>">ویرایش</a> | </span>
								<span class="<?php echo $active ? 'trash' : 'activate'; ?>"><a href="<?php echo esc_url( $toggle ); ?>"><?php echo $active ? 'غیرفعال کردن' : 'فعال کردن'; ?></a></span>
							</div>
						</td>
						<td><?php echo Arp_Admin_Ui::badge( $active ? 'فعال' : 'غیرفعال', $active ? '#16A34A' : '#64748B' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td>
						<td><?php echo esc_html( Arp_Format::fa( number_format_i18n( $usage[ $id ] ?? 0 ) ) ); ?></td>
						<?php if ( $extra_col ) : ?>
							<td><?php echo $extra_col( $row ); // phpcs:ignore WordPress.Security.EscapeOutput -- callback returns escaped HTML. ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
