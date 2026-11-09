<?php
/**
 * Admin: list all users' toman wallet balances.
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
require_once WALLET_PLUGIN_PATH . 'admin/class-mtw-admin-ui.php';

global $wpdb;

$per_page  = 50;
$paged     = max(1, absint($_GET['paged'] ?? 1));
$search    = sanitize_text_field(wp_unslash($_GET['user_q'] ?? ($_GET['s'] ?? '')));
$amount_min = isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? (float) wallet_sanitize_amount($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? (float) wallet_sanitize_amount($_GET['amount_max']) : null;
$orderby   = sanitize_key($_GET['orderby'] ?? 'balance');
$order     = strtoupper(sanitize_key($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
if (!in_array($orderby, ['balance', 'user_id', 'display_name', 'user_login'], true)) {
    $orderby = 'balance';
}

$balances_table = $wpdb->prefix . 'sheyda_wallet_balances';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$has_sheyda = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $balances_table)) === $balances_table;

$offset = ($paged - 1) * $per_page;
$rows   = [];
$total  = 0;

if ($has_sheyda) {
    $where  = '1=1';
    $params = [];
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR CAST(b.user_id AS CHAR) = %s)';
        array_push($params, $like, $like, $like, $search);
    }
    if ($amount_min !== null) {
        $where .= ' AND GREATEST(0, CAST(b.balance AS SIGNED) - CAST(b.locked AS SIGNED)) >= %d';
        $params[] = (int) $amount_min;
    }
    if ($amount_max !== null) {
        $where .= ' AND GREATEST(0, CAST(b.balance AS SIGNED) - CAST(b.locked AS SIGNED)) <= %d';
        $params[] = (int) $amount_max;
    }

    if ($orderby === 'user_id') {
        $order_sql = "b.user_id {$order}";
    } elseif ($orderby === 'display_name') {
        $order_sql = "u.display_name {$order}";
    } elseif ($orderby === 'user_login') {
        $order_sql = "u.user_login {$order}";
    } else {
        $order_sql = "available {$order}";
    }

    $count_sql = "SELECT COUNT(*)
        FROM `{$balances_table}` b
        INNER JOIN {$wpdb->users} u ON u.ID = b.user_id
        WHERE {$where}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

    $list_sql = "SELECT b.user_id, b.balance, b.locked,
            GREATEST(0, CAST(b.balance AS SIGNED) - CAST(b.locked AS SIGNED)) AS available,
            b.updated_at, u.display_name, u.user_login, u.user_email
        FROM `{$balances_table}` b
        INNER JOIN {$wpdb->users} u ON u.ID = b.user_id
        WHERE {$where}
        ORDER BY {$order_sql}
        LIMIT %d OFFSET %d";
    $list_params = array_merge($params, [$per_page, $offset]);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $sum_available = (int) $wpdb->get_var(
        "SELECT COALESCE(SUM(GREATEST(0, CAST(balance AS SIGNED) - CAST(locked AS SIGNED))), 0)
         FROM `{$balances_table}`"
    );
} else {
    $meta_key = 'wallet_toman_balance';
    $where    = 'um.meta_key = %s';
    $params   = [$meta_key];
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR CAST(um.user_id AS CHAR) = %s)';
        array_push($params, $like, $like, $like, $search);
    }
    if ($amount_min !== null) {
        $where .= ' AND CAST(um.meta_value AS DECIMAL(20,0)) >= %d';
        $params[] = (int) $amount_min;
    }
    if ($amount_max !== null) {
        $where .= ' AND CAST(um.meta_value AS DECIMAL(20,0)) <= %d';
        $params[] = (int) $amount_max;
    }

    if ($orderby === 'user_id') {
        $order_sql = "um.user_id {$order}";
    } elseif ($orderby === 'display_name') {
        $order_sql = "u.display_name {$order}";
    } elseif ($orderby === 'user_login') {
        $order_sql = "u.user_login {$order}";
    } else {
        $order_sql = "CAST(um.meta_value AS DECIMAL(20,0)) {$order}";
    }

    $count_sql = "SELECT COUNT(*)
        FROM {$wpdb->usermeta} um
        INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
        WHERE {$where}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

    $list_sql = "SELECT um.user_id, CAST(um.meta_value AS DECIMAL(20,0)) AS balance, 0 AS locked,
            CAST(um.meta_value AS DECIMAL(20,0)) AS available,
            NULL AS updated_at, u.display_name, u.user_login, u.user_email
        FROM {$wpdb->usermeta} um
        INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
        WHERE {$where}
        ORDER BY {$order_sql}
        LIMIT %d OFFSET %d";
    $list_params = array_merge($params, [$per_page, $offset]);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params));

    $sum_available = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(meta_value AS DECIMAL(20,0))), 0)
         FROM {$wpdb->usermeta}
         WHERE meta_key = %s",
        'wallet_toman_balance'
    ));
}

$base_url = MTW_Admin_Ui::url('medyar-wallet-balances');
$sort_url = static function (string $col) use ($base_url, $search, $orderby, $order, $amount_min, $amount_max): string {
    $next = ($orderby === $col && $order === 'DESC') ? 'ASC' : 'DESC';
    return add_query_arg(
        array_filter([
            'user_q'     => $search !== '' ? $search : null,
            'amount_min' => $amount_min !== null ? (string) $amount_min : null,
            'amount_max' => $amount_max !== null ? (string) $amount_max : null,
            'orderby'    => $col,
            'order'      => $next,
        ], static function ($v) {
            return $v !== null && $v !== '';
        }),
        $base_url
    );
};
?>
<div class="wrap wallet-admin-wrap mtw-balances-page">
    <h1 class="wp-heading-inline">موجودی کاربران</h1>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit')); ?>" class="page-title-action">واریز دستی</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>

    <div class="mtw-summary">
        <div class="mtw-summary__item">
            <span class="mtw-muted">تعداد کاربران با کیف پول</span>
            <strong><?php echo esc_html(number_format_i18n($total)); ?></strong>
        </div>
        <div class="mtw-summary__item">
            <span class="mtw-muted">جمع موجودی قابل استفاده</span>
            <strong><?php echo esc_html(number_format_i18n($sum_available)); ?> تومان</strong>
        </div>
    </div>

    <div class="wallet-card wallet-filter-card">
        <h2>فیلتر و جستجو</h2>
        <form method="get" class="mtw-balances-filter" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="medyar-wallet-balances" />
            <div class="wwp-filter-grid">
                <label>کاربر / موبایل / ایمیل
                    <input type="text" name="user_q" class="regular-text" value="<?php echo esc_attr($search); ?>" placeholder="شناسه، نام، ایمیل…" autocomplete="off" />
                </label>
                <label>حداقل موجودی
                    <input type="number" name="amount_min" min="0" step="1" value="<?php echo $amount_min !== null ? esc_attr((string) (int) $amount_min) : ''; ?>" />
                </label>
                <label>حداکثر موجودی
                    <input type="number" name="amount_max" min="0" step="1" value="<?php echo $amount_max !== null ? esc_attr((string) (int) $amount_max) : ''; ?>" />
                </label>
            </div>
            <p class="mtw-balances-filter__actions">
                <button type="submit" class="button button-primary">اعمال فیلتر</button>
                <a class="button" href="<?php echo esc_url($base_url); ?>">پاک کردن</a>
            </p>
        </form>
    </div>

    <div class="wallet-card">
        <h2>لیست موجودی‌ها (<?php echo esc_html(number_format_i18n($total)); ?>)</h2>
        <div class="wwp-table-scroll">
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><a href="<?php echo esc_url($sort_url('user_id')); ?>">شناسه</a></th>
                        <th><a href="<?php echo esc_url($sort_url('display_name')); ?>">کاربر</a></th>
                        <th><a href="<?php echo esc_url($sort_url('balance')); ?>">موجودی قابل استفاده</a></th>
                        <th>قفل‌شده</th>
                        <th>کل موجودی</th>
                        <th>آخرین به‌روزرسانی</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr>
                            <td colspan="7">موجودی‌ای یافت نشد.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) :
                            $uid = (int) $row->user_id;
                            $available = (int) $row->available;
                            $locked = (int) ($row->locked ?? 0);
                            $balance = (int) $row->balance;
                            $insights = MTW_Admin_Ui::url('medyar-wallet-user-insights', ['user_id' => $uid]);
                            $deposit  = MTW_Admin_Ui::url('medyar-wallet-deposit', ['user_q' => (string) $uid]);
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $uid); ?></td>
                                <td>
                                    <strong><?php echo esc_html((string) $row->display_name); ?></strong>
                                    <div class="mtw-muted"><?php echo esc_html((string) $row->user_login); ?></div>
                                    <div class="mtw-muted"><?php echo esc_html((string) $row->user_email); ?></div>
                                </td>
                                <td><strong><?php echo esc_html(number_format_i18n($available)); ?></strong> تومان</td>
                                <td><?php echo esc_html(number_format_i18n($locked)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($balance)); ?></td>
                                <td>
                                    <?php
                                    if (!empty($row->updated_at)) {
                                        echo esc_html(wwp_format_datetime_jalali((string) $row->updated_at));
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url($insights); ?>">پنل کاربر</a>
                                    <a class="button button-small" href="<?php echo esc_url($deposit); ?>">واریز</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $total_pages = (int) ceil($total / $per_page);
        if ($total_pages > 1) :
            $page_links = paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $total_pages,
                'current'   => $paged,
            ]);
            if ($page_links) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($page_links) . '</div></div>';
            }
        endif;
        ?>
    </div>
</div>
