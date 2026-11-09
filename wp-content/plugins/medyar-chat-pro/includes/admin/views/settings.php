<?php
/**
 * @var array<string,mixed> $s
 */
defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcp-settings">
	<?php wp_nonce_field( MCP_Admin::ACTION . '_settings' ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( MCP_Admin::ACTION ); ?>">
	<input type="hidden" name="do" value="settings">

	<h2><?php esc_html_e( 'تجربه کاربری', 'medyar-chat-pro' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="mcp-emergency"><?php esc_html_e( 'هشدار عدم پشتیبانی از موارد اورژانسی', 'medyar-chat-pro' ); ?></label></th>
			<td><textarea id="mcp-emergency" name="mcp[emergency_notice]" rows="3" class="large-text"><?php echo esc_textarea( (string) $s['emergency_notice'] ); ?></textarea>
			<p class="description"><?php esc_html_e( 'در بالای صفحه گفت‌وگو برای بیمار و متخصص نمایش داده می‌شود. خالی = عدم نمایش (پیش‌فرض).', 'medyar-chat-pro' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="mcp-qr"><?php esc_html_e( 'پاسخ‌های آماده پیش‌فرض متخصصان', 'medyar-chat-pro' ); ?></label></th>
			<td><textarea id="mcp-qr" name="mcp[default_quick_replies]" rows="6" class="large-text"><?php echo esc_textarea( implode( "\n", (array) $s['default_quick_replies'] ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'هر خط یک پاسخ. فقط برای متخصصانی اعمال می‌شود که هنوز فهرست شخصی خود را نساخته‌اند.', 'medyar-chat-pro' ); ?></p></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'حریم خصوصی پرونده بیمار', 'medyar-chat-pro' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'خلاصه پرونده پزشکی', 'medyar-chat-pro' ); ?></th>
			<td><label><input type="checkbox" name="mcp[share_medical_summary]" value="1" <?php checked( (int) $s['share_medical_summary'], 1 ); ?>> <?php esc_html_e( 'نمایش خلاصه پرونده پزشکی (افزونه پرونده پزشکی) به متخصصِ همان مکالمه', 'medyar-chat-pro' ); ?></label></td>
		</tr>
	</table>
	<p class="description"><?php esc_html_e( 'تب «مشخصات» پرونده بیمار برای متخصص فقط تاریخچه ارتباط همان بیمار با خود او را نشان می‌دهد؛ سن، تاریخ عضویت و اطلاعات تماس فقط برای مدیران با مجوز mcp_view_medical نمایش داده می‌شود.', 'medyar-chat-pro' ); ?></p>

	<h2><?php esc_html_e( 'امنیت و محدودیت‌ها', 'medyar-chat-pro' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="mcp-max"><?php esc_html_e( 'حداکثر حجم هر فایل (مگابایت)', 'medyar-chat-pro' ); ?></label></th>
			<td><input type="number" min="1" max="512" id="mcp-max" name="mcp[max_file_mb]" value="<?php echo esc_attr( (string) $s['max_file_mb'] ); ?>" class="small-text">
			<p class="description"><?php esc_html_e( 'مقدار کمترِ این عدد و تنظیم قالب اعمال می‌شود.', 'medyar-chat-pro' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="mcp-daily"><?php esc_html_e( 'سهمیه آپلود روزانه هر کاربر (مگابایت)', 'medyar-chat-pro' ); ?></label></th>
			<td><input type="number" min="1" id="mcp-daily" name="mcp[daily_upload_mb]" value="<?php echo esc_attr( (string) $s['daily_upload_mb'] ); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th scope="row"><label for="mcp-mph"><?php esc_html_e( 'حداکثر پیام در ساعت برای هر کاربر', 'medyar-chat-pro' ); ?></label></th>
			<td><input type="number" min="10" id="mcp-mph" name="mcp[messages_per_hour]" value="<?php echo esc_attr( (string) $s['messages_per_hour'] ); ?>" class="small-text">
			<p class="description"><?php esc_html_e( 'علاوه بر محدودیت دقیقه‌ای قالب (۱۰ پیام و ۵ آپلود در دقیقه).', 'medyar-chat-pro' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="mcp-scan"><?php esc_html_e( 'اسکن فایل‌ها', 'medyar-chat-pro' ); ?></label></th>
			<td>
				<select id="mcp-scan" name="mcp[scan_mode]">
					<option value="auto" <?php selected( $s['scan_mode'], 'auto' ); ?>><?php esc_html_e( 'خودکار: ClamAV در صورت وجود + بررسی محتوایی', 'medyar-chat-pro' ); ?></option>
					<option value="clamav" <?php selected( $s['scan_mode'], 'clamav' ); ?>><?php esc_html_e( 'الزامی ClamAV (در نبودِ آن آپلود رد می‌شود)', 'medyar-chat-pro' ); ?></option>
					<option value="heuristic" <?php selected( $s['scan_mode'], 'heuristic' ); ?>><?php esc_html_e( 'فقط بررسی محتوایی', 'medyar-chat-pro' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mcp-ret"><?php esc_html_e( 'نگهداری تاریخچه فعالیت‌ها (روز)', 'medyar-chat-pro' ); ?></label></th>
			<td><input type="number" min="0" id="mcp-ret" name="mcp[audit_retention_days]" value="<?php echo esc_attr( (string) $s['audit_retention_days'] ); ?>" class="small-text">
			<p class="description"><?php esc_html_e( '۰ یعنی نگهداری دائمی.', 'medyar-chat-pro' ); ?></p></td>
		</tr>
	</table>

	<?php submit_button( __( 'ذخیره تنظیمات', 'medyar-chat-pro' ) ); ?>
</form>

<div class="mcp-card">
	<h2><?php esc_html_e( 'بکاپ و بازیابی', 'medyar-chat-pro' ); ?></h2>
	<p><?php esc_html_e( 'اطلاعات چت در این جداول و پوشه نگهداری می‌شود؛ بکاپ کامل سایت (مثلاً Duplicator) باید همه را شامل شود:', 'medyar-chat-pro' ); ?></p>
	<pre class="mcp-code" dir="ltr"><?php
	global $wpdb;
	echo esc_html(
		implode(
			"\n",
			array(
				$wpdb->prefix . 'drplus_chat_sessions   (theme)',
				$wpdb->prefix . 'drplus_chat_messages   (theme)',
				$wpdb->prefix . 'mcp_session_meta',
				$wpdb->prefix . 'mcp_message_meta',
				$wpdb->prefix . 'mcp_reactions',
				$wpdb->prefix . 'mcp_notes',
				$wpdb->prefix . 'mcp_referrals',
				$wpdb->prefix . 'mcp_audit',
				'wp-content/chat-uploads/   (files, deny from all)',
			)
		)
	);
	?></pre>
	<p class="description"><?php esc_html_e( 'برای هر مکالمه از صفحه جزئیات آن «خروجی کامل (ZIP)» شامل متن، فایل‌ها، یادداشت‌ها و رویدادها قابل دریافت است. یادداشت‌ها حذف نرم می‌شوند و قابل بازیابی از دیتابیس هستند.', 'medyar-chat-pro' ); ?></p>
</div>
