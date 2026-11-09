<?php
/**
 * @var array{rows:array,total:int} $result
 * @var int                         $paged
 * @var int                         $pages
 */
defined( 'ABSPATH' ) || exit;
?>
<table class="widefat striped mcp-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'زمان', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'مکالمه', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'بیمار', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'ارجاع‌دهنده', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'ارجاع به', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'علت', 'medyar-chat-pro' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $result['rows'] ) : ?>
			<tr><td colspan="6" class="mcp-empty"><?php esc_html_e( 'ارجاعی ثبت نشده است.', 'medyar-chat-pro' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['rows'] as $row ) : ?>
			<tr>
				<td class="mcp-nowrap"><?php echo esc_html( MCP_Admin::date( (string) $row['created_at'] ) ); ?></td>
				<td><a href="<?php echo esc_url( MCP_Admin::url( array( 'view' => 'session', 'id' => (int) $row['session_id'] ) ) ); ?>">#<?php echo esc_html( (string) (int) $row['session_id'] ); ?></a></td>
				<td><?php echo esc_html( MCP_Theme::display_name( (int) $row['patient_id'] ) ); ?></td>
				<td><?php echo esc_html( MCP_Theme::display_name( (int) $row['from_user_id'] ) ); ?></td>
				<td><?php echo esc_html( MCP_Theme::display_name( (int) $row['to_user_id'] ) ); ?></td>
				<td><?php echo esc_html( (string) $row['reason'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php MCP_Admin::pagination( $paged, $pages, array( 'tab' => 'referrals' ) ); ?>
