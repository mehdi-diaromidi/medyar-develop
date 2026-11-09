<?php
/**
 * @var string             $db_version
 * @var string             $mysql
 * @var array<string,int>  $counts label => rows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="arp-settings-grid">
	<div class="arp-settings-main">
		<h2 class="title">خروجی اکسل (CSV)</h2>
		<p>فایل با کدگذاری UTF-8 ساخته می‌شود و مستقیم در اکسل باز می‌شود. در صفحه‌های «معرفی‌ها»، «همکاران» و «پورسانت‌ها» هم دکمه خروجی با همان فیلترهای فعلی وجود دارد.</p>
		<form method="get">
			<input type="hidden" name="page" value="arp-settings" />
			<input type="hidden" name="tab" value="tools" />
			<?php wp_nonce_field( 'arp_export', '_wpnonce', false ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="arp-export-type">نوع گزارش</label></th>
					<td>
						<select name="arp_export" id="arp-export-type">
							<option value="referrals">معرفی‌ها</option>
							<option value="commissions">پورسانت‌ها</option>
							<option value="affiliates">عملکرد همکاران</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">بازه تاریخ</th>
					<td>
						<?php Arp_Admin_Ui::date_input( 'arp_from', '', 'از تاریخ', 'از ۱۴۰۵/۰۱/۰۱' ); ?>
						<?php Arp_Admin_Ui::date_input( 'arp_to', '', 'تا تاریخ', 'تا ۱۴۰۵/۱۲/۲۹' ); ?>
						<p class="description">تاریخ شمسی؛ خالی یعنی همه. برای گزارش همکاران اعمال نمی‌شود.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'دانلود فایل', 'primary', '', true ); ?>
		</form>
	</div>

	<div class="arp-settings-side">
		<div class="postbox">
			<div class="postbox-header"><h2>اطلاعات سیستم</h2></div>
			<div class="inside">
				<table class="widefat striped arp-compact">
					<tbody>
						<tr><th>نسخه افزونه</th><td dir="ltr"><?php echo esc_html( ARP_VERSION ); ?></td></tr>
						<tr><th>نسخه پایگاه‌داده</th><td dir="ltr"><?php echo esc_html( $db_version ); ?><?php echo ARP_DB_VERSION === $db_version ? '' : ' ⚠'; ?></td></tr>
						<tr><th>PHP / MySQL</th><td dir="ltr"><?php echo esc_html( PHP_VERSION . ' / ' . $mysql ); ?></td></tr>
						<tr><th>وردپرس</th><td dir="ltr"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
						<?php foreach ( $counts as $label => $n ) : ?>
							<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( Arp_Format::fa( number_format_i18n( $n ) ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
