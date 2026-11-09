<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array{query_arg_name?:string,max_num_pages?:int,paged?:int} $args
 */
function mtw_myaccount_pagination(array $args): void
{
    $query_arg = !empty($args['query_arg_name']) ? sanitize_key($args['query_arg_name']) : 'page';
    $total     = max(1, (int) ($args['max_num_pages'] ?? 1));
    $paged     = max(1, (int) ($args['paged'] ?? 1));

    if ($total <= 1) {
        return;
    }

    $base = remove_query_arg(array_keys($_GET));
    $link = add_query_arg(array_merge($_GET, [$query_arg => '%#%']), $base);
    ?>
    <nav class="mtw-pagination sheyda_wallet_pagination" aria-label="صفحه‌بندی تراکنش‌ها">
        <?php
        echo paginate_links([
            'base'               => $link,
            'total'              => $total,
            'current'            => $paged,
            'format'             => '',
            'prev_next'          => true,
            'type'               => 'list',
            'prev_text'          => 'قبلی',
            'next_text'          => 'بعدی',
            'mid_size'           => 2,
            'end_size'           => 1,
            'before_page_number' => '<span class="screen-reader-text">صفحه </span>',
        ]);
        ?>
    </nav>
    <?php
}
