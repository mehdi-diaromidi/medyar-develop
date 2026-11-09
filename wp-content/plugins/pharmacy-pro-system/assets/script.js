/* assets/script.js - نسخه نهایی سیستم داروخانه (Pharmacy Pro) */

// ============================================
// 0. متغیرهای سراسری
// ============================================
var allProvinces = [];
var allCities = [];

jQuery(document).ready(function($) {
    // فقط روی صفحه فرم داروخانه — جلوگیری از بازنویسی submitFinalOrder آزمایشگاه
    if (!$('#phar-booking-app').length) {
        return;
    }

    console.log("Pharmacy Script Loaded: V 1.0");

    // دریافت اطلاعات شهر/استان هنگام لود شدن صفحه
    loadLocationData();

    // ============================================
    // 1. منطق شهر و استان (JSON محلی)
    // ============================================
    function loadLocationData() {
        // نام آبجکت ارسالی از PHP اکنون phar_ajax است
        var provUrl = phar_ajax.assets_url + 'provinces.json';
        var cityUrl = phar_ajax.assets_url + 'cities.json';

        $.when($.getJSON(provUrl), $.getJSON(cityUrl))
        .done(function(pRes, cRes) {
            allProvinces = pRes[0];
            allCities = cRes[0];

            var provOptions = '<option value="">انتخاب استان...</option>';
            $.each(allProvinces, function(index, p) {
                provOptions += `<option value="${p.name}" data-id="${p.id}">${p.name}</option>`;
            });

            $('#phar-booking-app #province').html(provOptions);
            $('#phar-booking-app #city').html('<option value="">ابتدا استان را انتخاب کنید</option>');
        })
        .fail(function() {
            console.error("خطا در لود کردن فایل‌های شهر و استان");
            $('#phar-booking-app #province').html('<option value="">خطا در بارگذاری لیست شهرها</option>');
        });
    }

    // تغییر شهرها بر اساس استان انتخاب شده
    $(document).on('change', '#phar-booking-app #province', function() {
        var selectedProvId = $(this).find(':selected').data('id');
        var citySelect = $('#phar-booking-app #city');
        
        citySelect.html('<option value="">انتخاب شهر...</option>');

        if(!selectedProvId) {
            citySelect.html('<option value="">ابتدا استان را انتخاب کنید</option>');
            return;
        }

        var filteredCities = allCities.filter(function(c) {
            return c.province_id == selectedProvId;
        });

        var cityOptions = '<option value="">انتخاب شهر...</option>';
        $.each(filteredCities, function(i, city) {
            cityOptions += `<option value="${city.name}">${city.name}</option>`;
        });
        
        citySelect.html(cityOptions);
    });

    // ============================================
    // 2. توابع ناوبری و UI
    // ============================================
    
    // تابع تغییر مرحله (Step)
    window.pharGoTo = function(viewId) {
        $('.lab-step').removeClass('active'); // مخفی کردن همه مراحل
        $('#view-' + viewId).addClass('active'); // نمایش مرحله جدید
        
        // اسکرول نرم به بالای فرم
        $('html, body').animate({ scrollTop: $("#phar-booking-app").offset().top - 100 }, 300);
    };

    // شروع فرآیند (نسخه دارم / ندارم)
    window.startPharFlow = function(type) {
        if(type === 'pres') {
            $('#pres-container').show();
            window.pharGoTo('info'); 
        } else {
            window.pharGoTo('nopres');
        }
    };

    // تغییر بین نسخه الکترونیک و کاغذی
    window.pharTogglePresInput = function(mode) {
        if(mode === 'elec') { 
            $('#phar-booking-app #inp-elec').slideDown(200); 
            $('#phar-booking-app #inp-paper').slideUp(200); 
        } else { 
            $('#phar-booking-app #inp-elec').slideUp(200); 
            $('#phar-booking-app #inp-paper').slideDown(200); 
        }
    };
    window.togglePresInput = window.pharTogglePresInput;

    // نمایش نام فایل‌های انتخاب شده (چندگانه)
    $(document).on('change', '#pres_files', function() {
        var fileList = this.files;
        var output = [];
        for (var i = 0; i < fileList.length; i++) {
            output.push('<i class="fas fa-file-image"></i> ' + fileList[i].name);
        }
        
        if (output.length > 0) {
            $('#files-selected-list').html(output.join('<br>'));
        } else {
            $('#files-selected-list').html('');
        }
    });

    // ============================================
    // 3. توابع AJAX (ارسال OTP)
    // ============================================
    window.sendPharOTP = function() {
        var $app = $('#phar-booking-app');
        var m = $app.find('#mobile').val();
        var n = $app.find('#fullname').val();
        var prov = $app.find('#province').val();
        
        if(!n || n.length < 2) { alert('لطفا نام و نام خانوادگی را وارد کنید'); return; }
        if(!m || m.length < 10) { alert('شماره موبایل صحیح نیست'); return; }
        if(!prov) { alert('لطفا استان و شهر را انتخاب کنید'); return; }

        var btn = event.target;
        var oldText = $(btn).text();
        $(btn).text('در حال ارسال...').prop('disabled', true);

        $.post(phar_ajax.url, { action: 'phar_send_otp', mobile: m }, function(res){
            $(btn).text(oldText).prop('disabled', false);
            
            if(res.success) {
                window.pharGoTo('otp');
            } else {
                alert(res.data.msg || 'خطا در ارسال پیامک');
            }
        }).fail(function() {
            alert('خطای ارتباط با سرور');
            $(btn).text(oldText).prop('disabled', false);
        });
    };

    // بررسی کد OTP وارد شده
    window.verifyPharOTP = function() {
        var $app = $('#phar-booking-app');
        var m = $app.find('#mobile').val();
        var c = $app.find('#otp_code').val();
        
        if(c.length < 4) { alert('کد تایید را وارد کنید'); return; }

        var btn = $app.find('#btn-verify');
        var oldText = btn.text();
        btn.text('بررسی...').prop('disabled', true);

        $.post(phar_ajax.url, { action: 'phar_verify_otp', mobile: m, code: c }, function(res){
            if(res.success) {
                window.pharSubmitFinalOrder();
            } else {
                alert('کد وارد شده اشتباه است');
                btn.text(oldText).prop('disabled', false);
            }
        }).fail(function() {
            alert('خطای ارتباط با سرور');
            btn.text(oldText).prop('disabled', false);
        });
    };

    // ============================================
    // 4. ثبت سفارش نهایی (نام یکتا — تداخل با آزمایشگاه نداشته باشد)
    // ============================================
    window.pharSubmitFinalOrder = function() {
        var fd = new FormData();
        var $app = $('#phar-booking-app');
        
        fd.append('action', 'phar_submit');
        fd.append('fullname', $app.find('#fullname').val());
        fd.append('mobile', $app.find('#mobile').val());
        fd.append('province', $app.find('#province').val());
        fd.append('city', $app.find('#city').val());
        fd.append('address', $app.find('#address').val());
        fd.append('national_code', $app.find('#nat_code').val());
        fd.append('insurance', $app.find('#ins_type').val());
        fd.append('insurance_code', $app.find('#ins_code').val());
        
        var pType = $app.find('input[name="ptype"]:checked').val();
        fd.append('pres_type', pType);

        if(pType === 'electronic') {
            fd.append('pres_data', $app.find('#pres_code').val());
        } else {
            var filesInput = $app.find('#pres_files')[0];
            var files = filesInput ? filesInput.files : [];
            for(var i=0; i<files.length; i++){
                fd.append('pres_files[]', files[i]);
            }
        }

        var btn = $app.find('#btn-verify');
        btn.text('در حال ثبت...').prop('disabled', true);
        
        $.ajax({
            url: phar_ajax.url,
            type: 'POST',
            data: fd,
            contentType: false,
            processData: false,
            success: function(res) {
                if(res.success) {
                    $app.find('#final-token').text(res.data.token);
                    window.pharGoTo('success');
                } else {
                    alert('خطا در ثبت درخواست: ' + (res.data.msg || 'نامشخص'));
                    btn.text('تلاش مجدد').prop('disabled', false);
                }
            },
            error: function() {
                alert('خطای سرور در هنگام ثبت اطلاعات');
                btn.text('تلاش مجدد').prop('disabled', false);
            }
        });
    };
    window.submitFinalOrder = window.pharSubmitFinalOrder;

});