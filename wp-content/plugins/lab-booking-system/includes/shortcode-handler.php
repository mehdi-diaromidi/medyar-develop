<?php
if (!defined('ABSPATH')) exit;

class Lab_Shortcode {
    public function __construct() {
        // ثبت شورت‌کد
        add_shortcode('lab_booking', [$this, 'render_form']);
        
        // هندل کردن صفحه پیگیری و بازگشت از درگاه
        add_action('init', [$this, 'handle_tracking_payment']);
        
        // ثبت اکشن‌های AJAX
        add_action('wp_ajax_lab_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_lab_send_otp', [$this, 'ajax_send_otp']);
        
        add_action('wp_ajax_lab_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_lab_verify_otp', [$this, 'ajax_verify_otp']);
        
        add_action('wp_ajax_lab_submit', [$this, 'ajax_submit']);
        add_action('wp_ajax_nopriv_lab_submit', [$this, 'ajax_submit']);
    }

    // ==========================================
    // رندر کردن فرم (HTML)
    // ==========================================
    public function render_form() {
        global $wpdb;
        $cats = array_filter(explode("\n", get_option('lab_categories')));
        $tests = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lab_tests");
        
        $consult_text = get_option('lab_text_nopres', 'جهت دریافت مشاوره تخصصی با کارشناسان ما تماس بگیرید.');
        $btn_text = get_option('lab_contact_btn_text', 'تماس با ما');
        $btn_link = get_option('lab_contact_btn_link', '#');

        // دریافت تنظیمات قیمت و متن
        $show_price = get_option('lab_show_price'); 
        $show_text = get_option('lab_show_text');   
        $price_alt_text = get_option('lab_price_alt_text', 'تماس بگیرید'); 

        ob_start();
        ?>
        <div id="lab-booking-app" class="lab-container">
            
            <div class="lab-step active" id="view-entry">
                <h2 style="text-align:center; margin-bottom:30px;">نوع پذیرش را انتخاب کنید</h2>
                <div class="lab-cards-row">
                    <div class="lab-card-btn" onclick="startFlow('pres')">
                        <i class="fas fa-file-medical"></i>
                        <br>نسخه دارم
                        <span>پزشک برایم نسخه الکترونیک/کاغذی صادر کرده است</span>
                    </div>
                    <div class="lab-card-btn" onclick="labGoTo('options')">
                        <i class="fas fa-file-signature"></i>
                        <br>نسخه ندارم
                        <span>درخواست آزمایش دارم اما نسخه&zwnj;ای برایم ثبت نشده است</span>
                    </div>
                </div>
            </div>

            <div class="lab-step" id="view-options">
                <h3 style="text-align:center; margin-bottom:30px;">انتخاب خدمات</h3>
                <div class="lab-cards-row">
                    <div class="lab-card-btn" onclick="startFlow('routine')">
                        <i class="fas fa-vials"></i>
                        <br>آزمایش روتین
                    </div>
                    <div class="lab-card-btn" onclick="labGoTo('consult')">
                        <i class="fas fa-headset"></i>
                        <br>ارتباط با پزشکان مدیار
                    </div>
                </div>
                <button class="lab-btn small" onclick="labGoTo('entry')" style="margin-top:20px; background:#777; width:100%;">بازگشت</button>
            </div>

            <div class="lab-step" id="view-consult" style="text-align:center;">
                <div style="background:#e3f2fd; padding:30px; border-radius:16px; margin-bottom:25px; border:1px solid #bbdefb;">
                    <i class="fas fa-info-circle" style="font-size:40px; color:#3498db; margin-bottom:15px;"></i>
                    <p style="font-size:16px; font-weight:bold; line-height:1.8;"><?= wpautop(esc_html($consult_text)) ?></p>
                </div>
                <?php if($btn_link != '#'): ?>
                    <a href="<?= esc_attr($btn_link) ?>" class="lab-btn primary" style="text-decoration:none;margin: 0px;padding: 5px 25px;"><?= esc_html($btn_text) ?></a>
                <?php endif; ?>
                <br><br>
                <button class="lab-btn small" onclick="labGoTo('options')" style="background:#777">بازگشت</button>
            </div>

            <div class="lab-step" id="view-tests">
                <h3>انتخاب آزمایش</h3>
                
                <div class="lab-tabs" id="labTabsContainer">
                    <button class="lab-tab active" onclick="filterCat('all', this)">همه</button>
                    <?php foreach($cats as $c): ?>
                        <button class="lab-tab" onclick="filterCat('<?= md5(trim($c)) ?>', this)"><?= trim($c) ?></button>
                    <?php endforeach; ?>
                </div>
                
                <div class="lab-tabs-dots" id="labTabsDots"></div>
                
                <div class="lab-tests-list" style="max-height:450px; overflow-y:auto; padding:5px;">
                    <?php foreach($tests as $t): ?>
                    <div class="lab-test-item cat-<?= md5(trim($t->category)) ?>">
                        <div class="lab-test-head" onclick="toggleTestInfo(this)">
                            <span><?= $t->title ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="lab-test-body">
                            <div class="lab-test-desc">
                                <?= !empty($t->description) ? nl2br(esc_html($t->description)) : 'توضیحات تکمیلی ندارد.' ?>
                            </div>
                            <div class="lab-test-footer">
                                <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-start;">
                                    <?php if($show_price): ?>
                                        <span class="lab-price"><?= number_format($t->price) ?> تومان</span>
                                    <?php endif; ?>
                                    <?php if($show_text): ?>
                                        <span class="lab-price" style="font-size:13px; color:#777; font-weight:normal;"><?= esc_html($price_alt_text) ?></span>
                                    <?php endif; ?>
                                </div>
                                <button class="lab-btn small" onclick='addToCart(<?= json_encode($t) ?>)'>افزودن +</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="lab-cart">
                    <h4>لیست انتخابی شما</h4>
                    <div id="cart-items"></div>
                    <button class="lab-btn success" onclick="proceedToInfo()" style="width:100%; margin-top:15px;">تایید و تکمیل مشخصات </button>
                </div>
                <button class="lab-btn small" onclick="labGoTo('options')" style="margin-top:15px; background:#999; width:100%;">بازگشت</button>
            </div>

            <div class="lab-step" id="view-info">
                <h3>اطلاعات پذیرش</h3>
                
                <div id="pres-container">
                    <h4 class="lab-section-title">اطلاعات نسخه</h4>
                    <div class="lab-radio-group">
                        <label><input type="radio" name="ptype" value="electronic" checked onclick="labTogglePresInput('elec')"> نسخه الکترونیک (کد رهگیری)</label>
                        <label><input type="radio" name="ptype" value="paper" onclick="labTogglePresInput('paper')"> نسخه کاغذی (آپلود عکس)</label>
                    </div>
                    <div id="inp-elec">
                        <input type="text" id="pres_code" placeholder="کد رهگیری نسخه" class="lab-input">
                    </div>
                    <div id="inp-paper" style="display:none">
                        <div class="lab-upload-area" style="padding:20px; margin-bottom:20px;">
                            <p style="margin:0;">برای انتخاب عکس نسخه کلیک کنید</p>
                            <input type="file" id="pres_file" accept="image/*,application/pdf">
                        </div>
                        <div id="file-selected-name" style="margin-top: -10px; margin-bottom: 20px; color: #27ae60; font-weight: bold; font-size: 13px; text-align: center;"></div>
                    </div>
                </div>

                <h4 class="lab-section-title">اطلاعات فردی</h4>
                <div class="row-lab">
                     <input type="text" id="fullname" placeholder="نام و نام خانوادگی بیمار" class="lab-input half">
                    <input type="tel" id="mobile" placeholder="شماره موبایل (جهت دریافت کد تایید)" class="lab-input half">
                </div>
                <h4 class="lab-section-title">اطلاعات محل سکونت</h4>
                <div class="row-lab">
                    <select id="province" class="lab-input half">
                        <option value="">درحال بارگذاری...</option>
                    </select>
                    <select id="city" class="lab-input half">
                        <option value="">ابتدا استان را انتخاب کنید</option>
                    </select>
                </div>
                <textarea id="address" placeholder="آدرس دقیق جهت نمونه‌گیری" class="lab-input" rows="3"></textarea>

                <h4 class="lab-section-title">اطلاعات تکمیلی</h4>
                <input type="number" id="nat_code" placeholder="کد ملی بیمار" class="lab-input">
                <div class="row-lab">
                    <select id="ins_type" class="lab-input half">
                        <option value="">انتخاب بیمه پایه...</option>
                        <option>تامین اجتماعی</option>
                        <option>سلامت</option>
                        <option>نیروهای مسلح</option>
                        <option>آزاد</option>
                    </select>
                    <select id="sup_ins" class="lab-input half">
                        <option value="">بیمه تکمیلی...</option>
                        <option>ندارم</option>
                        <option>دی</option>
                        <option>دانا</option>
                        <option>ایران</option>
                        <option>آسیا</option>
                        <option>SOS</option>
                    </select>
                </div>

                <button class="lab-btn primary" onclick="sendOTP()" style="width:100%; margin-top:20px;">دریافت کد تایید</button>
                <button class="lab-btn small" onclick="goBackFromInfo()" style="margin-top:15px; background:#fff; color:#777; width:100%;">بازگشت</button>
            </div>

            <div class="lab-step" id="view-otp" style="text-align:center;">
                <h3>احراز هویت</h3>
                <p>کد ۵ رقمی ارسال شده به موبایل را وارد کنید</p>
                <input type="text" id="otp_code" class="lab-input" style="text-align:center; font-size:24px; letter-spacing:8px; width:60%; margin:20px auto;">
                <button id="btn-verify" class="lab-btn primary" onclick="verifyOTP()" style="width:100%;">ثبت نهایی درخواست</button>
                <br>
                <button class="lab-btn small" onclick="labGoTo('info')" style="background:none; color:#777; margin-top:15px;">اصلاح شماره موبایل</button>
            </div>

            <div class="lab-step" id="view-success" style="text-align:center; padding:30px 0;">
                <div class="success-icon-container">
                    <i class="fas fa-check"></i>
                </div>
                <h2 style="color:#27ae60; margin-bottom:10px;">درخواست با موفقیت ثبت شد!</h2>
                <p style="color:#666; margin-bottom:30px;">همکاران ما به زودی درخواست شما را بررسی کرده و نتیجه را پیامک می‌کنند.</p>
                
                <div class="tracking-code-box">
                    <span class="tracking-code-label">کد پیگیری شما</span>
                    <span class="tracking-code-value" id="final-token">...</span>
                </div>

                <a href="<?= home_url() ?>" class="lab-btn primary" style="text-decoration:none;">بازگشت به صفحه اصلی</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // ==========================================
    // توابع AJAX
    // ==========================================
    public function ajax_send_otp() {
        $mob = sanitize_text_field($_POST['mobile']);
        if(!preg_match('/^09[0-9]{9}$/', $mob)) wp_send_json_error(['msg'=>'فرمت شماره موبایل صحیح نیست (مثال: 0912...)']);
        
        $code = rand(10000, 99999);
        $res = Lab_API::send_sms($mob, get_option('lab_pat_otp'), ['code'=>$code]);
        
        if($res['status'] === true) {
            set_transient('lab_otp_'.$mob, $code, 600);
            wp_send_json_success();
        } else {
            wp_send_json_error(['msg' => 'خطا در سامانه پیامک: ' . $res['msg']]);
        }
    }

    public function ajax_verify_otp() {
        $mob = sanitize_text_field($_POST['mobile']);
        $code = sanitize_text_field($_POST['code']);
        
        if(get_transient('lab_otp_'.$mob) == $code) {
            delete_transient('lab_otp_'.$mob);
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }

    public function ajax_submit() {
        global $wpdb;
        $token = wp_generate_password(8, false, false);
        $pres_data = isset($_POST['pres_data']) ? sanitize_text_field($_POST['pres_data']) : '';
        
        if(isset($_FILES['pres_file']) && !empty($_FILES['pres_file']['name'])) {
            $u = wp_upload_dir();
            $ext = pathinfo($_FILES['pres_file']['name'], PATHINFO_EXTENSION);
            $new_name = uniqid('pres_') . '.' . $ext;
            
            if (!file_exists($u['basedir'] . '/lab_secure/')) {
                wp_mkdir_p($u['basedir'] . '/lab_secure/');
            }
            
            if(move_uploaded_file($_FILES['pres_file']['tmp_name'], $u['basedir'].'/lab_secure/'.$new_name)) {
                $pres_data = $new_name;
            }
        }

        $items = isset($_POST['items']) ? stripslashes($_POST['items']) : '[]';

        $result = $wpdb->insert($wpdb->prefix.'lab_orders', [
            'token' => $token,
            'fullname' => sanitize_text_field($_POST['fullname']),
            'mobile' => sanitize_text_field($_POST['mobile']),
            'province' => sanitize_text_field($_POST['province']),
            'city' => sanitize_text_field($_POST['city']),
            'address' => sanitize_textarea_field($_POST['address']),
            'national_code' => sanitize_text_field($_POST['national_code']),
            'insurance' => sanitize_text_field($_POST['insurance']),
            'sup_insurance' => sanitize_text_field($_POST['sup_insurance']),
            'pres_type' => sanitize_text_field($_POST['pres_type']),
            'pres_data' => $pres_data,
            'items' => $items,
        ]);
        
        if($result) {
            $customer_name   = sanitize_text_field($_POST['fullname']);
            $customer_mobile = sanitize_text_field($_POST['mobile']);
            Lab_API::send_sms($customer_mobile, get_option('lab_pat_submit'), ['name'=>$customer_name]);

            // پیامک به ادمین‌های انتخاب‌شده در تنظیمات ← پیامک ادمین درخواست‌ها
            if ( class_exists( 'Lab_Admin_Sms_Settings' ) ) {
                Lab_Admin_Sms_Settings::notify_admins_new_order( [
                    'name'          => $customer_name,
                    'mobile'        => $customer_mobile,
                    'token'         => $token,
                    'city'          => sanitize_text_field( $_POST['city'] ?? '' ),
                    'province'      => sanitize_text_field( $_POST['province'] ?? '' ),
                    'address'       => sanitize_textarea_field( $_POST['address'] ?? '' ),
                    'national_code' => sanitize_text_field( $_POST['national_code'] ?? '' ),
                    'insurance'     => sanitize_text_field( $_POST['insurance'] ?? '' ),
                    'sup_insurance' => sanitize_text_field( $_POST['sup_insurance'] ?? '' ),
                    'pres_type'     => sanitize_text_field( $_POST['pres_type'] ?? '' ),
                ] );
            }

            wp_send_json_success(['token' => $token]);
        } else {
            wp_send_json_error(['msg' => 'خطا در ذخیره سفارش در دیتابیس']);
        }
    }

    // ==========================================
    // هندلینگ پیگیری و پرداخت
    // ==========================================
    public function handle_tracking_payment() {
        if(isset($_GET['lab_track'])) {
            global $wpdb;
            $token = sanitize_text_field($_GET['lab_track']);
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lab_orders WHERE token=%s", $token));
            
            if(!$order) wp_die('سفارش یافت نشد یا کد پیگیری اشتباه است.');

            if(isset($_POST['pay_now']) && $order->status == 'waiting_payment') {
                $res = Lab_API::payment_request($order->admin_price, home_url("/?lab_verify=1&oid={$order->id}"), "پرداخت سفارش {$order->id}", $order->mobile);
                if($res['status']) wp_redirect($res['url']); else wp_die($res['msg']);
                exit;
            }

            include LAB_PLUGIN_DIR . 'assets/tracking-template.php'; 
            exit;
        }

        if(isset($_GET['lab_verify']) && isset($_GET['Authority'])) {
            global $wpdb;
            $oid = intval($_GET['oid']);
            $order = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}lab_orders WHERE id=$oid");
            
            $res = Lab_API::payment_verify($order->admin_price, $_GET['Authority']);
            
            if($res['status']) {
                $wpdb->update($wpdb->prefix.'lab_orders', ['status'=>'paid', 'payment_ref'=>$res['ref_id']], ['id'=>$oid]);
                Lab_API::send_sms($order->mobile, get_option('lab_pat_success'), ['name'=>$order->fullname]);
                wp_redirect(home_url("/?lab_track={$order->token}"));
            } else {
                wp_die('پرداخت ناموفق بود. لطفا مجدد تلاش کنید.');
            }
            exit;
        }
    }
}
new Lab_Shortcode();