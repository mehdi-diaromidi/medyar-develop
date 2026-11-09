<?php
if (!defined('ABSPATH')) exit;

class Med_Records_Cases_Advanced {
    const CAP_MANAGE = 'manage_medical_records';
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'medrec_records';

        add_action('admin_menu', [$this,'admin_submenu']);
        add_action('admin_post_medrec_save_case', [$this,'save_case']);
        add_action('wp_ajax_medrec_get_case', [$this,'ajax_get_case']);
        add_action('admin_footer', [$this,'modal_styles_scripts']);
        add_action('admin_post_medrec_delete_case', [$this,'delete_case']);
        add_action('wp_ajax_search_patients', [$this, 'ajax_search_patients']);
        add_action('wp_ajax_medrec_get_cases_by_patient', [$this, 'ajax_get_cases_by_patient']);
		add_action('wp_ajax_get_doctors', [$this, 'get_doctors_ajax']);
        add_action('wp_ajax_get_nurses', [$this, 'get_nurses_ajax']);
        add_action('woocommerce_account_dashboard', [ $this, 'render_patient_header_card' ], 5);
        add_action('woocommerce_account_my-cases_endpoint', [ $this, 'render_patient_header_card' ], 1);
        add_action('admin_enqueue_scripts', function() {
            wp_enqueue_script('select2');
            wp_enqueue_style('select2');
        });
    }

    /** زیرمنوهای افزونه **/
    public function admin_submenu() {
        add_submenu_page(
            'medrec-main',
            'پرونده‌ها',
            'پرونده‌ها',
            self::CAP_MANAGE,
            'medrec-cases',
            [$this,'page_cases']
        );
        add_submenu_page(
            null,
            'افزودن پرونده',
            'افزودن پرونده',
            self::CAP_MANAGE,
            'medrec-add-case',
            [$this,'page_add_case']
        );
    }

    /** صفحه لیست پرونده‌ها **/
    public function page_cases() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');
        global $wpdb;

        $paged    = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;
        $search   = sanitize_text_field($_GET['s'] ?? '');

        $where_parts = ["1=1"];
        $params = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_parts[] = "(u.display_name LIKE %s OR u.user_login LIKE %s OR EXISTS (
                SELECT 1 FROM {$this->table} r2 
                WHERE r2.patient_id = r.patient_id 
                  AND (r2.visit_reason LIKE %s OR r2.doctor LIKE %s OR r2.clinic LIKE %s)
            ))";
            array_push($params, $like, $like, $like, $like, $like);
        }

        $where_sql = implode(' AND ', $where_parts);

        $total = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM (
                SELECT r.patient_id
                FROM {$this->table} r
                LEFT JOIN {$wpdb->users} u ON u.ID = r.patient_id
                WHERE $where_sql
                GROUP BY r.patient_id
            ) x
        ", $params));

        $offset = ($paged - 1) * $per_page;

        $patients = $wpdb->get_results($wpdb->prepare("
            SELECT r.patient_id,
                   COUNT(*) AS cases_count,
                   MAX(r.visit_date) AS last_visit
            FROM {$this->table} r
            LEFT JOIN {$wpdb->users} u ON u.ID = r.patient_id
            WHERE $where_sql
            GROUP BY r.patient_id
            ORDER BY last_visit DESC
            LIMIT %d, %d
        ", array_merge($params, [$offset, $per_page])));

        echo '<div class="wrap"><h1>پرونده‌ها (گروه‌بندی‌شده بر اساس بیمار)</h1>';

        echo '<a class="button-primary" href="'.esc_url(admin_url('admin.php?page=medrec-add-case')).'">+ افزودن پرونده</a>';

        echo '<form method="get" style="margin-top:10px;">';
        echo '<input type="hidden" name="page" value="medrec-cases">';
        echo '<input type="search" name="s" placeholder="جستجو (نام بیمار/پزشک/کلینیک/علت مراجعه)" value="'.esc_attr($search).'"> ';
        echo '<button class="button">جستجو</button>';
        echo '</form><br>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>
                <th style="width:40%">بیمار</th>
                <th style="width:15%">تعداد پرونده</th>
                <th style="width:20%">آخرین مراجعه</th>
                <th style="width:25%">لیست پرونده‌ها</th>
              </tr></thead><tbody>';

        if ($patients) {
            foreach ($patients as $p) {
                $user = get_userdata($p->patient_id);
                $name = $user ? $user->display_name . ' (' . $user->user_login . ')' : '—';
                $pid  = intval($p->patient_id);

                echo '<tr class="medrec-patient-row">';
                echo '  <td><strong>'.esc_html($name).'</strong></td>';
                echo '  <td>'.intval($p->cases_count).'</td>';
                echo '  <td>'.esc_html($p->last_visit ?: '—').'</td>';
                echo '  <td><button class="button button-secondary medrec-toggle" data-loaded="0" data-patient="'.$pid.'">نمایش پرونده‌ها</button></td>';
                echo '</tr>';

                echo '<tr class="medrec-patient-cases" style="display:none;"><td colspan="4">
                        <div id="medrec-cases-holder-'.$pid.'" class="medrec-cases-holder">
                            <div class="medrec-cases-loading">در حال بارگذاری...</div>
                        </div>
                      </td></tr>';
            }
        } else {
            echo '<tr><td colspan="4">موردی یافت نشد.</td></tr>';
        }

        echo '</tbody></table>';

        $total_pages = max(1, ceil($total / $per_page));
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '?paged=%#%',
                'total'   => $total_pages,
                'current' => $paged
            ]);
            echo '</div></div>';
        }

        echo '<div id="medrec-case-modal"><div><button id="medrec-close-modal">×</button><div class="medrec-modal-content"></div></div></div>';
        ?>
        <style>
        .medrec-cases-holder{background:#f8f9fb;border:1px solid #e7eef6;border-radius:10px;padding:12px}
        .medrec-cases-loading{padding:10px;color:#555}
        .medrec-case-actions a{margin:0 6px}
        </style>
        <script>
        jQuery(function($){
            $(document).on('click','.medrec-toggle',function(e){
                e.preventDefault();
                var $btn = $(this);
                var pid  = $btn.data('patient');
                var $row = $btn.closest('tr').next('.medrec-patient-cases');
                var loaded = $btn.data('loaded');

                if(!loaded){
                    var holder = $('#medrec-cases-holder-'+pid);
                    holder.html('<div class="medrec-cases-loading">در حال بارگذاری...</div>');
                    $.post(ajaxurl, {
                        action: 'medrec_get_cases_by_patient',
                        patient_id: pid,
                        s: $('input[name="s"]').val() || ''
                    }, function(res){
                        if(res.success){
                            holder.html(res.data.html);
                            $btn.data('loaded', 1);
                            $row.show();
                        } else {
                            holder.html('<div class="error">خطا در بارگذاری پرونده‌ها.</div>');
                            $row.show();
                        }
                    });
                } else {
                    $row.toggle();
                }
            });
        });
        </script>
        <?php
        echo '</div>';
    }

    /** صفحه افزودن / ویرایش پرونده **/
    public function page_add_case() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');
        global $wpdb;

        $case_id = intval($_GET['edit'] ?? $_GET['id'] ?? 0);
        $case = [];
        if ($case_id) {
            $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $case_id), ARRAY_A);
        }

        if (isset($_GET['updated']) && $_GET['updated'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ پرونده با موفقیت بروزرسانی شد. 
            <a href="'.admin_url('admin.php?page=medrec-cases').'">بازگشت به فهرست پرونده‌ها</a></p></div>';
        }
        if (isset($_GET['created']) && $_GET['created'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ پرونده جدید با موفقیت ایجاد شد. 
            <a href="'.admin_url('admin.php?page=medrec-cases').'">بازگشت به فهرست پرونده‌ها</a></p></div>';
        }

        $patient_name = '';
        if (!empty($case['patient_id'])) {
            $u = get_userdata($case['patient_id']);
            if ($u) $patient_name = $u->display_name . " ({$u->user_login})";
        }
        ?>

        <div class="wrap medrec-admin">
            <h1 class="wp-heading-inline"><?php echo $case_id ? 'ویرایش پرونده' : 'افزودن پرونده جدید'; ?></h1>
            <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="medrec_save_case">
                <?php wp_nonce_field('medrec_save_case','medrec_nonce'); ?>
                <input type="hidden" name="case_id" value="<?php echo esc_attr($case_id); ?>">

                <table class="form-table">
                    <tr>
                        <th><label>بیمار</label></th>
                        <td>
                            <select name="patient_id" id="patient_id" style="width:300px;">
                                <?php if ($case_id && $patient_name): ?>
                                    <option required value="<?php echo esc_attr($case['patient_id']); ?>" selected>
                                        <?php echo esc_html($patient_name); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <p class="description">برای انتخاب بیمار نام او را جستجو کنید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>تاریخ مراجعه</label></th>
                        <td><input required type="text" name="visit_date" class="medrec-date" data-jdp value="<?php echo esc_attr($case['visit_date'] ?? ''); ?>" placeholder="مثلاً 1403/02/01"></td>
                    </tr>
                    <tr>
                        <th><label>علت مراجعه</label></th>
                        <td><input required type="text" name="visit_reason" value="<?php echo esc_attr($case['visit_reason'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
    <th><label for="doctor">پزشک</label></th>
    <td>
        <select id="doctor_select" name="doctor" style="width:300px;">
            <option value="">پزشک را انتخاب کنید</option>
            <?php if ($case_id && !empty($case['doctor'])): ?>
                <option value="<?php echo esc_attr($case['doctor']); ?>" selected>
                    <?php echo esc_html($case['doctor']); ?>
                </option>
            <?php endif; ?>
        </select>
    </td>
</tr>

<tr>
    <th><label for="nurse">پرستار</label></th>
    <td>
        <select id="nurse_select" name="nurse" style="width:300px;">
            <option value="">پرستار را انتخاب کنید</option>
            <?php if ($case_id && !empty($case['nurse'])): ?>
                <option value="<?php echo esc_attr($case['nurse']); ?>" selected>
                    <?php echo esc_html($case['nurse']); ?>
                </option>
            <?php endif; ?>
        </select>
    </td>
</tr>

                    <tr>
                        <th><label>کلینیک</label></th>
                        <td><input type="text" name="clinic" value="<?php echo esc_attr($case['clinic'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>نوع ویزیت</label></th>
                        <td><input type="text" name="visit_type" value="<?php echo esc_attr($case['visit_type'] ?? ''); ?>"></td>
                    </tr>
                </table>

                <h2>علائم حیاتی</h2>
                <?php $vital = json_decode($case['vital_signs'] ?? '{}', true); ?>
                <div class="vitals-grid">
					<input type="text" name="vital[فشار خون]" placeholder="فشار خون" value="<?php echo esc_attr($vital['فشار خون'] ?? ''); ?>">
					<input type="text" name="vital[نبض]" placeholder="نبض" value="<?php echo esc_attr($vital['نبض'] ?? ''); ?>">
					<input type="text" name="vital[دمای بدن]" placeholder="دمای بدن" value="<?php echo esc_attr($vital['دمای بدن'] ?? ''); ?>">
					<input type="text" name="vital[تعداد تنفس]" placeholder="تعداد تنفس (عدد در دقیقه)" value="<?php echo esc_attr($vital['تعداد تنفس'] ?? ''); ?>">
					<input type="text" name="vital[سطح اکسیژن]" placeholder="سطح اکسیژن (٪)" value="<?php echo esc_attr($vital['سطح اکسیژن'] ?? ''); ?>">
				</div>
				
    			<h2>شکایت اصلی</h2>
				<textarea name="history" rows="3"><?php echo esc_textarea($case['history'] ?? ''); ?></textarea>
					
                <h2>معاینه فیزیکی</h2>
                <textarea name="physical_exam" rows="3"><?php echo esc_textarea($case['physical_exam'] ?? ''); ?></textarea>

                <h2>تشخیص اولیه</h2>
                <textarea name="diagnosis_initial" rows="2"><?php echo esc_textarea($case['diagnosis_initial'] ?? ''); ?></textarea>

                <h2>تشخیص نهایی</h2>
                <textarea name="diagnosis_final" rows="2"><?php echo esc_textarea($case['diagnosis_final'] ?? ''); ?></textarea>

                <h2>برنامه درمان</h2>
                <textarea name="treatment_plan" rows="3"><?php echo esc_textarea($case['treatment_plan'] ?? ''); ?></textarea>

                <h2>داروها</h2>
                <?php 
                $meds = json_decode($case['medications'] ?? '[]', true) ?: [['','','']];
                ?>
                <div class="repeat-wrap repeat-wrap-med">
                    <div class="items">
                        <?php foreach($meds as $m): ?>
                            <div class="repeat-item">
                                <input type="text" name="medications[name][]" placeholder="نام دارو" value="<?php echo esc_attr($m['name'] ?? ($m[0] ?? '')); ?>">
                                <input type="text" name="medications[dose][]" placeholder="دوز" value="<?php echo esc_attr($m['dose'] ?? ($m[1] ?? '')); ?>">
                                <input type="text" name="medications[warning][]" placeholder="هشدار" value="<?php echo esc_attr($m['warning'] ?? ($m[2] ?? '')); ?>">
                                <button class="button remove-item">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="button add-item">+ افزودن دارو</button>
                </div>

                <h2>آزمایش‌ها</h2>
                <?php 
                $labs = json_decode($case['labs'] ?? '[]', true) ?: [['name'=>'','files'=>[],'date'=>'']];
                ?>
                <div class="repeat-wrap repeat-wrap-lab">
                    <div class="items">
                        <?php foreach($labs as $i => $lab): ?>
                            <div class="repeat-item">
                                <input type="text" name="labs[name][]" placeholder="نام آزمایش" value="<?php echo esc_attr($lab['name'] ?? ''); ?>">
                                <input type="text" name="labs[date][]" class="medrec-date" data-jdp placeholder="تاریخ" value="<?php echo esc_attr($lab['date'] ?? ''); ?>">
                                <input type="file" name="labs_files[<?php echo $i; ?>][]" multiple>
                                <?php 
                                if(!empty($lab['files'])){
                                    echo '<ul class="uploaded-files">';
                                    foreach((array)$lab['files'] as $f){
                                        echo '<li><a href="'.esc_url($f).'" target="_blank">'.basename($f).'</a></li>';
                                    }
                                    echo '</ul>';
                                }
                                ?>
                                <button class="button remove-item">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="button add-item">+ افزودن آزمایش</button>
                </div>

                <h2>تصاویر و MRIها</h2>
                <?php 
                $images = json_decode($case['images'] ?? '[]', true) ?: [['name'=>'','files'=>[],'date'=>'']];
                ?>
                <div class="repeat-wrap repeat-wrap-img">
                    <div class="items">
                        <?php foreach($images as $i => $img): ?>
                            <div class="repeat-item">
                                <input type="text" name="images[name][]" placeholder="عنوان تصویر یا MRI" value="<?php echo esc_attr($img['name'] ?? ''); ?>">
                                <input type="text" name="images[date][]" placeholder="تاریخ" class="medrec-date" data-jdp value="<?php echo esc_attr($img['date'] ?? ''); ?>">
                                <input type="file" name="images_files[<?php echo $i; ?>][]" multiple>
                                <?php 
                                if(!empty($img['files'])){
                                    echo '<ul class="uploaded-files">';
                                    foreach((array)$img['files'] as $f){
                                        echo '<li><a href="'.esc_url($f).'" target="_blank">'.basename($f).'</a></li>';
                                    }
                                    echo '</ul>';
                                }
                                ?>
                                <button class="button remove-item">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="button add-item">+ افزودن تصویر یا MRI</button>
                </div>

                <h2>پیگیری‌ها</h2>
                <?php 
                $followups = json_decode($case['followups'] ?? '[]', true) ?: [['','']];
                ?>
                <div class="repeat-wrap repeat-wrap-fu">
                    <div class="items">
                        <?php foreach($followups as $f): ?>
                            <div class="repeat-item">
                                <input type="text" name="followups[date][]" placeholder="تاریخ" class="medrec-date" data-jdp value="<?php echo esc_attr($f['date'] ?? ($f[0] ?? '')); ?>">
                                <input type="text" name="followups[note][]" placeholder="توضیح" value="<?php echo esc_attr($f['notes'] ?? ($f['note'] ?? ($f[1] ?? ''))); ?>">
                                <button class="button remove-item">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="button add-item">+ افزودن پیگیری</button>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">💾 ذخیره پرونده</button>
                    <a href="<?php echo admin_url('admin.php?page=medrec-cases'); ?>" class="button">↩ بازگشت</a>
                </p>
            </form>
        </div>

        <style>
            .repeat-wrap .repeat-item {margin-bottom:10px;background:#f8f8f8;padding:10px;border-radius:8px;}
            .repeat-wrap input[type="text"], textarea {width:100%;}
            .repeat-wrap .remove-item {background:#dc3232;color:white;border:none;}
            .repeat-wrap .add-item {margin-top:10px;}
            .uploaded-files li {list-style:disc;margin-right:15px;}
            .vitals-grid {display:flex;flex-wrap:wrap;gap:8px;}
            .vitals-grid input {width:140px;}
        </style>

        <script>
        jQuery(document).ready(function($){
            $('#patient_id').select2({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { action: 'search_patients', q: params.term };
                    },
                    processResults: function (data) {
                        return { results: data };
                    },
                    cache: true
                },
                placeholder: 'نام بیمار را جستجو کنید...',
                minimumInputLength: 2
            });
			$('#doctor_select').select2({
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'get_doctors',
                                q: params.term
                            };
                        },
                        processResults: function (data) {
                            return { results: data };
                        },
                        cache: true
                    },
                    placeholder: 'پزشک را جستجو کنید...',
                    minimumInputLength: 2
                });

                // راه‌اندازی Select2 برای پرستاران
                $('#nurse_select').select2({
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'get_nurses',
                                q: params.term
                            };
                        },
                        processResults: function (data) {
                            return { results: data };
                        },
                        cache: true
                    },
                    placeholder: 'پرستار را جستجو کنید...',
                    minimumInputLength: 2
                });
            function initRepeat($wrap){
                $wrap.on('click','.add-item',function(e){
                    e.preventDefault();
                    var $item = $(this).siblings('.items').children('.repeat-item:first').clone();
                    $item.find('input').val('');
                    $item.find('ul.uploaded-files').remove();
                    $(this).siblings('.items').append($item);
                });
                $wrap.on('click','.remove-item',function(e){
                    e.preventDefault();
                    var $items = $(this).closest('.items').children('.repeat-item');
                    if($items.length>1){ $(this).closest('.repeat-item').remove(); }
                    else { $items.find('input').val(''); }
                });
            }
            initRepeat($('.repeat-wrap-med'));
            initRepeat($('.repeat-wrap-lab'));
            initRepeat($('.repeat-wrap-img'));
            initRepeat($('.repeat-wrap-fu'));
        });
        </script>

        <?php
    }

    /** ذخیره پرونده **/
    public function save_case() {
        if ( ! current_user_can(self::CAP_MANAGE) ) wp_die('دسترسی ندارید.');

        if ( ! isset($_POST['medrec_nonce']) || ! wp_verify_nonce($_POST['medrec_nonce'], 'medrec_save_case') ) {
            wp_die('درخواست نامعتبر است.');
        }

        global $wpdb;

        $id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;

        $patient_id   = intval($_POST['patient_id'] ?? 0);
        $visit_date   = sanitize_text_field($_POST['visit_date'] ?? '');
        $visit_reason = sanitize_textarea_field($_POST['visit_reason'] ?? '');
        $doctor       = sanitize_text_field($_POST['doctor'] ?? '');
        $nurse        = sanitize_text_field($_POST['nurse'] ?? '');
        $clinic       = sanitize_text_field($_POST['clinic'] ?? '');
        $visit_type   = sanitize_text_field($_POST['visit_type'] ?? '');

        // vital[] -> JSON
        $vital_raw = is_array($_POST['vital'] ?? null) ? $_POST['vital'] : [];
        $vital_sanitized = [];
        foreach ($vital_raw as $k=>$v) { $vital_sanitized[sanitize_text_field($k)] = sanitize_text_field($v); }
        $vital_signs = json_encode($vital_sanitized, JSON_UNESCAPED_UNICODE);
		
		$history = sanitize_textarea_field($_POST['history'] ?? '');
        $physical_exam     = sanitize_textarea_field($_POST['physical_exam'] ?? '');
        $diagnosis_initial = sanitize_textarea_field($_POST['diagnosis_initial'] ?? '');
        $diagnosis_final   = sanitize_textarea_field($_POST['diagnosis_final'] ?? '');
        $treatment_plan    = sanitize_textarea_field($_POST['treatment_plan'] ?? '');

        // medications[name|dose|warning][]
        $medications = [];
        $med_names   = $_POST['medications']['name']    ?? [];
        $med_doses   = $_POST['medications']['dose']    ?? [];
        $med_warns   = $_POST['medications']['warning'] ?? [];
        $count_meds  = max(count($med_names), count($med_doses), count($med_warns));
        for ($i=0; $i<$count_meds; $i++) {
            $name = sanitize_text_field($med_names[$i] ?? '');
            $dose = sanitize_text_field($med_doses[$i] ?? '');
            $warn = sanitize_text_field($med_warns[$i] ?? '');
            if ($name==='' && $dose==='' && $warn==='') continue;
            $medications[] = ['name'=>$name,'dose'=>$dose,'warning'=>$warn];
        }

        // آماده‌سازی مسیر آپلود
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'experiment-medrec';
        $base_url   = trailingslashit($upload_dir['baseurl']) . 'experiment-medrec';
        if (!file_exists($base_dir)) wp_mkdir_p($base_dir);

        $allowed_exts = ['jpg','jpeg','png','gif','webp'];
        $is_valid_img = function($tmp, $name) use ($allowed_exts) {
            if (!file_exists($tmp)) return false;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts)) return false;
            $mime = @mime_content_type($tmp);
            return ($mime && strpos($mime, 'image/') === 0);
        };
        $move_file = function($tmp, $name, $prefix) use ($base_dir, $base_url) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $unique_name = uniqid($prefix) . '.' . $ext;
            $dest = trailingslashit($base_dir) . $unique_name;
            @move_uploaded_file($tmp, $dest);
            return trailingslashit($base_url) . $unique_name;
        };

        // داده‌های موجود (برای ویرایش، جهت حفظ فایل‌های قبلی)
        $old_labs = [];
        $old_imgs = [];
        if ($id > 0) {
            $old = $wpdb->get_row($wpdb->prepare("SELECT labs, images FROM {$this->table} WHERE id=%d", $id));
            if ($old) {
                $old_labs = json_decode($old->labs   ?: '[]', true) ?: [];
                $old_imgs = json_decode($old->images ?: '[]', true) ?: [];
            }
        }

        // labs[name][], labs[date][], labs_files[i][]
        $labs = [];
        $lab_names = $_POST['labs']['name'] ?? [];
        $lab_dates = $_POST['labs']['date'] ?? [];
        $files_labs= $_FILES['labs_files']  ?? null;

        $labs_count = max(count($lab_names), count($lab_dates));
        for($i=0; $i<$labs_count; $i++){
            $lname = sanitize_text_field($lab_names[$i] ?? '');
            $ldate = sanitize_text_field($lab_dates[$i] ?? '');
            $files = [];

            // حفظ فایل‌های قدیمی بلوک i (اگر وجود داشت)
            if (isset($old_labs[$i]['files']) && is_array($old_labs[$i]['files'])) {
                $files = array_values(array_filter($old_labs[$i]['files']));
            }

            // فایل‌های جدید این بلوک
            if ($files_labs && isset($files_labs['name'][$i]) && is_array($files_labs['name'][$i])) {
                foreach ($files_labs['name'][$i] as $k=>$fname) {
                    $tmp = $files_labs['tmp_name'][$i][$k] ?? '';
                    if ($tmp && $this->is_uploaded($tmp) && $fname && $is_valid_img($tmp,$fname)) {
                        $files[] = $move_file($tmp, $fname, 'lab_');
                    }
                }
            }

            if ($lname==='' && $ldate==='' && empty($files)) {
                // اگر هیچ چیزی وارد نشده و فایل هم نداریم: از این آیتم عبور
                continue;
            }

            $labs[] = ['name'=>$lname,'date'=>$ldate,'files'=>$files];
        }

        // images[name][], images[date][], images_files[i][]
        $images = [];
        $img_names = $_POST['images']['name'] ?? [];
        $img_dates = $_POST['images']['date'] ?? [];
        $files_imgs= $_FILES['images_files']  ?? null;

        $img_count = max(count($img_names), count($img_dates));
        for($i=0; $i<$img_count; $i++){
            $iname = sanitize_text_field($img_names[$i] ?? '');
            $idate = sanitize_text_field($img_dates[$i] ?? '');
            $files = [];

            if (isset($old_imgs[$i]['files']) && is_array($old_imgs[$i]['files'])) {
                $files = array_values(array_filter($old_imgs[$i]['files']));
            }

            if ($files_imgs && isset($files_imgs['name'][$i]) && is_array($files_imgs['name'][$i])) {
                foreach ($files_imgs['name'][$i] as $k=>$fname) {
                    $tmp = $files_imgs['tmp_name'][$i][$k] ?? '';
                    if ($tmp && $this->is_uploaded($tmp) && $fname && $is_valid_img($tmp,$fname)) {
                        $files[] = $move_file($tmp, $fname, 'mri_');
                    }
                }
            }

            if ($iname==='' && $idate==='' && empty($files)) {
                continue;
            }

            $images[] = ['name'=>$iname,'date'=>$idate,'files'=>$files];
        }

        // followups[date][], followups[note][]
        $followups = [];
        $fu_dates = $_POST['followups']['date'] ?? [];
        $fu_notes = $_POST['followups']['note'] ?? [];
        $fu_count = max(count($fu_dates), count($fu_notes));
        for ($i=0; $i<$fu_count; $i++) {
            $d = sanitize_text_field($fu_dates[$i] ?? '');
            $n = sanitize_text_field($fu_notes[$i] ?? '');
            if ($d==='' && $n==='') continue;
            $followups[] = ['date'=>$d,'notes'=>$n];
        }

        $data = [
            'patient_id'       => $patient_id,
            'visit_date'       => $visit_date,
            'visit_reason'     => $visit_reason,
            'doctor'           => $doctor,
            'nurse'            => $nurse,
            'clinic'           => $clinic,
            'visit_type'       => $visit_type,
            'vital_signs'      => $vital_signs,
            'physical_exam'    => $physical_exam,
            'diagnosis_initial'=> $diagnosis_initial,
            'diagnosis_final'  => $diagnosis_final,
            'treatment_plan'   => $treatment_plan,
            'medications'      => json_encode($medications, JSON_UNESCAPED_UNICODE),
            'labs'             => json_encode($labs,        JSON_UNESCAPED_UNICODE),
            'images'           => json_encode($images,      JSON_UNESCAPED_UNICODE),
            'followups'        => json_encode($followups,   JSON_UNESCAPED_UNICODE),
			'history'          => $history,
        ];

        if ($id > 0) {
            $wpdb->update($this->table, $data, ['id' => $id]);
            wp_safe_redirect( add_query_arg(
                ['page'=>'medrec-add-case','edit'=>$id,'updated'=>1],
                admin_url('admin.php')
            ) );
            exit;
        } else {
            $wpdb->insert($this->table, $data);
            $new_id = $wpdb->insert_id;
            wp_safe_redirect( add_query_arg(
                ['page'=>'medrec-add-case','edit'=>$new_id,'created'=>1],
                admin_url('admin.php')
            ) );
            exit;
        }
    }

    /** helper: بررسی آپلود از طریق HTTP POST **/
    private function is_uploaded($tmp) {
        return is_string($tmp) && @is_uploaded_file($tmp);
    }

    /** AJAX بارگذاری پرونده برای مودال **/
    public function ajax_get_case() {
        if ( ! current_user_can( self::CAP_MANAGE ) && ! current_user_can('patient') ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید.' ] );
        }

        global $wpdb;
        $id   = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'شناسه پرونده نامعتبر است.' ] );
        }

        $case = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id=%d", $id ) );
        if ( ! $case ) {
            wp_send_json_error( [ 'message' => 'پرونده یافت نشد.' ] );
        }

        if ( current_user_can('patient') && intval($case->patient_id) !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'شما به این پرونده دسترسی ندارید.' ] );
        }

        $normalize_files_list = function($entry) {
            $out = [];
            if (empty($entry)) return $out;
            if (is_string($entry)) {
                $out[] = $entry;
            } elseif (is_array($entry)) {
                if (!empty($entry['files']) && is_array($entry['files'])) {
                    foreach ($entry['files'] as $f) if ($f) $out[] = $f;
                } else {
                    foreach ($entry as $v) {
                        if (is_string($v) && preg_match('/^https?:\/\//', $v)) {
                            $out[] = $v;
                        } elseif (is_array($v) && !empty($v['files']) && is_array($v['files'])) {
                            foreach ($v['files'] as $f2) if ($f2) $out[] = $f2;
                        }
                    }
                }
            }
            return $out;
        };

        $normalize_meds = function($raw) {
            $out = [];
            if (empty($raw)) return $out;
            if (is_array($raw)) {
                foreach ($raw as $m) {
                    if (is_array($m)) {
                        $name = $m['name'] ?? ($m[0] ?? '');
                        $dose = $m['dose'] ?? ($m[1] ?? '');
                        $warning = $m['warning'] ?? $m['alert'] ?? ($m[2] ?? '');
                        if ($name === '' && $dose === '' && $warning === '') continue;
                        $out[] = ['name'=> $name, 'dose'=> $dose, 'warning'=> $warning];
                    } elseif (is_string($m)) {
                        $out[] = ['name'=> $m, 'dose'=>'', 'warning'=>''];
                    }
                }
            }
            return $out;
        };

        ob_start();
        ?>
        <div class="medrec-case-view">
            <h2>📋 جزئیات پرونده پزشکی</h2>
            <div class="medrec-card">
                <h3>🧍‍♂️ مشخصات بیمار</h3>
                <table class="medrec-table">
                    <tr><th>نام بیمار</th><td><?php echo esc_html( get_userdata($case->patient_id)->display_name ?? '-' ); ?></td></tr>
                    <tr><th>تاریخ مراجعه</th><td><?php echo esc_html( $case->visit_date ); ?></td></tr>
                    <tr><th>پزشک</th><td><?php echo esc_html( $case->doctor ); ?></td></tr>
                    <tr><th>پرستار</th><td><?php echo esc_html( $case->nurse ); ?></td></tr>
                    <tr><th>کلینیک</th><td><?php echo esc_html( $case->clinic ); ?></td></tr>
                    <tr><th>نوع ویزیت</th><td><?php echo esc_html( $case->visit_type ); ?></td></tr>
                    <tr><th>علت مراجعه</th><td><?php echo nl2br( esc_html( $case->visit_reason ) ); ?></td></tr>
                </table>
            </div>

            <div class="medrec-card">
                <h3>❤️ علائم حیاتی</h3>
                <?php
                $vital = json_decode( $case->vital_signs, true );
                if ( ! empty($vital) && is_array($vital) ) : ?>
                    <table class="medrec-table">
                        <?php foreach ( $vital as $k => $v ): ?>
                            <tr><th><?php echo esc_html( $k ); ?></th><td><?php echo esc_html( $v ); ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>اطلاعات ثبت‌شده‌ای در این بخش وجود ندارد.</p>
                <?php endif; ?>
            </div>
			<?php if ( ! empty( $case->physical_exam ) ): ?>
            <div class="medrec-card">
                <h3>🩺 شکایت اصلی</h3>
                <p><?php echo nl2br( esc_html( $case->history ) ); ?></p>
            </div>
            <?php endif; ?>
            <?php if ( ! empty( $case->physical_exam ) ): ?>
            <div class="medrec-card">
                <h3>🩺 معاینه فیزیکی</h3>
                <p><?php echo nl2br( esc_html( $case->physical_exam ) ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $case->diagnosis_initial ) ): ?>
            <div class="medrec-card">
                <h3>🔍 تشخیص اولیه</h3>
                <p><?php echo nl2br( esc_html( $case->diagnosis_initial ) ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $case->diagnosis_final ) ): ?>
            <div class="medrec-card">
                <h3>🧩 تشخیص نهایی</h3>
                <p><?php echo nl2br( esc_html( $case->diagnosis_final ) ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $case->treatment_plan ) ): ?>
            <div class="medrec-card">
                <h3>💊 برنامه درمان</h3>
                <p><?php echo nl2br( esc_html( $case->treatment_plan ) ); ?></p>
            </div>
            <?php endif; ?>

            <div class="medrec-card">
                <h3>💊 داروهای تجویز شده</h3>
                <?php
                $raw_meds = json_decode( $case->medications, true );
                $meds = $normalize_meds($raw_meds);
                if ( ! empty($meds) ) : ?>
                    <table class="medrec-table">
                        <thead><tr><th>نام دارو</th><th>دوز</th><th>هشدار</th></tr></thead>
                        <tbody>
                            <?php foreach ( $meds as $m ): ?>
                                <tr>
                                    <td><?php echo esc_html( $m['name'] ?? '-' ); ?></td>
                                    <td><?php echo esc_html( $m['dose'] ?? '-' ); ?></td>
                                    <td><?php echo esc_html( $m['warning'] ?? '-' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>هیچ دارویی ثبت نشده است.</p>
                <?php endif; ?>
            </div>

            <div class="medrec-card">
                <h3>🧪 آزمایش‌ها</h3>
                <?php
                $raw_labs = json_decode( $case->labs, true );
                if ( ! empty($raw_labs) && is_array($raw_labs) ) : ?>
                    <table class="medrec-table">
                        <thead><tr><th>عنوان</th><th>تاریخ</th><th>فایل‌ها</th></tr></thead>
                        <tbody>
                            <?php foreach ( $raw_labs as $l ) :
                                $title = '';
                                $date = '';
                                $files = [];
                                if (is_string($l) && preg_match('/^https?:\/\//', $l)) {
                                    $files = [$l];
                                } elseif (is_array($l)) {
                                    $title = $l['name'] ?? ($l[0] ?? '');
                                    $date  = $l['date'] ?? ($l[1] ?? '');
                                    $files = $normalize_files_list($l);
                                }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $title ?: '-' ); ?></td>
                                <td><?php echo esc_html( $date ?: '-' ); ?></td>
                                <td>
                                    <?php
                                    if ( ! empty($files) ) {
                                        foreach ( $files as $f ) {
                                            echo '<a href="'.esc_url($f).'" target="_blank"><img src="'.esc_url($f).'" class="medrec-thumb" alt="Lab Image"></a>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>آزمایشی ثبت نشده است.</p>
                <?php endif; ?>
            </div>

            <div class="medrec-card">
                <h3>🩻 تصاویر MRI</h3>
                <?php
                $raw_imgs = json_decode( $case->images, true );
                if ( ! empty($raw_imgs) && is_array($raw_imgs) ) : ?>
                    <table class="medrec-table">
                        <thead><tr><th>عنوان</th><th>تاریخ</th><th>فایل‌ها</th></tr></thead>
                        <tbody>
                            <?php foreach ( $raw_imgs as $img ) {
                                $title = '';
                                $date = '';
                                $files = [];
                                if (is_string($img) && preg_match('/^https?:\/\//', $img)) {
                                    $files = [$img];
                                } elseif (is_array($img)) {
                                    $title = $img['name'] ?? ($img[0] ?? '');
                                    $date  = $img['date'] ?? ($img[1] ?? '');
                                    $files = $normalize_files_list($img);
                                }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $title ?: '-' ); ?></td>
                                <td><?php echo esc_html( $date ?: '-' ); ?></td>
                                <td>
                                    <?php
                                    if ( ! empty($files) ) {
                                        foreach ( $files as $f ) {
                                            echo '<a href="'.esc_url($f).'" target="_blank"><img src="'.esc_url($f).'" class="medrec-thumb" alt="MRI Image"></a>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>تصویری ثبت نشده است.</p>
                <?php endif; ?>
            </div>

            <div class="medrec-card">
                <h3>📅 پیگیری‌ها</h3>
                <?php
                $raw_fu = json_decode( $case->followups, true );
                $followups = [];
                if ( is_array($raw_fu) ) {
                    foreach ($raw_fu as $f) {
                        if (is_array($f)) {
                            $followups[] = [
                                'date' => $f['date'] ?? ($f[0] ?? ''),
                                'notes' => $f['notes'] ?? ($f['note'] ?? ($f[1] ?? ''))
                            ];
                        } elseif (is_string($f)) {
                            $followups[] = ['date'=>'','notes'=>$f];
                        }
                    }
                }
                if ( ! empty($followups) ) : ?>
                    <table class="medrec-table">
                        <thead><tr><th>تاریخ پیگیری</th><th>توضیحات</th></tr></thead>
                        <tbody>
                            <?php foreach ( $followups as $f ): ?>
                                <tr>
                                    <td><?php echo esc_html( $f['date'] ?? '-' ); ?></td>
                                    <td><?php echo nl2br( esc_html( $f['notes'] ?? '-' ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>هیچ پیگیری‌ای ثبت نشده است.</p>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .medrec-case-view { direction: rtl; font-family: IRANSans, sans-serif; color:#222; line-height:1.8; }
        .medrec-card { background:#fff; border-radius:10px; padding:15px 18px; margin-bottom:20px; box-shadow:0 1px 4px rgba(0,0,0,0.08); display:block; width:100%; box-sizing:border-box; }
        .medrec-card h3 { display:block; width:100%; font-size:16px; font-weight:700; color:#0073aa; margin-bottom:10px; border-bottom:2px solid #e5f2fa; padding-bottom:5px; text-align:right; }
        .medrec-table { width:100%; border-collapse:collapse; table-layout:auto; }
        .medrec-table th, .medrec-table td { padding:8px 10px; text-align:right; border-bottom:1px solid #eee; vertical-align:top; }
        .medrec-table th { background:#f7fbff; width:30%; font-weight:600; color:#333; white-space:nowrap; }
        .medrec-thumb { width:50px; height:50px; border-radius:12px; object-fit:cover; cursor:pointer; transition:transform 0.2s, box-shadow 0.2s; box-shadow:0 2px 6px rgba(0,0,0,0.15); }
        .medrec-thumb:hover { transform:scale(1.05); box-shadow:0 4px 10px rgba(0,0,0,0.25); }
        .medrec-lightbox { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.85); justify-content:center; align-items:center; z-index:999999; }
        .medrec-lightbox img { max-width:90%; max-height:90%; border-radius:16px; box-shadow:0 0 20px rgba(255,255,255,0.3); }
        .medrec-table tbody tr td a { margin: 0px 5px; }
        </style>

        <div class="medrec-lightbox" aria-hidden="true"><img src="" alt=""></div>

        <script>
        jQuery(function($){
            $(document).on('click', '.medrec-thumb', function(e){
                e.preventDefault();
                var src = $(this).attr('src') || $(this).closest('a').attr('href');
                if(!src) return;
                $('.medrec-lightbox img').attr('src', src);
                $('.medrec-lightbox').fadeIn(180).attr('aria-hidden','false').css("display","flex");
            });
            $('.medrec-lightbox').on('click', function(){ $(this).fadeOut(150).attr('aria-hidden','true'); });
        });
        </script>
        <?php

        $html = ob_get_clean();
        wp_send_json_success( [ 'html' => $html ] );
    }

    /** جستجوی بیماران برای select2 **/
    public function ajax_search_patients() {
        if (!current_user_can(self::CAP_MANAGE)) wp_send_json([]);

        $term = sanitize_text_field($_GET['q'] ?? '');
        $users = get_users([
            'role' => 'patient',
            'search' => "*{$term}*",
            'search_columns' => ['user_login', 'display_name', 'user_email'],
            'number' => 15,
        ]);

        $results = [];
        foreach ($users as $u) {
            $results[] = [
                'id' => $u->ID,
                'text' => $u->display_name . " ({$u->user_login})"
            ];
        }

        wp_send_json($results);
    }
	public function get_doctors_ajax() {
    global $wpdb;
    $term = sanitize_text_field($_GET['q'] ?? '');
    $doctors = $wpdb->get_results($wpdb->prepare("
        SELECT u.ID, u.display_name, d.subtitle
        FROM {$wpdb->users} u
        INNER JOIN Dj3i_drplus_specialists d ON u.ID = d.user_id
        WHERE d.subtitle != 'پرستار' AND (u.display_name LIKE %s OR u.user_login LIKE %s)
    ", "%$term%", "%$term%"));

    $results = [];
    foreach ($doctors as $doctor) {
        $results[] = [
            'id' => $doctor->display_name,
            'text' => $doctor->display_name . ' (' . $doctor->subtitle . ')'
        ];
    }

    wp_send_json($results);
}

	public function get_nurses_ajax() {
    global $wpdb;
    $term = sanitize_text_field($_GET['q'] ?? '');
    $nurses = $wpdb->get_results($wpdb->prepare("
        SELECT u.ID, u.display_name
        FROM {$wpdb->users} u
        INNER JOIN Dj3i_drplus_specialists d ON u.ID = d.user_id
        WHERE d.subtitle = 'پرستار' AND (u.display_name LIKE %s OR u.user_login LIKE %s)
    ", "%$term%", "%$term%"));

    $results = [];
    foreach ($nurses as $nurse) {
        $results[] = [
            'id' => $nurse->display_name,
            'text' => $nurse->display_name
        ];
    }

    wp_send_json($results);
}


    /** استایل و اسکریپت مودال **/
    public function modal_styles_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($){
            $(document).on('click','.button-view-case',function(){
                var id=$(this).data('id');
                $.post(ajaxurl,{action:'medrec_get_case',id:id},function(res){
                    if(res.success){
                        $('#medrec-case-modal .medrec-modal-content').html(res.data.html);
                        $('#medrec-case-modal').fadeIn(150);
                        $('#medrec-case-modal > div').scrollTop(0);
                    }else{ alert('خطا در بارگذاری پرونده'); }
                });
            });
            $('#medrec-close-modal').on('click',function(){ $('#medrec-case-modal').fadeOut(100); });
            $('#medrec-case-modal').on('click', function(e){ if(e.target==this) $(this).fadeOut(150); });
        });
        </script>
        <style>
        #medrec-case-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:9999;direction:rtl;}
        #medrec-case-modal>div{background:#fff;max-width:900px;margin:40px auto;padding:25px 30px;border-radius:14px;max-height:85vh;overflow-y:auto;position:relative;}
        #medrec-close-modal{position:absolute;top:12px;left:12px;background:#ff5f57;color:#fff;border:none;font-size:18px;width:34px;height:34px;border-radius:50%;cursor:pointer;}
        .medrec-card-section{background:#f9fafc;padding:14px 18px;border-radius:10px;margin-bottom:16px;box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);}
        .medrec-card-section h3{margin-bottom:10px;font-size:16px;font-weight:700;color:#444;}
        .medrec-card-section ul li{background:#fff;padding:6px 10px;border-radius:6px;box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);margin-bottom:4px;}
				.medrec-user-content * {
    font-family: "IRANYekanXFANum";
}
        </style>
        <?php
    }

    /** حذف پرونده و فایل‌های مربوط **/
    public function delete_case() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');
        global $wpdb;
        $upload_dir = wp_upload_dir();

        $id = intval($_GET['id'] ?? 0);
        if(!$id) wp_die('شناسه پرونده معتبر نیست.');

        $case = $wpdb->get_row($wpdb->prepare("SELECT labs, images FROM {$this->table} WHERE id=%d",$id));
        if($case){
            $labs = json_decode($case->labs,true) ?: [];
            foreach($labs as $lab){
                if(!empty($lab['files'])){
                    foreach((array)$lab['files'] as $file_url){
                        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
                        if(file_exists($file_path)) @unlink($file_path);
                    }
                }
            }
            $images = json_decode($case->images,true) ?: [];
            foreach($images as $img){
                if(!empty($img['files'])){
                    foreach((array)$img['files'] as $file_url){
                        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
                        if(file_exists($file_path)) @unlink($file_path);
                    }
                }
            }
        }

        $wpdb->delete($this->table,['id'=>$id]);

        wp_safe_redirect( admin_url('admin.php?page=medrec-cases') );
        exit;
    }

    public function ajax_get_cases_by_patient() {
        if (!current_user_can(self::CAP_MANAGE)) wp_send_json_error();

        global $wpdb;
        $patient_id = intval($_POST['patient_id'] ?? 0);
        $search     = sanitize_text_field($_POST['s'] ?? '');

        if (!$patient_id) wp_send_json_error(['message'=>'invalid patient']);

        $where = $wpdb->prepare("WHERE patient_id = %d", $patient_id);

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND (visit_reason LIKE %s OR doctor LIKE %s OR clinic LIKE %s)", $like, $like, $like);
        }

        $cases = $wpdb->get_results("SELECT * FROM {$this->table} $where ORDER BY visit_date DESC");

        ob_start();
        if ($cases) {
            echo '<table class="widefat striped"><thead><tr>
                    <th>تاریخ مراجعه</th>
                    <th>علت مراجعه</th>
                    <th>پزشک</th>
                    <th>کلینیک</th>
                    <th style="width:180px">عملیات</th>
                  </tr></thead><tbody>';
            foreach ($cases as $c) {
                $edit_url   = esc_url(add_query_arg(['page'=>'medrec-add-case','edit'=>$c->id], admin_url('admin.php')));
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=medrec_delete_case&id='.$c->id), 'medrec_del_'.$c->id);
                echo '<tr>';
                echo '  <td>'.esc_html($c->visit_date).'</td>';
                echo '  <td>'.esc_html($c->visit_reason).'</td>';
                echo '  <td>'.esc_html($c->doctor).'</td>';
                echo '  <td>'.esc_html($c->clinic).'</td>';
                echo '  <td class="medrec-case-actions">
                            <a href="'.$edit_url.'">ویرایش</a> |
                            <a href="#" class="button-view-case" data-id="'.$c->id.'">مشاهده</a> |
                            <a href="'.$delete_url.'" onclick="return confirm(\'حذف این پرونده؟\')" style="color:#d63638;">حذف</a>
                         </td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div>پرونده‌ای یافت نشد.</div>';
        }
        $html = ob_get_clean();
        wp_send_json_success(['html'=>$html]);
    }
	public function render_patient_header_card() {
    if (!is_user_logged_in() || !current_user_can('patient')) return;

    $uid = get_current_user_id();
    $u   = get_userdata($uid);
    $m   = get_user_meta($uid);

    // اطلاعات پایه
    $display = $u->display_name ?: trim(($m['first_name'][0] ?? '') . ' ' . ($m['last_name'][0] ?? ''));
    $national = $m['national_code'][0] ?? '-';
    $birth    = $m['birth_date'][0] ?? '-';
    $gender   = $m['gender'][0] ?? '-';
    $phone    = $m['phone'][0] ?? '-';
    $email    = $u->user_email ?? '-';
    $blood    = $m['blood_group'][0] ?? '-';
    $ins_type = $m['insurance_type'][0] ?? '-';
    $ins_no   = $m['insurance_number'][0] ?? '-';
    $ins_exp  = $m['insurance_expiry'][0] ?? '-';
    $photo    = $m['photo_url'][0] ?? includes_url('images/avatar.png');

    // اطلاعات پزشک و پرستار
    $doctor_id = $m['doctor_id'][0] ?? 0;
    $nurse_id  = $m['nurse_id'][0] ?? 0;

    $doctor = ($doctor_id) ? $this->drplus_get_specialist_profile($doctor_id) : null;
	$nurse  = ($nurse_id) ? $this->drplus_get_specialist_profile($nurse_id) : null;

    // اطلاعات تکمیلی
    $allergies        = $m['allergies']        ?? [];
    $drugs            = $m['drugs']            ?? [];
    $chronic_diseases = $m['chronic_diseases'] ?? [];
    $surgeries        = $m['surgeries']        ?? [];
    $vaccinations     = $m['vaccinations']     ?? [];
	$hospitalizations = $m['hospitalizations'] ?? [];
	$habits           = $m['habits'] ?? [];
	$family_history   = $m['family_history'] ?? [];
	$others           = $m['others'] ?? [];

    // تشخیص پیشخوان یا تب‌های دیگر
    $current      = current_action();
    $is_dashboard = ($current === 'woocommerce_account_dashboard');
    ?>
    <div class="medrec-card-wrapper" dir="rtl">
        <div class="medrec-card-glass">

            <!-- بلاک بالا: تصویر + نام + گروه خونی -->
            <div class="medrec-top">
                <div class="medrec-avatar">
                    <div class="medrec-avatar-inner">
                        <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($display); ?>">
                    </div>
                </div>
				<div class="medrec-values">
					<div class="medrec-name"><?php echo esc_html($display); ?></div>

                <?php if ($blood && $blood !== '-'): ?>
                    <span class="medrec-badge"><?php echo esc_html($blood); ?></span>
                <?php endif; ?>
				</div>
            </div>

            <!-- ستون راست: اطلاعات اصلی کارت -->
            <div class="medrec-right">
                <!-- اطلاعات هویتی و تماس -->
				<div class="medrec-section">
					<div class="medrec-section-title">اطلاعات هویتی و تماس</div>
					<div class="medrec-grid">
						<div class="medrec-item">
							<span class="medrec-label">کد ملی</span>
							<span class="medrec-value"><?php echo esc_html($national); ?></span>
						</div>
						<div class="medrec-item">
							<span class="medrec-label">تاریخ تولد</span>
							<span class="medrec-value"><?php echo esc_html($birth); ?></span>
						</div>
						<div class="medrec-item">
							<span class="medrec-label">جنسیت</span>
							<span class="medrec-value"><?php echo esc_html($gender); ?></span>
						</div>
						<div class="medrec-item">
							<span class="medrec-label">تلفن</span>
							<span class="medrec-value"><?php echo esc_html($phone); ?></span>
						</div>
						<div class="medrec-item">
							<span class="medrec-label">ایمیل</span>
							<span class="medrec-value medrec-value-email"><?php echo esc_html($email); ?></span>
						</div>
					</div>
				</div>
                <!-- بیمه -->
                <div class="medrec-section">
                    <div class="medrec-section-title">اطلاعات بیمه</div>
                    <div class="medrec-grid medrec-grid-ins">
                        <div class="medrec-item">
                            <span class="medrec-label">نوع بیمه</span>
                            <span class="medrec-value"><?php echo esc_html($ins_type); ?></span>
                        </div>
                        <div class="medrec-item">
                            <span class="medrec-label">شماره بیمه</span>
                            <span class="medrec-value"><?php echo esc_html($ins_no); ?></span>
                        </div>
                        <div class="medrec-item">
                            <span class="medrec-label">انقضای بیمه</span>
                            <span class="medrec-value"><?php echo esc_html($ins_exp); ?></span>
                        </div>
                    </div>
                </div>

                <div class="medrec-section">
					<div class="medrec-section-title">اطلاعات تیم پزشکی شخصی</div>
					<div class="medrec-box">
						<?php if ($doctor): ?>
							<div class="medrec-item medrec-team">
								<img src="<?php echo esc_url($doctor['avatar_url']); ?>" class="medrec-avatar-small" alt="پزشک">
								<div class="medic-team-container">
									<div class="medic-team-data">
										<span class="medrec-label">نام پزشک</span>
										<span class="medrec-value"><?php echo esc_html($doctor['full_name']); ?></span>
									</div>
									<div class="medic-team-data">
										<span class="medrec-label">تخصص</span>
										<span class="medrec-value"><?php echo esc_html($doctor['specialty']); ?></span>
									</div>
									<div class="medic-team-data">
										<span class="medrec-label">کد نظام پزشکی</span>
										<span class="medrec-value"><?php echo esc_html($doctor['medical_code']); ?></span>
									</div>
								</div>
							</div>
						<?php endif; ?>

						<?php if ($nurse): ?>
							<div class="medrec-item medrec-team">
								<img src="<?php echo esc_url($nurse['avatar_url']); ?>" class="medrec-avatar-small" alt="پرستار">
								<div class="medic-team-container">
									<div class="medic-team-data">
										<span class="medrec-label">نام پرستار</span>
										<span class="medrec-value"><?php echo esc_html($nurse['full_name']); ?></span>
									</div>
									<div class="medic-team-data">
										<span class="medrec-label">تخصص</span>
										<span class="medrec-value"><?php echo esc_html($nurse['specialty']); ?></span>
									</div>
									<div class="medic-team-data">
										<span class="medrec-label">
											<?php echo (strtolower($nurse['specialty']) === 'پرستار') ? 'کد نظام پرستاری' : 'کد نظام پزشکی'; ?>
										</span>
										<span class="medrec-value"><?php echo esc_html($nurse['medical_code']); ?></span>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>

                <?php if (!$is_dashboard): ?>
                    <!-- اطلاعات پزشکی تکمیلی -->
                    <div class="medrec-section">
                        <div class="medrec-section-title">اطلاعات پزشکی تکمیلی</div>
                        <div class="medrec-grid-med">
                            <?php if (!empty($allergies)): ?>
                                <div class="medrec-item-med">
                                    <span class="medrec-label">سابقه آلرژی</span>
                                    <span class="medrec-value"><?php echo esc_html(implode(', ', (array)$allergies)); ?></span>
                                </div>
                            <?php endif; ?>
							<?php if (!empty($vaccinations)): ?>
                                <div class="medrec-item-med">
                                    <span class="medrec-label">سابقه واکسن</span>
                                    <span class="medrec-value"><?php echo esc_html(implode(', ', (array)$vaccinations)); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($drugs)): ?>
                                <div class="medrec-item-med">
                                    <span class="medrec-label">سابقه دارو</span>
                                    <span class="medrec-value"><?php echo esc_html(implode(', ', (array)$drugs)); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($chronic_diseases)): ?>
                                <div class="medrec-item-med">
                                    <span class="medrec-label">سابقه بیماری ها</span>
                                    <span class="medrec-value"><?php echo esc_html(implode(', ', (array)$chronic_diseases)); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($surgeries)): ?>
                                <div class="medrec-item-med">
                                    <span class="medrec-label">سابقه جراحی</span>
                                    <span class="medrec-value"><?php echo esc_html(implode(', ', (array)$surgeries)); ?></span>
                                </div>
                            <?php endif; ?>
							<?php if (!empty($hospitalizations)): ?>
								<div class="medrec-item-med">
									<span class="medrec-label">سابقه بستری</span>
									<span class="medrec-value"><?php echo esc_html(implode(', ', (array)$hospitalizations)); ?></span>
								</div>
							<?php endif; ?>

							<?php if (!empty($habits)): ?>
								<div class="medrec-item-med">
									<span class="medrec-label">سابقه عادات زندگی</span>
									<span class="medrec-value"><?php echo esc_html(implode(', ', (array)$habits)); ?></span>
								</div>
							<?php endif; ?>

							<?php if (!empty($family_history)): ?>
								<div class="medrec-item-med">
									<span class="medrec-label">‌سابقه خانوادگی</span>
									<span class="medrec-value"><?php echo esc_html(implode(', ', (array)$family_history)); ?></span>
								</div>
							<?php endif; ?>

							<?php if (!empty($others)): ?>
								<div class="medrec-item-med medrec-item-med-full">
									<span class="medrec-label">سایر موارد</span>
									<span class="medrec-value"><?php echo esc_html(implode(', ', (array)$others)); ?></span>
								</div>
							<?php endif; ?>
                            <?php if (
                                empty($allergies) &&
                                empty($drugs) &&
                                empty($chronic_diseases) &&
                                empty($surgeries) &&
                                empty($vaccinations) &&
								empty($hospitalizations) &&
								empty($habits) &&
								empty($family_history) &&
								empty($others)
                            ): ?>
                                <div class="medrec-item-med medrec-item-med-full">
                                    <span class="medrec-label">اطلاعات تکمیلی</span>
                                    <span class="medrec-value">اطلاعات پزشکی تکمیلی ثبت نشده است.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
        .medrec-card-wrapper {
            margin: 0 0 20px 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* کارت شیشه‌ای */
        .medrec-card-glass{
            position:relative;
            width:100%;
            display:flex;
            flex-wrap:wrap;
            gap:20px;
            align-items:stretch;
            padding:18px 20px;
            border-radius:28px;
            background:radial-gradient(100.21% 271.27% at 2.81% 7.08%, rgba(255,252,252,0.85) 0%, rgba(250,250,250,0.35) 100%);
            border:1px solid rgba(255,255,255,0.6);
            backdrop-filter:blur(22px);
            -webkit-backdrop-filter:blur(22px);
        }

        /* بلاک بالا: تصویر + نام + گروه خونی */
        .medrec-top{
            width:100%;
            text-align:center;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            margin-bottom:10px;
            background: #eef6ff;
            padding: 15px 0px;
            border-radius: 20px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            box-shadow: 6px 6px 14px rgba(15, 23, 42, 0.10), -4px -4px 12px rgba(255, 255, 255, 0.9);
        }

        .medrec-avatar{
            width:110px;
            height:110px;
            border-radius:50%;
            padding:6px;
            background:linear-gradient(145deg,#ffffff,#d2e9fb);
            box-shadow:
                8px 8px 18px rgba(0,0,0,0.12),
                -6px -6px 18px rgba(255,255,255,0.9);
        }
        .medrec-avatar-inner{
            width:100%;
            height:100%;
            border-radius:50%;
            background:radial-gradient(circle,#f9fafb,#dbeafe);
            box-shadow:
                inset 4px 4px 8px rgba(148,163,184,0.4),
                inset -4px -4px 10px rgba(255,255,255,0.9);
            overflow:hidden;
        }
        .medrec-avatar-inner img{
            width:100%;
            height:100%;
            object-fit:cover;
            border-radius:50%;
        }

        .medrec-name{
            margin-top:8px;
            font-size:18px;
            font-weight:800;
            color:#01527f;
        }

        .medrec-badge{
            direction:ltr;
            margin-top:4px;
            display:inline-block;
            padding:4px 14px;
            border-radius:999px;
            font-size:11px;
            font-weight:700;
            color:#ffffff;
            background:linear-gradient(135deg,#f97373,#ef4444);
            box-shadow:
                4px 4px 10px rgba(239,68,68,0.4),
                -2px -2px 8px rgba(254,226,226,0.9);
        }

        .medrec-right{
            flex:1 1 260px;
            display:flex;
            flex-direction:column;
            gap:10px;
        }

        .medrec-section{
            padding:15px;
            border-radius:20px;
            background:rgba(237,246,255,0.95);
            border:1px solid rgba(148,163,184,0.4);
            box-shadow:
                6px 6px 14px rgba(15,23,42,0.10),
                -4px -4px 12px rgba(255,255,255,0.9);
        }

        .medrec-section-title{
            font-size:13px;
            font-weight:700;
            color:#0272b1;
            margin-bottom:6px;
            letter-spacing:.05em;
            text-transform:uppercase;
        }

        /* شبکه اطلاعات هویتی و تماس (سه ستونه) */
        .medrec-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* سه ستون */
            gap: 10px 14px;
        }

        .medrec-grid-ins{
            grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
        }

        .medrec-item{
            border-radius:16px;
            padding:8px 12px;
            background:#fff;
            border:1px solid rgba(148,163,184,0.5);
            box-shadow:
                4px 4px 10px rgba(148,163,184,0.45),
                -4px -4px 12px rgba(255,255,255,0.95);
            display:flex;
            flex-direction:column;
            gap:3px;
            min-height:44px;
        }
        .medrec-item-wide{
            grid-column:span 2;
        }

        .medrec-label{
            font-size:11px;
            font-weight:600;
            color:#6b7280;
        }
        .medrec-value{
            font-size:13px;
            font-weight:600;
            color:#111827;
            word-break:break-word;
        }
        .medrec-value-email{
            direction:ltr;
            text-align:left;
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-size:12px;
        }

        /* شبکه اطلاعات پزشکی تکمیلی */
        .medrec-grid-med {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 14px;
        }
        .medrec-item-med{
            border-radius:16px;
            padding:8px 12px;
            background:#fff;
            border:1px solid rgba(148,163,184,0.5);
            box-shadow:
                3px 3px 8px rgba(148,163,184,0.4),
                -3px -3px 8px rgba(255,255,255,0.95);
            display:flex;
            flex-direction:column;
            gap:3px;
        }
        .medrec-item-med-full{
            grid-column:1 / -1;
        }
        .medrec-section-title::before {
            content: "";
            width: 5px;
            height: 10px;
            display: inline-block;
            background: #0373b1;
            margin-left: 7px;
            border-radius: 5px;
        }
		.medrec-item img.medrec-avatar-small {
			border-radius: 10px;
    padding: 3px;
    border: 1px solid #0000004f;
}
		.medrec-item.medrec-team {
    flex-direction: row;
	width:100%;
}
		.medic-team-container {
    margin-right: 10px;
}
		.medrec-box {
    display: flex;
    gap: 0px 15px;
}
        /* ریسپانسیو */
        @media (max-width:768px){
            .medrec-grid-med{
                grid-template-columns: 1fr; /* یک ستون در اندازه‌های کوچکتر */
            }

            .medrec-card-glass{
                flex-direction:column;
                padding:16px 14px;
            }
            .medrec-item-wide{
                grid-column:1 / -1;
            }
			.medrec-item.medrec-team {
				flex-direction: column;
				justify-content: center;
				align-items: center;
			}
			.medrec-item img.medrec-avatar-small {
				width: 60px;
			}
			.medic-team-container {
				margin-right: 0px;
				text-align: center;
			}
			.medrec-box {
				flex-direction: column;
				gap: 10px 0;
			}
			.medrec-item.medrec-team {
				width: auto;
			}
        }

        @media (max-width:520px){
            .medrec-grid{
                grid-template-columns:1fr;
            }
            .medrec-grid-ins{
                grid-template-columns:1fr;
            }
        }
    </style>
    <?php
}
public function drplus_get_specialist_profile($specialist_id) {
    global $wpdb;

    // اسم جدول متخصص‌ها
    $table = $wpdb->prefix . 'drplus_specialists'; // Dj3i_drplus_specialists

    // گرفتن رکورد متخصص از جدول بر اساس user_id
    $spec = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", $specialist_id)
    );

    if (!$spec) {
        return null; // اگر رکوردی پیدا نشد، null برمی‌گرداند
    }

    // حالا می‌توانیم اطلاعات متخصص را بر اساس user_id که درست می‌آید، دریافت کنیم
    $user_id = (int) $spec->user_id; // تبدیل به عدد صحیح
    $user = get_userdata($user_id); // دریافت داده‌های کاربر از روی user_id

    // اگر داده‌های کاربر پیدا نشد
    if (!$user) {
        return null;
    }

    // استخراج "کد نظام پزشکی" از user_meta با کلید 'specialist_code'
    $medical_code = get_user_meta($user_id, 'specialist_code', true); // گرفتن کد نظام پزشکی

    // اگر کد نظام پزشکی موجود نباشد، مقدار پیش‌فرض را نمایش می‌دهیم
    if (empty($medical_code)) {
        $medical_code = 'کد نظام پزشکی موجود نیست';
    }

    return [
        'specialist_id' => (int) $spec->id,
        'user_id'       => $user_id,
        'full_name'     => $user->display_name,
        'specialty'     => $spec->subtitle,
        'avatar_url'    => get_avatar_url($user_id), // استفاده از get_avatar_url برای دریافت آواتار
        'medical_code'  => $medical_code, // مقدار "کد نظام پزشکی"
    ];
}



}

new Med_Records_Cases_Advanced();

/** اطمینان از وجود capability روی نقش مدیر (می‌توانی این را به register_activation_hook منتقل کنی) */
add_action('init', function(){
    if ($role = get_role('administrator')) {
        $role->add_cap('manage_medical_records');
    }
});

/**
 * افزودن منوی "پرونده‌های من" به پنل کاربری ووکامرس
 */
add_filter('woocommerce_account_menu_items', function ($items) {
    if (current_user_can('patient')) {
        $new = ['my-cases' => '📋 پرونده‌های من'];
        $items = array_slice($items, 0, 1, true) + $new + array_slice($items, 1, null, true);
    }
    return $items;
});

/** ثبت endpoint برای پرونده‌ها */
add_action('init', function () {
    add_rewrite_endpoint('my-cases', EP_ROOT | EP_PAGES);
});

/** محتوای تب پرونده‌های من */
add_action('woocommerce_account_my-cases_endpoint', function () {
    if (!current_user_can('patient')) {
        echo 'پرونده پزشکی برای شما تشکیل نشده برای ثبت نام <a href="https://medyarco.com/%d9%be%d8%b1%d9%88%d9%86%d8%af%d9%87-%d9%be%d8%b2%d8%b4%da%a9%db%8c-%d8%af%db%8c%d8%ac%db%8c%d8%aa%d8%a7%d9%84/">کلیک کنید</a>.';

        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'medrec_records';
    $user_id = get_current_user_id();

    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 10;
    $search = sanitize_text_field($_GET['s'] ?? '');
    $offset = ($paged - 1) * $per_page;

    $where = $wpdb->prepare("WHERE patient_id = %d", $user_id);
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(" AND (visit_reason LIKE %s OR doctor LIKE %s OR clinic LIKE %s)", $like, $like, $like);
    }

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $cases = $wpdb->get_results("SELECT * FROM $table $where ORDER BY visit_date DESC LIMIT $offset, $per_page");

    echo '<div class="woocommerce-account-cases">';
    echo '<h2 style="margin-bottom:20px;">📋 پرونده‌های من</h2>';

    echo '<form method="get" class="woocommerce-form" style="margin-bottom:15px;">';
    echo '<input type="hidden" name="my-account-endpoint" value="my-cases">';
    //echo '<input type="text" name="s" placeholder="جستجو در پرونده‌ها..." value="' . esc_attr($search) . '" style="max-width:250px;"> ';
    //echo '<button type="submit" class="button">🔍 جستجو</button>';
    echo '</form>';

    if ($cases) {
        echo '<table class="shop_table shop_table_responsive my_account_orders widefat striped medrec-account-cases-table">';
        echo '<thead><tr>';
        echo '<th>تاریخ مراجعه</th>';
        echo '<th>علت مراجعه</th>';
        echo '<th>پزشک</th>';
        echo '<th>کلینیک</th>';
        echo '<th>عملیات</th>';
        echo '</tr></thead><tbody>';

        foreach ($cases as $c) {
            echo '<tr>';
            echo '<td data-label="تاریخ مراجعه">' . esc_html($c->visit_date) . '</td>';
            echo '<td data-label="علت مراجعه">' . esc_html($c->visit_reason) . '</td>';
            echo '<td data-label="پزشک">' . esc_html($c->doctor) . '</td>';
            echo '<td data-label="کلینیک">' . esc_html($c->clinic) . '</td>';
            echo '<td><a href="#" class="button view-case" data-id="' . esc_attr($c->id) . '">مشاهده</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1) {
            echo '<div class="woocommerce-pagination" style="margin-top:20px;">';
            echo paginate_links([
                'base' => add_query_arg(['paged' => '%#%']),
                'format' => '?paged=%#%',
                'total' => $total_pages,
                'current' => $paged,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            echo '</div>';
        }
    } else {
        echo '<p>پرونده‌ای یافت نشد.</p>';
    }

    echo '<div id="medrec-user-modal" style="display:none;">
            <div class="medrec-user-modal-inner">
                <button id="medrec-user-close">×</button>
                <div class="medrec-user-content"></div>
            </div>
        </div>';

    echo '</div>';
});

/** اسکریپت AJAX برای مشاهده جزئیات پرونده در حساب کاربری */
add_action('wp_footer', function () {
    if (!is_account_page() || !is_user_logged_in()) return;
    if (!current_user_can('patient')) return;
    ?>
    <script>
    jQuery(function($){
        $(document).on('click','.view-case',function(e){
            e.preventDefault();
            var id = $(this).data('id');
            $.post('<?php echo admin_url('admin-ajax.php'); ?>',{action:'medrec_get_case',id:id},function(res){
                if(res.success){
                    $('#medrec-user-modal .medrec-user-content').html(res.data.html);
                    $('#medrec-user-modal').fadeIn(150);
                    $('#medrec-user-modal .medrec-user-modal-inner').scrollTop(0);
                } else {
                    alert('خطا در بارگذاری پرونده');
                }
            });
        });
        $('#medrec-user-close').on('click',function(){
            $('#medrec-user-modal').fadeOut(100);
        });
        $('#medrec-user-modal').on('click', function(e){
            if(e.target==this) $(this).fadeOut(100);
        });
    });
    </script>
    <style>
		.woocommerce-account-cases form.woocommerce-form {
    display: flex;
}
		.woocommerce-account-cases form.woocommerce-form input[type="text"] {
    background: #fff;
    margin-left: 10px;
}
/* استایل عمومی برای جدول پرونده‌ها */
.medrec-account-cases-table {
    border: 1px solid #ccc;
    border-collapse: collapse;
    margin: 0;
    padding: 0;
    width: 100%;
    table-layout: fixed;
}
.medrec-account-cases-table a.button.view-case {
    padding: 3px 16px;
}
.medrec-account-cases-table caption {
    font-size: 1.5em;
    margin: .5em 0 .75em;
}

.medrec-account-cases-table tr {
    background-color: #f8f8f8;
    border: 1px solid #ddd;
    padding: .35em;
}

.medrec-account-cases-table th,
.medrec-account-cases-table td {
    padding: .625em;
    text-align: center;
}

.medrec-account-cases-table th {
    font-size: .85em;
    letter-spacing: .1em;
    text-transform: uppercase;
}

/* استایل برای حالت موبایل */
@media screen and (max-width: 600px) {
    .medrec-account-cases-table {
        border: 0;
    }

    .medrec-account-cases-table caption {
        font-size: 1.3em;
    }
    
    .medrec-account-cases-table thead {
        border: none;
        clip: rect(0 0 0 0);
        height: 1px;
        margin: -1px;
        overflow: hidden;
        padding: 0;
        position: absolute;
        width: 1px;
    }
    
    .medrec-account-cases-table tr {
        border-bottom: 3px solid #ddd;
        display: block;
        margin-bottom: .625em;
    }
    
    .medrec-account-cases-table td {
        border-bottom: 1px solid #ddd;
        display: block;
        font-size: .8em;
        text-align: right;
    }
    
    .medrec-account-cases-table td::before {
        content: attr(data-label);
        float: left;
        font-weight: bold;
        text-transform: uppercase;
    }
    
    .medrec-account-cases-table td:last-child {
        border-bottom: 0;
    }
	a.button.view-case {
    display: block;
    width: 100%;
}
}


    #medrec-user-modal {
        display:none; position:fixed; top:0; right:0; width:100%; height:100%;
        background:rgba(0,0,0,.6); z-index:9999; direction:rtl; font-family:inherit;
    }
    #medrec-user-modal .medrec-user-modal-inner {
        background:#fff; max-width:800px; margin:50px auto; padding:25px; border-radius:12px;
        max-height:85vh; overflow-y:auto; position:relative; box-shadow:0 4px 20px rgba(0,0,0,0.15);
    }
    #medrec-user-close {
        position:absolute; top:12px; left:12px; border:none; background:#ff5f57; color:white;
        border-radius:50%; width:32px; height:32px; font-size:18px; cursor:pointer;
    }
		.medrec-user-content * {
    font-family: "IRANYekanXFANum";
}
    </style>
    <?php
});
