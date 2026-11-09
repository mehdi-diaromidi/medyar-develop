<?php
if (!defined('ABSPATH')) {
    exit;
}

class Medyar_Bank_Cards
{
    const META_KEY = 'medyar_bank_cards';

    public static function init()
    {
        add_shortcode('medyar_bank_cards', [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('wp_ajax_medyar_add_bank_card', [__CLASS__, 'ajax_add']);
        add_action('wp_ajax_medyar_remove_bank_card', [__CLASS__, 'ajax_remove']);
    }

    public static function register_assets()
    {
        $css_path = MTW_PATH . 'assets/frontend/css/bank-cards.css';
        wp_register_style(
            'medyar-bank-cards',
            MTW_URL . 'assets/frontend/css/bank-cards.css',
            [],
            (string) (@filemtime($css_path) ?: MTW_VERSION)
        );
        // version assets by mtime
        $js_path = MTW_PATH . 'assets/frontend/js/bank-cards.js';
        wp_register_script(
            'medyar-bank-cards',
            MTW_URL . 'assets/frontend/js/bank-cards.js',
            ['jquery'],
            (string) (@filemtime($js_path) ?: MTW_VERSION),
            true
        );
    }

    public static function enqueue_logo_styles()
    {
        self::register_assets();
        wp_enqueue_style('medyar-bank-cards');
    }

    public static function enqueue_assets()
    {
        self::enqueue_logo_styles();
        $mtw_css = MTW_PATH . 'assets/frontend/css/mtw-bank-cards.css';
        wp_register_style(
            'mtw-bank-cards',
            MTW_URL . 'assets/frontend/css/mtw-bank-cards.css',
            ['medyar-bank-cards'],
            (string) (@filemtime($mtw_css) ?: MTW_VERSION)
        );
        wp_enqueue_style('mtw-bank-cards');
        wp_enqueue_script('medyar-bank-cards');
        wp_localize_script('medyar-bank-cards', 'medyarBankCards', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('medyar_bank_cards'),
            'max_cards'     => medyar_bank_card_max_count(),
            'initialCards'  => self::cards_for_js(get_current_user_id()),
            'addAction'     => 'medyar_add_bank_card',
            'removeAction'  => 'medyar_remove_bank_card',
            'banks'         => medyar_get_bank_bins(),
        ]);
    }

    public static function render_shortcode()
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('برای مشاهده کارت‌ها وارد شوید.', 'medyar-toman-wallet') . '</p>';
        }
        self::enqueue_assets();
        ob_start();
        include MTW_PATH . 'templates/bank-cards.php';
        return ob_get_clean();
    }

    public static function ajax_add()
    {
        check_ajax_referer('medyar_bank_cards', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'ورود الزامی است'], 401);
        }
        $user_id = get_current_user_id();
        if (!self::rate_limit_ok($user_id, 'add', 6, 60)) {
            wp_send_json_error(['message' => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.'], 429);
        }
        $card = preg_replace('/\D/', '', (string) wp_unslash($_POST['card_number'] ?? ''));
        if (strlen($card) !== 16) {
            wp_send_json_error(['message' => 'شماره کارت باید ۱۶ رقم باشد.']);
        }
        $iban_raw = (string) wp_unslash($_POST['iban'] ?? '');
        $iban     = medyar_normalize_iban($iban_raw);
        if ($iban === '' || !medyar_is_valid_iban($iban)) {
            wp_send_json_error(['message' => 'شماره شبا نامعتبر است؛ باید با IR شروع شود و ۲۴ رقم داشته باشد.']);
        }
        // فقط ۱۶ رقم الزامی است؛ BIN ناشناخته = کارت بانکی با آیکن پیش‌فرض (بدون خطا).
        $bank  = medyar_resolve_bank_info_by_card_number($card);
        $owner = '';
        $user  = get_userdata($user_id);
        if ($user) {
            $owner = trim((string) $user->display_name);
        }
        $result = medyar_add_user_bank_card($user_id, [
            $card => [
                'iban'  => $iban,
                'bank'  => $bank,
                'owner' => $owner,
            ],
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success([
            'cards'   => self::cards_for_js($user_id),
            'message' => 'کارت با موفقیت ثبت شد.',
        ]);
    }

    public static function ajax_remove()
    {
        check_ajax_referer('medyar_bank_cards', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'ورود الزامی است'], 401);
        }
        $user_id = get_current_user_id();
        if (!self::rate_limit_ok($user_id, 'remove', 8, 60)) {
            wp_send_json_error(['message' => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.'], 429);
        }
        $card = preg_replace('/\D/', '', (string) wp_unslash($_POST['card_number'] ?? ''));
        if (!medyar_remove_user_bank_card($user_id, $card)) {
            wp_send_json_error(['message' => 'حذف کارت انجام نشد.']);
        }
        wp_send_json_success([
            'cards'   => self::cards_for_js($user_id),
            'message' => 'کارت حذف شد.',
        ]);
    }

    private static function rate_limit_ok(int $user_id, string $bucket, int $max_hits, int $window): bool
    {
        $key   = 'mtw_bc_rl_' . $bucket . '_' . $user_id;
        $state = get_transient($key);
        if (!is_array($state) || empty($state['count']) || empty($state['start'])) {
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return true;
        }
        $elapsed = time() - (int) $state['start'];
        if ($elapsed >= $window) {
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return true;
        }
        $count = (int) $state['count'];
        if ($count >= $max_hits) {
            return false;
        }
        $state['count'] = $count + 1;
        set_transient($key, $state, max(1, $window - $elapsed));
        return true;
    }

    public static function cards_for_js(int $user_id): array
    {
        $out   = [];
        $cards = medyar_get_user_bank_cards($user_id);
        foreach ($cards as $num => $data) {
            $digits = preg_replace('/\D/', '', (string) $num);
            $bank_fa = (string) ( $data['bank']['fa'] ?? '' );
            $bank_en = (string) ( $data['bank']['en'] ?? 'bank-logo-default-ws' );
            if ( $bank_fa === '' ) {
                $bank_fa = 'کارت بانکی';
            }
            if ( $bank_en === '' ) {
                $bank_en = 'bank-logo-default-ws';
            }
            $out[]  = [
                'id'                   => $digits,
                'card_number_formatted'=> self::format_card($digits),
                'bank_name_fa'         => $bank_fa,
                'bank_name_en'         => $bank_en,
                'iban'                 => $data['iban'] ?? '',
                'owner'                => $data['owner'] ?? '',
            ];
        }
        return $out;
    }

    private static function format_card(string $digits): string
    {
        if (strlen($digits) !== 16) {
            return $digits;
        }
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 4) . '-' . substr($digits, 8, 4) . '-' . substr($digits, 12, 4);
    }
}

function medyar_bank_card_max_count(): int
{
    return 3;
}

/**
 * Normalize Iranian IBAN to IR + 24 digits (no spaces).
 */
function medyar_normalize_iban($iban): string
{
    $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));
    $iban = preg_replace('/[^A-Z0-9]/', '', $iban);
    if ($iban === '') {
        return '';
    }
    if (strpos($iban, 'IR') !== 0 && preg_match('/^\d{24}$/', $iban)) {
        $iban = 'IR' . $iban;
    }
    return $iban;
}

/**
 * Validate Iranian IBAN format (IR + 24 digits) with ISO 13616 mod-97.
 */
function medyar_is_valid_iban(string $iban): bool
{
    if (!preg_match('/^IR\d{24}$/', $iban)) {
        return false;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric    = '';
    $len        = strlen($rearranged);
    for ($i = 0; $i < $len; $i++) {
        $ch = $rearranged[$i];
        if ($ch >= 'A' && $ch <= 'Z') {
            $numeric .= (string) (ord($ch) - 55);
        } else {
            $numeric .= $ch;
        }
    }
    $checksum = 0;
    $nlen     = strlen($numeric);
    for ($i = 0; $i < $nlen; $i++) {
        $checksum = ((int) $checksum * 10 + (int) $numeric[$i]) % 97;
    }
    return $checksum === 1;
}

function medyar_get_bank_bins(): array
{
    return [
        '603799' => ['fa' => 'بانک ملی', 'en' => 'melli-iran-bank'],
        '636214' => ['fa' => 'بانک ملی', 'en' => 'melli-iran-bank'],
        '589210' => ['fa' => 'بانک سپه', 'en' => 'sepah-bank'],
        '627381' => ['fa' => 'بانک سپه', 'en' => 'sepah-bank'],
        '603769' => ['fa' => 'بانک صادرات', 'en' => 'saderat-bank'],
        '627961' => ['fa' => 'بانک صنعت و معدن', 'en' => 'sanat-va-madan-bank'],
        '603770' => ['fa' => 'بانک کشاورزی', 'en' => 'keshavarzi-bank'],
        '639217' => ['fa' => 'بانک کشاورزی', 'en' => 'keshavarzi-bank'],
        '628023' => ['fa' => 'بانک مسکن', 'en' => 'maskan-bank'],
        '627760' => ['fa' => 'پست بانک', 'en' => 'post-bank-iran-bank'],
        '621986' => ['fa' => 'بانک سامان', 'en' => 'saman-bank'],
        '502908' => ['fa' => 'بانک توسعه تعاون', 'en' => 'tosee-taavon-bank'],
        '627412' => ['fa' => 'بانک اقتصاد نوین', 'en' => 'eghtesad-novin-bank'],
        '622106' => ['fa' => 'بانک پارسیان', 'en' => 'parsian-bank'],
        '502229' => ['fa' => 'بانک پاسارگاد', 'en' => 'pasargad-bank'],
        '639347' => ['fa' => 'بانک پاسارگاد', 'en' => 'pasargad-bank'],
        '627488' => ['fa' => 'بانک کارآفرین', 'en' => 'karafarin-bank'],
        '502910' => ['fa' => 'بانک کارآفرین', 'en' => 'karafarin-bank'],
        '639346' => ['fa' => 'بانک سینا', 'en' => 'sina-bank'],
        '639607' => ['fa' => 'بانک سرمایه', 'en' => 'sarmayeh-bank'],
        '504706' => ['fa' => 'بانک شهر', 'en' => 'shahr-bank'],
        '502806' => ['fa' => 'بانک شهر', 'en' => 'shahr-bank'],
        '502938' => ['fa' => 'بانک دی', 'en' => 'day-bank'],
        '610433' => ['fa' => 'بانک ملت', 'en' => 'mellat-bank'],
        '991975' => ['fa' => 'بانک ملت', 'en' => 'mellat-bank'],
        '627353' => ['fa' => 'بانک تجارت', 'en' => 'tejarat-bank'],
        '585983' => ['fa' => 'بانک تجارت', 'en' => 'tejarat-bank'],
        '589463' => ['fa' => 'بانک رفاه', 'en' => 'refah-bank'],
        '627648' => ['fa' => 'بانک توسعه صادرات', 'en' => 'tosee-saderat-iran-bank'],
        '505785' => ['fa' => 'بانک ایران زمین', 'en' => 'iran-zamin-bank'],
        '505416' => ['fa' => 'بانک گردشگری', 'en' => 'gardeshgari-bank'],
        '636949' => ['fa' => 'بانک حکمت ایرانیان', 'en' => 'bank-logo-default-ws'],
        '505801' => ['fa' => 'مؤسسه کوثر', 'en' => 'kosar-credit-institution'],
    ];
}

/**
 * Fallback when BIN is not in the known list — neutral label + default icon slug.
 *
 * @return array{fa:string,en:string}
 */
function medyar_unknown_bank_info(): array
{
    return [
        'fa' => 'کارت بانکی',
        'en' => 'bank-logo-default-ws',
    ];
}

/**
 * Known BIN lookup only (null when unrecognized).
 *
 * @return array{fa:string,en:string}|null
 */
function medyar_get_bank_info_by_card_number($card_number)
{
    $card_digits = preg_replace('/\D/', '', (string) $card_number);
    if (strlen($card_digits) < 6) {
        return null;
    }
    $bin   = substr($card_digits, 0, 6);
    $banks = medyar_get_bank_bins();
    return $banks[ $bin ] ?? null;
}

/**
 * Always returns bank meta for a card with enough digits — never blocks unknown BINs.
 *
 * @return array{fa:string,en:string}
 */
function medyar_resolve_bank_info_by_card_number($card_number): array
{
    $known = medyar_get_bank_info_by_card_number($card_number);
    return is_array($known) ? $known : medyar_unknown_bank_info();
}

function medyar_add_user_bank_card($user_id, array $bank_info)
{
    if (!$user_id || empty($bank_info)) {
        return false;
    }
    $cards = get_user_meta($user_id, Medyar_Bank_Cards::META_KEY, true);
    if (!is_array($cards)) {
        $cards = [];
    }
    if (count($cards) >= medyar_bank_card_max_count()) {
        return new WP_Error('MAX_CARDS_REACHED', sprintf('حداکثر %d کارت بانکی مجاز است', medyar_bank_card_max_count()));
    }
    foreach ($bank_info as $card_number => $card_data) {
        $card_number = (string) preg_replace('/\D/', '', $card_number);
        if (isset($cards[$card_number])) {
            continue;
        }
        $cards[$card_number] = [
            'iban'    => sanitize_text_field($card_data['iban'] ?? ''),
            'bank'    => [
                'fa' => sanitize_text_field($card_data['bank']['fa'] ?? ''),
                'en' => sanitize_text_field($card_data['bank']['en'] ?? ''),
            ],
            'owner'   => sanitize_text_field($card_data['owner'] ?? ''),
            'created' => time(),
        ];
    }
    update_user_meta($user_id, Medyar_Bank_Cards::META_KEY, $cards);
    return true;
}

function medyar_get_user_bank_cards($user_id)
{
    if (!$user_id) {
        return [];
    }
    $cards = get_user_meta($user_id, Medyar_Bank_Cards::META_KEY, true);
    return is_array($cards) ? $cards : [];
}

function medyar_remove_user_bank_card($user_id, $card_number)
{
    $card_number = preg_replace('/\D/', '', (string) $card_number);
    $raw         = get_user_meta($user_id, Medyar_Bank_Cards::META_KEY, true);
    if (!is_array($raw) || empty($raw)) {
        return false;
    }
    $bank_cards = [];
    $removed    = false;
    foreach ($raw as $key => $value) {
        $key_digits = preg_replace('/\D/', '', (string) $key);
        if ($key_digits === $card_number) {
            $removed = true;
            continue;
        }
        $bank_cards[$key_digits !== '' ? $key_digits : (string) $key] = $value;
    }
    if (!$removed) {
        return false;
    }
    if (empty($bank_cards)) {
        delete_user_meta($user_id, Medyar_Bank_Cards::META_KEY);
    } else {
        delete_user_meta($user_id, Medyar_Bank_Cards::META_KEY);
        add_user_meta($user_id, Medyar_Bank_Cards::META_KEY, $bank_cards, true);
    }
    return true;
}
