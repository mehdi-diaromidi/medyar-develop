<?php
/**
 * @var array<string,string>       $filters
 * @var int                        $paged
 * @var int                        $pages
 * @var string                     $notice
 * @var int                        $drained
 * @var bool                       $installed
 * @var array<string,int>          $counts
 * @var array{rows:array,total:int} $result
 * @var string[]                   $channels
 * @var array<string,int|string>   $diag
 */
defined( 'ABSPATH' ) || exit;

$status_labels  = MSQ_Admin::status_labels();
$channel_labels = MSQ_Admin::channel_labels();
$source_labels  = array(
	'loopback'    => 'درخواست پس‌زمینه',
	'system-cron' => 'کرون سرور',
	'wp-cron'     => 'کرون وردپرس',
	'manual'      => 'اجرای دستی',
	'cli'         => 'WP-CLI',
);
$notices        = array(
	'retried'         => array( 'success', 'پیامک دوباره در صف قرار گرفت.' ),
	'retried_all'     => array( 'success', 'همهٔ پیامک‌های ناموفق دوباره در صف قرار گرفتند.' ),
	'canceled'        => array( 'success', 'پیامک لغو شد.' ),
	'drained'         => array( 'success', sprintf( 'صف اجرا شد؛ %d پیامک پردازش شد.', $drained ) ),
	'key_regenerated' => array( 'warning', 'کلید کرون عوض شد؛ دستور کرون سرور را به‌روز کنید.' ),
	'noop'            => array( 'info', 'تغییری انجام نشد.' ),
);
$system_cron_at = (int) ( $diag['system_cron_at'] ?? 0 );
$system_cron_ok = $system_cron_at > 0 && ( time() - $system_cron_at ) < 5 * MINUTE_IN_SECONDS;
$cron_url       = MSQ_Admin::system_cron_url();
?>
<div class="wrap msq-wrap" dir="rtl">
	<h1 class="wp-heading-inline">صف پیامک Medyar</h1>
	<p class="msq-lead">همهٔ پیامک‌های طرح همکاران، کیف پول و رزرو نوبت اول در این صف ثبت می‌شوند و در پس‌زمینه ارسال می‌شوند. پیامک ناموفق تا ۳ بار با فاصله دوباره تلاش می‌شود.</p>

	<?php if ( '' !== $notice && isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $installed ) : ?>
		<div class="notice notice-error"><p>جدول صف هنوز ساخته نشده است. افزونه را یک‌بار غیرفعال و دوباره فعال کنید.</p></div>
		<?php return; ?>
	<?php endif; ?>

	<div class="msq-stats">
		<?php foreach ( $status_labels as $key => $label ) : ?>
			<a class="msq-stat msq-stat--<?php echo esc_attr( $key ); ?><?php echo $filters['status'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MSQ_Admin::url( array( 'status' => $key ) ) ); ?>">
				<span class="msq-stat__num"><?php echo esc_html( number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) ); ?></span>
				<span class="msq-stat__label"><?php echo esc_html( $label ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="msq-grid">
		<section class="msq-card">
			<h2>وضعیت ارسال‌کننده‌ها</h2>
			<table class="msq-kv">
				<tr><th>آخرین شلیک پس‌زمینه</th><td><?php echo esc_html( MSQ_Admin::format_ts( $diag['spawn_at'] ?? 0 ) ); ?></td></tr>
				<tr><th>آخرین اجرای ورکر پس‌زمینه</th><td><?php echo esc_html( MSQ_Admin::format_ts( $diag['worker_at'] ?? 0 ) ); ?></td></tr>
				<tr><th>آخرین اجرای کرون سرور</th><td><?php echo esc_html( MSQ_Admin::format_ts( $system_cron_at ) ); ?> <?php echo $system_cron_ok ? '<span class="msq-badge msq-badge--sent">فعال</span>' : '<span class="msq-badge msq-badge--dead">تنظیم نشده</span>'; ?></td></tr>
				<tr><th>آخرین اجرای کرون وردپرس</th><td><?php echo esc_html( MSQ_Admin::format_ts( $diag['wp_cron_at'] ?? 0 ) ); ?></td></tr>
				<tr>
					<th>آخرین پردازش</th>
					<td>
						<?php echo esc_html( MSQ_Admin::format_ts( $diag['drain_at'] ?? 0 ) ); ?>
						<?php if ( ! empty( $diag['drain_src'] ) ) : ?>
							(<?php echo esc_html( $source_labels[ (string) $diag['drain_src'] ] ?? (string) $diag['drain_src'] ); ?>، <?php echo esc_html( number_format_i18n( (int) ( $diag['drain_jobs'] ?? 0 ) ) ); ?> پیامک)
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="msq-actions">
				<a class="button button-primary" href="<?php echo esc_url( MSQ_Admin::action_url( 'drain' ) ); ?>">اجرای صف همین حالا</a>
				<?php if ( ! empty( $counts['dead'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( MSQ_Admin::action_url( 'retry_dead' ) ); ?>">تلاش مجدد همهٔ ناموفق‌ها</a>
				<?php endif; ?>
			</p>
		</section>

		<section class="msq-card<?php echo $system_cron_ok ? '' : ' msq-card--warn'; ?>">
			<h2>کرون واقعی سرور</h2>
			<p>برای اینکه ارسال به بازدید سایت وابسته نباشد، در cPanel → Cron Jobs یک کرون <strong>هر دقیقه</strong> با دستور زیر بسازید:</p>
			<pre class="msq-code" dir="ltr">* * * * * curl -s "<?php echo esc_html( $cron_url ); ?>" &gt;/dev/null 2&gt;&amp;1</pre>
			<p class="description">اگر curl در دسترس نبود: <code dir="ltr">wget -q -O /dev/null "…همان آدرس…"</code></p>
			<p><a class="button button-link-delete" href="<?php echo esc_url( MSQ_Admin::action_url( 'regen_key' ) ); ?>" onclick="return confirm('کلید عوض شود؟ کرون فعلی سرور از کار می‌افتد تا آدرس جدید را بگذارید.');">ساخت کلید جدید</a></p>
		</section>
	</div>

	<form method="get" class="msq-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( MSQ_Admin::PAGE ); ?>" />
		<select name="status">
			<option value="">همهٔ وضعیت‌ها</option>
			<?php foreach ( $status_labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="channel">
			<option value="">همهٔ بخش‌ها</option>
			<?php foreach ( $channels as $ch ) : ?>
				<option value="<?php echo esc_attr( $ch ); ?>" <?php selected( $filters['channel'], $ch ); ?>><?php echo esc_html( $channel_labels[ $ch ] ?? $ch ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="موبایل، رویداد یا متن خطا" />
		<button class="button">فیلتر</button>
		<?php if ( array_filter( $filters ) ) : ?>
			<a class="button-link" href="<?php echo esc_url( MSQ_Admin::url() ); ?>">حذف فیلتر</a>
		<?php endif; ?>
	</form>

	<table class="widefat striped msq-table">
		<thead>
			<tr>
				<th>#</th>
				<th>ثبت</th>
				<th>بخش / رویداد</th>
				<th>گیرنده</th>
				<th>محتوا</th>
				<th>وضعیت</th>
				<th>تلاش</th>
				<th>آخرین خطا</th>
				<th>ارسال</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['rows'] ) ) : ?>
				<tr><td colspan="10" class="msq-empty">پیامکی یافت نشد.</td></tr>
			<?php endif; ?>
			<?php
			foreach ( $result['rows'] as $row ) :
				$status   = (string) $row['status'];
				$waiting  = MSQ_Queue::STATUS_PENDING === $status && (int) $row['attempts'] > 0;
				$label    = $waiting ? 'در انتظار تلاش مجدد' : ( $status_labels[ $status ] ?? $status );
				$priority = (int) $row['priority'] <= MSQ_Client::PRIORITY_ADMIN;
				$content  = '' !== (string) $row['pattern_code'] ? 'پترن ' . $row['pattern_code'] : '';
				$text     = trim( (string) $row['body'] );
				?>
				<tr>
					<td><?php echo esc_html( (string) $row['id'] ); ?></td>
					<td class="msq-nowrap"><?php echo esc_html( MSQ_Admin::format_utc( $row['created_at'] ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $channel_labels[ (string) $row['channel'] ] ?? (string) $row['channel'] ); ?></strong>
						<?php if ( $priority ) : ?><span class="msq-badge msq-badge--admin">ادمین</span><?php endif; ?>
						<br /><code><?php echo esc_html( (string) $row['event'] ); ?></code>
					</td>
					<td dir="ltr" class="msq-nowrap"><?php echo esc_html( (string) $row['phone'] ); ?></td>
					<td class="msq-content">
						<?php if ( '' !== $content ) : ?><span class="msq-badge"><?php echo esc_html( $content ); ?></span><?php endif; ?>
						<?php if ( '' !== $text ) : ?><span class="msq-text" title="<?php echo esc_attr( $text ); ?>"><?php echo esc_html( wp_trim_words( $text, 14, '…' ) ); ?></span><?php endif; ?>
					</td>
					<td><span class="msq-badge msq-badge--<?php echo esc_attr( $waiting ? 'retry' : $status ); ?>"><?php echo esc_html( $label ); ?></span></td>
					<td><?php echo esc_html( (int) $row['attempts'] . ' / ' . (int) $row['max_attempts'] ); ?></td>
					<td class="msq-error" title="<?php echo esc_attr( (string) $row['last_error'] ); ?>"><?php echo esc_html( wp_trim_words( (string) $row['last_error'], 10, '…' ) ); ?></td>
					<td class="msq-nowrap"><?php echo esc_html( MSQ_Admin::format_utc( $row['sent_at'] ) ); ?></td>
					<td class="msq-nowrap">
						<?php if ( in_array( $status, array( MSQ_Queue::STATUS_DEAD, MSQ_Queue::STATUS_CANCELED ), true ) || $waiting ) : ?>
							<a href="<?php echo esc_url( MSQ_Admin::action_url( 'retry', (int) $row['id'] ) ); ?>">ارسال دوباره</a>
						<?php endif; ?>
						<?php if ( MSQ_Queue::STATUS_PENDING === $status ) : ?>
							<a class="msq-danger" href="<?php echo esc_url( MSQ_Admin::action_url( 'cancel', (int) $row['id'] ) ); ?>">لغو</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
