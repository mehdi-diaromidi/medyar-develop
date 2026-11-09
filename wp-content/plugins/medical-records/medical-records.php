<?php
/**
 * Plugin Name: پرونده پزشکی - Medical Records
 * Description: مدیریت بیماران — نقش بیمار، فرم افزودن/ویرایش با نام و نام خانوادگی جدا، فیلدهای تکرارشونده، آپلود عکس (خارج از رسانه)، بیمه و گروه خونی به‌صورت select، و مشاهده پروفایل در مودال. همچنین ساخت خودکار جدول پرونده‌ها و include فایل پرونده‌ها در صورت وجود.
 * Version: 1.7
 * Author: دستیار شما
 * Text Domain: med-records-fa
 */

if (!defined('ABSPATH')) exit;

class Med_Records_Plugin {
    const ROLE_SLUG   = 'patient';
    const CAP_MANAGE  = 'manage_medical_records';
    const UPLOAD_SUBDIR = 'medrec';

    public function __construct() {
        // فعال‌سازی / غیرفعال‌سازی
        register_activation_hook(__FILE__, [ $this, 'on_activate' ]);
        register_deactivation_hook(__FILE__, [ $this, 'on_deactivate' ]);

        // منوها و صفحات ادمین
        add_action('admin_menu', [ $this, 'admin_menus' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);

        // عملیات ذخیره / حذف بیمار
        add_action('admin_post_medrec_save_patient', [ $this, 'save_patient' ]);
        add_action('admin_post_medrec_delete_patient', [ $this, 'delete_patient' ]);

        // AJAX برای مودال پروفایل
        add_action('wp_ajax_medrec_get_profile', [ $this, 'ajax_get_profile' ]);
		add_action('wp_ajax_medrec_get_doctors', [ $this, 'get_doctors_ajax' ]);
   		add_action('wp_ajax_medrec_get_nurses', [ $this, 'get_nurses_ajax' ]);

        // در صورت وجود فایل پرونده‌ها، آن را include کن
        $cases_file = plugin_dir_path(__FILE__) . 'medrec-case.php';
        if (file_exists($cases_file)) {
            include_once $cases_file;
        }
    }

    /**
     * فعال‌سازی پلاگین
     * - اضافه کردن نقش بیمار
     * - اضافه کردن capability به administrator
     * - ایجاد جدول پرونده‌ها در دیتابیس (dbDelta)
     */
    public function on_activate() {
        // نقش بیمار
        add_role(self::ROLE_SLUG, 'بیمار', ['read' => true]);

        // افزودن capability به administrator
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP_MANAGE)) {
            $admin->add_cap(self::CAP_MANAGE);
        }

        // ایجاد جدول پرونده‌ها در دیتابیس (اگر فایل medrec-records.php از ساخت جدول استفاده نکرده بود)
        global $wpdb;
        $table = $wpdb->prefix . 'medrec_records';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id BIGINT UNSIGNED NOT NULL,
            visit_date VARCHAR(32) DEFAULT '',
            visit_reason TEXT,
            doctor VARCHAR(255) DEFAULT '',
            nurse VARCHAR(255) DEFAULT '',
            clinic VARCHAR(255) DEFAULT '',
            visit_type VARCHAR(50) DEFAULT '',
            vital_signs LONGTEXT,
            physical_exam LONGTEXT,
            diagnosis_initial LONGTEXT,
            diagnosis_final LONGTEXT,
            treatment_plan LONGTEXT,
            medications LONGTEXT,
            labs LONGTEXT,
            images LONGTEXT,
            followups LONGTEXT,
			history LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";


        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

    }

    /**
     * غیرفعال‌سازی پلاگین
     * - حذف capability از administrator (اختیاری)
     */
    public function on_deactivate() {
        $admin = get_role('administrator');
        if ($admin && $admin->has_cap(self::CAP_MANAGE)) {
            $admin->remove_cap(self::CAP_MANAGE);
        }
        // توجه: نقش بیمار را حذف نمی‌کنیم تا داده‌ها حفظ شوند
    }

    /** منوهای ادمین اصلی (پرونده پزشکی) **/
    public function admin_menus() {
        add_menu_page('پرونده پزشکی', 'پرونده پزشکی', self::CAP_MANAGE, 'medrec-main', [ $this, 'page_patients' ], 'dashicons-heart', 30);
        add_submenu_page('medrec-main', 'بیماران', 'بیماران', self::CAP_MANAGE, 'medrec-patients', [ $this, 'page_patients' ]);
        add_submenu_page(null, 'افزودن بیمار', 'افزودن بیمار', self::CAP_MANAGE, 'medrec-add', [ $this, 'page_add_patient' ]);

        // در صورتی که فایل پرونده‌ها وجود داشته باشد، آن کلاس خودش زیرمنوها را اضافه می‌کند
    }

    /** enqueue استایل‌ها و اسکریپت‌ها (فقط صفحات افزونه) **/
    public function enqueue_assets($hook) {
        // فقط در صفحات مربوط به medrec
        if (strpos($hook, 'medrec') === false && strpos($_GET['page'] ?? '', 'medrec') === false) return;

        wp_enqueue_script('jquery');
        wp_enqueue_style('dashicons');
        wp_enqueue_style('jalalidatepicker-css', 'https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css');
        wp_enqueue_script('jalalidatepicker-js', 'https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js', ['jquery'], null, true);
		add_action('admin_enqueue_scripts', function() {
            wp_enqueue_script('select2');
            wp_enqueue_style('select2');
        });
        wp_enqueue_script(
			'medrecscript-js',
			plugins_url('/assets/js/medrec-admin.js', __FILE__),
			['jquery', 'select2'],
			null,
			true
		);

        // استایل‌های داخلی
        $css = '
        .form-table { width:100%; border-collapse:collapse; margin-bottom:18px; direction:rtl; }
        .form-table td { vertical-align:top; padding:10px; }
        .form-table input, .form-table select, .form-table textarea { width:100%; box-sizing:border-box; padding:7px; border:1px solid #dfe7ef; border-radius:4px; height:38px; display:block;}
        .form-table textarea { height:90px; resize:vertical; }
        .medrec-section-title { display:block; background:#f7fafc; border:1px solid #e2e8f0; border-right:6px solid #3b82f6; padding:10px 14px; border-radius:6px; margin-top:20px; margin-bottom:12px; font-size:16px; font-weight:700; direction:rtl; }

        .repeat-wrap { margin-bottom:12px; }
        .repeat-wrap .items .repeat-item { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
        .repeat-wrap .repeat-item input { flex:1; height:38px; }

        .preview { max-width:120px; display:block; margin-bottom:8px; border-radius:6px; }

        .medrec-modal-overlay {
            position: fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.45);
            display:none; justify-content:center; align-items:center; z-index:99999;
        }
        .medrec-modal {
            background: #fff;
            padding: 35px;
            border-radius: 14px;
            max-width: 820px;
            width: 96%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.22);
            max-height: 90vh;
            overflow: auto;
            direction: rtl;
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            bottom: 0;
            margin: auto;
        }
        /* مودال پیشرفته */
        #medrec-case-modal {
            display: none;
            position: fixed;
            top:0; left:0;
            width:100%; height:100%;
            background: rgba(0,0,0,0.55);
            z-index: 9999;
            direction: rtl;
            font-family: Tahoma, sans-serif;
        }

        #medrec-case-modal > div {
            background: #fff;
            max-width: 900px;
            margin: 40px auto;
            padding: 25px 30px;
            border-radius: 14px;
            position: relative;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 10px 35px rgba(0,0,0,0.28);
        }

        #medrec-close-modal {
            position: absolute;
            top: 12px; right: 12px;
            background: #ff5f57;
            color: #fff;
            border: none;
            font-size: 18px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            line-height: 0;
            text-align: center;
        }

        /* هدر مودال */
        #medrec-modal-content h2 {
            text-align: center;
            margin-bottom: 20px;
            font-weight: 700;
            color: #222;
        }

        /* کارت‌ها */
        .medrec-card-section {
            background: #f9fafc;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);
        }

        .medrec-card-section h3 {
            margin-bottom: 10px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #444;
        }

        .medrec-card-section p, 
        .medrec-card-section ul {
            margin-bottom: 8px;
        }

        .medrec-card-section ul li {
            background: #fff;
            padding: 6px 10px;
            border-radius: 6px;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
            margin-bottom: 4px;
        }

        /* آیکون Dashicons کنار عنوان بخش */
        .medrec-card-section h3 .dashicons {
            font-size: 18px;
            color: #0073aa;
        }

        /* ریسپانسیو موبایل */
        @media (max-width:700px){
            #medrec-case-modal > div {
                margin: 20px auto;
                padding: 15px;
            }
            .medrec-card-section h3 {
                font-size: 14px;
            }
        }

        span.button-view-profile { cursor: pointer; color: #882dbf; }
        .medrec-close { position:absolute; left:16px; top:14px; background:none; border:none; font-size:20px; cursor:pointer; }
        .medrec-profile-header { display:flex; flex-direction:column; align-items:center; text-align:center; margin-bottom:14px; }
        .medrec-profile-header img { width:120px; height:120px; border-radius:50%; object-fit:cover; box-shadow:0 6px 18px rgba(0,0,0,0.12); margin-bottom:10px; }
        .medrec-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-top:8px; }
        .medrec-card { background:#f9fafc; padding:12px 14px; border-radius:10px; display:flex; gap:10px; align-items:center; box-shadow: inset 0 0 3px rgba(0,0,0,0.03); }
        .medrec-card .dashicons { font-size:20px; color:#0073aa; }
        .medrec-card .meta { display:flex; flex-direction:column; }
        .medrec-card .meta .label { font-weight:700; color:#444; margin-bottom:4px; }
        .medrec-card .meta .value { color:#222; }
        @media (max-width:700px) {
            .medrec-modal { padding:16px; }
            .medrec-profile-header img { width:96px; height:96px; }
        }';
        wp_add_inline_style('jalalidatepicker-css', $css);

        // اسکریپت داخلی: datepicker، repeat fields، preview و مودال
        $js = "
        jQuery(document).ready(function($){
			if ($.fn.select2) {
				$('#medrec_doctor_select').select2({
					ajax: {
						url: ajaxurl,
						dataType: 'json',
						delay: 250,
						data: function (params) {
							return {
								action: 'medrec_get_doctors',
								q: params.term
							};
						},
						processResults: function (data) {
							return { results: data };
						},
						cache: true
					},
					placeholder: 'پزشک را جستجو کنید...',
					minimumInputLength: 2,
					width: 'resolve',
					dir: 'rtl'
				});

				$('#medrec_nurse_select').select2({
					ajax: {
						url: ajaxurl,
						dataType: 'json',
						delay: 250,
						data: function (params) {
							return {
								action: 'medrec_get_nurses',
								q: params.term
							};
						},
						processResults: function (data) {
							return { results: data };
						},
						cache: true
					},
					placeholder: 'پرستار را جستجو کنید...',
					minimumInputLength: 2,
					width: 'resolve',
					dir: 'rtl'
				});
			}

            if (typeof flatpickr !== 'undefined') {
                $('.medrec-date').each(function(){
                    try {
                        flatpickr(this, { dateFormat:'Y/m/d', locale:'fa', calendarType:'jalali', disableMobile:true });
                    } catch(e) { /* ignore */ }
                });
            }

            // repeatable: add
            $(document).on('click', '.add-item', function(e){
                e.preventDefault();
                var wrap = $(this).closest('.repeat-wrap');
                var template = wrap.find('.template').html();
                wrap.find('.items').append(template);
            });

            // repeatable: remove
            $(document).on('click', '.remove-item', function(e){
                e.preventDefault();
                $(this).closest('.repeat-item').remove();
            });

            // preview local file
            $(document).on('change', 'input[type=\"file\"][name=\"patient_photo\"]', function(e){
                var input = this;
                if (input.files && input.files[0]) {
                    var reader = new FileReader();
                    var preview = $(input).closest('td').find('.preview');
                    reader.onload = function(ev){ preview.attr('src', ev.target.result).show(); };
                    reader.readAsDataURL(input.files[0]);
                }
            });

            // modal: load profile via AJAX
            $(document).on('click', '.button-view-profile', function(e){
                e.preventDefault();
                var id = $(this).data('id');
                $.post(ajaxurl, { action: 'medrec_get_profile', id: id }, function(res){
                    if (res && res.success) {
                        $('.medrec-modal-content').html(res.data.html);
                        $('.medrec-modal-overlay').fadeIn(140);
                        $('.medrec-modal').scrollTop(0);
                    } else {
                        alert('خطا در بارگذاری پروفایل');
                    }
                });
            });
            $(document).on('click', '.medrec-close', function(){ $('.medrec-modal-overlay').fadeOut(140); });
            $(document).on('click', '.medrec-modal-overlay', function(e){ if (e.target === this) $(this).fadeOut(140); });
        });
        ";
        wp_add_inline_script('medrecscript-js', $js);
    }

    /** صفحه لیست بیماران **/
    public function page_patients() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');
        echo '<div class="wrap"><h1>لیست بیماران</h1>';
        echo '<a class="button-primary" href="'.esc_url(admin_url('admin.php?page=medrec-add')).'">+ افزودن بیمار</a><br><br>';

        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        $args = ['role' => self::ROLE_SLUG, 'number' => $per_page, 'paged' => $paged];
        if ($search !== '') {
            $args['search'] = '*'.$search.'*';
            $args['search_columns'] = ['user_login','display_name','user_email'];
        }
        $users = new WP_User_Query($args);
        $total = $users->get_total();

        echo '<form method="get" style="margin-bottom:12px;"><input type="hidden" name="page" value="medrec-patients"><input type="search" name="s" placeholder="جستجوی بیمار" value="'.esc_attr($search).'"> <button class="button">جستجو</button></form>';

        echo '<table class="widefat"><thead><tr><th>نام بیمار</th><th>کدملی</th><th>ایمیل</th><th>عملیات</th></tr></thead><tbody>';
        if (!empty($users->results)) {
            foreach ($users->results as $u) {
                $meta = get_user_meta($u->ID);
                $display = esc_html($u->display_name ?: trim(($meta['first_name'][0] ?? '') . ' ' . ($meta['last_name'][0] ?? '')));
                $national = esc_html($meta['national_code'][0] ?? '');
                $edit_url = esc_url(add_query_arg(['page'=>'medrec-add','edit'=>$u->ID], admin_url('admin.php')));
                $delete_action = 'admin-post.php?action=medrec_delete_patient&id=' . intval($u->ID);
                $delete_url = esc_url(wp_nonce_url(admin_url($delete_action), 'medrec_delete_' . intval($u->ID)));
                echo '<tr>';
                echo '<td>'.$display.'</td>';
                echo '<td>'.$national.'</td>';
                echo '<td>'.esc_html($u->user_email).'</td>';
                echo '<td><a href="'.$edit_url.'">ویرایش</a> | <span class="button-view-profile" data-id="'.intval($u->ID).'">مشاهده پروفایل</span> | <a href="'.$delete_url.'" onclick="return confirm(\'آیا از حذف این بیمار مطمئن هستید؟\');">حذف</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4">بیماری یافت نشد.</td></tr>';
        }
        echo '</tbody></table>';

        $total_pages = (int) ceil($total / $per_page);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged','%#%'),
                'format' => '?paged=%#%',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $total_pages,
                'current' => $paged,
                'add_args' => ['page' => 'medrec-patients']
            ));
            echo '</div></div>';
        }

        echo '</div>';

        // مودال (مخفی)
        echo '<div class="medrec-modal-overlay"><div class="medrec-modal"><button class="medrec-close dashicons dashicons-no"></button><div class="medrec-modal-content"></div></div></div>';
    }

    /** صفحه افزودن/ویرایش بیمار **/
    public function page_add_patient() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $editing = $edit_id > 0;
        $user_data = $editing ? get_userdata($edit_id) : null;
        $meta = $editing ? get_user_meta($edit_id) : [];

        $old = [];
        $fields = [
			'first_name','last_name','national_code','father_name','birth_date','gender','marital_status','phone','address','photo_url',
			'insurance_type','insurance_number','insurance_expiry','emergency_name','emergency_phone','emergency_relation','blood_group',
			'doctor_id','nurse_id' // 👈 این دو تا جدید
		];

        foreach ($fields as $f) {
            $old[$f] = $meta[$f][0] ?? '';
        }
        $old['username'] = $user_data->user_login ?? '';
        $old['email'] = $user_data->user_email ?? '';

        $multi_keys = ['allergies','drugs','chronic_diseases','surgeries','vaccinations',
               'hospitalizations','habits','family_history','others'];
        foreach ($multi_keys as $m) {
            $old[$m] = $meta[$m] ?? [''];
            if (!is_array($old[$m])) $old[$m] = [$old[$m]];
        }

        $insurances = ['تأمین اجتماعی','خدمات درمانی','نیروهای مسلح','سلامت','ایران','آسیا','البرز','کوثر','دانا','ما','پاسارگاد','ملت'];
        $bloods = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
        $maritals = ['مجرد','متأهل','سایر']; // 💍 وضعیت تأهل

        ?>
        <div class="wrap">
            <h1><?php echo $editing ? 'ویرایش بیمار' : 'افزودن بیمار جدید'; ?></h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('medrec_save_patient'); ?>
                <input type="hidden" name="action" value="medrec_save_patient">
                <?php if ($editing): ?>
                    <input type="hidden" name="user_id" value="<?php echo intval($edit_id); ?>">
                <?php endif; ?>

                <span class="medrec-section-title">👤 مشخصات فردی</span>
                <table class="form-table" role="presentation">
                    <tr>
                        <td>
                            <label>نام *</label>
                            <input name="first_name" required value="<?php echo esc_attr($old['first_name']); ?>">
                        </td>
                        <td>
                            <label>نام خانوادگی *</label>
                            <input name="last_name" required value="<?php echo esc_attr($old['last_name']); ?>">
                        </td>
                        <td>
                            <label>کدملی / شماره پرونده *</label>
                            <input name="national_code" required value="<?php echo esc_attr($old['national_code']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label>نام پدر *</label>
                            <input name="father_name" required value="<?php echo esc_attr($old['father_name']); ?>">
                        </td>
                        <td>
                            <label>تاریخ تولد *</label>
                            <input class="medrec-date" data-jdp name="birth_date" required value="<?php echo esc_attr($old['birth_date']); ?>">
                        </td>
                        <td>
                            <label>جنسیت *</label>
                            <select name="gender" required>
                                <option value="">انتخاب کنید</option>
                                <option value="مرد" <?php selected($old['gender'],'مرد'); ?>>مرد</option>
                                <option value="زن" <?php selected($old['gender'],'زن'); ?>>زن</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label>وضعیت تأهل</label>
                            <select name="marital_status">
                                <option value="">انتخاب کنید</option>
                                <?php foreach ($maritals as $m): ?>
                                    <option value="<?php echo esc_attr($m); ?>" <?php selected($old['marital_status'], $m); ?>>
                                        <?php echo esc_html($m); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <label>شماره تماس *</label>
                            <input name="phone" required value="<?php echo esc_attr($old['phone']); ?>">
                        </td>
                        <td>
                            <label>ایمیل *</label>
                            <input name="email" type="email" required value="<?php echo esc_attr($old['email']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label>آدرس *</label>
                            <textarea name="address" required><?php echo esc_textarea($old['address']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label>عکس بیمار</label><br>
                            <?php $photo_url = esc_url($old['photo_url']); ?>
                            <img src="<?php echo $photo_url; ?>" class="preview" style="<?php echo empty($photo_url) ? 'display:none;' : ''; ?>">
                            <input type="file" name="patient_photo" accept="image/*">
                        </td>
                    </tr>
                </table>

                <span class="medrec-section-title">⚕️ اطلاعات بیمه‌ای و مالی</span>
                <table class="form-table" role="presentation">
                    <tr>
                        <td>
                            <label>نوع بیمه</label>
                            <select name="insurance_type">
                                <option value="">انتخاب کنید</option>
                                <?php foreach ($insurances as $ins): ?>
                                    <option value="<?php echo esc_attr($ins); ?>" <?php selected($old['insurance_type'], $ins); ?>>
                                        <?php echo esc_html($ins); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <label>شماره بیمه‌نامه</label>
                            <input name="insurance_number" value="<?php echo esc_attr($old['insurance_number']); ?>">
                        </td>
                        <td>
                            <label>تاریخ اعتبار بیمه</label>
                            <input class="medrec-date" data-jdp name="insurance_expiry" value="<?php echo esc_attr($old['insurance_expiry']); ?>">
                        </td>
                    </tr>
                </table>

                <span class="medrec-section-title">👨‍👩‍👧 اطلاعات تماس اضطراری</span>
                <table class="form-table" role="presentation">
                    <tr>
                        <td><label>نام فرد تماس اضطراری</label><input name="emergency_name" value="<?php echo esc_attr($old['emergency_name']); ?>"></td>
                        <td><label>شماره تماس</label><input name="emergency_phone" value="<?php echo esc_attr($old['emergency_phone']); ?>"></td>
                        <td><label>نسبت</label><input name="emergency_relation" value="<?php echo esc_attr($old['emergency_relation']); ?>"></td>
                    </tr>
                </table>
				<span class="medrec-section-title">⚕️ پزشک و پرستار اختصاصی</span>
				<table class="form-table" role="presentation">
					<tr>
						<td>
							<label for="medrec_doctor_select">پزشک اختصاصی</label>
							<select id="medrec_doctor_select" name="doctor_id" style="width:100%;">
								<option value="">انتخاب کنید</option>
								<?php
								$doctor_id = !empty($old['doctor_id']) ? intval($old['doctor_id']) : 0;
								if ($doctor_id) {
									$duser = get_userdata($doctor_id);
									$dtext = $duser ? $duser->display_name : ('کاربر #' . $doctor_id);
									?>
									<option value="<?php echo esc_attr($doctor_id); ?>" selected>
										<?php echo esc_html($dtext); ?>
									</option>
									<?php
								}
								?>
							</select>
						</td>
						<td>
							<label for="medrec_nurse_select">پرستار اختصاصی</label>
							<select id="medrec_nurse_select" name="nurse_id" style="width:100%;">
								<option value="">انتخاب کنید</option>
								<?php
								$nurse_id = !empty($old['nurse_id']) ? intval($old['nurse_id']) : 0;
								if ($nurse_id) {
									$nuser = get_userdata($nurse_id);
									$ntext = $nuser ? $nuser->display_name : ('کاربر #' . $nurse_id);
									?>
									<option value="<?php echo esc_attr($nurse_id); ?>" selected>
										<?php echo esc_html($ntext); ?>
									</option>
									<?php
								}
								?>
							</select>
						</td>
						<td></td>
					</tr>
				</table>

                <span class="medrec-section-title">🧬 سابقه پزشکی پایه</span>
                <table class="form-table" role="presentation">
                    <tr>
                        <td>
                            <label>گروه خونی</label>
                            <select name="blood_group">
                                <option value="">انتخاب کنید</option>
                                <?php foreach ($bloods as $b): ?>
                                    <option value="<?php echo esc_attr($b); ?>" <?php selected($old['blood_group'], $b); ?>>
                                        <?php echo esc_html($b); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td></td><td></td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <?php 
                                $this->repeat_field('سابقه آلرژی', 'allergies', $old);
                                $this->repeat_field('سابقه دارو', 'drugs', $old);
                                $this->repeat_field('‌سابقه بیماری', 'chronic_diseases', $old);
                                $this->repeat_field('سابقه جراحی', 'surgeries', $old);
                                $this->repeat_field('‌سابقه واکسن', 'vaccinations', $old);
								$this->repeat_field('سابقه بستری', 'hospitalizations', $old);
								$this->repeat_field('سابقه عادات', 'habits', $old);
								$this->repeat_field('سابقه خانوادگی', 'family_history', $old);
								$this->repeat_field('سایر موارد', 'others', $old);
                            ?>
                        </td>
                    </tr>
                </table>

                <span class="medrec-section-title">🔐 اطلاعات ورود</span>
                <table class="form-table" role="presentation">
                    <tr>
                        <td>
                            <label>نام کاربری <?php echo $editing ? '(غیرقابل تغییر)' : '*'; ?></label>
                            <input name="username" <?php echo $editing ? 'readonly' : 'required'; ?> value="<?php echo esc_attr($old['username']); ?>">
                        </td>
                        <td>
                            <label>رمز عبور <?php echo $editing ? '(در صورت خالی تغییر نمی‌کند)' : '*'; ?></label>
                            <input type="password" name="password" <?php echo $editing ? '' : 'required'; ?>>
                        </td>
                        <td></td>
                    </tr>
                </table>

                <p><button type="submit" class="button-primary"><?php echo $editing ? 'ذخیره تغییرات' : 'ثبت بیمار'; ?></button></p>
            </form>
        </div>
        <?php
    }


    /** تولید بخش فیلدهای تکرارشونده **/
    private function repeat_field($label, $key, $old) {
        $values = $old[$key] ?? [];
        if (!is_array($values)) $values = (array)$values;
        $values = array_filter($values, function($v){ return trim((string)$v) !== ''; });
        if (empty($values)) $values = [''];
        ?>
        <div class="repeat-wrap">
            <h4 style="margin:6px 0;"><?php echo esc_html($label); ?></h4>
            <div class="items">
                <?php foreach ($values as $v): ?>
                    <div class="repeat-item">
                        <input name="<?php echo esc_attr($key); ?>[]" value="<?php echo esc_attr($v); ?>">
                        <button type="button" class="button remove-item">حذف</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="template" style="display:none;">
                <div class="repeat-item">
                    <input name="<?php echo esc_attr($key); ?>[]" value="">
                    <button type="button" class="button remove-item">حذف</button>
                </div>
            </div>
            <p><button type="button" class="button add-item">+ افزودن</button></p>
        </div>
        <?php
    }

    /** ذخیره بیمار **/
    public function save_patient() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');
        check_admin_referer('medrec_save_patient');

        $data = wp_unslash($_POST);
        $edit_id = intval($data['user_id'] ?? 0);
        $editing = $edit_id > 0;

        $first = sanitize_text_field($data['first_name'] ?? '');
        $last = sanitize_text_field($data['last_name'] ?? '');
        $username = sanitize_user($data['username'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $display_name = trim($first . ' ' . $last);

        $errors = [];
        if ($first === '') $errors[] = 'نام الزامی است.';
        if ($last === '') $errors[] = 'نام خانوادگی الزامی است.';
        if (empty($data['national_code'] ?? '')) $errors[] = 'کدملی/شماره پرونده الزامی است.';
        if (empty($email) || !is_email($email)) $errors[] = 'ایمیل معتبر نیست.';
        if (!$editing && empty($username)) $errors[] = 'نام کاربری الزامی است.';
        if (!$editing && username_exists($username)) $errors[] = 'نام کاربری قبلاً استفاده شده است.';
        if (!$editing && !empty($email) && email_exists($email)) $errors[] = 'ایمیل قبلاً استفاده شده است.';
        if (!$editing && empty($password)) $errors[] = 'رمز عبور الزامی است.';

        if ($editing) {
            $user_obj = get_userdata($edit_id);
            if (!$user_obj) $errors[] = 'کاربر یافت نشد.';
            else {
                if ($user_obj->user_login !== $username && username_exists($username)) $errors[] = 'این نام کاربری قبلاً موجود است.';
                if ($user_obj->user_email !== $email && email_exists($email)) $errors[] = 'این ایمیل قبلاً استفاده شده است.';
            }
        }

        if (!empty($errors)) {
            $msg = '<div class="medrec-errors"><strong>خطا(ها):</strong><ul>';
            foreach ($errors as $e) $msg .= '<li>'.esc_html($e).'</li>';
            $msg .= '</ul></div>';
            wp_die($msg . '<p><a href="javascript:history.back()">بازگشت</a></p>');
        }

        if (!$editing) {
            $user_insert = [
                'user_login' => $username,
                'user_email' => $email,
                'display_name' => $display_name,
                'role' => self::ROLE_SLUG,
                'user_pass' => $password,
            ];
            $res = wp_insert_user($user_insert);
        } else {
            $user_update = [
                'ID' => $edit_id,
                'user_email' => $email,
                'display_name' => $display_name,
            ];
            if (!empty($password)) $user_update['user_pass'] = $password;
            $res = wp_update_user($user_update);
        }

        if (is_wp_error($res)) {
            wp_die('خطا در ذخیرهٔ کاربر: ' . esc_html($res->get_error_message()));
        }

        $user_id = intval($res);

        // متاهای ساده
		$meta_fields = [
			'first_name','last_name','national_code','father_name','birth_date','gender','marital_status',
			'phone','address','insurance_type','insurance_number','insurance_expiry','emergency_name','emergency_phone','emergency_relation','blood_group'
		];
		foreach ($meta_fields as $m) {
			update_user_meta($user_id, $m, sanitize_text_field($data[$m] ?? ''));
		}

		// ذخیره پزشک و پرستار به‌صورت ID
		$doctor_id = isset($data['doctor_id']) ? intval($data['doctor_id']) : 0;
		$nurse_id  = isset($data['nurse_id'])  ? intval($data['nurse_id'])  : 0;

		update_user_meta($user_id, 'doctor_id', $doctor_id);
		update_user_meta($user_id, 'nurse_id',  $nurse_id);


        // چندتایی‌ها
        $multi = ['allergies','drugs','chronic_diseases','surgeries','vaccinations',
          'hospitalizations','habits','family_history','others'];
        foreach ($multi as $k) {
            delete_user_meta($user_id, $k);
            if (!empty($data[$k]) && is_array($data[$k])) {
                foreach ($data[$k] as $val) {
                    $vtrim = trim($val);
                    if ($vtrim !== '') add_user_meta($user_id, $k, sanitize_text_field($vtrim));
                }
            }
        }

        // آپلود عکس به uploads/medrec و ذخیره URL در متا (بدون ایجاد attachment)
        if (!empty($_FILES['patient_photo']['name'])) {
            $uploaded = $_FILES['patient_photo'];
            $upload_dir = wp_upload_dir();
            $target_dir = trailingslashit($upload_dir['basedir']) . self::UPLOAD_SUBDIR . '/';
            wp_mkdir_p($target_dir);

            $filename = sanitize_file_name($uploaded['name']);
            $filename = time() . '_' . $filename;
            $target_path = $target_dir . $filename;

            if (move_uploaded_file($uploaded['tmp_name'], $target_path)) {
                $photo_url = trailingslashit($upload_dir['baseurl']) . self::UPLOAD_SUBDIR . '/' . $filename;
                update_user_meta($user_id, 'photo_url', esc_url_raw($photo_url));
            }
        }

        wp_redirect(admin_url('admin.php?page=medrec-patients&updated=1'));
        exit;
    }

    /** حذف بیمار **/
    public function delete_patient() {
        if (!current_user_can(self::CAP_MANAGE)) wp_die('دسترسی ندارید.');
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) wp_die('شناسه نامعتبر است.');
        check_admin_referer('medrec_delete_' . $id);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($id);
        wp_redirect(admin_url('admin.php?page=medrec-patients&deleted=1'));
        exit;
    }

    /** AJAX: برگرداندن HTML مودال پروفایل برای یک کاربر **/
    public function ajax_get_profile() {
        if (!current_user_can(self::CAP_MANAGE)) wp_send_json_error(['message'=>'دسترسی ندارید']);
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message'=>'شناسه نامعتبر']);
        $u = get_userdata($id);
        if (!$u) wp_send_json_error(['message'=>'کاربر یافت نشد']);

        $m = get_user_meta($id);

        ob_start();
        ?>
        <div class="medrec-profile-header">
            <?php $photo = $m['photo_url'][0] ?? ''; ?>
            <?php if (!empty($photo)): ?>
                <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($u->display_name); ?>">
            <?php else: ?>
                <img src="<?php echo esc_url(includes_url('images/avatar.png')); ?>" alt="بدون عکس">
            <?php endif; ?>
            <h2><?php echo esc_html($u->display_name ?: trim(($m['first_name'][0] ?? '') . ' ' . ($m['last_name'][0] ?? ''))); ?></h2>
        </div>

        <div class="medrec-info-grid">
            <div class="medrec-card"><span class="dashicons dashicons-id"></span><div class="meta"><span class="label">کدملی</span><span class="value"><?php echo esc_html($m['national_code'][0] ?? '-'); ?></span></div></div>
            <div class="medrec-card"><span class="dashicons dashicons-email"></span><div class="meta"><span class="label">ایمیل</span><span class="value"><?php echo esc_html($u->user_email ?? '-'); ?></span></div></div>
            <div class="medrec-card"><span class="dashicons dashicons-phone"></span><div class="meta"><span class="label">شماره تماس</span><span class="value"><?php echo esc_html($m['phone'][0] ?? '-'); ?></span></div></div>
            <div class="medrec-card"><span class="dashicons dashicons-location"></span><div class="meta"><span class="label">آدرس</span><span class="value"><?php echo esc_html($m['address'][0] ?? '-'); ?></span></div></div>

            <div class="medrec-card"><span class="dashicons dashicons-businessman"></span><div class="meta"><span class="label">نام پدر</span><span class="value"><?php echo esc_html($m['father_name'][0] ?? '-'); ?></span></div></div>
            <div class="medrec-card"><span class="dashicons dashicons-calendar"></span><div class="meta"><span class="label">تاریخ تولد</span><span class="value"><?php echo esc_html($m['birth_date'][0] ?? '-'); ?></span></div></div>
            <div class="medrec-card"><span class="dashicons dashicons-forms"></span><div class="meta"><span class="label">وضعیت تأهل</span><span class="value"><?php echo esc_html($m['marital_status'][0] ?? '-'); ?></span></div></div>
            <div class="medrec-card"><span class="dashicons dashicons-shield"></span><div class="meta"><span class="label">نوع بیمه</span><span class="value"><?php echo esc_html($m['insurance_type'][0] ?? '-'); ?></span></div></div>

            <div class="medrec-card"><span class="dashicons dashicons-heart"></span><div class="meta"><span class="label">گروه خونی</span><span class="value"><?php echo esc_html($m['blood_group'][0] ?? '-'); ?></span></div></div>
			<?php
$doctor_id = !empty($m['doctor_id'][0]) ? intval($m['doctor_id'][0]) : 0;
$nurse_id  = !empty($m['nurse_id'][0])  ? intval($m['nurse_id'][0])  : 0;

$doctor_name = '-';
$nurse_name  = '-';

if ($doctor_id) {
    $duser = get_userdata($doctor_id);
    if ($duser) $doctor_name = $duser->display_name;
}

if ($nurse_id) {
    $nuser = get_userdata($nurse_id);
    if ($nuser) $nurse_name = $nuser->display_name;
}
?>

<div class="medrec-card">
    <span class="dashicons dashicons-stethoscope"></span>
    <div class="meta">
        <span class="label">پزشک اختصاصی</span>
        <span class="value"><?php echo esc_html($doctor_name); ?></span>
    </div>
</div>

<div class="medrec-card">
    <span class="dashicons dashicons-groups"></span>
    <div class="meta">
        <span class="label">پرستار اختصاصی</span>
        <span class="value"><?php echo esc_html($nurse_name); ?></span>
    </div>
</div>
            <div class="medrec-card"><span class="dashicons dashicons-admin-users"></span><div class="meta"><span class="label">تماس اضطراری</span><span class="value"><?php echo esc_html(($m['emergency_name'][0] ?? '-') . ' — ' . ($m['emergency_phone'][0] ?? '-')); ?></span></div></div>
        </div>

        <?php
        $repeat_keys = ['allergies' => 'آلرژی‌ها', 'drugs' => 'داروهای مصرفی', 'chronic_diseases' => 'بیماری‌های مزمن', 'surgeries' => 'سوابق جراحی', 'vaccinations' => 'واکسن‌ها'];
        echo '<div style="margin-top:18px;">';
        foreach ($repeat_keys as $key => $label) {
            $items = get_user_meta($id, $key);
            if (!empty($items)) {
                echo '<h3 style="margin-bottom:6px;margin-top:10px;">' . esc_html($label) . '</h3>';
                echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
                foreach ($items as $it) {
                    if (trim($it) === '') continue;
                    echo '<div style="background:#fff; border:1px solid #eee; padding:8px 10px; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,0.04);">' . esc_html($it) . '</div>';
                }
                echo '</div>';
            }
        }
        echo '</div>';

        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }
	public function get_doctors_ajax() {
    if (!current_user_can(self::CAP_MANAGE)) {
        wp_send_json([]);
    }

    global $wpdb;
    $term = sanitize_text_field($_GET['q'] ?? '');

    $doctors = $wpdb->get_results($wpdb->prepare("
        SELECT u.ID, u.display_name, d.subtitle
        FROM {$wpdb->users} u
        INNER JOIN Dj3i_drplus_specialists d ON u.ID = d.user_id
        WHERE d.subtitle != 'پرستار'
          AND (u.display_name LIKE %s OR u.user_login LIKE %s)
        LIMIT 20
    ", "%$term%", "%$term%"));

    $results = [];
    foreach ($doctors as $doctor) {
        $results[] = [
            'id'   => (int) $doctor->ID, // 👈 این‌جا ID ذخیره می‌شه، نه نام
            'text' => $doctor->display_name . (!empty($doctor->subtitle) ? ' (' . $doctor->subtitle . ')' : '')
        ];
    }

    wp_send_json($results);
}

public function get_nurses_ajax() {
    if (!current_user_can(self::CAP_MANAGE)) {
        wp_send_json([]);
    }

    global $wpdb;
    $term = sanitize_text_field($_GET['q'] ?? '');

    $nurses = $wpdb->get_results($wpdb->prepare("
        SELECT u.ID, u.display_name
        FROM {$wpdb->users} u
        INNER JOIN Dj3i_drplus_specialists d ON u.ID = d.user_id
        WHERE d.subtitle = 'پرستار'
          AND (u.display_name LIKE %s OR u.user_login LIKE %s)
        LIMIT 20
    ", "%$term%", "%$term%"));

    $results = [];
    foreach ($nurses as $nurse) {
        $results[] = [
            'id'   => (int) $nurse->ID, // 👈 این‌جا هم ID
            'text' => $nurse->display_name
        ];
    }

    wp_send_json($results);
}
}

new Med_Records_Plugin();

