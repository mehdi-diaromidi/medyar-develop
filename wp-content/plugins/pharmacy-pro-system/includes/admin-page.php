<?php
if (!defined('ABSPATH')) exit;

class Phar_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        
        // نمایش تعداد سفارش‌های باز در نوار ابزار بالای وردپرس
        add_action('admin_bar_menu', [$this, 'admin_bar_notif'], 100);
    }

    public function admin_assets() {
        // لود استایل اختصاصی ادمین با ورژن زمانی
        wp_enqueue_style('phar-admin-style', PHAR_PLUGIN_URL . 'assets/style.css', [], time());
    }

    // ==========================================
    // نوار ابزار (Admin Bar Notification)
    // ==========================================
    public function admin_bar_notif($wp_admin_bar) {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'phar_orders';
        // شمارش سفارش‌هایی که هنوز تکمیل نشده‌اند
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status != 'completed'");

        if ($count > 0) {
            $args = [
                'id'    => 'phar_pending_orders',
                'title' => '<span class="ab-icon dashicons dashicons-warning" style="color:#f39c12; margin-top:2px;"></span> ' . 
                           '<span style="color:#f39c12; font-weight:bold;">' . $count . ' نسخه باز داروخانه</span>',
                'href'  => admin_url('admin.php?page=phar-orders'),
                'meta'  => ['title' => 'مشاهده سفارشات داروخانه']
            ];
            $wp_admin_bar->add_node($args);
        }
    }

    public function menu() {
        add_menu_page('داروخانه', 'داروخانه', 'manage_options', 'phar-orders', [$this, 'page_orders'], 'dashicons-prescriptions', 6);
        add_submenu_page('phar-orders', 'تنظیمات', 'تنظیمات', 'manage_options', 'phar-settings', [$this, 'page_settings']);
    }

    public function settings() {
        $options = [
            'phar_zp_merchant', 'phar_zp_sandbox', 
            'phar_sms_apikey', 'phar_sms_line',
            'phar_pat_otp', 'phar_pat_submit', 'phar_pat_paylink', 'phar_pat_success', 'phar_pat_result',
            'phar_text_nopres', 'phar_contact_btn_link', 'phar_contact_btn_text'
        ];
        foreach($options as $opt) register_setting('phar_opts', $opt);
    }

    // ==========================================
    // 1. صفحه تنظیمات
    // ==========================================
    public function page_settings() {
        ?>
        <div class="wrap">
            <h1>تنظیمات سیستم داروخانه (Pharmacy Pro)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('phar_opts'); ?>
                <table class="form-table">
                    <tr><th colspan="2"><h3 style="margin:0; border-bottom:1px solid #ccc; padding-bottom:10px;">درگاه پرداخت و پیامک</h3></th></tr>
                    <tr><th>مرچنت زرین پال</th><td><input type="text" name="phar_zp_merchant" value="<?php echo esc_attr(get_option('phar_zp_merchant')); ?>" class="regular-text"></td></tr>
                    <tr><th>حالت تست (Sandbox)</th><td><input type="checkbox" name="phar_zp_sandbox" value="1" <?php checked(1, get_option('phar_zp_sandbox')); ?>></td></tr>
                    <tr><th>API Key پیامک (IPanel)</th><td><input type="text" name="phar_sms_apikey" value="<?php echo esc_attr(get_option('phar_sms_apikey')); ?>" class="regular-text"></td></tr>
                    <tr><th>خط ارسال کننده</th><td><input type="text" name="phar_sms_line" value="<?php echo esc_attr(get_option('phar_sms_line')); ?>"></td></tr>
                    
                    <tr><th colspan="2"><h3 style="margin-top:20px; border-bottom:1px solid #ccc; padding-bottom:10px;">کدهای پترن پیامک</h3></th></tr>
                    <tr><th>پترن OTP (متغیر: code)</th><td><input type="text" name="phar_pat_otp" value="<?php echo esc_attr(get_option('phar_pat_otp')); ?>"></td></tr>
                    <tr><th>پترن ثبت درخواست (متغیر: name)</th><td><input type="text" name="phar_pat_submit" value="<?php echo esc_attr(get_option('phar_pat_submit')); ?>"></td></tr>
                    <tr><th>پترن لینک پرداخت (متغیر: name, link)</th><td><input type="text" name="phar_pat_paylink" value="<?php echo esc_attr(get_option('phar_pat_paylink')); ?>"></td></tr>
                    <tr><th>پترن پرداخت موفق (متغیر: name)</th><td><input type="text" name="phar_pat_success" value="<?php echo esc_attr(get_option('phar_pat_success')); ?>"></td></tr>
                    <tr><th>پترن تکمیل/نتیجه (متغیر: name, link)</th><td><input type="text" name="phar_pat_result" value="<?php echo esc_attr(get_option('phar_pat_result')); ?>"></td></tr>
                    
                    <tr><th colspan="2"><h3 style="margin-top:20px; border-bottom:1px solid #ccc; padding-bottom:10px;">متن‌ها و دکمه‌ها</h3></th></tr>
                    <tr><th>متن بخش "نسخه ندارم"</th><td><textarea name="phar_text_nopres" rows="3" class="large-text"><?php echo esc_textarea(get_option('phar_text_nopres')); ?></textarea></td></tr>
                    <tr><th>متن دکمه تماس</th><td><input type="text" name="phar_contact_btn_text" value="<?php echo esc_attr(get_option('phar_contact_btn_text')); ?>"></td></tr>
                    <tr><th>لینک دکمه تماس</th><td><input type="text" name="phar_contact_btn_link" value="<?php echo esc_attr(get_option('phar_contact_btn_link')); ?>" class="regular-text"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // ==========================================
    // 2. صفحه مدیریت سفارشات
    // ==========================================
    public function page_orders() {
        global $wpdb;
        $table = $wpdb->prefix . 'phar_orders';
        $upload_base = wp_upload_dir()['baseurl'] . '/pharmacy_secure/';

        // --- پردازش فرم ذخیره (مودال) ---
        if(isset($_POST['update_order_modal'])) {
            $oid = intval($_POST['oid']);
            
            // الف) پردازش ریز اقلام فاکتور (JSON)
            $invoice_json = isset($_POST['invoice_json']) ? stripslashes($_POST['invoice_json']) : '[]';
            $items = json_decode($invoice_json, true);
            
            // محاسبه قیمت کل
            $total_price = 0;
            if(is_array($items)) {
                foreach($items as $itm) {
                    $total_price += intval($itm['price']); 
                }
            }

            // ب) منطق تغییر وضعیت خودکار
            $current_status = $wpdb->get_var("SELECT status FROM $table WHERE id=$oid");
            $new_status = $current_status;

            if($current_status == 'pending' && $total_price > 0) {
                $new_status = 'waiting_payment';
            }
            
            if(isset($_POST['mark_completed'])) {
                $new_status = 'completed';
            }

            // ج) آپدیت دیتابیس
            $wpdb->update($table, [
                'invoice_items' => $invoice_json,
                'total_price'   => $total_price,
                'status'        => $new_status,
                'usage_notes'   => sanitize_textarea_field($_POST['usage_notes']),
                'delivery_notes'=> sanitize_textarea_field($_POST['delivery_notes']),
            ], ['id' => $oid]);

            // د) ارسال پیامک‌ها
            $o = $wpdb->get_row("SELECT * FROM $table WHERE id=$oid");
            $link = home_url('/?phar_track=' . $o->token);

            if(isset($_POST['send_sms_pay'])) {
                $res = Phar_API::send_sms($o->mobile, get_option('phar_pat_paylink'), ['name'=>$o->fullname, 'link'=>$link]);
                if($res['status']) echo '<div class="updated notice is-dismissible"><p>پیامک لینک پرداخت ارسال شد.</p></div>';
                else echo '<div class="error notice"><p>خطا در ارسال پیامک: '.esc_html($res['msg']).'</p></div>';
            }

            if(isset($_POST['send_sms_complete'])) {
                $res = Phar_API::send_sms($o->mobile, get_option('phar_pat_result'), ['name'=>$o->fullname, 'link'=>$link]);
                if($res['status']) echo '<div class="updated notice is-dismissible"><p>پیامک تکمیل سفارش ارسال شد.</p></div>';
            }

            echo '<div class="updated notice is-dismissible"><p>سفارش با موفقیت بروزرسانی شد.</p></div>';
        }

        // --- حذف سفارش ---
        if(isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['oid'])) {
            $oid = intval($_GET['oid']);
            $wpdb->delete($table, ['id' => $oid]);
            echo '<div class="updated notice is-dismissible" style="border-right:4px solid red;"><p>سفارش حذف شد.</p></div>';
        }

        // --- لیست‌گیری و صفحه بندی ---
        $pagenum = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $limit = 20;
        $offset = ( $pagenum - 1 ) * $limit;
        
        $search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where_clause = "WHERE 1=1";
        if($search_term) {
            $where_clause .= " AND (fullname LIKE '%$search_term%' OR mobile LIKE '%$search_term%' OR id LIKE '$search_term')";
        }

        $orders = $wpdb->get_results( "SELECT * FROM $table $where_clause ORDER BY id DESC LIMIT $offset, $limit" );
        $total = $wpdb->get_var( "SELECT COUNT(id) FROM $table $where_clause" );
        $num_of_pages = ceil( $total / $limit );
        ?>
        
        <div class="wrap">
            <h1 class="wp-heading-inline">مدیریت نسخه‌های داروخانه</h1>
            <form method="get">
                <input type="hidden" name="page" value="phar-orders">
                <?php $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : ''; ?>
                <p class="search-box">
                    <label class="screen-reader-text" for="post-search-input">جستجو:</label>
                    <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($search); ?>">
                    <input type="submit" id="search-submit" class="button" value="جستجو">
                </p>
            </form>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th width="60">شناسه</th>
                        <th>نام بیمار</th>
                        <th>موبایل</th>
                        <th>نوع بیمه</th>
                        <th>وضعیت</th>
                        <th>مبلغ کل</th>
                        <th>تاریخ</th>
                        <th width="150">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($orders): foreach($orders as $o): 
                        $st_color = '#777'; $st_text = $o->status;
                        if($o->status == 'pending') { $st_color = '#f39c12'; $st_text = 'بررسی نشده'; }
                        if($o->status == 'waiting_payment') { $st_color = '#3498db'; $st_text = 'منتظر پرداخت'; }
                        if($o->status == 'paid') { $st_color = '#27ae60'; $st_text = 'پرداخت شده'; }
                        if($o->status == 'completed') { $st_color = '#2c3e50'; $st_text = 'تکمیل شده'; }
                    ?>
                    <tr>
                        <td>#<?php echo $o->id; ?></td>
                        <td><strong><?php echo esc_html($o->fullname); ?></strong><br><small style="color:#888"><?php echo esc_html($o->national_code); ?></small></td>
                        <td><?php echo esc_html($o->mobile); ?></td>
                        <td><?php echo esc_html($o->insurance_type); ?></td>
                        <td><span style="background:<?php echo $st_color; ?>; color:#fff; padding:2px 6px; border-radius:4px; font-size:11px;"><?php echo $st_text; ?></span></td>
                        <td><?php echo number_format($o->total_price); ?> تومان</td>
                        <td><?php echo date_i18n('Y/m/d', strtotime($o->created_at)); ?></td>
                        <td>
                            <button type="button" class="button button-primary open-phar-modal" data-json='<?php echo json_encode($o); ?>'>مدیریت</button>
                            <a href="?page=phar-orders&action=delete&oid=<?php echo $o->id; ?>" class="button" style="color:#a00;" onclick="return confirm('حذف شود؟')">X</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="8">موردی یافت نشد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php
            if ($num_of_pages > 1) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages">';
                echo paginate_links(['base'=>add_query_arg('paged','%#%'), 'format'=>'', 'total'=>$num_of_pages, 'current'=>$pagenum]);
                echo '</div></div>';
            }
            ?>
        </div>

        <div id="pharModal" class="lab-modal-overlay">
            <div class="lab-modal" style="max-width: 900px;">
                <div class="lab-modal-header">
                    <h2 style="margin:0">جزئیات سفارش <span id="m_oid_disp"></span></h2>
                    <button type="button" class="lab-close-modal" onclick="document.getElementById('pharModal').style.display='none'">&times;</button>
                </div>
                <div class="lab-modal-body">
                    <form method="post" id="phar-admin-form">
                        <input type="hidden" name="update_order_modal" value="1">
                        <input type="hidden" name="oid" id="m_oid">
                        <input type="hidden" name="invoice_json" id="invoice_json_input">

                        <div style="background:#f9f9f9; padding:15px; border-radius:8px; border:1px solid #eee; margin-bottom:20px;">
                            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px;">
                                <div><strong>نام:</strong> <span id="m_name"></span></div>
                                <div><strong>موبایل:</strong> <span id="m_mobile"></span></div>
                                <div><strong>کد ملی:</strong> <span id="m_nat"></span></div>
                                <div><strong>بیمه:</strong> <span id="m_ins_type"></span></div>
                                <div style="grid-column: span 2; color:#d63638;"><strong>کد/شماره بیمه:</strong> <span id="m_ins_code" style="font-weight:bold; font-size:14px;"></span></div>
                                <div style="grid-column: span 3;"><strong>آدرس:</strong> <span id="m_addr"></span></div>
                            </div>
                        </div>

                        <div style="margin-bottom:20px;">
                            <h4>📷 تصاویر نسخه</h4>
                            <div id="m_images_container" style="display:flex; gap:10px; flex-wrap:wrap; background:#fff; border:2px dashed #ddd; padding:10px; min-height:80px;"></div>
                        </div>

                        <hr>

                        <div style="margin-bottom:20px;">
                            <h3 style="color:#0073aa;">💊 ریز اقلام دارویی (فاکتور)</h3>
                            <table class="widefat fixed striped" style="border:1px solid #ccc;">
                                <thead>
                                    <tr>
                                        <th>نام دارو / خدمت</th>
                                        <th width="100">تعداد</th>
                                        <th width="150">قیمت کل (تومان)</th>
                                        <th width="50">حذف</th>
                                    </tr>
                                </thead>
                                <tbody id="invoice_rows"></tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4">
                                            <button type="button" class="button" onclick="addNewRow()">+ افزودن سطر جدید</button>
                                            <span style="float:left; font-size:16px;"><strong>جمع کل: <span id="total_price_disp">0</span> تومان</strong></span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                            
                            <div style="margin-top:10px; background:#e5f5fa; padding:10px; border-right:4px solid #00a0d2;">
                                <label><input type="checkbox" name="send_sms_pay" value="1"> <strong>ارسال پیامک فاکتور</strong> (پس از ذخیره، لینک پرداخت برای کاربر ارسال می‌شود)</label>
                            </div>
                        </div>

                        <hr>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
                            <div>
                                <label style="font-weight:bold; display:block; margin-bottom:5px;">⚠️ دستور مصرف / توصیه داروساز:</label>
                                <textarea name="usage_notes" id="m_usage" class="large-text" rows="4" placeholder="مثال: قرص‌ها هر ۸ ساعت یکبار مصرف شود..."></textarea>
                            </div>
                            <div>
                                <label style="font-weight:bold; display:block; margin-bottom:5px;">🚚 اطلاعات ارسال / پیگیری:</label>
                                <textarea name="delivery_notes" id="m_delivery" class="large-text" rows="4" placeholder="مثال: تحویل به پیک داده شد. کد پیگیری: ۱۲۳۴۵"></textarea>
                            </div>
                        </div>

                        <div style="background:#fcf0f1; padding:15px; border-radius:5px; border:1px solid #f5c6cb; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <label style="font-size:14px; font-weight:bold; color:#a00;">
                                    <input type="checkbox" name="mark_completed" value="1">
                                    تغییر وضعیت به "تکمیل شده"
                                </label>
                                <br>
                                <label style="margin-right:20px;"><input type="checkbox" name="send_sms_complete" value="1"> ارسال پیامک اطلاع‌رسانی تکمیل</label>
                            </div>
                            <button type="submit" class="button button-primary button-hero">ذخیره نهایی</button>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <div id="pharLightbox" class="lab-lightbox" onclick="this.style.display='none'">
            <img id="pharLbImg" src="" style="max-height:90%;">
        </div>

        <script>
        var invoiceData = []; 
        const uploadBase = '<?php echo $upload_base; ?>';

        jQuery(document).ready(function($){
            // باز کردن مودال
            $('.open-phar-modal').click(function(){
                var data = $(this).data('json');
                
                $('#m_oid').val(data.id);
                $('#m_oid_disp').text('#' + data.id);
                $('#m_name').text(data.fullname);
                $('#m_mobile').text(data.mobile);
                $('#m_nat').text(data.national_code);
                $('#m_ins_type').text(data.insurance_type);
                $('#m_ins_code').text(data.insurance_code || '---');
                $('#m_addr').text(data.province + '، ' + data.city + '، ' + data.address);
                $('#m_usage').val(data.usage_notes);
                $('#m_delivery').val(data.delivery_notes);

                var imgHtml = '';
                if(data.pres_files && data.pres_files != 'null') {
                    try {
                        var files = JSON.parse(data.pres_files);
                        if(!Array.isArray(files)) files = [data.pres_files]; 
                        if(files.length > 0) {
                            files.forEach(function(fname){
                                if(fname.indexOf('CODE:') === 0) {
                                    imgHtml += '<div style="background:#eee; padding:5px; border-radius:4px;">'+fname+'</div>';
                                } else {
                                    var url = uploadBase + fname;
                                    imgHtml += `<div style="text-align:center;"><img src="${url}" style="height:80px; cursor:pointer; border:1px solid #ccc;" onclick="openPharLb('${url}')"><br><a href="${url}" target="_blank" style="font-size:10px;">دانلود</a></div>`;
                                }
                            });
                        } else { imgHtml = '<span>فایلی موجود نیست</span>'; }
                    } catch(e) { imgHtml = '<span>خطا در فایل</span>'; }
                } else { imgHtml = '<span>بدون فایل</span>'; }
                $('#m_images_container').html(imgHtml);

                invoiceData = [];
                if(data.invoice_items && data.invoice_items !== 'null' && data.invoice_items !== '') {
                    try { invoiceData = JSON.parse(data.invoice_items); } catch(e){}
                }
                renderTable(); 

                $('#pharModal').css('display', 'flex');
            });

            $(window).click(function(e) { if ($(e.target).is('#pharModal')) $('#pharModal').hide(); });
        });

        function addNewRow() {
            invoiceData.push({ name: '', count: 1, price: 0 });
            renderTable();
        }

        function removeRow(index) {
            invoiceData.splice(index, 1);
            renderTable();
        }

        function updateRow(index, field, value) {
            invoiceData[index][field] = value;
            recalculateTotal(); // فقط دیتا و جمع کل را آپدیت کن
        }

        // فقط برای رندر اولیه یا تغییر تعداد سطرها
        function renderTable() {
            var html = '';
            invoiceData.forEach(function(item, i){
                html += `<tr>
                    <td><input type="text" class="regular-text" style="width:100%" placeholder="نام دارو..." value="${item.name}" oninput="updateRow(${i}, 'name', this.value)"></td>
                    <td><input type="number" style="width:100%" value="${item.count}" oninput="updateRow(${i}, 'count', this.value)"></td>
                    <td><input type="number" style="width:100%" value="${item.price}" oninput="updateRow(${i}, 'price', this.value)"></td>
                    <td style="text-align:center;"><span class="dashicons dashicons-trash" style="color:red; cursor:pointer; margin-top:5px;" onclick="removeRow(${i})"></span></td>
                </tr>`;
            });
            if(invoiceData.length === 0) html = '<tr><td colspan="4" style="text-align:center; color:#888;">هیچ آیتمی اضافه نشده است.</td></tr>';
            
            document.getElementById('invoice_rows').innerHTML = html;
            recalculateTotal();
        }

        // فقط برای محاسبه ریاضی و آپدیت جیسون (بدون دستکاری HTML اینپوت‌ها)
        function recalculateTotal() {
            var total = 0;
            invoiceData.forEach(function(item){ total += parseInt(item.price) || 0; });
            document.getElementById('total_price_disp').innerText = new Intl.NumberFormat().format(total);
            document.getElementById('invoice_json_input').value = JSON.stringify(invoiceData);
        }

        function openPharLb(url) {
            document.getElementById('pharLbImg').src = url;
            document.getElementById('pharLightbox').style.display = 'flex';
        }
        </script>
        <?php
    }
}
new Phar_Admin();