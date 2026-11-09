<?php
/**
 * Admin AJAX list + CSV export + selective bulk actions.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Admin_List')) { return; }

class Wallet_Admin_List
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
        require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
        require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';

        $entities = [
            'transactions',
            'recent_transactions',
            'withdrawals',
            'documents',
            'processing',
            'panel_txs',
        ];
        foreach ($entities as $entity) {
            add_action('wp_ajax_mtw_admin_list_' . $entity, [$this, 'ajax_list']);
            add_action('admin_post_mtw_admin_export_' . $entity, [$this, 'export_csv']);
        }

        add_action('admin_post_wallet_bulk_update_withdrawals', [$this, 'bulk_update_withdrawals']);
        add_action('admin_post_wallet_bulk_document_action', [$this, 'bulk_document_action']);
        add_action('wp_ajax_mtw_admin_lookup', [$this, 'ajax_lookup']);
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }
    }

    private function request_args(): array
    {
        $src = array_merge($_GET, $_POST);
        return mtw_admin_list_parse_args($src);
    }

    private function entity_from_action(): string
    {
        $action = (string) ($_REQUEST['action'] ?? '');
        if (strpos($action, 'mtw_admin_list_') === 0) {
            return substr($action, strlen('mtw_admin_list_'));
        }
        if (strpos($action, 'mtw_admin_export_') === 0) {
            return substr($action, strlen('mtw_admin_export_'));
        }
        return sanitize_key((string) ($_REQUEST['entity'] ?? ''));
    }

    public function ajax_list(): void
    {
        $this->guard();
        check_ajax_referer('mtw_admin_list', 'nonce');

        $entity = $this->entity_from_action();
        $args   = $this->request_args();
        $payload = $this->build_list_payload($entity, $args);
        wp_send_json_success($payload);
    }

    public function export_csv(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        check_admin_referer('mtw_admin_list', 'nonce');

        $entity = $this->entity_from_action();
        $args   = $this->request_args();
        $args['per_page'] = 5000;
        $args['paged']    = 1;

        $rows = $this->build_csv_rows($entity, $args);
        wwp_admin_csv_download('wwp-' . $entity . '-' . gmdate('Ymd-His') . '.csv', $rows);
    }

    public function ajax_lookup(): void
    {
        $this->guard();
        check_ajax_referer('mtw_admin_list', 'nonce');

        $q = sanitize_text_field((string) ($_REQUEST['q'] ?? ''));
        if ($q === '') {
            wp_send_json_error(['message' => 'عبارت خالی است']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';

        if (ctype_digit($q)) {
            $tx = $wpdb->get_row($wpdb->prepare("SELECT id, user_id, operation_type, status FROM {$table} WHERE id = %d", (int) $q));
            if ($tx) {
                $page = ($tx->operation_type === 'withdraw') ? 'medyar-wallet-withdrawals' : 'medyar-wallet-transactions';
                wp_send_json_success([
                    'type' => 'transaction',
                    'url'  => admin_url('admin.php?page=' . $page . '&tx_q=' . (int) $tx->id),
                    'label'=> 'تراکنش #' . (int) $tx->id,
                ]);
            }
            $user = get_userdata((int) $q);
            if ($user) {
                wp_send_json_success([
                    'type' => 'user',
                    'url'  => admin_url('admin.php?page=medyar-wallet-user-insights&user_id=' . (int) $user->ID),
                    'label'=> $user->display_name,
                ]);
            }
        }

        $tx = $wpdb->get_row($wpdb->prepare(
            "SELECT id, operation_type FROM {$table} WHERE transaction_code = %s LIMIT 1",
            $q
        ));
        if ($tx) {
            $page = ($tx->operation_type === 'withdraw') ? 'medyar-wallet-withdrawals' : 'medyar-wallet-transactions';
            wp_send_json_success([
                'type' => 'transaction',
                'url'  => admin_url('admin.php?page=' . $page . '&tx_q=' . rawurlencode($q)),
                'label'=> 'تراکنش ' . $q,
            ]);
        }

        $uids = wwp_admin_resolve_user_ids_from_q($q, 5);
        if (!empty($uids)) {
            $uid = (int) $uids[0];
            $user = get_userdata($uid);
            wp_send_json_success([
                'type' => 'user',
                'url'  => admin_url('admin.php?page=medyar-wallet-user-insights&user_id=' . $uid),
                'label'=> $user ? $user->display_name : ('کاربر #' . $uid),
            ]);
        }

        wp_send_json_error(['message' => 'نتیجه‌ای یافت نشد']);
    }

    /**
     * @return array{rows_html:string,pagination_html:string,meta:array<string,mixed>,tx_js_data?:array}
     */
    private function build_list_payload(string $entity, array $args): array
    {
        switch ($entity) {
            case 'transactions':
                $result = wwp_admin_query_transactions($args, 'all');
                return [
                    'rows_html' => wwp_admin_render_tx_rows_html($result['rows'], [
                        'show_view' => true,
                        'redirect_page' => 'medyar-wallet-transactions',
                    ]),
                    'pagination_html' => wwp_admin_render_pagination_html($result['total'], (int) $args['paged'], (int) $args['per_page'], $entity),
                    'meta' => [
                        'total' => $result['total'],
                        'page' => (int) $args['paged'],
                        'orderby' => $args['orderby'],
                        'order' => $args['order'],
                    ],
                    'tx_js_data' => function_exists('wwp_build_transaction_modal_js_data')
                        ? wwp_build_transaction_modal_js_data($result['rows'])
                        : [],
                ];

            case 'recent_transactions':
                $result = wwp_admin_query_transactions($args, 'recent');
                return [
                    'rows_html' => wwp_admin_render_tx_rows_html($result['rows'], [
                        'show_view' => true,
                        'redirect_page' => 'medyar-wallet-recent-transactions',
                    ]),
                    'pagination_html' => wwp_admin_render_pagination_html($result['total'], (int) $args['paged'], (int) $args['per_page'], $entity),
                    'meta' => [
                        'total' => $result['total'],
                        'page' => (int) $args['paged'],
                        'orderby' => $args['orderby'],
                        'order' => $args['order'],
                    ],
                    'tx_js_data' => function_exists('wwp_build_transaction_modal_js_data')
                        ? wwp_build_transaction_modal_js_data($result['rows'])
                        : [],
                ];

            case 'withdrawals':
                if (empty($args['list_mode']) || $args['list_mode'] === 'all') {
                    $args['list_mode'] = 'pending';
                }
                $args['per_page'] = (int) ($args['per_page'] ?: 50);
                $result = wwp_admin_query_transactions($args, 'withdraw');
                $pending = ($args['list_mode'] === 'pending');
                return [
                    'rows_html' => wwp_admin_render_tx_rows_html($result['rows'], [
                        'show_checkbox' => $pending,
                        'show_view' => true,
                        'show_approve_cancel' => $pending,
                        'withdraw_mode' => true,
                        'redirect_page' => 'medyar-wallet-withdrawals',
                    ]),
                    'pagination_html' => wwp_admin_render_pagination_html($result['total'], (int) $args['paged'], (int) $args['per_page'], $entity),
                    'meta' => [
                        'total' => $result['total'],
                        'page' => (int) $args['paged'],
                        'orderby' => $args['orderby'],
                        'order' => $args['order'],
                        'list_mode' => $args['list_mode'],
                    ],
                    'tx_js_data' => function_exists('wwp_build_transaction_modal_js_data')
                        ? wwp_build_transaction_modal_js_data($result['rows'])
                        : [],
                ];

            case 'processing':
                $args['per_page'] = min(100, (int) ($args['per_page'] ?: 100));
                $result = wwp_admin_query_transactions($args, 'processing');
                return [
                    'rows_html' => wwp_admin_render_tx_rows_html($result['rows'], [
                        'show_view' => true,
                        'show_approve_cancel' => true,
                        'redirect_page' => 'medyar-wallet',
                    ]),
                    'pagination_html' => wwp_admin_render_pagination_html($result['total'], (int) $args['paged'], (int) $args['per_page'], $entity),
                    'meta' => [
                        'total' => $result['total'],
                        'page' => (int) $args['paged'],
                        'orderby' => $args['orderby'],
                        'order' => $args['order'],
                    ],
                    'tx_js_data' => function_exists('wwp_build_transaction_modal_js_data')
                        ? wwp_build_transaction_modal_js_data($result['rows'])
                        : [],
                ];

            case 'documents':
                return $this->payload_documents($args);

            case 'panel_txs':
                $uid = (int) ($args['user_id'] ?? 0);
                if ($uid <= 0) {
                    return ['rows_html' => '', 'pagination_html' => '', 'meta' => ['total' => 0]];
                }
                $args['user_id'] = $uid;
                $result = wwp_admin_query_transactions($args, 'all');
                return [
                    'rows_html' => wwp_admin_render_tx_rows_html($result['rows'], [
                        'show_view' => true,
                        'hide_user' => true,
                        'redirect_page' => 'medyar-wallet-user-insights',
                    ]),
                    'pagination_html' => wwp_admin_render_pagination_html($result['total'], (int) $args['paged'], (int) $args['per_page'], $entity),
                    'meta' => ['total' => $result['total'], 'page' => (int) $args['paged']],
                    'tx_js_data' => function_exists('wwp_build_transaction_modal_js_data')
                        ? wwp_build_transaction_modal_js_data($result['rows'])
                        : [],
                ];

            default:
                wp_send_json_error(['message' => 'entity نامعتبر'], 400);
        }
    }

    private function payload_documents(array $args): array
    {
        if (!function_exists('medyar_get_document_submissions_for_admin')) {
            return ['rows_html' => '<p>ماژول مدارک در دسترس نیست.</p>', 'pagination_html' => '', 'meta' => ['total' => 0]];
        }

        $query = [
            'status'   => ($args['doc_status'] === 'all' || $args['doc_status'] === '') ? '' : ($args['doc_status'] ?: 'pending_review'),
            'doc_type' => $args['doc_type'],
            'user_id'  => (int) $args['user_id'],
            'phone'    => $args['phone'] !== '' ? $args['phone'] : $args['user_q'],
            'date_from'=> $args['date_from'],
            'date_to'  => $args['date_to'],
            'limit'    => (int) $args['per_page'],
            'offset'   => ((int) $args['paged'] - 1) * (int) $args['per_page'],
        ];
        if ($query['user_id'] <= 0 && $args['user_q'] !== '' && !preg_match('/^\d{10,}$/', preg_replace('/\D/', '', $args['user_q']))) {
            $uids = wwp_admin_resolve_user_ids_from_q($args['user_q'], 1);
            $query['user_id'] = $uids[0] ?? 0;
        }

        $rows = medyar_get_document_submissions_for_admin($query);
        $total = function_exists('medyar_count_document_submissions_for_admin')
            ? medyar_count_document_submissions_for_admin($query)
            : count($rows);

        $html = $this->render_document_rows($rows, ($query['status'] === '' || $query['status'] === 'pending_review'));
        return [
            'rows_html' => $html,
            'pagination_html' => wwp_admin_render_pagination_html($total, (int) $args['paged'], (int) $args['per_page'], 'documents'),
            'meta' => ['total' => $total, 'page' => (int) $args['paged']],
        ];
    }

    /**
     * @param object[] $rows
     */
    private function render_document_rows(array $rows, bool $allow_bulk): string
    {
        if (empty($rows)) {
            return '<div class="wwp-empty-row"><p>موردی یافت نشد.</p></div>';
        }
        ob_start();
        foreach ($rows as $row) {
            $user = get_userdata((int) $row->user_id);
            $phone = $user ? get_user_meta($user->ID, 'billing_phone', true) : '';
            $pending = ((string) $row->status === 'pending_review');
            echo '<details class="wwp-document-review-details" data-doc-id="' . (int) $row->id . '">';
            echo '<summary>';
            if ($allow_bulk && $pending) {
                echo '<input type="checkbox" class="wwp-row-check" value="' . (int) $row->id . '" onclick="event.stopPropagation()"> ';
            }
            echo '#' . (int) $row->id . ' — ' . esc_html((string) $row->doc_type) . ' — ';
            echo esc_html($user ? $user->display_name : ('#' . $row->user_id));
            echo ' <span class="wallet-badge">' . esc_html((string) $row->status) . '</span>';
            echo ' <small>' . esc_html(wwp_format_datetime_jalali((string) $row->updated_at)) . '</small>';
            echo '</summary><div class="wwp-details-body">';
            echo '<p>موبایل: <span dir="ltr">' . esc_html($phone ?: '—') . '</span></p>';
            if ($pending) {
                $admin_post = esc_url(admin_url('admin-post.php'));
                echo '<div class="wallet-action-buttons">';
                echo '<form method="post" action="' . $admin_post . '" class="wallet-form-inline">';
                wp_nonce_field('wallet_approve_document', 'wallet_document_nonce');
                echo '<input type="hidden" name="action" value="wallet_approve_document">';
                echo '<input type="hidden" name="submission_id" value="' . (int) $row->id . '">';
                echo '<input type="hidden" name="redirect_page" value="wallet-document-reviews">';
                echo '<button type="submit" class="button button-primary button-small">تایید</button></form>';
                echo '<form method="post" action="' . $admin_post . '" class="wallet-form-inline" onsubmit="var r=this.querySelector(\'[name=rejection_reason]\'); if(!r.value){alert(\'دلیل رد لازم است\');return false;}">';
                wp_nonce_field('wallet_reject_document', 'wallet_document_nonce');
                echo '<input type="hidden" name="action" value="wallet_reject_document">';
                echo '<input type="hidden" name="submission_id" value="' . (int) $row->id . '">';
                echo '<input type="hidden" name="redirect_page" value="wallet-document-reviews">';
                echo '<input type="text" name="rejection_reason" placeholder="دلیل رد" required> ';
                echo '<button type="submit" class="button button-small wwp-btn-danger">رد</button></form>';
                echo '</div>';
            }
            if ($user) {
                echo '<p><a href="' . esc_url(admin_url('admin.php?page=medyar-wallet-user-insights&user_id=' . (int) $user->ID)) . '">پنل کاربر</a></p>';
            }
            echo '</div></details>';
        }
        return (string) ob_get_clean();
    }

    /**
     * @return string[][]
     */
    private function build_csv_rows(string $entity, array $args): array
    {
        $out = [];
        switch ($entity) {
            case 'transactions':
            case 'recent_transactions':
            case 'processing':
                $scope = $entity === 'transactions' ? 'all' : ($entity === 'processing' ? 'processing' : 'recent');
                $result = wwp_admin_query_transactions($args, $scope);
                $out[] = ['id', 'user_id', 'balance_type', 'operation_type', 'amount', 'status', 'transaction_code', 'created_at'];
                foreach ($result['rows'] as $tx) {
                    $out[] = [
                        $tx->id, $tx->user_id, $tx->balance_type, $tx->operation_type,
                        $tx->amount, $tx->status, $tx->transaction_code, $tx->created_at,
                    ];
                }
                break;
            case 'withdrawals':
                if (empty($args['list_mode'])) {
                    $args['list_mode'] = 'history';
                }
                $result = wwp_admin_query_transactions($args, 'withdraw');
                $out[] = ['id', 'user_id', 'amount', 'status', 'card', 'iban', 'transaction_code', 'created_at'];
                foreach ($result['rows'] as $tx) {
                    $p = !empty($tx->p_info) ? json_decode((string) $tx->p_info, true) : [];
                    $out[] = [
                        $tx->id, $tx->user_id, $tx->amount, $tx->status,
                        $p['card_number'] ?? '', $p['iban'] ?? '', $tx->transaction_code, $tx->created_at,
                    ];
                }
                break;
            case 'documents':
                if (function_exists('medyar_get_document_submissions_for_admin')) {
                    $query = [
                        'status' => ($args['doc_status'] === 'all') ? '' : ($args['doc_status'] ?: ''),
                        'doc_type' => $args['doc_type'],
                        'user_id' => (int) $args['user_id'],
                        'phone' => $args['phone'],
                        'date_from' => $args['date_from'],
                        'date_to' => $args['date_to'],
                        'limit' => 5000,
                        'offset' => 0,
                    ];
                    $rows = medyar_get_document_submissions_for_admin($query);
                    $out[] = ['id', 'user_id', 'doc_type', 'status', 'updated_at'];
                    foreach ($rows as $r) {
                        $out[] = [$r->id, $r->user_id, $r->doc_type, $r->status, $r->updated_at];
                    }
                }
                break;
            default:
                $out[] = ['message'];
                $out[] = ['export not available for ' . $entity];
        }
        return $out;
    }

    public function bulk_update_withdrawals(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        check_admin_referer('wallet_bulk_update_withdrawals', 'wallet_bulk_nonce');

        $ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
        $ids = array_values(array_filter($ids));
        $new_status = sanitize_text_field((string) ($_POST['new_status'] ?? ''));
        $redirect = admin_url('admin.php?page=medyar-wallet-withdrawals');

        if (empty($ids) || !in_array($new_status, ['completed', 'canceled'], true)) {
            wp_redirect(add_query_arg('wwp_error', urlencode('انتخاب نامعتبر'), $redirect));
            exit;
        }

        $menu = Wallet_Admin_Menu::get_instance();
        $tm = Wallet_Transaction_Manager::get_instance();
        $ok = 0;
        $fail = 0;
        $admin_name = wp_get_current_user()->display_name;
        foreach ($ids as $tx_id) {
            $tx = $tm->get_transaction($tx_id);
            if (!$tx || $tx->operation_type !== 'withdraw') {
                $fail++;
                continue;
            }
            if (!in_array((string) $tx->status, ['pending', 'processing'], true)) {
                $fail++;
                continue;
            }
            $note = ($new_status === 'completed')
                ? ('تأیید گروهی توسط ادمین ' . $admin_name)
                : ('لغو گروهی توسط ادمین ' . $admin_name);
            $err = $menu->apply_transaction_status_change($tx, $new_status, $note);
            if ($err) {
                $fail++;
            } else {
                $ok++;
            }
        }

        wp_redirect(add_query_arg('wwp_updated', urlencode("گروهی: {$ok} موفق، {$fail} ناموفق"), $redirect));
        exit;
    }

    public function bulk_document_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        check_admin_referer('wallet_bulk_document_action', 'wallet_bulk_nonce');

        $ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
        $ids = array_values(array_filter($ids));
        $do = sanitize_key((string) ($_POST['bulk_action'] ?? ''));
        $reason = sanitize_text_field((string) ($_POST['rejection_reason'] ?? 'رد گروهی توسط ادمین'));
        $redirect = admin_url('admin.php?page=wallet-document-reviews');

        if (empty($ids) || !in_array($do, ['approve', 'reject'], true)) {
            wp_redirect(add_query_arg('doc_error', urlencode('انتخاب نامعتبر'), $redirect));
            exit;
        }
        $ok = 0;
        foreach ($ids as $id) {
            if ($do === 'approve' && function_exists('medyar_admin_approve_document')) {
                $res = medyar_admin_approve_document($id, get_current_user_id());
                if ($res === true || $res === 'already_approved') {
                    $ok++;
                }
            } elseif ($do === 'reject' && function_exists('medyar_admin_reject_document')) {
                $res = medyar_admin_reject_document($id, get_current_user_id(), $reason);
                if ($res === true || !is_wp_error($res)) {
                    $ok++;
                }
            }
        }
        wp_redirect(add_query_arg('doc_updated', '1', $redirect));
        exit;
    }
}

Wallet_Admin_List::get_instance();
