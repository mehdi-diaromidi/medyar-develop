<?php
/**
 * @var array<int,array<string,mixed>> $items
 * @var array<int,int>                 $usage status_id => referrals
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
$base  = Arp_Admin_Ui::url( 'arp-settings', array( 'tab' => 'statuses' ) );
$flags = array(
	'is_default'          => array( 'پیش‌فرض', 'وضعیت اولیه هر معرفی جدید (فقط یک وضعیت).' ),
	'triggers_commission' => array( 'پورسانت‌دار', 'انتقال به این وضعیت پورسانت ایجاد می‌کند و خروج از آن، پورسانت پرداخت‌نشده را برمی‌گرداند.' ),
	'is_terminal'         => array( 'پایانی', 'پرونده بسته‌شده حساب می‌شود.' ),
);
?>
<div id="col-container" class="wp-clearfix">
	<div id="col-left">
		<div class="col-wrap">
			<div class="form-wrap">
				<h2><?php echo $item ? 'ویرایش وضعیت' : 'افزودن وضعیت'; ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'arp_save_status' ); ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $item ? (string) $item['id'] : '0' ); ?>" />
					<div class="form-field form-required">
						<label for="arp-st-name">نام</label>
						<input type="text" name="name" id="arp-st-name" required aria-required="true" value="<?php echo esc_attr( $item['name'] ?? '' ); ?>" />
					</div>
					<div class="form-field form-required">
						<label for="arp-st-slug">نامک</label>
						<input type="text" name="slug" id="arp-st-slug" required aria-required="true" dir="ltr" pattern="[a-z0-9_\-]+" value="<?php echo esc_attr( $item['slug'] ?? '' ); ?>" />
						<p>فقط حروف کوچک انگلیسی، عدد و _ ؛ در کد و گزارش‌ها استفاده می‌شود.</p>
					</div>
					<div class="form-field">
						<label for="arp-st-order">ترتیب نمایش</label>
						<input type="number" name="sort_order" id="arp-st-order" min="0" step="10" class="small-text" value="<?php echo esc_attr( (string) ( $item['sort_order'] ?? '0' ) ); ?>" />
					</div>
					<?php
					$current_color = $item
						? Arp_Ui::status_color( $item, (int) array_search( $item, $items, true ) )
						: Arp_Ui::default_status_color( '', count( $items ) );
					?>
					<fieldset class="form-field arp-swatches">
						<legend>رنگ وضعیت</legend>
						<p class="description">در برچسب‌ها، نمودارها و پنل همکار استفاده می‌شود.</p>
						<div class="arp-swatches__grid">
							<?php foreach ( Arp_Ui::STATUS_PALETTE as $hex => $label ) : ?>
								<label class="arp-swatch" title="<?php echo esc_attr( $label ); ?>" style="--arp-c:<?php echo esc_attr( $hex ); ?>">
									<input type="radio" name="color" value="<?php echo esc_attr( $hex ); ?>" <?php checked( $current_color, $hex ); ?> />
									<span class="arp-swatch__dot" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<fieldset class="form-field arp-flags">
						<legend>رفتار</legend>
						<?php foreach ( $flags as $key => $meta ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $item[ $key ] ) ); ?> />
								<strong><?php echo esc_html( $meta[0] ); ?></strong>
								<span class="description"><?php echo esc_html( $meta[1] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<fieldset class="form-field arp-flags">
						<legend>شمارش در داشبورد همکار</legend>
						<?php foreach ( Arp_Statuses_Repo::dashboard_groups() as $group => $group_label ) : ?>
							<label>
								<input type="radio" name="dashboard_group" value="<?php echo esc_attr( $group ); ?>" <?php checked( (string) ( $item['dashboard_group'] ?? '' ), $group ); ?> />
								<strong><?php echo esc_html( $group_label ); ?></strong>
							</label>
						<?php endforeach; ?>
						<span class="description">معرفی‌های این وضعیت در کدام کارت داشبورد همکار («در حال پیگیری» یا «موفق») شمرده شوند.</span>
					</fieldset>
					<p class="submit">
						<?php submit_button( $item ? 'به‌روزرسانی' : 'افزودن وضعیت', 'primary', 'arp_save_status', false ); ?>
						<?php if ( $item ) : ?>
							<a class="button" href="<?php echo esc_url( $base ); ?>">انصراف</a>
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
						<th class="column-primary">وضعیت</th>
						<th>نامک</th>
						<th>رفتار</th>
						<th>داشبورد همکار</th>
						<th>معرفی‌ها</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $items as $row ) : ?>
					<?php
					$row_id    = (int) $row['id'];
					$edit      = add_query_arg( 'edit', $row_id, $base );
					$row_usage = (int) ( $usage[ $row_id ] ?? 0 );
					$groups    = Arp_Statuses_Repo::dashboard_groups();
					?>
					<tr>
						<td class="column-primary">
							<?php echo Arp_Admin_Ui::status_badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
							<span class="arp-muted">ترتیب <?php echo esc_html( Arp_Format::fa( (int) $row['sort_order'] ) ); ?></span>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit ); ?>">ویرایش</a></span>
								<?php
								if ( empty( $row['is_default'] ) && Arp_Statuses_Repo::FALLBACK_SLUG !== $row['slug'] ) :
									$confirm = $row_usage
										? sprintf( 'وضعیت «%1$s» حذف شود؟ %2$s معرفی این وضعیت به «تماس ناموفق» منتقل می‌شوند.', $row['name'], Arp_Format::fa( number_format_i18n( $row_usage ) ) )
										: sprintf( 'وضعیت «%s» حذف شود؟', $row['name'] );
									?>
									| <span class="trash"><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'arp_delete_status', $row_id, $base ), 'arp_delete_status_' . $row_id ) ); ?>" data-confirm="<?php echo esc_attr( $confirm ); ?>">حذف</a></span>
								<?php endif; ?>
							</div>
						</td>
						<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
						<td>
							<?php
							$labels = array();
							foreach ( $flags as $key => $meta ) {
								if ( ! empty( $row[ $key ] ) ) {
									$labels[] = $meta[0];
								}
							}
							echo esc_html( $labels ? implode( '، ', $labels ) : '—' );
							?>
						</td>
						<td><?php echo '' !== (string) $row['dashboard_group'] ? esc_html( $groups[ $row['dashboard_group'] ] ?? '—' ) : '<span class="arp-muted">—</span>'; ?></td>
						<td>
							<a href="<?php echo esc_url( Arp_Admin_Ui::url( 'arp-referrals', array( 'status_id' => $row_id ) ) ); ?>"><?php echo esc_html( Arp_Format::fa( number_format_i18n( $row_usage ) ) ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
