<?php
/**
 * Wallet settings — ARP-style tabs: general + SMS templates.
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/class-mtw-admin-ui.php';

$tabs = [
    'general' => 'عمومی',
    'sms'     => 'پیامک',
];
$tab = MTW_Admin_Ui::current_tab($tabs);

$sms_enabled = (int) get_option('wallet_sms_enabled', 1);
$deposit_sms_enabled = '1' === (string) get_option('arp_wallet_deposit_sms_enabled', '1');
$withdraw_sms_enabled = '1' === (string) get_option('arp_wallet_withdraw_sms_enabled', '1');

$sms_defaults = class_exists('Wallet_SMS') ? Wallet_SMS::default_templates() : [];
$sms_templates = [
    'deposit_completed'   => (string) get_option('arp_wallet_sms_deposit_completed', $sms_defaults['deposit_completed'] ?? ''),
    'deposit_canceled'    => (string) get_option('arp_wallet_sms_deposit_canceled', $sms_defaults['deposit_canceled'] ?? ''),
    'withdraw_registered' => (string) get_option('arp_wallet_sms_withdraw_registered', $sms_defaults['withdraw_registered'] ?? ''),
    'withdraw_completed'  => (string) get_option('arp_wallet_sms_withdraw_completed', $sms_defaults['withdraw_completed'] ?? ''),
    'withdraw_canceled'   => (string) get_option('arp_wallet_sms_withdraw_canceled', $sms_defaults['withdraw_canceled'] ?? ''),
];
foreach ($sms_templates as $k => $v) {
    if ($v === '' && isset($sms_defaults[$k])) {
        $sms_templates[$k] = $sms_defaults[$k];
    }
}

$mtw_zarinpal_merchant = (string) get_option('mtw_zarinpal_merchant_id', '');
if ($mtw_zarinpal_merchant === '') {
    $zpal = get_option('woocommerce_WC_ZPal_settings', []);
    if (is_array($zpal) && !empty($zpal['merchantcode'])) {
        $mtw_zarinpal_merchant = (string) $zpal['merchantcode'];
    }
}

$sms_transport = class_exists('Wallet_SMS')
    ? Wallet_SMS::transport_status()
    : ['ready' => false, 'label' => 'Wallet_SMS بارگذاری نشده', 'detail' => ''];
$sms_transport_ready = !empty($sms_transport['ready']);

$sms_test_flash = null;
if (!empty($_GET['sms_test'])) {
    $sms_test_flash = [
        'ok'      => (string) ($_GET['sms_test'] ?? '') === 'ok',
        'message' => isset($_GET['sms_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['sms_msg'])) : '',
        'mobile'  => isset($_GET['sms_mobile']) ? sanitize_text_field(wp_unslash((string) $_GET['sms_mobile'])) : '',
        'via'     => isset($_GET['sms_via']) ? sanitize_text_field(wp_unslash((string) $_GET['sms_via'])) : '',
    ];
}

$current_user = wp_get_current_user();
$default_test_mobile = '';
if ($current_user instanceof WP_User) {
    $billing = (string) get_user_meta($current_user->ID, 'billing_phone', true);
    if ($billing !== '') {
        $default_test_mobile = $billing;
    } elseif (preg_match('/^0?9\d{9}$/', preg_replace('/\D/', '', $current_user->user_login))) {
        $default_test_mobile = $current_user->user_login;
    }
}

$deposit_withdrawal_admin_ids = get_option('medyar_deposit_withdrawal_admin_ids', []);
if (!is_array($deposit_withdrawal_admin_ids)) {
    $deposit_withdrawal_admin_ids = [];
}
$deposit_withdrawal_admin_ids = array_map('absint', $deposit_withdrawal_admin_ids);

$wallet_max_deposit = function_exists('medyar_wallet_get_max_deposit')
    ? medyar_wallet_get_max_deposit()
    : 500000000;
$wallet_max_withdraw = function_exists('medyar_wallet_get_max_withdraw')
    ? medyar_wallet_get_max_withdraw()
    : 200000000;
$wallet_max_deposit_phrase = function_exists('medyar_wallet_deposit_limit_phrase')
    ? medyar_wallet_deposit_limit_phrase($wallet_max_deposit)
    : '';
$wallet_max_withdraw_phrase = function_exists('medyar_wallet_withdraw_limit_phrase')
    ? medyar_wallet_withdraw_limit_phrase($wallet_max_withdraw)
    : '';

$settings_users = get_users([
    'role'    => 'administrator',
    'orderby' => 'display_name',
    'order'   => 'ASC',
    'number'  => 200,
]);

$resolve_admin_mobile = static function (WP_User $user): string {
    $candidates = [
        (string) get_user_meta($user->ID, 'billing_phone', true),
        (string) $user->user_login,
        (string) get_user_meta($user->ID, 'user_phone', true),
    ];
    foreach ($candidates as $raw) {
        $digits = preg_replace('/\D/', '', str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $raw
        ));
        if (preg_match('/^(0?9\d{9}|989\d{9})$/', (string) $digits)) {
            if (strpos($digits, '98') === 0 && strlen($digits) === 12) {
                $digits = '0' . substr($digits, 2);
            } elseif (strpos($digits, '9') === 0 && strlen($digits) === 10) {
                $digits = '0' . $digits;
            }
            return $digits;
        }
    }
    return '';
};

$render_picker = static function ($name, $id, array $selected, array $users) use ($resolve_admin_mobile) {
    $selected = array_map('absint', $selected);
    ?>
    <div class="wwp-admin-picker" id="<?php echo esc_attr($id); ?>">
        <?php if (empty($users)) : ?>
            <p class="description" style="margin:0;">کاربری با نقش مدیرکل یافت نشد.</p>
        <?php else : ?>
            <?php foreach ($users as $settings_user) :
                if (!$settings_user instanceof WP_User) {
                    continue;
                }
                $uid = (int) $settings_user->ID;
                $display = $settings_user->display_name ?: $settings_user->user_login;
                $input_id = $id . '-' . $uid;
                $mobile = $resolve_admin_mobile($settings_user);
                ?>
                <label class="wwp-admin-picker__item" for="<?php echo esc_attr($input_id); ?>">
                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($input_id); ?>"
                           value="<?php echo esc_attr((string) $uid); ?>" <?php checked(in_array($uid, $selected, true)); ?>>
                    <?php echo get_avatar($uid, 32, '', $display, ['class' => 'wwp-admin-picker__avatar']); ?>
                    <span class="wwp-admin-picker__meta">
                        <span class="wwp-admin-picker__name"><?php echo esc_html($display); ?></span>
                        <span class="wwp-admin-picker__sub">
                            @<?php echo esc_html($settings_user->user_login); ?>
                            · مدیرکل
                            <?php if ($mobile !== '') : ?>
                                · <span dir="ltr"><?php echo esc_html($mobile); ?></span>
                            <?php else : ?>
                                · <span style="color:#b32d2e;">بدون موبایل</span>
                            <?php endif; ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
};

$render_sms_vars = static function (string $target, array $vars) {
    echo '<p class="description mtw-sms-vars">متغیرها (برای درج کلیک کنید): ';
    $parts = [];
    foreach ($vars as $token => $label) {
        $parts[] = sprintf(
            '<button type="button" class="button-link mtw-insert-var" data-target="%1$s" data-var="%2$s"><code>%2$s</code></button> %3$s',
            esc_attr($target),
            esc_attr($token),
            esc_html($label)
        );
    }
    echo implode('، ', $parts);
    echo '</p>';
};

$render_pattern_field = static function (string $name, string $hint, string $label = 'کد پترن فراز') {
    $value = (string) get_option($name, '');
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th>
        <td>
            <input type="text" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>"
                   class="regular-text" dir="ltr" autocomplete="off"
                   value="<?php echo esc_attr($value); ?>"
                   placeholder="خالی = ارسال متن آزاد">
            <p class="description">
                اگر پر باشد، به‌جای متن آزاد از پترن فراز ارسال می‌شود.
                <?php if ($hint !== '') : ?>
                    متغیرهای پترن: <code dir="ltr"><?php echo esc_html($hint); ?></code>
                <?php endif; ?>
            </p>
        </td>
    </tr>
    <?php
};
?>
<div class="wrap wallet-admin-wrap">
    <h1 class="wp-heading-inline">تنظیمات کیف پول</h1>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet')); ?>" class="page-title-action">بازگشت به پیشخوان</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>
    <?php MTW_Admin_Ui::tabs('medyar-wallet-settings', $tabs, $tab); ?>

    <div class="mtw-tab-body">
        <?php if ($tab === 'sms') : ?>
            <div class="mtw-settings-grid">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mtw-settings-main">
                    <?php wp_nonce_field('medyar_save_settings', 'settings_nonce'); ?>
                    <input type="hidden" name="action" value="medyar_save_settings">
                    <input type="hidden" name="settings_tab" value="sms">

                    <div class="postbox">
                        <div class="postbox-header"><h2>سوییچ اصلی</h2></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">ارسال پیامک کیف پول</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wallet_sms_enabled" value="1" <?php checked($sms_enabled, 1); ?>>
                                            پیامک‌های کیف پول (ادمین و کاربر) فعال باشد
                                        </label>
                                        <p class="description">سوییچ اصلی برای همه رویدادهای کیف پول.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <section class="mtw-sms-category" aria-labelledby="mtw-sms-admin-heading">
                        <h2 class="title" id="mtw-sms-admin-heading">پیامک‌های ادمین</h2>
                        <p class="mtw-sms-lead">پیامک‌هایی که فقط برای ادمین‌های انتخاب‌شده ارسال می‌شود.</p>

                        <div class="mtw-sms-category__item">
                            <h3 class="mtw-sms-category__subtitle">تنظیمات مشترک ادمین</h3>
                            <p class="description mtw-sms-category__hint">گیرندگان پیامک هنگام ثبت درخواست برداشت. فقط افراد تیک‌خورده پیامک می‌گیرند.</p>
                            <?php $render_picker('medyar_deposit_withdrawal_admin_ids[]', 'medyar_deposit_withdrawal_admin_ids', $deposit_withdrawal_admin_ids, $settings_users); ?>
                        </div>

                        <div class="mtw-sms-category__item">
                            <h3 class="mtw-sms-category__subtitle">۱. ثبت درخواست برداشت</h3>
                            <p class="description mtw-sms-category__hint">وقتی کاربر درخواست برداشت ثبت می‌کند، برای ادمین‌های بالا ارسال می‌شود. متن سیستمی است؛ در صورت نیاز پترن فراز را پر کنید.</p>
                            <table class="form-table" role="presentation">
                                <?php $render_pattern_field('arp_wallet_sms_withdraw_admin_pattern', 'name, amount, url', 'پترن پیامک ادمین'); ?>
                            </table>
                        </div>
                    </section>

                    <section class="mtw-sms-category" aria-labelledby="mtw-sms-user-heading">
                        <h2 class="title" id="mtw-sms-user-heading">پیامک‌های کاربر</h2>
                        <p class="mtw-sms-lead">پیامک‌هایی که برای خودِ کاربر (صاحب کیف پول) ارسال می‌شود.</p>

                        <div class="mtw-sms-category__item">
                            <h3 class="mtw-sms-category__subtitle">۱. واریز / شارژ</h3>
                            <p class="description mtw-sms-category__hint">با تکمیل یا لغو واریز برای کاربر ارسال می‌شود.</p>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">فعال‌سازی</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="arp_wallet_deposit_sms_enabled" value="1" <?php checked($deposit_sms_enabled); ?>>
                                            پیامک واریز برای کاربر فعال باشد
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="arp_wallet_sms_deposit_completed">متن تکمیل واریز</label></th>
                                    <td>
                                        <textarea name="arp_wallet_sms_deposit_completed" id="arp_wallet_sms_deposit_completed" class="large-text" rows="3"><?php echo esc_textarea($sms_templates['deposit_completed']); ?></textarea>
                                        <?php $render_sms_vars('arp_wallet_sms_deposit_completed', [
                                            '{name}'   => 'نام کاربر',
                                            '{amount}' => 'مبلغ',
                                            '{status}' => 'وضعیت',
                                        ]); ?>
                                    </td>
                                </tr>
                                <?php $render_pattern_field('arp_wallet_sms_deposit_completed_pattern', 'name, amount, status'); ?>
                                <tr>
                                    <th scope="row"><label for="arp_wallet_sms_deposit_canceled">متن لغو واریز</label></th>
                                    <td>
                                        <textarea name="arp_wallet_sms_deposit_canceled" id="arp_wallet_sms_deposit_canceled" class="large-text" rows="3"><?php echo esc_textarea($sms_templates['deposit_canceled']); ?></textarea>
                                        <?php $render_sms_vars('arp_wallet_sms_deposit_canceled', [
                                            '{name}'   => 'نام کاربر',
                                            '{amount}' => 'مبلغ',
                                            '{status}' => 'وضعیت',
                                        ]); ?>
                                    </td>
                                </tr>
                                <?php $render_pattern_field('arp_wallet_sms_deposit_canceled_pattern', 'name, amount, status'); ?>
                            </table>
                        </div>

                        <div class="mtw-sms-category__item">
                            <h3 class="mtw-sms-category__subtitle">۲. برداشت</h3>
                            <p class="description mtw-sms-category__hint">با ثبت، تایید یا لغو برداشت برای کاربر ارسال می‌شود.</p>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">فعال‌سازی</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="arp_wallet_withdraw_sms_enabled" value="1" <?php checked($withdraw_sms_enabled); ?>>
                                            پیامک برداشت برای کاربر فعال باشد
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="arp_wallet_sms_withdraw_registered">متن ثبت برداشت</label></th>
                                    <td>
                                        <textarea name="arp_wallet_sms_withdraw_registered" id="arp_wallet_sms_withdraw_registered" class="large-text" rows="3"><?php echo esc_textarea($sms_templates['withdraw_registered']); ?></textarea>
                                        <?php $render_sms_vars('arp_wallet_sms_withdraw_registered', [
                                            '{name}'   => 'نام کاربر',
                                            '{amount}' => 'مبلغ',
                                            '{status}' => 'وضعیت',
                                        ]); ?>
                                    </td>
                                </tr>
                                <?php $render_pattern_field('arp_wallet_sms_withdraw_registered_pattern', 'name, amount, status'); ?>
                                <tr>
                                    <th scope="row"><label for="arp_wallet_sms_withdraw_completed">متن تایید برداشت</label></th>
                                    <td>
                                        <textarea name="arp_wallet_sms_withdraw_completed" id="arp_wallet_sms_withdraw_completed" class="large-text" rows="3"><?php echo esc_textarea($sms_templates['withdraw_completed']); ?></textarea>
                                        <?php $render_sms_vars('arp_wallet_sms_withdraw_completed', [
                                            '{name}'   => 'نام کاربر',
                                            '{amount}' => 'مبلغ',
                                            '{status}' => 'وضعیت',
                                        ]); ?>
                                    </td>
                                </tr>
                                <?php $render_pattern_field('arp_wallet_sms_withdraw_completed_pattern', 'name, amount, status'); ?>
                                <tr>
                                    <th scope="row"><label for="arp_wallet_sms_withdraw_canceled">متن لغو برداشت</label></th>
                                    <td>
                                        <textarea name="arp_wallet_sms_withdraw_canceled" id="arp_wallet_sms_withdraw_canceled" class="large-text" rows="3"><?php echo esc_textarea($sms_templates['withdraw_canceled']); ?></textarea>
                                        <?php $render_sms_vars('arp_wallet_sms_withdraw_canceled', [
                                            '{name}'   => 'نام کاربر',
                                            '{amount}' => 'مبلغ',
                                            '{status}' => 'وضعیت',
                                        ]); ?>
                                    </td>
                                </tr>
                                <?php $render_pattern_field('arp_wallet_sms_withdraw_canceled_pattern', 'name, amount, status'); ?>
                            </table>
                        </div>

                    </section>

                    <p class="submit">
                        <button type="submit" class="button button-primary">ذخیره تنظیمات پیامک</button>
                    </p>
                </form>

                <aside class="mtw-settings-side">
                    <div class="postbox">
                        <div class="postbox-header"><h2>تست پیامک</h2></div>
                        <div class="inside">
                            <?php if (is_array($sms_test_flash)) : ?>
                                <div class="notice notice-<?php echo $sms_test_flash['ok'] ? 'success' : 'error'; ?> inline" style="margin:0 0 12px;">
                                    <p style="margin:0.5em 0;">
                                        <?php echo esc_html($sms_test_flash['message'] !== '' ? $sms_test_flash['message'] : ($sms_test_flash['ok'] ? 'ارسال شد.' : 'ارسال ناموفق.')); ?>
                                        <?php if ($sms_test_flash['mobile'] !== '') : ?>
                                            <br><code dir="ltr"><?php echo esc_html($sms_test_flash['mobile']); ?></code>
                                        <?php endif; ?>
                                        <?php if ($sms_test_flash['via'] !== '') : ?>
                                            <br><span class="description">از طریق: <?php echo esc_html($sms_test_flash['via']); ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <p class="description" style="margin-top:0;">
                                کانال فعال:
                                <strong><?php echo esc_html($sms_transport['label']); ?></strong>
                                <?php if (!empty($sms_transport['detail'])) : ?>
                                    <code dir="ltr" style="font-size:11px;"><?php echo esc_html($sms_transport['detail']); ?></code>
                                <?php endif; ?>
                            </p>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mtw-sms-test-form" style="display:flex;flex-direction:column;gap:10px;">
                                <?php wp_nonce_field('mtw_test_sms', 'mtw_test_sms_nonce'); ?>
                                <input type="hidden" name="action" value="mtw_test_sms">
                                <input type="hidden" name="settings_tab" value="sms">
                                <p style="margin:0;">
                                    <label for="mtw_test_sms_mobile"><strong>شماره موبایل</strong></label><br>
                                    <input type="text" class="regular-text" name="mobile" id="mtw_test_sms_mobile"
                                           value="<?php echo esc_attr($default_test_mobile); ?>"
                                           placeholder="09121234567" dir="ltr" inputmode="tel" autocomplete="tel" required
                                           style="max-width:100%;">
                                </p>
                                <p style="margin:0;">
                                    <label for="mtw_test_sms_message"><strong>متن پیامک</strong> <span class="description">(اختیاری)</span></label><br>
                                    <textarea name="message" id="mtw_test_sms_message" rows="3" class="large-text"
                                              placeholder="اگر خالی باشد متن پیش‌فرض تست ارسال می‌شود."
                                              style="width:100%;"></textarea>
                                </p>
                                <p style="margin:0;">
                                    <button type="submit" class="button button-secondary" <?php disabled(!$sms_transport_ready); ?>>
                                        ارسال پیامک تست
                                    </button>
                                </p>
                                <p class="description" style="margin:0;">
                                    این تست حتی اگر سوییچ اصلی خاموش باشد هم تلاش می‌کند.
                                </p>
                            </form>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h2>وضعیت سیستم</h2></div>
                        <div class="inside">
                            <ul class="mtw-checks">
                                <li class="<?php echo $sms_enabled ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $sms_enabled ? 'yes' : 'no'; ?>" aria-hidden="true"></span>
                                    <span>پیامک کیف پول <?php echo $sms_enabled ? 'فعال' : 'غیرفعال'; ?> است.</span>
                                </li>
                                <li class="<?php echo $sms_transport_ready ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $sms_transport_ready ? 'yes' : 'no'; ?>" aria-hidden="true"></span>
                                    <span>کانال ارسال: <?php echo esc_html($sms_transport['label']); ?>.</span>
                                </li>
                                <li class="<?php echo $deposit_sms_enabled ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $deposit_sms_enabled ? 'yes' : 'no'; ?>" aria-hidden="true"></span>
                                    <span>پیامک واریز <?php echo $deposit_sms_enabled ? 'فعال' : 'غیرفعال'; ?>.</span>
                                </li>
                                <li class="<?php echo $withdraw_sms_enabled ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $withdraw_sms_enabled ? 'yes' : 'no'; ?>" aria-hidden="true"></span>
                                    <span>پیامک برداشت <?php echo $withdraw_sms_enabled ? 'فعال' : 'غیرفعال'; ?>.</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </aside>
            </div>
        <?php else : ?>
            <div class="mtw-settings-grid">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mtw-settings-main">
                    <?php wp_nonce_field('medyar_save_settings', 'settings_nonce'); ?>
                    <input type="hidden" name="action" value="medyar_save_settings">
                    <input type="hidden" name="settings_tab" value="general">

                    <div class="postbox">
                        <div class="postbox-header"><h2>سقف‌ها و درگاه</h2></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="mtw_zarinpal_merchant_id">مرچنت‌کد زرین‌پال</label></th>
                                    <td>
                                        <input type="text" class="regular-text mtw-mono" name="mtw_zarinpal_merchant_id" id="mtw_zarinpal_merchant_id"
                                               value="<?php echo esc_attr($mtw_zarinpal_merchant); ?>" autocomplete="off">
                                        <p class="description">برای شارژ کیف پول. اگر خالی باشد از تنظیمات افزونه درگاه زرین‌پال ووکامرس خوانده می‌شود.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="medyar_wallet_max_deposit">سقف واریز</label></th>
                                    <td>
                                        <input type="text" class="regular-text" name="medyar_wallet_max_deposit" id="medyar_wallet_max_deposit"
                                               value="<?php echo esc_attr((string) $wallet_max_deposit); ?>" inputmode="numeric">
                                        <p class="description"><?php echo esc_html($wallet_max_deposit_phrase ?: 'مبلغ به تومان'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="medyar_wallet_max_withdraw">سقف برداشت</label></th>
                                    <td>
                                        <input type="text" class="regular-text" name="medyar_wallet_max_withdraw" id="medyar_wallet_max_withdraw"
                                               value="<?php echo esc_attr((string) $wallet_max_withdraw); ?>" inputmode="numeric">
                                        <p class="description"><?php echo esc_html($wallet_max_withdraw_phrase ?: 'مبلغ به تومان'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h2>گیرندگان پیامک ادمین</h2></div>
                        <div class="inside">
                            <p class="description" style="margin-top:0;">
                                انتخاب گیرندگان و پترن پیامک ادمین در تب
                                <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-settings', ['tab' => 'sms'])); ?>">پیامک ← پیامک‌های ادمین</a>
                                انجام می‌شود.
                                <?php if ($deposit_withdrawal_admin_ids) : ?>
                                    هم‌اکنون <strong><?php echo (int) count($deposit_withdrawal_admin_ids); ?></strong> مدیرکل انتخاب شده است.
                                <?php else : ?>
                                    هنوز گیرنده‌ای انتخاب نشده است.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">ذخیره تنظیمات عمومی</button>
                    </p>
                </form>

                <aside class="mtw-settings-side">
                    <div class="postbox">
                        <div class="postbox-header"><h2>وضعیت سیستم</h2></div>
                        <div class="inside">
                            <ul class="mtw-checks">
                                <li class="<?php echo $mtw_zarinpal_merchant !== '' ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $mtw_zarinpal_merchant !== '' ? 'yes' : 'no'; ?>" aria-hidden="true"></span>
                                    <span>مرچنت‌کد زرین‌پال <?php echo $mtw_zarinpal_merchant !== '' ? 'تنظیم شده' : 'خالی است'; ?>.</span>
                                </li>
                                <li class="is-ok">
                                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                    <span>سقف واریز: <?php echo esc_html(MTW_Admin_Ui::money($wallet_max_deposit)); ?></span>
                                </li>
                                <li class="is-ok">
                                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                    <span>سقف برداشت: <?php echo esc_html(MTW_Admin_Ui::money($wallet_max_withdraw)); ?></span>
                                </li>
                                <li class="<?php echo $deposit_withdrawal_admin_ids ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $deposit_withdrawal_admin_ids ? 'yes' : 'warning'; ?>" aria-hidden="true"></span>
                                    <span><?php echo count($deposit_withdrawal_admin_ids); ?> مدیرکل برای پیامک برداشت انتخاب شده.</span>
                                </li>
                                <li class="<?php echo $sms_enabled ? 'is-ok' : 'is-bad'; ?>">
                                    <span class="dashicons dashicons-<?php echo $sms_enabled ? 'yes' : 'no'; ?>" aria-hidden="true"></span>
                                    <span>پیامک کیف پول <?php echo $sms_enabled ? 'فعال' : 'غیرفعال'; ?> —
                                        <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-settings', ['tab' => 'sms'])); ?>">مدیریت الگوها</a>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h2>میانبرها</h2></div>
                        <div class="inside">
                            <ul class="mtw-links">
                                <li><span class="dashicons dashicons-dashboard" aria-hidden="true"></span>
                                    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet')); ?>">پیشخوان کیف پول</a></li>
                                <li><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                                    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-settings', ['tab' => 'sms'])); ?>">الگوی پیامک</a></li>
                                <li><span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
                                    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit')); ?>">واریز دستی</a></li>
                                <li><span class="dashicons dashicons-migrate" aria-hidden="true"></span>
                                    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-withdrawals')); ?>">برداشت‌ها</a></li>
                            </ul>
                            <p class="description" style="margin-top:12px;">
                                <button type="button" class="button" id="mtwClearListStorage">پاک‌کردن حافظه فیلترهای لیست</button>
                            </p>
                        </div>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('mtwClearListStorage');
    if (btn) {
        btn.addEventListener('click', function () {
            try {
                Object.keys(localStorage).forEach(function (k) {
                    if (k.indexOf('wwp') !== -1 || k.indexOf('mtw') !== -1 || k.indexOf('wallet') !== -1) {
                        localStorage.removeItem(k);
                    }
                });
                btn.textContent = 'پاک شد';
            } catch (e) {}
        });
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('.mtw-insert-var');
        if (!el) return;
        e.preventDefault();
        var area = document.getElementById(el.getAttribute('data-target'));
        var token = el.getAttribute('data-var') || '';
        if (!area || !token) return;
        var start = area.selectionStart != null ? area.selectionStart : area.value.length;
        var end = area.selectionEnd != null ? area.selectionEnd : start;
        area.value = area.value.slice(0, start) + token + area.value.slice(end);
        area.focus();
        area.selectionStart = area.selectionEnd = start + token.length;
        area.dispatchEvent(new Event('input'));
    });
})();
</script>
