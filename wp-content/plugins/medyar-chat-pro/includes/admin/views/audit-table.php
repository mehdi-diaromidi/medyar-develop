<?php
/**
 * @var array<int,array<string,mixed>> $rows
 */
defined( 'ABSPATH' ) || exit;
?>
<table class="widefat striped mcp-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'زمان', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'کاربر', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'رویداد', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'مکالمه', 'medyar-chat-pro' ); ?></th>
			<th><?php esc_html_e( 'جزئیات', 'medyar-chat-pro' ); ?></th>
			<th>IP</th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="6" class="mcp-empty"><?php esc_html_e( 'رویدادی ثبت نشده است.', 'medyar-chat-pro' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$details = $row['details'] ? json_decode( (string) $row['details'], true ) : array();
			$parts   = array();
			foreach ( (array) $details as $k => $v ) {
				if ( in_array( $k, array( 'from', 'to' ), true ) && is_string( $v ) && MCP_Sessions::is_valid( $v ) ) {
					$v = MCP_Sessions::labels()[ $v ];
				}
				$parts[] = $k . ': ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) );
			}
			if ( ! empty( $row['object_type'] ) ) {
				$parts[] = $row['object_type'] . ' #' . $row['object_id'];
			}
			?>
			<tr>
				<td class="mcp-nowrap"><?php echo esc_html( MCP_Admin::date( (string) $row['created_at'] ) ); ?></td>
				<td><?php echo (int) $row['actor_id'] ? MCP_Admin::user_link( (int) $row['actor_id'] ) : esc_html__( 'سیستم', 'medyar-chat-pro' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td><?php echo esc_html( MCP_Audit::label( (string) $row['action'] ) ); ?></td>
				<td>
					<?php if ( ! empty( $row['session_id'] ) ) : ?>
						<a href="<?php echo esc_url( MCP_Admin::url( array( 'view' => 'session', 'id' => (int) $row['session_id'] ) ) ); ?>">#<?php echo esc_html( (string) (int) $row['session_id'] ); ?></a>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td class="mcp-details"><?php echo esc_html( implode( ' | ', $parts ) ); ?></td>
				<td class="mcp-nowrap" dir="ltr"><?php echo esc_html( (string) $row['ip'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
