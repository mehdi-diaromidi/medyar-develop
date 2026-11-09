<?php
if (!defined('ABSPATH')) exit;

class Lab_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        
        // هوک نوار ابزار ادمین برای نمایش تعداد سفارش‌های باز
        add_action('admin_bar_menu', [$this, 'admin_bar_notif'], 100);
    }

    public function admin_assets() {
        // لود کردن استایل با ورژن زمانی برای جلوگیری از کش
        wp_enqueue_style('lab-admin-style', LAB_PLUGIN_URL . 'assets/style.css', [], time());
    }

    // ==========================================
    // نمایش نوتیفیکیشن در نوار ابزار بالا
    // ==========================================
    public function admin_bar_notif($wp_admin_bar) {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lab_orders';
        // شمارش سفارش‌هایی که وضعیتشان 'completed' نیست
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status != 'completed'");

        if ($count > 0) {
            $args = [
                'id'    => 'lab_pending_orders',
                'title' => '<span class="ab-icon dashicons dashicons-warning" style="color:#f39c12; margin-top:2px;"></span> ' . 
                           '<span style="color:#f39c12; font-weight:bold;">' . $count . ' سفارش باز آزمایشگاه</span>',
                'href'  => admin_url('admin.php?page=lab-orders'),
                'meta'  => ['title' => 'مشاهده سفارشات آزمایشگاه']
            ];
            $wp_admin_bar->add_node($args);
        }
    }

    public function menu() {
        add_menu_page('آزمایشگاه', 'آزمایشگاه', 'manage_options', 'lab-main', [$this, 'page_settings'], 'dashicons-forms', 6);
        add_submenu_page('lab-main', 'تنظیمات', 'تنظیمات', 'manage_options', 'lab-main', [$this, 'page_settings']);
        add_submenu_page('lab-main', 'افزودن آزمایش', 'افزودن آزمایش', 'manage_options', 'lab-add', [$this, 'page_add']);
        add_submenu_page('lab-main', 'سفارشات', 'سفارشات', 'manage_options', 'lab-orders', [$this, 'page_orders']);
    }

    public function settings() {
        $options = [
            'lab_zp_merchant', 'lab_zp_sandbox', 
            'lab_sms_apikey', 'lab_sms_line',
            'lab_pat_otp', 'lab_pat_submit', 'lab_pat_paylink', 'lab_pat_success', 'lab_pat_result',
            'lab_categories', 'lab_text_nopres', 'lab_contact_btn_link', 'lab_contact_btn_text',
            // فیلدهای مربوط به قیمت و متن (جدید)
            'lab_show_price', 'lab_show_text', 'lab_price_alt_text'
        ];
        foreach($options as $opt) register_setting('lab_opts', $opt);
    }

    // ==========================================
    // 1. صفحه تنظیمات
    // ==========================================
    public function page_settings() {
        if (isset($_POST['lab_send_test_sms'])) {
            $mobile = sanitize_text_field($_POST['test_mobile']);
            $type = $_POST['test_type'];
            $pat_code = get_option($type);

            if(empty($mobile) || empty($pat_code)) {
                echo '<div class="error"><p>شماره موبایل یا کد پترن انتخاب شده خالی است.</p></div>';
            } else {
                $args = [];
                if($type == 'lab_pat_otp') $args = ['code' => '12345']; 
                elseif($type == 'lab_pat_paylink' || $type == 'lab_pat_result') $args = ['name' => 'کاربر تست', 'link' => 'example.com'];
                else $args = ['name' => 'کاربر تست'];

                $res = Lab_API::send_sms($mobile, $pat_code, $args);

                if($res['status'] === true) {
                    echo '<div class="updated" style="border-left: 4px solid #46b450;"><p><strong>پیامک با موفقیت ارسال شد.</strong><br>پاسخ سرور: ' . esc_html($res['msg']) . '</p></div>';
                } else {
                    echo '<div class="error" style="border-left: 4px solid #dc3232;"><p><strong>ارسال ناموفق بود!</strong><br>علت خطا: ' . esc_html($res['msg']) . '</p></div>';
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>تنظیمات پلاگین آزمایشگاه</h1>
            <form method="post" action="options.php">
                <?php settings_fields('lab_opts'); ?>
                <table class="form-table">
                    <tr><th colspan="2"><h3 style="margin:0; border-bottom:1px solid #ccc; padding-bottom:10px;">نمایش و قیمت‌ها</h3></th></tr>
                    
                    <tr>
                        <th>نمایش قیمت</th>
                        <td>
                            <label>
                                <input type="checkbox" name="lab_show_price" value="1" <?php checked(1, get_option('lab_show_price')); ?>>
                                نمایش قیمت عددی آزمایش
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th>نمایش متن دلخواه</th>
                        <td>
                            <label>
                                <input type="checkbox" name="lab_show_text" value="1" <?php checked(1, get_option('lab_show_text')); ?>>
                                نمایش متن زیر (در صورت فعال بودن، زیر یا بجای قیمت نمایش داده می‌شود)
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th>متن دلخواه</th>
                        <td>
                            <input type="text" name="lab_price_alt_text" value="<?php echo esc_attr(get_option('lab_price_alt_text')); ?>" class="regular-text" placeholder="مثلا: تماس بگیرید">
                        </td>
                    </tr>

                    <tr><th colspan="2"><h3 style="margin-top:20px; border-bottom:1px solid #ccc; padding-bottom:10px;">تنظیمات درگاه و پیامک</h3></th></tr>
                    <tr><th>مرچنت زرین پال</th><td><input type="text" name="lab_zp_merchant" value="<?php echo esc_attr(get_option('lab_zp_merchant')); ?>" class="regular-text"></td></tr>
                    <tr><th>حالت تست (Sandbox)</th><td><input type="checkbox" name="lab_zp_sandbox" value="1" <?php checked(1, get_option('lab_zp_sandbox')); ?>></td></tr>
                    <tr><th>API Key پیامک</th><td><input type="text" name="lab_sms_apikey" value="<?php echo esc_attr(get_option('lab_sms_apikey')); ?>" class="regular-text"></td></tr>
                    <tr><th>خط ارسال پیامک</th><td><input type="text" name="lab_sms_line" value="<?php echo esc_attr(get_option('lab_sms_line')); ?>"></td></tr>
                    
                    <tr><th colspan="2"><h3 style="margin-top:20px; border-bottom:1px solid #ccc; padding-bottom:10px;">کدهای پترن پیامک (IPanel/Faraz)</h3></th></tr>
                    <tr><th>پترن OTP (متغیر: code)</th><td><input type="text" name="lab_pat_otp" value="<?php echo esc_attr(get_option('lab_pat_otp')); ?>"></td></tr>
                    <tr><th>پترن ثبت سفارش (متغیر: name)</th><td><input type="text" name="lab_pat_submit" value="<?php echo esc_attr(get_option('lab_pat_submit')); ?>"></td></tr>
                    <tr><th>پیامک ادمین</th><td><a href="<?php echo esc_url( admin_url( 'options-general.php?page=lab-admin-sms' ) ); ?>">تنظیم گیرندگان، متن و پترن اختیاری پیامک ادمین</a> <span class="description">(متن آزاد پیش‌فرض؛ پترن فراز در صورت پر بودن)</span></td></tr>
                    <tr><th>پترن لینک پرداخت (متغیر: name, link)</th><td><input type="text" name="lab_pat_paylink" value="<?php echo esc_attr(get_option('lab_pat_paylink')); ?>"></td></tr>
                    <tr><th>پترن موفقیت (متغیر: name)</th><td><input type="text" name="lab_pat_success" value="<?php echo esc_attr(get_option('lab_pat_success')); ?>"></td></tr>
                    <tr><th>پترن نتیجه (متغیر: name, link)</th><td><input type="text" name="lab_pat_result" value="<?php echo esc_attr(get_option('lab_pat_result')); ?>"></td></tr>
                    
                    <tr><th colspan="2"><h3 style="margin-top:20px; border-bottom:1px solid #ccc; padding-bottom:10px;">تنظیمات ظاهری</h3></th></tr>
                    <tr><th>دسته بندی ها (هر خط یکی)</th><td><textarea name="lab_categories" rows="5" class="large-text"><?php echo esc_textarea(get_option('lab_categories')); ?></textarea></td></tr>
                    <tr><th>متن بخش "نسخه ندارم"</th><td><textarea name="lab_text_nopres" rows="3" class="large-text"><?php echo esc_textarea(get_option('lab_text_nopres')); ?></textarea></td></tr>
                    <tr><th>متن دکمه تماس</th><td><input type="text" name="lab_contact_btn_text" value="<?php echo esc_attr(get_option('lab_contact_btn_text')); ?>"></td></tr>
                    <tr><th>لینک دکمه تماس</th><td><input type="text" name="lab_contact_btn_link" value="<?php echo esc_attr(get_option('lab_contact_btn_link')); ?>" class="regular-text"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <div style="margin-top:50px; background:#fff; padding:20px; border:1px solid #ccc; border-right:4px solid #0073aa; max-width:600px;">
                <h3>🧪 تست ارسال پیامک</h3>
                <form method="post">
                    <table class="form-table">
                        <tr><th>شماره موبایل تست:</th><td><input type="text" name="test_mobile" placeholder="0912..." required></td></tr>
                        <tr><th>انتخاب پترن:</th>
                            <td>
                                <select name="test_type">
                                    <option value="lab_pat_otp">OTP</option>
                                    <option value="lab_pat_submit">ثبت سفارش</option>
                                    <option value="lab_pat_paylink">لینک پرداخت</option>
                                    <option value="lab_pat_success">پرداخت موفق</option>
                                    <option value="lab_pat_result">اعلام نتیجه</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="lab_send_test_sms" class="button button-secondary" value="ارسال پیامک تست">
                </form>
            </div>
        </div>
        <?php
    }

    // ==========================================
    // 2. صفحه افزودن آزمایش
    // ==========================================
    public function page_add() {
        global $wpdb;
        $table = $wpdb->prefix . 'lab_tests';
        
        if(isset($_GET['del'])) {
            $wpdb->delete($table, ['id' => intval($_GET['del'])]);
            echo '<div class="updated"><p>آزمایش حذف شد.</p></div>';
        }

        if (isset($_POST['save_test'])) {
            $wpdb->insert($table, [
                'title' => sanitize_text_field($_POST['title']),
                'category' => sanitize_text_field($_POST['category']),
                'description' => sanitize_textarea_field($_POST['desc']),
                'price' => intval($_POST['price'])
            ]);
            echo '<div class="updated"><p>آزمایش اضافه شد.</p></div>';
        }

        $cat_opt = get_option('lab_categories', '');
        $cats = array_filter(explode("\n", (string)$cat_opt));
        $tests = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>مدیریت آزمایش ها</h1>
            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <div style="flex:1; min-width:300px; background:#fff; padding:20px; border:1px solid #ccc;">
                    <h3>افزودن جدید</h3>
                    <form method="post">
                        <p><label>عنوان:</label><input type="text" name="title" required style="width:100%" class="regular-text"></p>
                        <p><label>دسته:</label><select name="category" style="width:100%"><?php foreach($cats as $c) echo "<option>".trim($c)."</option>"; ?></select></p>
                        <p><label>توضیحات:</label><textarea name="desc" style="width:100%" rows="3"></textarea></p>
                        <p><label>قیمت:</label><input type="number" name="price" required style="width:100%"></p>
                        <button type="submit" name="save_test" class="button button-primary">ذخیره</button>
                    </form>
                </div>
                <div style="flex:2; min-width:300px;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>عنوان</th><th>دسته</th><th>قیمت</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php if($tests): foreach($tests as $t): ?>
                            <tr>
                                <td><?php echo esc_html($t->title); ?></td>
                                <td><?php echo esc_html($t->category); ?></td>
                                <td><?php echo number_format($t->price); ?></td>
                                <td><a href="?page=lab-add&del=<?php echo $t->id; ?>" style="color:red;" onclick="return confirm('حذف؟')">حذف</a></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4">موردی یافت نشد.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    // ==========================================
    // 3. صفحه سفارشات (کامل)
    // ==========================================
    public function page_orders() {
        global $wpdb;
        $table = $wpdb->prefix . 'lab_orders';
        $upload_base = wp_upload_dir()['baseurl'] . '/lab_secure/';
        $upload_dir_path = wp_upload_dir()['basedir'] . '/lab_secure/';

        // --- الف: حذف کل سفارش ---
        if(isset($_GET['action']) && $_GET['action'] == 'delete_order' && isset($_GET['oid'])) {
            $oid = intval($_GET['oid']);
            $wpdb->delete($table, ['id' => $oid]);
            echo '<div class="updated notice is-dismissible" style="border-right: 4px solid #d63638;"><p>سفارش شماره #'.$oid.' حذف شد.</p></div>';
        }

        // --- ب: آپدیت سفارش (ذخیره مودال) ---
        if(isset($_POST['update_order_modal'])) {
            $oid = intval($_POST['oid']);
            
            // 1. مدیریت فایل‌ها (حذف و اضافه)
            $prev_json = $wpdb->get_var("SELECT result_files FROM $table WHERE id=$oid");
            $current_files = $prev_json ? json_decode($prev_json, true) : [];
            if(!is_array($current_files)) $current_files = [];

            // حذف فایل‌های تیک خورده
            if(isset($_POST['del_files']) && is_array($_POST['del_files'])) {
                foreach($_POST['del_files'] as $del_name) {
                    if(file_exists($upload_dir_path . $del_name)) unlink($upload_dir_path . $del_name);
                    $key = array_search($del_name, $current_files);
                    if($key !== false) unset($current_files[$key]);
                }
                $current_files = array_values($current_files); // مرتب‌سازی ایندکس
            }

            // آپلود فایل‌های جدید
            if(!empty($_FILES['res_file']['name'][0])) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                $count = count($_FILES['res_file']['name']);
                for($i=0; $i<$count; $i++) {
                    if($_FILES['res_file']['error'][$i] == 0) {
                        $ext = pathinfo($_FILES['res_file']['name'][$i], PATHINFO_EXTENSION);
                        $new_name = uniqid('res_') . '.' . $ext;
                        if(!file_exists($upload_dir_path)) wp_mkdir_p($upload_dir_path);
                        if(move_uploaded_file($_FILES['res_file']['tmp_name'][$i], $upload_dir_path . $new_name)) {
                            $current_files[] = $new_name;
                        }
                    }
                }
            }

            // 2. ذخیره اطلاعات
            $current_status = $wpdb->get_var("SELECT status FROM $table WHERE id=$oid");
            $new_status = $current_status;
            if($current_status == 'pending' && intval($_POST['aprice']) > 0) $new_status = 'waiting_payment';
            if(!empty($current_files) && $new_status != 'completed') $new_status = 'completed';

            $wpdb->update($table, [
                'admin_price' => intval($_POST['aprice']),
                'admin_note' => sanitize_textarea_field($_POST['anote']),
                'status' => $new_status,
                'result_files' => json_encode($current_files)
            ], ['id' => $oid]);

            // 3. ارسال پیامک‌ها
            $o = $wpdb->get_row("SELECT * FROM $table WHERE id=$oid");
            $link = home_url('/?lab_track=' . $o->token);

            if(isset($_POST['action_sms_pay'])) {
                $res = Lab_API::send_sms($o->mobile, get_option('lab_pat_paylink'), ['name'=>$o->fullname, 'link'=>$link]);
                if($res['status']===true) echo '<div class="updated notice is-dismissible"><p>پیامک لینک پرداخت ارسال شد.</p></div>';
                else echo '<div class="error notice is-dismissible"><p>خطا در ارسال پیامک پرداخت: '.esc_html($res['msg']).'</p></div>';
            }
            if(isset($_POST['action_sms_result'])) {
                $res = Lab_API::send_sms($o->mobile, get_option('lab_pat_result'), ['name'=>$o->fullname, 'link'=>$link]);
                if($res['status']===true) echo '<div class="updated notice is-dismissible"><p>پیامک نتیجه ارسال شد.</p></div>';
                else echo '<div class="error notice is-dismissible"><p>خطا در ارسال پیامک نتیجه: '.esc_html($res['msg']).'</p></div>';
            }

            echo '<div class="updated notice is-dismissible"><p>سفارش بروزرسانی شد.</p></div>';
        }

        // --- پ: نمایش جدول ---
        $pagenum = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $limit = 20;
        $offset = ( $pagenum - 1 ) * $limit;
        $search_term = '';
        $where_sql = "WHERE 1=1";
        
        if (isset($_POST['s']) || isset($_GET['s'])) {
            $search_term = sanitize_text_field(isset($_POST['s']) ? $_POST['s'] : $_GET['s']);
            if(!empty($search_term)) $where_sql .= " AND (fullname LIKE '%$search_term%' OR mobile LIKE '%$search_term%' OR id LIKE '$search_term')";
        }

        $total = $wpdb->get_var( "SELECT COUNT(id) FROM $table $where_sql" );
        $num_of_pages = ceil( $total / $limit );
        $orders = $wpdb->get_results( "SELECT * FROM $table $where_sql ORDER BY id DESC LIMIT $offset, $limit" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">سفارشات آزمایشگاه</h1>
            <form method="get" style="float:left; margin-top:10px;">
                <input type="hidden" name="page" value="lab-orders">
                <input type="search" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="جستجو...">
                <input type="submit" id="search-submit" class="button" value="جستجو">
            </form>
            <div style="clear:both;"></div>

            <table class="wp-list-table widefat fixed striped table-view-list posts">
                <thead><tr><th>شناسه</th><th>نام کاربر</th><th>موبایل</th><th>وضعیت</th><th>تاریخ</th><th width="150">عملیات</th></tr></thead>
                <tbody>
                    <?php if($orders): foreach($orders as $o): 
                        $status_label = ''; $status_color = '#777';
                        switch($o->status){
                            case 'pending': $status_label='در انتظار'; $status_color='#f39c12'; break;
                            case 'waiting_payment': $status_label='منتظر پرداخت'; $status_color='#3498db'; break;
                            case 'paid': $status_label='پرداخت شده'; $status_color='#27ae60'; break;
                            case 'completed': $status_label='تکمیل شده'; $status_color='#2c3e50'; break;
                        }
                    ?>
                    <tr>
                        <td>#<?php echo $o->id; ?></td>
                        <td><strong><?php echo esc_html($o->fullname); ?></strong></td>
                        <td><?php echo esc_html($o->mobile); ?></td>
                        <td><span style="color:#fff; background:<?php echo $status_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px;"><?php echo $status_label; ?></span></td>
                        <td><?php echo date_i18n('Y/m/d', strtotime($o->created_at)); ?></td>
                        <td>
                            <button type="button" class="button button-primary open-lab-modal" 
                                data-order='<?php echo json_encode($o); ?>'
                                data-items='<?php echo $o->items; ?>'
                                data-files='<?php echo $o->result_files; ?>'>
                                مشاهده
                            </button>
                            <a href="?page=lab-orders&action=delete_order&oid=<?php echo $o->id; ?>" 
                               class="button" style="border-color:#d63638; color:#d63638; margin-right:5px;"
                               onclick="return confirm('آیا مطمئن هستید؟ این عملیات غیرقابل بازگشت است.')">حذف</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?><tr><td colspan="6">سفارشی یافت نشد.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php echo paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','total'=>$num_of_pages,'current'=>$pagenum)); ?>
        </div>

        <div id="labModal" class="lab-modal-overlay">
            <div class="lab-modal">
                <div class="lab-modal-header">
                    <h2 style="margin:0">جزئیات سفارش <span id="modal-oid-disp"></span></h2>
                    <button type="button" class="lab-close-modal" onclick="document.getElementById('labModal').style.display='none'">&times;</button>
                </div>
                <div class="lab-modal-body">
                    <form method="post" enctype="multipart/form-data" id="lab-admin-form">
                        <input type="hidden" name="update_order_modal" value="1">
                        <input type="hidden" name="oid" id="modal_oid">
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
                            <div>
                                <p><strong>نام:</strong> <span id="m_name"></span></p>
                                <p><strong>موبایل:</strong> <span id="m_mobile"></span></p>
                                <p><strong>کد ملی:</strong> <span id="m_nat"></span></p>
                                <p><strong>بیمه:</strong> <span id="m_ins"></span></p>
                            </div>
                            <div>
                                <p><strong>آدرس:</strong> <span id="m_addr"></span></p>
                                <p><strong>نوع:</strong> <span id="m_pres_type"></span></p>
                                <div id="m_pres_link_box"></div>
                            </div>
                        </div>
                        <div style="background:#f9f9f9; padding:10px; border-radius:5px; margin-bottom:15px;">
                            <strong>لیست آیتم‌ها:</strong> <ul id="m_items_list" style="margin:0; padding-right:20px;"></ul>
                        </div>
                        <hr>
                        <table class="form-table">
                            <tr><th>قیمت نهایی:</th><td><input type="number" name="aprice" id="m_aprice" class="regular-text"> تومان</td></tr>
                            <tr><th>یادداشت ادمین:</th><td><textarea name="anote" id="m_anote" class="large-text" rows="2"></textarea></td></tr>
                            <tr><th>فایل نتیجه:</th><td>
                                <div class="lab-upload-area">
                                    <p>برای انتخاب فایل جدید کلیک کنید (Drag & Drop)</p>
                                    <input type="file" name="res_file[]" multiple id="file-uploader">
                                </div>
                                <div id="new-files-preview" style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;"></div>
                                <div id="m_prev_files" style="margin-top:10px;"></div>
                            </td></tr>
                        </table>
                        <div style="margin-top:20px; border-top:1px solid #eee; padding-top:20px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <label><input type="checkbox" name="action_sms_pay"> پیامک لینک پرداخت</label><br>
                                <label><input type="checkbox" name="action_sms_result"> پیامک نتیجه</label>
                            </div>
                            <button type="submit" class="button button-primary button-hero">ذخیره تغییرات</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="labLightbox" class="lab-lightbox">
            <span class="lab-lb-close" onclick="document.getElementById('labLightbox').style.display='none'">&times;</span>
            <img id="labLbImg" src="">
        </div>

        <script>
        jQuery(document).ready(function($){
            // 1. پیش‌نمایش فایل‌های جدید
            $('#file-uploader').on('change', function(e) {
                var container = $('#new-files-preview');
                container.html(''); // پاک کردن
                var files = e.target.files;
                for (var i = 0; i < files.length; i++) {
                    (function(file){
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            var isImg = file.type.match('image.*');
                            var html = isImg ? '<img src="'+e.target.result+'" style="width:50px; height:50px; object-fit:cover;">' : '<span class="dashicons dashicons-media-document"></span>';
                            container.append('<div style="border:1px solid #ddd; padding:5px; width:60px; text-align:center;">'+html+'<br><small style="font-size:9px;">'+file.name+'</small></div>');
                        };
                        reader.readAsDataURL(file);
                    })(files[i]);
                }
            });

            // 2. باز کردن مودال
            $('.open-lab-modal').click(function(){
                var o = $(this).data('order');
                var items = $(this).data('items'); 
                var files = $(this).data('files');

                $('#modal_oid').val(o.id);
                $('#modal-oid-disp').text('#'+o.id);
                $('#m_name').text(o.fullname);
                $('#m_mobile').text(o.mobile);
                $('#m_nat').text(o.national_code);
                $('#m_ins').text(o.insurance + ' (' + o.sup_insurance + ')');
                $('#m_addr').text(o.province + ' - ' + o.city + ' - ' + o.address);
                $('#m_pres_type').text(o.pres_type == 'electronic' ? 'الکترونیک' : (o.pres_type=='routine'?'روتین':'کاغذی'));
                $('#m_aprice').val(o.admin_price);
                $('#m_anote').val(o.admin_note);
                
                // ریست کردن فیلد آپلود
                $('#file-uploader').val('');
                $('#new-files-preview').html('');

                var presHtml = '';
                if(o.pres_type == 'paper') {
                    var ext = o.pres_data.split('.').pop().toLowerCase();
                    var url = '<?php echo $upload_base; ?>' + o.pres_data;
                    if(['jpg','jpeg','png'].includes(ext)) presHtml = '<button type="button" class="button" onclick="openAdminLightbox(\''+url+'\')">مشاهده تصویر</button>';
                    else presHtml = '<a href="'+url+'" target="_blank" class="button">دانلود نسخه</a>';
                } else {
                    presHtml = '<strong>' + o.pres_data + '</strong>';
                }
                $('#m_pres_link_box').html(presHtml);

                var itemsHtml = '';
                if(items) { if(typeof items === 'string') items = JSON.parse(items); items.forEach(function(i){ itemsHtml += '<li>'+i.title+'</li>'; }); }
                $('#m_items_list').html(itemsHtml);

                var filesHtml = '';
                if(files) {
                    if(typeof files === 'string') files = JSON.parse(files);
                    var baseUrl = '<?php echo $upload_base; ?>';
                    files.forEach(function(f){
                        var ext = f.split('.').pop().toLowerCase();
                        var url = baseUrl + f;
                        var preview = ['jpg','jpeg','png'].includes(ext) ? '<img src="'+url+'" style="height:40px;" onclick="openAdminLightbox(\''+url+'\')">' : '<span class="dashicons dashicons-media-default" style="font-size:30px"></span>';
                        
                        filesHtml += `
                        <div class="existing-file-row" style="display:flex; justify-content:space-between; align-items:center; border:1px solid #eee; padding:5px; margin-bottom:5px;">
                            <div style="display:flex; align-items:center; gap:10px;">${preview} <a href="${url}" target="_blank" style="font-size:12px">دانلود</a></div>
                            <button type="button" style="color:red; border:none; background:none; cursor:pointer;" onclick="markForDel(this, '${f}')">حذف</button>
                        </div>`;
                    });
                }
                $('#m_prev_files').html(filesHtml);

                $('#labModal').css('display', 'flex');
            });

            window.openAdminLightbox = function(url) {
                $('#labLbImg').attr('src', url);
                $('#labLightbox').css('display', 'flex');
            };
            
            window.markForDel = function(btn, fname) {
                if(confirm('این فایل پس از ذخیره حذف میشود. ادامه میدهید؟')) {
                    $(btn).parent().css('opacity','0.4').css('text-decoration','line-through');
                    $(btn).remove();
                    $('#lab-admin-form').append('<input type="hidden" name="del_files[]" value="'+fname+'">');
                }
            };
            
            $(window).click(function(e) { if ($(e.target).is('#labModal')) $('#labModal').hide(); });
        });
        </script>
        <?php
    }
}
new Lab_Admin();