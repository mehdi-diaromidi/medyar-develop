<?php
/**
 * @var int                            $session_id
 * @var array<string,mixed>            $session
 * @var array<string,mixed>            $meta
 * @var array<string,mixed>            $state
 * @var array<int,array<string,mixed>> $notes
 * @var array<int,array<string,mixed>> $referrals
 * @var array{rows:array,total:int}    $audit
 * @var array<string,mixed>            $booking
 * @var array<string,int>              $counts
 */
defined( 'ABSPATH' ) || exit;

$transcript = MCP_Theme::admin_transcript_url( $session_id );
$avg        = (int) $meta['response_count'] > 0 ? (int) $meta['response_total_secs'] / (int) $meta['response_count'] : null;
$waiting    = ! empty( $meta['awaiting_since'] ) && 'closed' !== $state['status'] ? strtotime( MCP_Theme::now() ) - strtotime( (string) $meta['awaiting_since'] ) : null;
$follow_val  = ! empty( $meta['follow_up_at'] ) ? MCP_Jalali::from_gregorian( (string) $meta['follow_up_at'] ) : '';
$follow_time = ! empty( $meta['follow_up_at'] ) ? substr( (string) $meta['follow_up_at'], 11, 5 ) : '';
?>
<p><a href="<?php echo esc_url( MCP_Admin::url() ); ?>">&larr; <?php esc_html_e( 'بازگشت به فهرست', 'medyar-chat-pro' ); ?></a></p>
<h2>
	<?php
	/* translators: %d: session id */
	printf( esc_html__( 'مکالمه #%d', 'medyar-chat-pro' ), (int) $session_id );
	?>
	<?php echo MCP_Admin::status_badge( (string) $state['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</h2>

<div class="mcp-grid">
	<div class="mcp-card">
		<h2><?php esc_html_e( 'اطلاعات مکالمه', 'medyar-chat-pro' ); ?></h2>
		<table class="mcp-kv">
			<tr><th><?php esc_html_e( 'بیمار', 'medyar-chat-pro' ); ?></th><td><?php echo MCP_Admin::user_link( (int) $session['user_2_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><?php esc_html_e( 'متخصص', 'medyar-chat-pro' ); ?></th><td><?php echo MCP_Admin::user_link( (int) $session['user_1_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><?php esc_html_e( 'شناسه نوبت', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( (string) (int) $session['context_id'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'علت مراجعه', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( (string) ( $booking['reason'] ?? '—' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'ایجاد', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::date( (string) $session['created_at'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'زمان باز شدن', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::date( (string) $session['open_at'] ) ); ?></td></tr>
			<?php if ( ! empty( $session['is_closed'] ) ) : ?>
				<tr><th><?php esc_html_e( 'زمان بسته شدن', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::date( (string) $session['closed_at'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'پیام‌های بیمار / متخصص', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['patient'] ) . ' / ' . number_format_i18n( $counts['specialist'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'فایل‌ها', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['files'] ) ); ?></td></tr>
		</table>
		<p class="mcp-actions">
			<?php if ( $transcript ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $transcript ); ?>"><?php esc_html_e( 'مشاهده متن مکالمه', 'medyar-chat-pro' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( MCP_Admin::action_url( 'export', array( 'id' => $session_id ) ) ); ?>"><?php esc_html_e( 'خروجی کامل (ZIP)', 'medyar-chat-pro' ); ?></a>
			<a class="button" href="<?php echo esc_url( MCP_Admin::action_url( 'recompute', array( 'id' => $session_id ) ) ); ?>"><?php esc_html_e( 'بازمحاسبه آمار', 'medyar-chat-pro' ); ?></a>
		</p>
	</div>

	<div class="mcp-card">
		<h2><?php esc_html_e( 'زمان پاسخ', 'medyar-chat-pro' ); ?></h2>
		<table class="mcp-kv">
			<tr><th><?php esc_html_e( 'اولین پاسخ متخصص', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::duration( $meta['first_response_secs'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'میانگین زمان پاسخ', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::duration( $avg ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'تعداد پاسخ‌ها', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $meta['response_count'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'انتظار فعلی بیمار', 'medyar-chat-pro' ); ?></th><td class="<?php echo $waiting > DAY_IN_SECONDS ? 'mcp-danger' : ''; ?>"><?php echo esc_html( null === $waiting ? '—' : MCP_Admin::duration( $waiting ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'آخرین پیام بیمار', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::date( $meta['last_patient_at'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'آخرین پیام متخصص', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::date( $meta['last_specialist_at'] ) ); ?></td></tr>
		</table>
	</div>

	<div class="mcp-card">
		<h2><?php esc_html_e( 'مدیریت', 'medyar-chat-pro' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcp-inline-form">
			<?php wp_nonce_field( MCP_Admin::ACTION . '_status' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( MCP_Admin::ACTION ); ?>">
			<input type="hidden" name="do" value="status">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $session_id ); ?>">
			<label for="mcp-status"><?php esc_html_e( 'وضعیت مکالمه', 'medyar-chat-pro' ); ?></label>
			<select name="status" id="mcp-status">
				<?php foreach ( MCP_Sessions::labels() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button button-primary"><?php esc_html_e( 'ذخیره وضعیت', 'medyar-chat-pro' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'بستن مکالمه، ارسال پیام را برای هر دو طرف غیرفعال و سفارش نوبت را «تکمیل‌شده» می‌کند (همانند دکمه بستن قالب).', 'medyar-chat-pro' ); ?></p>
		<hr>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcp-inline-form">
			<?php wp_nonce_field( MCP_Admin::ACTION . '_follow_up' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( MCP_Admin::ACTION ); ?>">
			<input type="hidden" name="do" value="follow_up">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $session_id ); ?>">
			<span><?php esc_html_e( 'پیگیری', 'medyar-chat-pro' ); ?></span>
			<?php MCP_Admin::date_input( 'follow_up_date', $follow_val, __( 'تاریخ پیگیری', 'medyar-chat-pro' ), __( '۱۴۰۵/۰۸/۱۵', 'medyar-chat-pro' ), 'mcp-fu' ); ?>
			<label class="screen-reader-text" for="mcp-fu-time"><?php esc_html_e( 'ساعت پیگیری', 'medyar-chat-pro' ); ?></label>
			<input type="text" id="mcp-fu-time" name="follow_up_time" value="<?php echo esc_attr( MCP_Jalali::fa( $follow_time ) ); ?>" placeholder="<?php esc_attr_e( '۰۹:۰۰', 'medyar-chat-pro' ); ?>" class="mcp-time-input" inputmode="numeric" autocomplete="off" dir="ltr">
			<input type="text" name="follow_up_note" value="<?php echo esc_attr( (string) ( $meta['follow_up_note'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'توضیح', 'medyar-chat-pro' ); ?>">
			<button class="button"><?php esc_html_e( 'ذخیره', 'medyar-chat-pro' ); ?></button>
			<?php if ( $follow_val ) : ?>
				<button class="button-link mcp-danger" name="clear" value="1"><?php esc_html_e( 'حذف پیگیری', 'medyar-chat-pro' ); ?></button>
			<?php endif; ?>
		</form>
	</div>
</div>

<div class="mcp-grid">
	<div class="mcp-card">
		<h2><?php esc_html_e( 'یادداشت‌های خصوصی متخصص', 'medyar-chat-pro' ); ?></h2>
		<?php if ( ! MCP_Access::can_view_medical() ) : ?>
			<p class="mcp-muted"><?php esc_html_e( 'برای مشاهده یادداشت‌ها به مجوز «mcp_view_medical» نیاز دارید.', 'medyar-chat-pro' ); ?></p>
		<?php elseif ( ! $notes ) : ?>
			<p class="mcp-muted"><?php esc_html_e( 'یادداشتی ثبت نشده است.', 'medyar-chat-pro' ); ?></p>
		<?php else : ?>
			<ul class="mcp-notes">
				<?php foreach ( $notes as $note ) : ?>
					<li>
						<div class="mcp-note-head">
							<strong><?php echo esc_html( $note['author'] ); ?></strong>
							<span class="mcp-muted"><?php echo esc_html( MCP_Admin::date( $note['updated_at'] ) ); ?></span>
							<?php if ( ! $note['this_session'] ) : ?>
								<span class="mcp-badge">#<?php echo esc_html( (string) $note['session_id'] ); ?></span>
							<?php endif; ?>
						</div>
						<div class="mcp-note-body"><?php echo nl2br( esc_html( $note['body'] ) ); ?></div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<div class="mcp-card">
		<h2><?php esc_html_e( 'ارجاع‌ها', 'medyar-chat-pro' ); ?></h2>
		<?php if ( ! $referrals ) : ?>
			<p class="mcp-muted"><?php esc_html_e( 'ارجاعی ثبت نشده است.', 'medyar-chat-pro' ); ?></p>
		<?php endif; ?>
		<ul class="mcp-notes">
			<?php foreach ( $referrals as $ref ) : ?>
				<li>
					<strong><?php echo esc_html( $ref['name'] ); ?></strong> <span class="mcp-muted"><?php echo esc_html( $ref['subtitle'] ); ?></span>
					<?php if ( '' !== $ref['reason'] ) : ?>
						<div class="mcp-note-body"><?php echo esc_html( $ref['reason'] ); ?></div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>

<div class="mcp-card">
	<h2><?php esc_html_e( 'تاریخچه فعالیت‌های این مکالمه', 'medyar-chat-pro' ); ?></h2>
	<?php
	$rows = $audit['rows'];
	include MCP_PLUGIN_DIR . 'includes/admin/views/audit-table.php';
	?>
</div>
