<?php
/**
 * @var array<int,array<string,mixed>> $items
 * @var array<int,int>                 $usage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$toggle_type = 'job_type';
$action      = 'arp_save_job_type';
$singular    = 'نوع شغل';
$usage_label = 'تعداد کاربر';
$extra_col   = null;
$extra_label = '';
$help        = 'در فرم ثبت‌نام و پروفایل همکاران نمایش داده می‌شود.';

require __DIR__ . '/named-items.php';
