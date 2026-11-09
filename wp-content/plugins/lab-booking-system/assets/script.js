/* assets/script.js - نسخه نهایی و اصلاح شده */

// ============================================
// 0. متغیرهای سراسری (Global Variables)
// ============================================
var labCart = [];
var labUserFlow = 'pres'; // 'pres' یا 'routine'
var allProvinces = [];
var allCities = [];

jQuery(document).ready(function($) {
    // فقط روی صفحه‌ای که فرم آزمایشگاه هست اجرا شود
    // (جلوگیری از تداخل با اسکریپت داروخانه که توابع هم‌نام را بازنویسی می‌کرد)
    if (!$('#lab-booking-app').length) {
        return;
    }

    console.log("Lab Script Loaded: Final Version with File Preview");

    // دریافت اطلاعات شهر/استان و راه‌اندازی نقطه‌های موبایل
    loadLocationData();
    initMobileDots();
    
    // آپدیت نقطه‌ها هنگام تغییر سایز صفحه
    $(window).resize(function() { initMobileDots(); });

    // ============================================
    // 1. منطق شهر و استان (JSON محلی)
    // ============================================
    function loadLocationData() {
        var provUrl = lab_ajax.assets_url + 'provinces.json';
        var cityUrl = lab_ajax.assets_url + 'cities.json';

        $.when($.getJSON(provUrl), $.getJSON(cityUrl))
        .done(function(pRes, cRes) {
            allProvinces = pRes[0];
            allCities = cRes[0];

            var provOptions = '<option value="">انتخاب استان...</option>';
            $.each(allProvinces, function(index, p) {
                provOptions += `<option value="${p.name}" data-id="${p.id}">${p.name}</option>`;
            });

            $('#lab-booking-app #province').html(provOptions);
            $('#lab-booking-app #city').html('<option value="">ابتدا استان را انتخاب کنید</option>');
        })
        .fail(function() {
            console.error("خطا در لود کردن فایل‌های JSON شهر و استان");
            $('#lab-booking-app #province').html('<option value="">خطا در بارگذاری</option>');
        });
    }

    $(document).on('change', '#lab-booking-app #province', function() {
        var selectedProvId = $(this).find(':selected').data('id');
        var citySelect = $('#lab-booking-app #city');
        
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
    // 2. توابع ناوبری و UI (متصل به window)
    // ============================================
    window.labGoTo = function(viewId) {
        $('.lab-step').removeClass('active');
        $('#view-' + viewId).addClass('active');
        $('html, body').animate({ scrollTop: $("#lab-booking-app").offset().top - 60 }, 300);
    };

    window.startFlow = function(type) {
        labUserFlow = type;
        if(type === 'routine') {
            $('#pres-container').hide();
            window.labGoTo('tests'); 
        } else {
            $('#pres-container').show();
            window.labGoTo('info'); 
        }
    };

    window.proceedToInfo = function() {
        if(labCart.length === 0) {
            alert('لطفا حداقل یک آزمایش را انتخاب کنید.');
            return;
        }
        window.labGoTo('info');
    };

    window.goBackFromInfo = function() {
        if(labUserFlow === 'routine') window.labGoTo('tests');
        else window.labGoTo('entry');
    };

    window.toggleTestInfo = function(headerElement) {
        var body = $(headerElement).next('.lab-test-body');
        body.slideToggle(200);
        $(headerElement).toggleClass('open');
    };

    window.labTogglePresInput = function(mode) {
        if(mode === 'elec') { $('#lab-booking-app #inp-elec').show(); $('#lab-booking-app #inp-paper').hide(); }
        else { $('#lab-booking-app #inp-elec').hide(); $('#lab-booking-app #inp-paper').show(); }
    };
    // سازگاری با onclickهای قدیمی در HTML
    window.togglePresInput = window.labTogglePresInput;

    // ============================================
    // 3. توابع AJAX (ارسال و تایید)
    // ============================================
    window.sendOTP = function() {
        var $app = $('#lab-booking-app');
        var m = $app.find('#mobile').val();
        var n = $app.find('#fullname').val();
        var prov = $app.find('#province').val();
        
        if(!n || n.length < 2) { alert('لطفا نام و نام خانوادگی را وارد کنید'); return; }
        if(!m || m.length < 10) { alert('شماره موبایل صحیح نیست'); return; }
        if(!prov) { alert('لطفا استان و شهر را انتخاب کنید'); return; }

        var btn = event.target;
        var oldText = $(btn).text();
        $(btn).text('در حال ارسال...').prop('disabled', true);

        $.post(lab_ajax.url, { action: 'lab_send_otp', mobile: m }, function(res){
            $(btn).text(oldText).prop('disabled', false);
            if(res.success) {
                window.labGoTo('otp');
            } else {
                alert(res.data.msg || 'خطا در ارسال پیامک');
            }
        }).fail(function() {
            alert('خطای ارتباط با سرور');
            $(btn).text(oldText).prop('disabled', false);
        });
    };

    window.verifyOTP = function() {
        var $app = $('#lab-booking-app');
        var m = $app.find('#mobile').val();
        var c = $app.find('#otp_code').val();
        
        if(c.length < 4) { alert('کد تایید را وارد کنید'); return; }

        var btn = $app.find('#btn-verify');
        var oldText = btn.text();
        btn.text('بررسی...').prop('disabled', true);

        $.post(lab_ajax.url, { action: 'lab_verify_otp', mobile: m, code: c }, function(res){
            if(res.success) {
                window.labSubmitFinalOrder();
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
    // 4. ثبت سفارش نهایی (نام یکتا — تداخل با داروخانه نداشته باشد)
    // ============================================
    window.labSubmitFinalOrder = function() {
        var fd = gatherFormData();
        var $app = $('#lab-booking-app');
        
        if(labUserFlow === 'pres') {
            fd.append('pres_type', $app.find('input[name="ptype"]:checked').val());
            fd.append('pres_data', $app.find('#pres_code').val());
            var presFile = $app.find('#pres_file')[0];
            if(presFile && presFile.files[0]) fd.append('pres_file', presFile.files[0]);
            fd.append('items', '[]'); 
        } else {
            fd.append('pres_type', 'routine');
            fd.append('pres_data', 'Routine Checkup');
            fd.append('items', JSON.stringify(labCart));
        }

        performAjaxSubmit(fd);
    };
    window.submitFinalOrder = window.labSubmitFinalOrder;

    function gatherFormData() {
        var fd = new FormData();
        var $app = $('#lab-booking-app');
        fd.append('action', 'lab_submit');
        fd.append('fullname', $app.find('#fullname').val());
        fd.append('mobile', $app.find('#mobile').val());
        fd.append('province', $app.find('#province').val());
        fd.append('city', $app.find('#city').val());
        fd.append('address', $app.find('#address').val());
        fd.append('national_code', $app.find('#nat_code').val());
        fd.append('insurance', $app.find('#ins_type').val());
        fd.append('sup_insurance', $app.find('#sup_ins').val());
        return fd;
    }

    function performAjaxSubmit(formData) {
        var btn = $('#lab-booking-app #btn-verify');
        $.ajax({
            url: lab_ajax.url, type: 'POST', data: formData,
            contentType: false, processData: false,
            success: function(res) {
                if(res.success) {
                    $('#lab-booking-app #final-token').text(res.data.token);
                    window.labGoTo('success');
                } else {
                    alert('خطا در ثبت درخواست: ' + (res.data.msg || 'نامشخص'));
                    btn.text('تایید نهایی').prop('disabled', false);
                }
            },
            error: function() {
                alert('خطای سرور');
                btn.text('تایید نهایی').prop('disabled', false);
            }
        });
    }

    // ============================================
    // 5. سبد خرید و فیلترها
    // ============================================
    window.filterCat = function(cat, el) {
        $('.lab-tab').removeClass('active'); 
        $(el).addClass('active');
        if(cat === 'all') { $('.lab-test-item').show(); } 
        else { $('.lab-test-item').hide(); $('.cat-'+cat).show(); }
        if(window.innerWidth <= 600) {
            var idx = $(el).index();
            $('#labTabsDots .lab-dot').removeClass('active').eq(idx).addClass('active');
        }
    };

    window.addToCart = function(item) {
        var exists = labCart.find(function(x) { return x.id === item.id; });
        if(exists) { alert('این آزمایش قبلا انتخاب شده است.'); return; }
        labCart.push(item);
        renderCart();
        var btn = event.target;
        var originalText = $(btn).text();
        $(btn).text('✓');
        setTimeout(function() { $(btn).text(originalText); }, 1500);
    };

    window.renderCart = function() {
        var h = '';
        if(labCart.length === 0) {
            $('#cart-items').html('<p style="color:#aaa; text-align:center; font-size:12px;">هنوز موردی انتخاب نکرده‌اید.</p>');
            return;
        }
        labCart.forEach(function(i, idx) {
            h += '<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding:8px 0;">';
            h += '<span style="font-size:13px;">' + i.title + '</span>';
            h += '<span onclick="labCart.splice('+idx+',1);window.renderCart()" style="color:red; cursor:pointer; font-weight:bold; font-size:18px;" title="حذف">×</span>';
            h += '</div>';
        });
        $('#cart-items').html(h);
    };

    // ============================================
    // 6. مدیریت نقطه‌های کاروسل (Mobile Dots)
    // ============================================
    function initMobileDots() {
        var tabsContainer = $('#labTabsContainer');
        var dotsContainer = $('#labTabsDots');
        var tabs = tabsContainer.find('.lab-tab');
        if(window.innerWidth > 600 || tabs.length === 0) {
            dotsContainer.hide();
            return;
        }
        dotsContainer.show();
        dotsContainer.html('');
        tabs.each(function(index) {
            var activeClass = $(this).hasClass('active') ? 'active' : '';
            dotsContainer.append('<span class="lab-dot '+activeClass+'" data-index="'+index+'"></span>');
        });
        tabsContainer.on('scroll', function() {
            var containerWidth = $(this).width();
            tabs.each(function(index) {
                var tabLeft = $(this).position().left;
                if (Math.abs(tabLeft) < containerWidth / 2) {
                    dotsContainer.find('.lab-dot').removeClass('active').eq(index).addClass('active');
                }
            });
        });
        $(document).on('click', '.lab-dot', function() {
            var index = $(this).data('index');
            var targetTab = tabs.eq(index);
            targetTab.trigger('click');
            var pos = targetTab.position().left + tabsContainer.scrollLeft();
            tabsContainer.animate({ scrollLeft: pos }, 300);
        });
    }

    // ============================================
    // 7. نمایش نام فایل پس از انتخاب (نسخه کاغذی)
    // ============================================
    $(document).on('change', '#pres_file', function() {
        var fileName = $(this).val().split('\\').pop(); // استخراج نام فایل از مسیر
        if (fileName) {
            $('#file-selected-name').html('<i class="fas fa-check-circle" style="margin-left:5px;"></i> فایل انتخاب شد: ' + fileName);
        } else {
            $('#file-selected-name').html('');
        }
    });

});