jQuery(document).ready(function($){
    jalaliDatepicker.startWatch({
        hideAfterChange: true,
    });
    $(document).on('focus', '.medrec-date', function() {
        jalaliDatepicker.show(this); // نمایش تاریخ‌نگار
    });
});
