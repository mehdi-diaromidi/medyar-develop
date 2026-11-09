<?php 
/* Template Name: Pharmacy Tracking */
get_header(); 

// تبدیل وضعیت‌ها به عدد برای نمایش نوار پیشرفت (Stepper)
$steps_map = [
    'pending' => 1, 
    'waiting_payment' => 2, 
    'paid' => 3, 
    'completed' => 4
];
$current_step = isset($steps_map[$order->status]) ? $steps_map[$order->status] : 1;

// دیکد کردن اقلام فاکتور از دیتابیس
$invoice_items = [];
if(!empty($order->invoice_items)) {
    $decoded = json_decode($order->invoice_items, true);
    if(is_array($decoded)) $invoice_items = $decoded;
}
?>

<style>
    /* مخفی کردن هدر و فوتر قالب */
    header#header-container, #site-footer, .footer-widgets, .site-header, .site-footer { display: none !important; }
    
    /* استایل بدنه صفحه پیگیری */
    .phar-track-page-wrapper { background: #f4f6f8; min-height: 100vh; padding: 30px 10px; font-family: inherit; }
    
    /* استایل بخش پرداخت */
    .payment-action-box {
        background: #fff; border: 2px dashed #3498db; padding: 25px; 
        border-radius: 12px; text-align: center; margin-top: 30px;
        box-shadow: 0 5px 15px rgba(52, 152, 219, 0.1);
    }
</style>

<div class="phar-track-page-wrapper">
    <div class="phar-track-card">
        
        <div class="lab-track-header">
            <h2><i class="fas fa-file-medical-alt"></i> پیگیری نسخه داروخانه</h2>
            <p>شماره سفارش: <strong>#<?= $order->id ?></strong> | تاریخ ثبت: <?= date_i18n('j F Y', strtotime($order->created_at)) ?></p>
        </div>

        <div class="lab-track-body">
            
            <div class="lab-status-steps">
                <div class="step-item <?= $current_step >= 1 ? 'active' : '' ?>">
                    <div class="step-icon"><i class="fas fa-search"></i></div>
                    <div class="step-text">بررسی نسخه</div>
                </div>
                <div class="step-item <?= $current_step >= 2 ? 'active' : '' ?>">
                    <div class="step-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="step-text">قیمت‌گذاری</div>
                </div>
                <div class="step-item <?= $current_step >= 3 ? 'active' : '' ?>">
                    <div class="step-icon"><i class="fas fa-box-open"></i></div>
                    <div class="step-text">آماده‌سازی</div>
                </div>
                <div class="step-item <?= $current_step >= 4 ? 'active' : '' ?>">
                    <div class="step-icon"><i class="fas fa-motorcycle"></i></div>
                    <div class="step-text">تکمیل/ارسال</div>
                </div>
            </div>

            <div style="text-align: center; margin-bottom: 30px;">
                <span class="badge dark" style="font-size: 14px; padding: 8px 25px; background: #34495e; color: #fff; border-radius: 20px;">
                    <?php
                    switch($order->status){
                        case 'pending': echo 'در حال بررسی توسط دکتر داروساز...'; break;
                        case 'waiting_payment': echo 'نسخه تایید شد. لطفا فاکتور را پرداخت کنید.'; break;
                        case 'paid': echo 'پرداخت موفق. در حال آماده‌سازی داروها...'; break;
                        case 'completed': echo 'سفارش تکمیل و ارسال شد.'; break;
                    }
                    ?>
                </span>
            </div>

            <?php if(!empty($invoice_items)): ?>
                <div class="track-box invoice-details" style="border: 1px solid #eee; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h3 style="color:#2c3e50; margin-top:0; font-size: 18px; border-bottom: 2px solid #3498db; padding-bottom: 10px; display:inline-block;">
                        <i class="fas fa-list-ol"></i> ریز اقلام نسخه
                    </h3>
                    
                    <table class="phar-invoice-table">
                        <thead>
                            <tr>
                                <th>نام دارو / خدمت</th>
                                <th style="text-align:center; width: 80px;">تعداد</th>
                                <th style="text-align:left; width: 120px;">قیمت کل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($invoice_items as $item): ?>
                            <tr>
                                <td><strong><?= esc_html($item['name']) ?></strong></td>
                                <td style="text-align:center;"><?= esc_html($item['count']) ?></td>
                                <td style="text-align:left;"><?= number_format((int)$item['price']) ?> تومان</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="phar-invoice-total">
                        مبلغ کل: <?= number_format($order->total_price) ?> تومان
                    </div>
                </div>
            <?php elseif($order->status != 'pending'): ?>
                <div class="track-box" style="text-align:center; color:#777; background:#f9f9f9; padding:15px;">
                    جزییات فاکتور ثبت نشده است.
                </div>
            <?php endif; ?>


            <?php if($order->status == 'waiting_payment'): ?>
                <div class="payment-action-box">
                    <h3 style="margin:0 0 15px 0; color:#3498db;">تایید نهایی و پرداخت</h3>
                    <p style="margin-bottom:20px; color:#666;">مبلغ فاکتور بالا تایید شده است. جهت ارسال دارو لطفا پرداخت کنید.</p>
                    <form method="post">
                        <button type="submit" name="pay_now" class="lab-btn success" style="width:100%; max-width:350px; font-size:18px; padding: 15px; box-shadow: 0 5px 20px rgba(46, 204, 113, 0.4);">
                            <i class="fas fa-lock"></i> پرداخت آنلاین
                        </button>
                    </form>
                </div>
            <?php endif; ?>


            <?php if($order->status == 'completed'): ?>
                <div class="track-box result" style="border-top: 1px solid #eee; margin-top: 30px; padding-top: 20px;">
                    <div style="text-align:center; margin-bottom:25px;">
                        <i class="fas fa-check-circle" style="font-size:70px; color:#27ae60; margin-bottom:15px;"></i>
                        <h3 style="margin:0; color:#27ae60; font-size: 22px;">سفارش تکمیل شده است</h3>
                    </div>

                    <?php if(!empty($order->usage_notes)): ?>
                        <div class="phar-note-box" style="background: #fff3e0; border-left: 5px solid #ff9800; color: #e65100; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin:0 0 10px 0; display:flex; align-items:center;">
                                <i class="fas fa-capsules" style="font-size:20px; margin-left:8px;"></i> 
                                دستور مصرف / توصیه داروساز:
                            </h4>
                            <p style="margin:0;line-height:1.8;white-space: pre-line;font-weight: 500;color: #555;text-align: justify;">
                                <?= esc_html($order->usage_notes) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($order->delivery_notes)): ?>
                        <div class="phar-note-box delivery" style="background: #eaf2f8; border-left: 5px solid #3498db; color: #2980b9; padding: 15px; border-radius: 8px;">
                            <h4 style="margin:0 0 10px 0; display:flex; align-items:center;">
                                <i class="fas fa-truck" style="font-size:20px; margin-left:8px;"></i> 
                                وضعیت تحویل / ارسال:
                            </h4>
                            <p style="margin:0;line-height:1.8;white-space: pre-line;font-weight: 500;color: #555;text-align: justify;">
                                <?= esc_html($order->delivery_notes) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 40px;">
                 <a href="<?= home_url() ?>" class="lab-btn small" style="background:#ecf0f1; color:#7f8c8d; border:1px solid #bdc3c7;">بازگشت به صفحه اصلی</a>
            </div>

        </div>
    </div>
</div>

<?php get_footer(); ?>