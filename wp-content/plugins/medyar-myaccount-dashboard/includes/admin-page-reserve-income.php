<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var array      $settings
 * @var array|null $income_report
 */
$report = is_array( $income_report ) ? $income_report : [
	'total_fee'        => 0,
	'total_commission' => 0,
	'total_income'     => 0,
	'count'            => 0,
	'rows'             => [],
];
?>
<form method="post" action="options.php" id="mmad_admin_reserve_income_form">
	<?php settings_fields( 'medyar_admin_reserve_sms_group' ); ?>
	<input type="hidden" name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[_tab]" value="medyar-income" />

	<section class="mmad-sms-category" aria-labelledby="mmad-income-heading">
		<h2 class="title" id="mmad-income-heading">سهم مدیار از واریز رزرو</h2>
		<p class="mmad-sms-lead">
			در صورت فعال بودن، درصدی از مبلغ واریزی به کیف پول متخصص بابت رزرو کسر و به‌عنوان درآمد مدیار ثبت می‌شود.
			کمیسیون دریافتی از بیمار بابت هر رزرو نیز در همین گزارش به‌عنوان درآمد مدیار لحاظ می‌شود.
		</p>

		<div class="mmad-sms-category__item">
			<h3 class="mmad-sms-category__subtitle">تنظیمات کارمزد واریز متخصص</h3>
			<p class="description mmad-sms-category__hint">این کسر برای همه انواع رزرو متخصص اعمال می‌شود و در رابط متخصص/بیمار نمایش داده نمی‌شود.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mmad_income_enabled">فعال‌سازی</label></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[income_enabled]" id="mmad_income_enabled" value="1" <?php checked( ! empty( $settings['income_enabled'] ) ); ?>>
							کسر درصد از واریز متخصص فعال باشد
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mmad_income_percent">درصد کسر</label></th>
					<td>
						<input
							type="number"
							class="small-text ltr"
							id="mmad_income_percent"
							name="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::OPTION ); ?>[income_percent]"
							value="<?php echo esc_attr( (string) $settings['income_percent'] ); ?>"
							min="0"
							max="100"
							step="0.1"
							dir="ltr"
						>
						<span class="description">٪ از مبلغ واریز به کیف پول متخصص (پیش‌فرض ۱۰)</span>
					</td>
				</tr>
			</table>
		</div>
		<?php submit_button( 'ذخیره تنظیمات درآمد' ); ?>
	</section>
</form>

<?php
$filters        = $report['filters'] ?? MMAD_Admin_Reserve_SMS::parse_income_filters( [] );
$has_filters    = $filters['s'] !== '' || $filters['specialist'] > 0 || $filters['visit_type'] !== '' || $filters['status'] !== '' || $filters['source'] !== '' || $filters['period'] !== '';
$base_url       = MMAD_Admin_Reserve_SMS::tab_url( 'medyar-income' );
$filter_args    = array_filter(
	[
		's'            => $filters['s'],
		'specialist'   => $filters['specialist'] ?: '',
		'visit_type'   => $filters['visit_type'],
		'order_status' => $filters['status'],
		'source'       => $filters['source'],
		'period'       => $filters['period'],
	],
	static fn( $v ) => $v !== '' && $v !== 0
);
$export_url     = wp_nonce_url(
	add_query_arg( array_merge( [ 'action' => 'mmad_income_export' ], $filter_args ), admin_url( 'admin-post.php' ) ),
	'mmad_income_export'
);
$visit_options  = MMAD_Admin_Reserve_SMS::visit_type_options();
$period_options = MMAD_Admin_Reserve_SMS::income_period_options();
$source_options = [
	''           => 'همه منابع درآمد',
	'commission' => 'دارای کمیسیون بیمار',
	'fee'        => 'دارای کارمزد واریز',
	'both'       => 'هر دو',
];
?>

<div class="mmad-kpis">
	<div class="mmad-kpi mmad-kpi--green">
		<div class="mmad-kpi__icon dashicons dashicons-chart-area"></div>
		<div class="mmad-kpi__body">
			<span class="mmad-kpi__label">مجموع درآمد مدیار</span>
			<span class="mmad-kpi__value">
				<?php echo esc_html( number_format_i18n( (int) ( $report['total_income'] ?? 0 ) ) ); ?>
				<small>تومان</small>
			</span>
			<span class="mmad-kpi__meta"><?php echo esc_html( number_format_i18n( (int) $report['count'] ) ); ?> رزرو<?php echo $has_filters ? ' · طبق فیلتر' : ''; ?></span>
		</div>
	</div>
	<div class="mmad-kpi mmad-kpi--teal">
		<div class="mmad-kpi__icon dashicons dashicons-groups"></div>
		<div class="mmad-kpi__body">
			<span class="mmad-kpi__label">کمیسیون از بیمار</span>
			<span class="mmad-kpi__value">
				<?php echo esc_html( number_format_i18n( (int) ( $report['total_commission'] ?? 0 ) ) ); ?>
				<small>تومان</small>
			</span>
		</div>
	</div>
	<div class="mmad-kpi">
		<div class="mmad-kpi__icon dashicons dashicons-money-alt"></div>
		<div class="mmad-kpi__body">
			<span class="mmad-kpi__label">کارمزد واریز متخصص</span>
			<span class="mmad-kpi__value">
				<?php echo esc_html( number_format_i18n( (int) ( $report['total_fee'] ?? 0 ) ) ); ?>
				<small>تومان</small>
			</span>
			<span class="mmad-kpi__meta"><?php echo ! empty( $settings['income_enabled'] ) ? 'فعال' : 'غیرفعال'; ?> · <?php echo esc_html( (string) $settings['income_percent'] ); ?>٪</span>
		</div>
	</div>
	<div class="mmad-kpi mmad-kpi--purple">
		<div class="mmad-kpi__icon dashicons dashicons-businessperson"></div>
		<div class="mmad-kpi__body">
			<span class="mmad-kpi__label">واریز به متخصصان</span>
			<span class="mmad-kpi__value">
				<?php echo esc_html( number_format_i18n( (int) ( $report['total_net'] ?? 0 ) ) ); ?>
				<small>تومان</small>
			</span>
		</div>
	</div>
</div>

<div class="mmad-filter-card">
	<h2>فیلتر درآمدها</h2>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mmad-filter-form" role="search">
		<input type="hidden" name="page" value="<?php echo esc_attr( MMAD_Admin_Reserve_SMS::PAGE_INCOME ); ?>">

		<div class="mmad-filter-grid">
			<label>مراجع / موبایل / سفارش
				<input type="search" name="s" class="regular-text" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="نام، موبایل، شماره سفارش یا رزرو…" autocomplete="off">
			</label>

			<label>متخصص
				<select name="specialist">
					<option value="">همه متخصص‌ها</option>
					<?php foreach ( (array) ( $report['specialists'] ?? [] ) as $sid => $sname ) : ?>
						<option value="<?php echo esc_attr( (string) $sid ); ?>" <?php selected( (int) $filters['specialist'], (int) $sid ); ?>><?php echo esc_html( $sname ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>نوع ویزیت
				<select name="visit_type">
					<option value="">همه انواع</option>
					<?php foreach ( $visit_options as $vkey => $vlabel ) : ?>
						<option value="<?php echo esc_attr( sanitize_key( $vkey ) ); ?>" <?php selected( $filters['visit_type'], sanitize_key( $vkey ) ); ?>><?php echo esc_html( $vlabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>وضعیت سفارش
				<select name="order_status">
					<option value="">همه وضعیت‌ها</option>
					<?php foreach ( (array) ( $report['statuses'] ?? [] ) as $skey => $slabel ) : ?>
						<option value="<?php echo esc_attr( $skey ); ?>" <?php selected( $filters['status'], $skey ); ?>><?php echo esc_html( $slabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>منبع درآمد
				<select name="source">
					<?php foreach ( $source_options as $okey => $olabel ) : ?>
						<option value="<?php echo esc_attr( $okey ); ?>" <?php selected( $filters['source'], $okey ); ?>><?php echo esc_html( $olabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>بازه زمانی
				<select name="period">
					<?php foreach ( $period_options as $pkey => $plabel ) : ?>
						<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $filters['period'], $pkey ); ?>><?php echo esc_html( $plabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<p class="mmad-filter-actions">
			<button type="submit" class="button button-primary">اعمال فیلتر</button>
			<a class="button" href="<?php echo esc_url( $base_url ); ?>">بازنشانی</a>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>">خروجی CSV</a>
			<span class="mmad-filter-meta description">
				<?php echo esc_html( number_format_i18n( (int) $report['count'] ) ); ?> مورد<?php echo $has_filters ? ' · طبق فیلتر' : ''; ?>
			</span>
		</p>
	</form>
</div>

<section class="mmad-sms-category" aria-labelledby="mmad-income-recent-heading">
	<h2 class="title" id="mmad-income-recent-heading">موارد درآمد</h2>

	<?php if ( empty( $report['rows'] ) ) : ?>
		<p class="mmad-empty">
			<span class="dashicons dashicons-info"></span>
			<?php echo $has_filters ? 'موردی با این فیلترها پیدا نشد.' : 'هنوز درآمدی از رزرو ثبت نشده است.'; ?>
		</p>
	<?php else : ?>
		<div class="mmad-table-scroll">
			<table class="widefat striped mmad-compact mmad-income-table">
				<thead>
					<tr>
						<th>سفارش</th>
						<th>نام مراجع</th>
						<th>نام متخصص</th>
						<th>نوع ویزیت</th>
						<th>کمیسیون بیمار</th>
						<th>کارمزد واریز</th>
						<th>جمع درآمد</th>
						<th>واریز متخصص</th>
						<th>تاریخ</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<tr class="mmad-income-row" data-mmad-income="<?php echo esc_attr( wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ); ?>">
							<td>
								<a href="<?php echo esc_url( $row['order_url'] ); ?>">
									#<?php echo esc_html( (string) (int) $row['order_id'] ); ?>
								</a>
							</td>
							<td>
								<?php if ( $row['appointment_url'] !== '' ) : ?>
									<a href="<?php echo esc_url( $row['appointment_url'] ); ?>"><strong>#<?php echo esc_html( (string) (int) $row['book_id'] ); ?></strong></a>
								<?php endif; ?>
								<?php echo esc_html( $row['patient_name'] !== '' ? $row['patient_name'] : '—' ); ?>
							</td>
							<td>
								<?php echo esc_html( $row['specialist_name'] !== '' ? $row['specialist_name'] : '—' ); ?>
								<?php if ( $row['specialist_subtitle'] !== '' ) : ?>
									<span class="mmad-cell-sub"><?php echo esc_html( $row['specialist_subtitle'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['visit_type_label'] ); ?></td>
							<td class="ltr"><?php echo esc_html( number_format_i18n( (int) $row['commission'] ) ); ?></td>
							<td class="ltr"><?php echo esc_html( number_format_i18n( (int) $row['fee'] ) ); ?></td>
							<td class="ltr"><strong><?php echo esc_html( number_format_i18n( (int) $row['total_income'] ) ); ?></strong></td>
							<td class="ltr"><?php echo esc_html( number_format_i18n( (int) $row['net'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['date'] ); ?></td>
							<td>
								<button type="button" class="button button-small mmad-income-detail-btn">جزئیات</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( (int) $report['pages'] > 1 ) : ?>
			<div class="tablenav bottom mmad-pagination">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							[
								'base'      => add_query_arg( 'paged', '%#%', add_query_arg( $filter_args, $base_url ) ),
								'format'    => '',
								'current'   => (int) $report['paged'],
								'total'     => (int) $report['pages'],
								'prev_text' => '&rsaquo;',
								'next_text' => '&lsaquo;',
							]
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</section>

<div id="mmad-income-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="mmad-income-modal-title" hidden>
	<div id="mmad-income-modal">
		<div class="mmad-modal-header">
			<h2 id="mmad-income-modal-title">جزئیات درآمد رزرو</h2>
			<div id="mmad-income-status-wrap"></div>
			<button type="button" class="mmad-modal-close" id="mmad-income-modal-close" aria-label="بستن">&times;</button>
		</div>
		<div class="mmad-modal-body" id="mmad-income-modal-body"></div>
		<div class="mmad-modal-footer">
			<button type="button" class="button" id="mmad-income-modal-cancel">بستن</button>
			<a class="button button-primary" id="mmad-income-modal-order" href="#" target="_blank" rel="noopener noreferrer">مشاهده سفارش</a>
		</div>
	</div>
</div>
