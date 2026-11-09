<?php
if (!defined('ABSPATH')) exit;

class Phar_API {
    
    // ==========================================
    // 1. متد ارسال پیامک (وب‌سرویس جدید FarazSMS)
    // ==========================================
    public static function send_sms($phone, $pattern_code, $input_data) {
        $apiKey = trim(get_option('phar_sms_apikey'));
        $line = '3000505';
        
        if(empty($apiKey) || empty($pattern_code)) {
            return ['status' => false, 'msg' => 'تنظیمات پیامک (API Key یا پترن) در پنل مدیریت وارد نشده است.'];
        }

        $url = 'https://api.iranpayamak.com/ws/v1/sms/pattern';

        $body = [
            "code"          => $pattern_code,
            "attributes"    => $input_data,
            "recipient"     => $phone,
            "line_number"   => $line,
            "number_format" => "english"
        ];

        $args = [
            'body'        => json_encode($body),
            'timeout'     => 30,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'Api-Key'      => $apiKey // حساس به حروف بزرگ و کوچک
            ],
            'data_format' => 'body',
        ];

        $response = wp_remote_post($url, $args);

        // بررسی خطای ارتباطی (مشکل هاست یا شبکه)
        if (is_wp_error($response)) {
            return ['status' => false, 'msg' => 'خطای ارتباط با سرور پیامک: ' . $response->get_error_message()];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);

        if ($http_code === 401) {
            return ['status' => false, 'msg' => 'خطای احراز هویت: API Key نامعتبر است.'];
        }

        if ($http_code !== 200) {
            return ['status' => false, 'msg' => "خطای سامانه پیامک (کد $http_code). جزئیات: " . $body_response];
        }

        return ['status' => true, 'msg' => 'پیامک با موفقیت ارسال شد.', 'response' => $body_response];
    }

    // ==========================================
    // 2. متد ایجاد درخواست پرداخت (ZarinPal Rest V4)
    // ==========================================
    public static function payment_request($amount, $callback, $desc, $mobile) {
        $merchant = trim(get_option('phar_zp_merchant'));
        $sandbox = get_option('phar_zp_sandbox');
        
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
        $amount_rial = $amount * 10;

        $data = [
            "merchant_id"  => $merchant,
            "amount"       => $amount_rial,
            "callback_url" => $callback,
            "description"  => $desc,
            "metadata"     => ["mobile" => $mobile]
        ];
        
        $args = [
            'body'        => json_encode($data),
            'timeout'     => 30,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'data_format' => 'body',
        ];

        $response = wp_remote_post($baseUrl . '/pg/v4/payment/request.json', $args);

        // بررسی خطای ارتباطی شبکه
        if (is_wp_error($response)) {
            return ['status' => false, 'msg' => 'خطای اتصال به درگاه پرداخت: ' . $response->get_error_message()];
        }

        $body_response = wp_remote_retrieve_body($response);
        $result = json_decode($body_response, true);
        
        // کد 100: ساخت موفق لینک پرداخت
        if (isset($result['data']['code']) && $result['data']['code'] == 100) {
            return [
                'status' => true, 
                'url'    => "$baseUrl/pg/StartPay/" . $result['data']["authority"]
            ];
        }
        
        // مدیریت خطاهای زرین‌پال
        $msg = 'خطا در ایجاد تراکنش';
        if (isset($result['errors']['message'])) {
            $msg .= ': ' . $result['errors']['message'];
        } elseif (isset($result['errors']['code'])) {
            $msg .= ' (کد خطا: ' . $result['errors']['code'] . ')';
        }
        
        return ['status' => false, 'msg' => $msg];
    }

    // ==========================================
    // 3. متد تایید و وریفای پرداخت (ZarinPal Rest V4)
    // ==========================================
    public static function payment_verify($amount, $authority) {
        $merchant = trim(get_option('phar_zp_merchant'));
        $sandbox = get_option('phar_zp_sandbox');
        
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
        $amount_rial = $amount * 10;

        $data = [
            "merchant_id" => $merchant, 
            "authority"   => $authority, 
            "amount"      => $amount_rial
        ];
        
        $args = [
            'body'        => json_encode($data),
            'timeout'     => 30,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'data_format' => 'body',
        ];

        $response = wp_remote_post($baseUrl . '/pg/v4/payment/verify.json', $args);

        // اضافه شدن مدیریت خطای ارتباطی که در کد قبلی وجود نداشت
        if (is_wp_error($response)) {
            return ['status' => false, 'msg' => 'خطای ارتباط در مرحله تایید پرداخت: ' . $response->get_error_message()];
        }

        $body_response = wp_remote_retrieve_body($response);
        $result = json_decode($body_response, true);
        
        // کد 100: تراکنش موفق / کد 101: تراکنش قبلاً تایید شده است
        if (isset($result['data']['code']) && ($result['data']['code'] == 100 || $result['data']['code'] == 101)) {
            return [
                'status' => true, 
                'ref_id' => $result['data']['ref_id']
            ];
        }
        
        return ['status' => false, 'msg' => 'تراکنش توسط درگاه تایید نشد.'];
    }
}