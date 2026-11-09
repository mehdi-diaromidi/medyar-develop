<?php
if (!defined('ABSPATH')) exit;

class Lab_API {
    
    // ==========================================
    // بخش ارسال پیامک (وب‌سرویس جدید FarazSMS)
    // خروجی: آرایه شامل status و msg
    // ==========================================
    public static function send_sms($phone, $pattern_code, $input_data) {
        $apiKey = trim(get_option('lab_sms_apikey'));
        $line = '3000505';
        
        // 1. بررسی تنظیمات
        if(empty($apiKey) || empty($pattern_code)) {
            return ['status' => false, 'msg' => 'تنظیمات پیامک (API Key یا پترن) در پنل مدیریت وارد نشده است.'];
        }

        // آدرس جدید API سامانه پیامک بر اساس مستندات
        $url = 'https://api.iranpayamak.com/ws/v1/sms/pattern';

        // آماده‌سازی داده‌ها برای ساختار جدید فراز اس‌ام‌اس
        $body = [
            "code"          => $pattern_code,
            "attributes"    => $input_data,  // متغیرهای پترن
            "recipient"     => $phone,       // شماره گیرنده
            "line_number"   => $line,        // شماره خط ارسال کننده
            "number_format" => "english"
        ];

        // تنظیمات ارسال با توابع بومی وردپرس
        $args = [
            'body'        => json_encode($body),
            'timeout'     => 30,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'Api-Key'      => $apiKey // کلید با حروف بزرگ و کوچک دقیق
            ],
            'data_format' => 'body',
        ];

        $response = wp_remote_post($url, $args);

        // 2. بررسی خطاهای اتصال شبکه یا هاست
        if (is_wp_error($response)) {
            return ['status' => false, 'msg' => 'خطای ارتباط با سرور پیامک: ' . $response->get_error_message()];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);

        // 3. بررسی خطای احراز هویت
        if ($http_code === 401) {
            return ['status' => false, 'msg' => 'خطای احراز هویت: API Key نامعتبر است.'];
        }

        // 4. بررسی سایر خطاهای سمت پنل پیامک
        if ($http_code !== 200) {
            return ['status' => false, 'msg' => "خطای پنل پیامک (کد $http_code). جزئیات: " . $body_response];
        }

        // موفقیت
        return ['status' => true, 'msg' => 'پیامک با موفقیت ارسال شد.', 'response' => $body_response];
    }
    
    public static function payment_request($amount, $callback, $desc, $mobile) {
        $merchant = trim(get_option('lab_zp_merchant'));
        $sandbox = get_option('lab_zp_sandbox'); // حالت تست
        
        // تعیین آدرس زرین‌پال بر اساس حالت سندباکس یا واقعی
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
        
        // تبدیل تومان به ریال (زرین‌پال ریال می‌گیرد)
        $amount_rial = $amount * 10;

        $data = [
            "merchant_id" => $merchant,
            "amount" => $amount_rial,
            "callback_url" => $callback,
            "description" => $desc,
            "metadata" => ["mobile" => $mobile]
        ];
        
        $jsonData = json_encode($data);
        
        $ch = curl_init($baseUrl . '/pg/v4/payment/request.json');
        curl_setopt_array($ch, array(
            CURLOPT_USERAGENT => 'ZarinPal Rest Api v4',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonData)
            ),
        ));
        
        $result = json_decode(curl_exec($ch), true);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            return ['status' => false, 'msg' => 'خطای cURL در اتصال به درگاه: ' . $err];
        }
        
        // بررسی کد موفقیت (100)
        if (isset($result['data']['code']) && $result['data']['code'] == 100) {
            return [
                'status' => true, 
                'url' => "$baseUrl/pg/StartPay/" . $result['data']["authority"]
            ];
        }
        
        // مدیریت خطاهای زرین‌پال
        $msg = 'خطا در اتصال به درگاه';
        if(isset($result['errors']['message'])) $msg .= ': ' . $result['errors']['message'];
        elseif(isset($result['errors']['code'])) $msg .= ' (کد خطا: ' . $result['errors']['code'] . ')';
        
        return ['status' => false, 'msg' => $msg];
    }

    // ==========================================
    // 3. متد تایید و وریفای پرداخت
    // ==========================================
    public static function payment_verify($amount, $authority) {
        $merchant = trim(get_option('lab_zp_merchant'));
        $sandbox = get_option('lab_zp_sandbox');
        
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
        
        // تبدیل تومان به ریال
        $amount_rial = $amount * 10;

        $data = [
            "merchant_id" => $merchant, 
            "authority" => $authority, 
            "amount" => $amount_rial
        ];
        
        $jsonData = json_encode($data);
        
        $ch = curl_init($baseUrl . '/pg/v4/payment/verify.json');
        curl_setopt_array($ch, array(
            CURLOPT_USERAGENT => 'ZarinPal Rest Api v4',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonData)
            ),
        ));
        
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        // کد 100: تراکنش موفق
        // کد 101: تراکنش قبلاً تایید شده است (همچنان موفق)
        if (isset($result['data']['code']) && ($result['data']['code'] == 100 || $result['data']['code'] == 101)) {
            return [
                'status' => true, 
                'ref_id' => $result['data']['ref_id']
            ];
        }
        
        return ['status' => false];
    }
}