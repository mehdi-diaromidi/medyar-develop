<?php
if (!defined('ABSPATH')) exit;

class Phar_Shortcode {
    public function __construct() {
        // ثبت شورت‌کد برای نمایش فرم در برگه
        add_shortcode('phar_booking', [$this, 'render_form']);
        
        // هندل کردن صفحه پیگیری و بازگشت از درگاه پرداخت
        add_action('init', [$this, 'handle_tracking_payment']);
        
        // ثبت اکشن‌های AJAX با پیشوند phar
        add_action('wp_ajax_phar_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_phar_send_otp', [$this, 'ajax_send_otp']);
        
        add_action('wp_ajax_phar_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_phar_verify_otp', [$this, 'ajax_verify_otp']);
        
        add_action('wp_ajax_phar_submit', [$this, 'ajax_submit']);
        add_action('wp_ajax_nopriv_phar_submit', [$this, 'ajax_submit']);
    }

    // ==========================================
    // 1. رندر کردن فرم (Front-End HTML)
    // ==========================================
    public function render_form() {
        // دریافت متن‌ها از تنظیمات
        $nopres_text = get_option('phar_text_nopres', 'برای دریافت اقلام بدون نسخه (OTC) یا مشاوره دارویی لطفاً با ما تماس بگیرید.');
        $btn_text = get_option('phar_contact_btn_text', 'تماس با داروخانه');
        $btn_link = get_option('phar_contact_btn_link', '#');

        ob_start();
        ?>
        <div id="phar-booking-app" class="phar-container">
            
            <div class="lab-step active" id="view-entry">
                <h2 style="text-align:center; margin-bottom:30px;">پذیرش آنلاین نسخه</h2>
                <div class="lab-cards-row">
                    <div class="lab-card-btn" onclick="startPharFlow('pres')">
                        <i class="fas fa-file-prescription"></i>
                        <br>نسخه دارم
                        <span>ارسال عکس نسخه (کاغذی) یا کد رهگیری (الکترونیک)</span>
                    </div>
                    <div class="lab-card-btn" onclick="pharGoTo('nopres')">
                        <i class="fas fa-pills"></i>
                        <br>نسخه ندارم
                        <span>ارتباط با پزشکان مدیار</span>
                    </div>
                </div>
            </div>

            <div class="lab-step" id="view-nopres" style="text-align:center;">
                <div style="background:#e3f2fd; padding:30px; border-radius:16px; margin-bottom:25px; border:1px solid #bbdefb;">
                    <i class="fas fa-headset" style="font-size:40px; color:#3498db; margin-bottom:15px;"></i>
                    <p style="font-size:16px; font-weight:bold; line-height:1.8; color:#333;"><?= wpautop(esc_html($nopres_text)) ?></p>
                </div>
                <?php if($btn_link != '#'): ?>
                    <a href="<?= esc_attr($btn_link) ?>" class="lab-btn primary" style="text-decoration:none; padding: 10px 30px;"><?= esc_html($btn_text) ?></a>
                <?php endif; ?>
                <br><br>
                <button class="lab-btn small" onclick="pharGoTo('entry')" style="background:#777">بازگشت</button>
            </div>

            <div class="lab-step" id="view-info">
                <h3 class="lab-section-title">اطلاعات نسخه</h3>
                
                <div id="pres-container">
                    <div class="lab-radio-group">
                        <label><input type="radio" name="ptype" value="electronic" checked onclick="pharTogglePresInput('elec')"> نسخه الکترونیک (کد رهگیری)</label>
                        <label><input type="radio" name="ptype" value="paper" onclick="pharTogglePresInput('paper')"> نسخه کاغذی (آپلود عکس)</label>
                    </div>
                    
                    <div id="inp-elec">
                        <input type="text" id="pres_code" placeholder="کد رهگیری نسخه الکترونیک (تامین اجتماعی / سلامت)" class="phar-input">
                    </div>
                    
                    <div id="inp-paper" style="display:none">
                        <div class="lab-upload-area" style="padding:20px; margin-bottom:10px;">
                            <i class="fas fa-cloud-upload-alt" style="font-size:30px; color:#27ae60; margin-bottom:10px;"></i>
                            <p style="margin:0;">برای انتخاب تصاویر کلیک کنید</p>
                            <input type="file" id="pres_files" multiple accept="image/*">
                        </div>
                        <div id="files-selected-list" style="margin-bottom: 20px; color: #27ae60; font-size: 13px; text-align: right; line-height: 1.8;"></div>
                    </div>
                </div>

                <h4 class="lab-section-title">اطلاعات بیمه</h4>
                <div class="row-lab">
                    <select id="ins_type" class="phar-input half">
                        <option value="">نوع بیمه...</option>
                        <option>تامین اجتماعی</option>
                        <option>خدمات درمانی (سلامت)</option>
                        <option>نیروهای مسلح</option>
                        <option>آزاد / بدون بیمه</option>
                        <option>سایر بانک‌ها و ارگان‌ها</option>
                    </select>
                    <input type="text" id="ins_code" placeholder="شماره بیمه" class="phar-input half">
                </div>

                <h4 class="lab-section-title">مشخصات بیمار و تحویل</h4>
                <div class="row-lab">
                     <input type="text" id="fullname" placeholder="نام و نام خانوادگی بیمار" class="phar-input half">
                    <input type="tel" id="mobile" placeholder="شماره موبایل" class="phar-input half">
                </div>
                
                <input type="number" id="nat_code" placeholder="کد ملی بیمار" class="phar-input">

                <div class="row-lab">
                    <select id="province" class="phar-input half">
                        <option value="">استان...</option>
                    </select>
                    <select id="city" class="phar-input half">
                        <option value="">شهر...</option>
                    </select>
                </div>
                <textarea id="address" placeholder="نشانی" class="phar-input" rows="3"></textarea>

                <button class="lab-btn primary" onclick="sendPharOTP()" style="width:100%; margin-top:20px; font-size:16px;">تایید و دریافت کد احراز هویت</button>
                <button class="lab-btn small" onclick="pharGoTo('entry')" style="margin-top:15px; background:#fff; color:#777; width:100%; border:1px solid #ddd;">بازگشت</button>
            </div>

            <div class="lab-step" id="view-otp" style="text-align:center;">
                <h3>احراز هویت</h3>
                <p>کد ۵ رقمی ارسال شده به موبایل را وارد کنید</p>
                <input type="text" id="otp_code" class="phar-input" style="text-align:center; font-size:24px; letter-spacing:8px; width:60%; margin:20px auto;" maxlength="5">
                <button id="btn-verify" class="lab-btn primary" onclick="verifyPharOTP()" style="width:100%;">ثبت نهایی درخواست</button>
                <br>
                <button class="lab-btn small" onclick="pharGoTo('info')" style="background:none; color:#777; margin-top:15px; border:none; text-decoration:underline;">اصلاح شماره موبایل</button>
            </div>

            <div class="lab-step" id="view-success" style="text-align:center; padding:30px 0;">
                <div class="success-icon-container">
                    <i class="fas fa-check"></i>
                </div>
                <h2 style="color:#27ae60; margin-bottom:10px;">درخواست شما با موفقیت ثبت شد!</h2>
                <p style="color:#666; margin-bottom:30px;">نسخه شما توسط دکتر داروساز بررسی شده و فاکتور نهایی برای شما پیامک می‌شود.</p>
                
                <div class="tracking-code-box">
                    <span class="tracking-code-label">کد پیگیری</span>
                    <span class="tracking-code-value" id="final-token">...</span>
                </div>

                <a href="<?= home_url() ?>" class="lab-btn primary" style="text-decoration:none;">بازگشت به صفحه اصلی</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // ==========================================
    // 2. توابع پردازش AJAX
    // ==========================================
    
    // الف) ارسال کد OTP
    public function ajax_send_otp() {
        $mob = sanitize_text_field($_POST['mobile']);
        // اعتبارسنجی فرمت موبایل
        if(!preg_match('/^09[0-9]{9}$/', $mob)) {
            wp_send_json_error(['msg'=>'فرمت شماره موبایل صحیح نیست.']);
        }
        
        $code = rand(10000, 99999);
        $res = Phar_API::send_sms($mob, get_option('phar_pat_otp'), ['code'=>$code]);
        
        if($res['status'] === true) {
            set_transient('phar_otp_'.$mob, $code, 600); // کد تا ۱۰ دقیقه معتبر است
            wp_send_json_success();
        } else {
            wp_send_json_error(['msg' => 'خطا در سامانه پیامک: ' . $res['msg']]);
        }
    }

    // ب) بررسی صحت کد OTP
    public function ajax_verify_otp() {
        $mob = sanitize_text_field($_POST['mobile']);
        $code = sanitize_text_field($_POST['code']);
        
        if(get_transient('phar_otp_'.$mob) == $code) {
            delete_transient('phar_otp_'.$mob); // حذف کد مصرف شده
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }

    // ج) ثبت نهایی سفارش (با قابلیت آپلود چندگانه)
    public function ajax_submit() {
        global $wpdb;
        $token = wp_generate_password(8, false, false); // تولید کد پیگیری رندوم
        
        // --- مدیریت ذخیره فایل‌ها ---
        $saved_files = [];
        $u = wp_upload_dir();
        $target_dir = $u['basedir'] . '/pharmacy_secure/';
        
        // اگر پوشه وجود نداشت بساز
        if (!file_exists($target_dir)) wp_mkdir_p($target_dir);

        // 1. اگر نوع نسخه الکترونیک باشد
        if(isset($_POST['pres_type']) && $_POST['pres_type'] === 'electronic') {
            $code = sanitize_text_field($_POST['pres_data']);
            if(!empty($code)) {
                // ذخیره کد رهگیری با پیشوند خاص در آرایه فایل‌ها
                $saved_files[] = "CODE:" . $code; 
            }
        } 
        // 2. اگر نسخه کاغذی باشد (آپلود فایل)
        elseif(isset($_FILES['pres_files'])) {
            $files = $_FILES['pres_files'];
            // بررسی اینکه آیا آرایه فایل است (چون input multiple است)
            if(is_array($files['name'])) {
                $count = count($files['name']);
                for($i=0; $i<$count; $i++) {
                    if($files['error'][$i] == 0) {
                        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                        // ایجاد نام فایل ایمن و یکتا
                        $new_name = uniqid('pres_') . '_' . $i . '.' . $ext;
                        
                        if(move_uploaded_file($files['tmp_name'][$i], $target_dir . $new_name)) {
                            $saved_files[] = $new_name;
                        }
                    }
                }
            }
        }

        // درج در دیتابیس
        $result = $wpdb->insert($wpdb->prefix.'phar_orders', [
            'token'         => $token,
            'fullname'      => sanitize_text_field($_POST['fullname']),
            'mobile'        => sanitize_text_field($_POST['mobile']),
            'province'      => sanitize_text_field($_POST['province']),
            'city'          => sanitize_text_field($_POST['city']),
            'address'       => sanitize_textarea_field($_POST['address']),
            'national_code' => sanitize_text_field($_POST['national_code']),
            
            'insurance_type'=> sanitize_text_field($_POST['insurance']),
            'insurance_code'=> sanitize_text_field($_POST['insurance_code']),
            
            'pres_type'     => sanitize_text_field($_POST['pres_type']),
            'pres_files'    => json_encode($saved_files, JSON_UNESCAPED_UNICODE), // ذخیره آرایه فایل‌ها به صورت JSON
            
            'invoice_items' => '[]', // فاکتور خالی در ابتدا
            'status'        => 'pending',
            'created_at'    => current_time('mysql')
        ]);
        
        if($result) {
            $customer_name   = sanitize_text_field($_POST['fullname']);
            $customer_mobile = sanitize_text_field($_POST['mobile']);

            // ارسال پیامک ثبت موفقیت‌آمیز به مشتری
            Phar_API::send_sms($customer_mobile, get_option('phar_pat_submit'), ['name'=>$customer_name]);

            // پیامک به ادمین‌ها (تنظیمات ← پیامک ادمین درخواست‌ها) — متن آزاد یا پترن اختیاری
            if ( class_exists( 'Lab_Admin_Sms_Settings' ) ) {
                Lab_Admin_Sms_Settings::notify_admins( 'phar', [
                    'name'           => $customer_name,
                    'mobile'         => $customer_mobile,
                    'token'          => $token,
                    'city'           => sanitize_text_field( $_POST['city'] ?? '' ),
                    'province'       => sanitize_text_field( $_POST['province'] ?? '' ),
                    'address'        => sanitize_textarea_field( $_POST['address'] ?? '' ),
                    'national_code'  => sanitize_text_field( $_POST['national_code'] ?? '' ),
                    'insurance'      => sanitize_text_field( $_POST['insurance'] ?? '' ),
                    'insurance_code' => sanitize_text_field( $_POST['insurance_code'] ?? '' ),
                    'pres_type'      => sanitize_text_field( $_POST['pres_type'] ?? '' ),
                ] );
            }

            wp_send_json_success(['token' => $token]);
        } else {
            wp_send_json_error(['msg' => 'خطا در ذخیره اطلاعات در پایگاه داده.']);
        }
    }

    // ==========================================
    // 3. هندلینگ پیگیری و پرداخت (Init Hook)
    // ==========================================
    public function handle_tracking_payment() {
        
        // الف) نمایش صفحه پیگیری (اگر پارامتر phar_track در URL باشد)
        if(isset($_GET['phar_track'])) {
            global $wpdb;
            $token = sanitize_text_field($_GET['phar_track']);
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}phar_orders WHERE token=%s", $token));
            
            if(!$order) {
                wp_die('سفارش یافت نشد یا کد پیگیری اشتباه است.', 'خطای پیگیری');
            }

            // اگر کاربر دکمه "پرداخت" را زد
            if(isset($_POST['pay_now']) && $order->status == 'waiting_payment') {
                $desc = "پرداخت نسخه دارویی #{$order->id}";
                $callback = home_url("/?phar_verify=1&oid={$order->id}");
                
                $res = Phar_API::payment_request($order->total_price, $callback, $desc, $order->mobile);
                
                if($res['status']) {
                    wp_redirect($res['url']);
                    exit;
                } else {
                    wp_die($res['msg'], 'خطای درگاه پرداخت');
                }
            }

            // بارگذاری قالب گرافیکی پیگیری
            include PHAR_PLUGIN_DIR . 'assets/tracking-template.php'; 
            exit;
        }

        // ب) بازگشت از درگاه پرداخت (Callback)
        if(isset($_GET['phar_verify']) && isset($_GET['Authority'])) {
            global $wpdb;
            $oid = intval($_GET['oid']);
            $order = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}phar_orders WHERE id=$oid");
            
            if(!$order) wp_die('سفارش نامعتبر است.');

            $res = Phar_API::payment_verify($order->total_price, $_GET['Authority']);
            
            if($res['status']) {
                // آپدیت وضعیت به "پرداخت شده"
                $wpdb->update($wpdb->prefix.'phar_orders', [
                    'status' => 'paid', 
                    'payment_ref' => $res['ref_id']
                ], ['id'=>$oid]);
                
                // ارسال پیامک موفقیت پرداخت
                Phar_API::send_sms($order->mobile, get_option('phar_pat_success'), ['name'=>$order->fullname]);
                
                // ریدایرکت به صفحه پیگیری برای مشاهده وضعیت جدید
                wp_redirect(home_url("/?phar_track={$order->token}"));
            } else {
                wp_die(
                    '<div style="text-align:center; margin-top:50px; font-family:tahoma;">
                        <h2 style="color:red;">پرداخت ناموفق بود!</h2>
                        <p>تراکنش توسط کاربر لغو شد یا بانک تایید نکرد.</p>
                        <a href="'.home_url("/?phar_track={$order->token}").'" style="padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;">بازگشت به پنل</a>
                    </div>'
                );
            }
            exit;
        }
    }
}
new Phar_Shortcode();