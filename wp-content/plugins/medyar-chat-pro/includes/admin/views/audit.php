<?php
/**
 * @var array<string,mixed>         $filters
 * @var array{from:string,to:string} $dates Jalali (ASCII) filter values.
 * @var array{rows:array,total:int} $result
 * @var int                         $paged
 * @var int                         $pages
 */
defined( 'ABSPATH' ) || exit;

$rows = $result['rows'];
$base = array_filter(
	array(
		'tab'        => 'audit',
		'session_id' => $filters['session_id'],
		'actor_id'   => $filters['actor_id'],
		'log_action' => $filters['action'],
		'from'       => $dates['from'],
		'to'         => $dates['to'],
	)
);
?>
<form method="get" class="mcp-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( MCP_Admin::PAGE ); ?>">
	<input type="hidden" name="tab" value="audit">
	<select name="log_action">
		<option value=""><?php esc_html_e( 'همه رویدادها', 'medyar-chat-pro' ); ?></option>
		<?php foreach ( MCP_Audit::labels() as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['action'], $key ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<input type="number" min="1" name="session_id" value="<?php echo esc_attr( $filters['session_id'] ? (string) $filters['session_id'] : '' ); ?>" placeholder="<?php esc_attr_e( 'شناسه مکالمه', 'medyar-chat-pro' ); ?>">
	<input type="number" min="1" name="actor_id" value="<?php echo esc_attr( $filters['actor_id'] ? (string) $filters['actor_id'] : '' ); ?>" placeholder="<?php esc_attr_e( 'شناسه کاربر', 'medyar-chat-pro' ); ?>">
	<?php MCP_Admin::date_input( 'from', $dates['from'], __( 'از تاریخ', 'medyar-chat-pro' ), __( 'از ۱۴۰۵/۰۱/۰۱', 'medyar-chat-pro' ) ); ?>
	<?php MCP_Admin::date_input( 'to', $dates['to'], __( 'تا تاریخ', 'medyar-chat-pro' ), __( 'تا ۱۴۰۵/۱۲/۲۹', 'medyar-chat-pro' ) ); ?>
	<button class="button button-primary"><?php esc_html_e( 'فیلتر', 'medyar-chat-pro' ); ?></button>
</form>
<p class="mcp-muted">
	<?php
	/* translators: %s: number of events */
	printf( esc_html__( '%s رویداد', 'medyar-chat-pro' ), esc_html( number_format_i18n( $result['total'] ) ) );
	?>
</p>
<?php
include MCP_PLUGIN_DIR . 'includes/admin/views/audit-table.php';
MCP_Admin::pagination( $paged, $pages, $base );
