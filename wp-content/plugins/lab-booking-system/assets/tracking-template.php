<?php 
/* Template Name: Lab Tracking */
get_header(); 

// تعیین وضعیت استپ‌ها
$steps = ['pending' => 1, 'waiting_payment' => 2, 'paid' => 3, 'completed' => 4];
$current_step = isset($steps[$order->status]) ? $steps[$order->status] : 1;

// تفکیک فایل‌ها (عکس‌ها و غیرعکس‌ها)
$files = json_decode($order->result_files, true);
$base = wp_upload_dir()['baseurl'] . '/lab_secure/';
$image_urls = [];
$other_files = [];

if($files) {
    foreach($files as $f) {
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        if(in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp'])) {
            $image_urls[] = $base . $f;
        } else {
            $other_files[] = ['url' => $base . $f, 'ext' => $ext];
        }
    }
}
?>
<style>header#header-container, #site-footer {
    display: none;
}
.lab-track-card {
    margin-top: 0 !important;
}</style>
<div class="lab-track-card">
    <div class="lab-track-header">
        <h2><i class="fas fa-laptop-medical"></i> پیگیری آزمایش</h2>
        <p>شماره پرونده: <strong><?= $order->id ?></strong> | تاریخ: <?= date_i18n('j F Y', strtotime($order->created_at)) ?></p>
    </div>

    <div class="lab-track-body">
        <div class="lab-status-steps">
            <div class="step-item <?= $current_step >= 1 ? 'active' : '' ?>">
                <div class="step-icon"><i class="fas fa-file-contract"></i></div>
                <div class="step-text">بررسی</div>
            </div>
            <div class="step-item <?= $current_step >= 2 ? 'active' : '' ?>">
                <div class="step-icon"><i class="fas fa-credit-card"></i></div>
                <div class="step-text">پرداخت</div>
            </div>
            <div class="step-item <?= $current_step >= 3 ? 'active' : '' ?>">
                <div class="step-icon"><i class="fas fa-microscope"></i></div>
                <div class="step-text">آزمایش</div>
            </div>
            <div class="step-item <?= $current_step >= 4 ? 'active' : '' ?>">
                <div class="step-icon"><i class="fas fa-clipboard-check"></i></div>
                <div class="step-text">تکمیل</div>
            </div>
        </div>

        <div style="text-align: center; margin-bottom: 30px;">
            <span style="background: #f0f0f0; padding: 8px 20px; border-radius: 50px; font-size: 14px; color:#555; font-weight:bold;">
                <?php
                switch($order->status){
                    case 'pending': echo 'در حال بررسی نسخه'; break;
                    case 'waiting_payment': echo 'منتظر پرداخت وجه'; break;
                    case 'paid': echo 'در حال انجام آزمایش'; break;
                    case 'completed': echo 'نتیجه آماده است'; break;
                }
                ?>
            </span>
        </div>

        <?php if($order->status == 'waiting_payment'): ?>
            <div class="track-box payment">
                <h3><i class="fas fa-file-invoice-dollar"></i> فاکتور پرداخت</h3>
                <p style="color:#666;"><?= !empty($order->admin_note) ? esc_html($order->admin_note) : 'لطفا مبلغ زیر را پرداخت کنید.' ?></p>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 25px; padding-top:15px; border-top:1px dashed #eee;">
                    <span class="lab-price" style="font-size:22px;"><?= number_format($order->admin_price) ?> <small style="font-size:14px; color:#777;">تومان</small></span>
                    <form method="post">
                        <button type="submit" name="pay_now" class="lab-btn success">پرداخت آنلاین <i class="fas fa-arrow-left" style="margin-right:5px;"></i></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if($order->status == 'completed'): ?>
            <div class="track-box result">
                <div style="text-align:center; margin-bottom:25px; padding-bottom:20px; border-bottom:1px dashed #ddd;">
                    <i class="fas fa-check-circle" style="font-size:50px; color:#0272b1; margin-bottom:15px;"></i>
                    <h3 style="margin:0; font-size:18px; color:#2c3e50; line-height:1.6;">
                        جواب آزمایش شما کاربر گرامی <span style="color:#0272b1;"><?= esc_html($order->fullname) ?></span> آماده است.
                    </h3>
                </div>

                <div class="result-grid">
                    
                    <?php if(!empty($image_urls)): ?>
                        <div class="file-card" onclick="openGalleryLightbox()" style="cursor:pointer; border: 2px solid #3498db; background:#f0f9ff;">
                            <i class="fas fa-images" style="color:#3498db;"></i>
                            <br>
                            <span style="font-weight:bold; font-size:14px; color:#3498db;">مشاهده تصاویر آزمایش</span>
                            <br>
                            <small style="color:#777; font-size:11px;">(<?= count($image_urls) ?> تصویر)</small>
                        </div>
                    <?php endif; ?>

                    <?php foreach($other_files as $pdf): ?>
                        <a href="<?= $pdf['url'] ?>" class="file-card" target="_blank" download>
                            <i class="fas fa-file-pdf" style="color:#e74c3c;"></i>
                            <br>
                            <span style="font-weight:bold; font-size:13px;">دانلود جواب آزمایش</span>
                        </a>
                    <?php endforeach; ?>

                </div>
            </div>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 30px;">
             <a href="<?= home_url() ?>" class="lab-btn small" style="background:#95a5a6; color:#fff;">بازگشت به صفحه اصلی</a>
        </div>
    </div>
</div>

<div id="trackLightbox" class="lab-lightbox" style="display:none;">
    <span class="lab-lb-close" onclick="closeGallery()">&times;</span>
    
    <span class="lab-lb-nav prev" onclick="changeImage(-1)"><i class="fas fa-chevron-right"></i></span>
    <span class="lab-lb-nav next" onclick="changeImage(1)"><i class="fas fa-chevron-left"></i></span>
    
    <img id="trackLbImg" src="">
    
    <div class="lab-lb-counter" id="lbCounter">1 / 1</div>
</div>

<script>
// انتقال آرایه تصاویر PHP به JS
var galleryImages = <?php echo json_encode($image_urls); ?>;
var currentImgIndex = 0;

function openGalleryLightbox() {
    if(galleryImages.length === 0) return;
    currentImgIndex = 0;
    showImage(currentImgIndex);
    document.getElementById('trackLightbox').style.display = 'flex';
}

function closeGallery() {
    document.getElementById('trackLightbox').style.display = 'none';
}

function changeImage(n) {
    currentImgIndex += n;
    // چرخش بین تصاویر (Loop)
    if (currentImgIndex >= galleryImages.length) { currentImgIndex = 0; }
    if (currentImgIndex < 0) { currentImgIndex = galleryImages.length - 1; }
    showImage(currentImgIndex);
}

function showImage(index) {
    var img = document.getElementById('trackLbImg');
    var counter = document.getElementById('lbCounter');
    
    img.src = galleryImages[index];
    counter.innerText = (index + 1) + " / " + galleryImages.length;
    
    // مخفی کردن دکمه‌های ناوبری اگر فقط یک عکس باشد
    if(galleryImages.length <= 1) {
        document.querySelectorAll('.lab-lb-nav').forEach(el => el.style.display = 'none');
    } else {
        document.querySelectorAll('.lab-lb-nav').forEach(el => el.style.display = 'flex');
    }
}

// بستن با کلیک بیرون
document.getElementById('trackLightbox').addEventListener('click', function(e){
    if(e.target === this) closeGallery();
});

// کیبورد (Arrow Keys)
document.addEventListener('keydown', function(e) {
    if(document.getElementById('trackLightbox').style.display === 'flex') {
        if(e.key === 'ArrowLeft') changeImage(1); // بعدی
        if(e.key === 'ArrowRight') changeImage(-1); // قبلی
        if(e.key === 'Escape') closeGallery();
    }
});
</script>

<?php get_footer(); ?>