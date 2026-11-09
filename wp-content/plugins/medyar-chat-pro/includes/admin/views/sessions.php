<?php
/**
 * @var array<string,mixed>            $filters
 * @var array{from:string,to:string}   $dates Jalali (ASCII) filter values.
 * @var array<string,mixed>            $overview
 * @var array<int,array<string,mixed>> $per_spec
 * @var array{rows:array,total:int}    $result
 * @var array<string,string>           $labels
 * @var array<string,mixed>            $base
 * @var string                         $orderby
 * @var string                         $order
 * @var int                            $paged
 * @var int                            $pages
 */
defined( 'ABSPATH' ) || exit;

$cards = array(
	''                   => array( __( 'همه مکالمات', 'medyar-chat-pro' ), (int) ( $overview['total'] ?? 0 ) ),
	'open'               => array( $labels['open'], (int) ( $overview['open_count'] ?? 0 ) ),
	'waiting_specialist' => array( $labels['waiting_specialist'], (int) ( $overview['waiting_specialist'] ?? 0 ) ),
	'waiting_patient'    => array( $labels['waiting_patient'], (int) ( $overview['waiting_patient'] ?? 0 ) ),
	'answered'           => array( $labels['answered'], (int) ( $overview['answered'] ?? 0 ) ),
	'closed'             => array( $labels['closed'], (int) ( $overview['closed'] ?? 0 ) ),
);
$filter_base = $base;
unset( $filter_base['status'] );
?>
<div class="mcp-stats">
	<?php foreach ( $cards as $key => $card ) : ?>
		<a class="mcp-stat mcp-stat--<?php echo esc_attr( '' === $key ? 'all' : $key ); ?><?php echo $filters['status'] === $key ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( MCP_Admin::url( array_merge( $filter_base, '' === $key ? array() : array( 'status' => $key ) ) ) ); ?>">
			<span class="mcp-stat__num"><?php echo esc_html( number_format_i18n( $card[1] ) ); ?></span>
			<span class="mcp-stat__label"><?php echo esc_html( $card[0] ); ?></span>
		</a>
	<?php endforeach; ?>
</div>

<div class="mcp-grid">
	<div class="mcp-card">
		<h2><?php esc_html_e( 'زمان انتظار بیماران', 'medyar-chat-pro' ); ?></h2>
		<table class="mcp-kv">
			<tr><th><?php esc_html_e( 'میانگین زمان پاسخ متخصص', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::duration( $overview['avg_response_secs'] ?? null ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'میانگین زمان اولین پاسخ', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::duration( $overview['avg_first_response_secs'] ?? null ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'طولانی‌ترین انتظار فعلی', 'medyar-chat-pro' ); ?></th><td><?php echo esc_html( MCP_Admin::duration( $overview['max_waiting_secs'] ?? null ) ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'پیگیری‌های سررسیده', 'medyar-chat-pro' ); ?></th>
				<td><a href="<?php echo esc_url( MCP_Admin::url( array_merge( $filter_base, array( 'follow_up' => 1 ) ) ) ); ?>"><?php echo esc_html( number_format_i18n( (int) ( $overview['follow_up_due'] ?? 0 ) ) ); ?></a></td>
			</tr>
		</table>
		<p class="mcp-actions">
			<a class="button" href="<?php echo esc_url( MCP_Admin::action_url( 'recompute_all' ) ); ?>"><?php esc_html_e( 'بازمحاسبه آمار همه مکالمات', 'medyar-chat-pro' ); ?></a>
		</p>
	</div>
	<div class="mcp-card mcp-card--wide">
		<h2><?php esc_html_e( 'عملکرد متخصصان', 'medyar-chat-pro' ); ?></h2>
		<table class="widefat striped mcp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'متخصص', 'medyar-chat-pro' ); ?></th>
					<th><?php esc_html_e( 'کل', 'medyar-chat-pro' ); ?></th>
					<th><?php esc_html_e( 'فعال', 'medyar-chat-pro' ); ?></th>
					<th><?php esc_html_e( 'بسته', 'medyar-chat-pro' ); ?></th>
					<th><?php esc_html_e( 'منتظر پاسخ', 'medyar-chat-pro' ); ?></th>
					<th><?php esc_html_e( 'میانگین پاسخ', 'medyar-chat-pro' ); ?></th>
					<th><?php esc_html_e( 'بیشترین انتظار فعلی', 'medyar-chat-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $per_spec ) : ?>
					<tr><td colspan="7" class="mcp-empty"><?php esc_html_e( 'داده‌ای وجود ندارد.', 'medyar-chat-pro' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $per_spec as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( MCP_Admin::url( array_merge( $base, array( 'specialist' => (int) $row['specialist_id'] ) ) ) ); ?>"><?php echo esc_html( MCP_Theme::display_name( (int) $row['specialist_id'] ) ); ?></a></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['active'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['closed'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['waiting_specialist'] ) ); ?></td>
						<td><?php echo esc_html( MCP_Admin::duration( $row['avg_response_secs'] ) ); ?></td>
						<td><?php echo esc_html( MCP_Admin::duration( $row['max_waiting_secs'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<form method="get" class="mcp-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( MCP_Admin::PAGE ); ?>">
	<input type="hidden" name="tab" value="sessions">
	<label class="screen-reader-text" for="mcp-f-status"><?php esc_html_e( 'وضعیت', 'medyar-chat-pro' ); ?></label>
	<select name="status" id="mcp-f-status">
		<option value=""><?php esc_html_e( 'همه وضعیت‌ها', 'medyar-chat-pro' ); ?></option>
		<?php foreach ( $labels as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<label class="screen-reader-text" for="mcp-f-spec"><?php esc_html_e( 'متخصص', 'medyar-chat-pro' ); ?></label>
	<select name="specialist" id="mcp-f-spec">
		<option value=""><?php esc_html_e( 'همه متخصصان', 'medyar-chat-pro' ); ?></option>
		<?php foreach ( self::specialists() as $uid => $name ) : ?>
			<option value="<?php echo esc_attr( (string) $uid ); ?>" <?php selected( (int) $filters['specialist'], $uid ); ?>><?php echo esc_html( $name ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php MCP_Admin::date_input( 'from', $dates['from'], __( 'از تاریخ', 'medyar-chat-pro' ), __( 'از ۱۴۰۵/۰۱/۰۱', 'medyar-chat-pro' ) ); ?>
	<?php MCP_Admin::date_input( 'to', $dates['to'], __( 'تا تاریخ', 'medyar-chat-pro' ), __( 'تا ۱۴۰۵/۱۲/۲۹', 'medyar-chat-pro' ) ); ?>
	<label><input type="checkbox" name="follow_up" value="1" <?php checked( (int) $filters['follow_up'], 1 ); ?>> <?php esc_html_e( 'فقط پیگیری سررسیده', 'medyar-chat-pro' ); ?></label>
	<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'شناسه مکالمه یا نام کاربر', 'medyar-chat-pro' ); ?>">
	<button class="button button-primary"><?php esc_html_e( 'فیلتر', 'medyar-chat-pro' ); ?></button>
	<a class="button" href="<?php echo esc_url( MCP_Admin::url() ); ?>"><?php esc_html_e( 'حذف فیلترها', 'medyar-chat-pro' ); ?></a>
</form>

<p class="mcp-muted">
	<?php
	/* translators: %s: number of sessions */
	printf( esc_html__( '%s مکالمه', 'medyar-chat-pro' ), esc_html( number_format_i18n( $result['total'] ) ) );
	?>
</p>
<table class="widefat striped mcp-table">
	<thead>
		<tr>
			<th><?php echo MCP_Admin::sort_link( '#', 'id', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php esc_html_e( 'بیمار', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'متخصص', 'medyar-chat-pro' ); ?></th>
			<th><?php echo MCP_Admin::sort_link( __( 'وضعیت', 'medyar-chat-pro' ), 'status', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo MCP_Admin::sort_link( __( 'ایجاد', 'medyar-chat-pro' ), 'created_at', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo MCP_Admin::sort_link( __( 'آخرین فعالیت', 'medyar-chat-pro' ), 'last_activity', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo MCP_Admin::sort_link( __( 'پیام‌ها', 'medyar-chat-pro' ), 'messages', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo MCP_Admin::sort_link( __( 'انتظار فعلی', 'medyar-chat-pro' ), 'waiting', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo MCP_Admin::sort_link( __( 'میانگین پاسخ', 'medyar-chat-pro' ), 'avg_response', $orderby, $order, $base ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php esc_html_e( 'پیگیری', 'medyar-chat-pro' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $result['rows'] ) : ?>
			<tr><td colspan="10" class="mcp-empty"><?php esc_html_e( 'مکالمه‌ای با این فیلترها پیدا نشد.', 'medyar-chat-pro' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['rows'] as $row ) : ?>
			<?php $due = ! empty( $row['follow_up_at'] ) && $row['follow_up_at'] <= MCP_Theme::now(); ?>
			<tr>
				<td><a href="<?php echo esc_url( MCP_Admin::url( array( 'view' => 'session', 'id' => (int) $row['id'] ) ) ); ?>"><strong>#<?php echo esc_html( (string) (int) $row['id'] ); ?></strong></a></td>
				<td><?php echo esc_html( MCP_Theme::display_name( (int) $row['user_2_id'] ) ); ?></td>
				<td><?php echo esc_html( MCP_Theme::display_name( (int) $row['user_1_id'] ) ); ?></td>
				<td><?php echo MCP_Admin::status_badge( (string) $row['eff_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td class="mcp-nowrap"><?php echo esc_html( MCP_Admin::date( $row['created_at'] ) ); ?></td>
				<td class="mcp-nowrap"><?php echo esc_html( MCP_Admin::date( $row['last_activity'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (int) $row['message_count'] ) ); ?></td>
				<td class="<?php echo (int) $row['waiting_secs'] > DAY_IN_SECONDS ? 'mcp-danger' : ''; ?>"><?php echo esc_html( (int) $row['waiting_secs'] > 0 ? MCP_Admin::duration( $row['waiting_secs'] ) : '—' ); ?></td>
				<td><?php echo esc_html( MCP_Admin::duration( $row['avg_response_secs'] ) ); ?></td>
				<td class="mcp-nowrap<?php echo $due ? ' mcp-danger' : ''; ?>"><?php echo esc_html( MCP_Admin::date( $row['follow_up_at'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php MCP_Admin::pagination( $paged, $pages, array_merge( $base, array( 'orderby' => $orderby, 'order' => strtolower( $order ) ) ) ); ?>
