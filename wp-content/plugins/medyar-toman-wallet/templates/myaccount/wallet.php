<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var string $prefix */
/** @var array $sections */
/** @var string $active_section */
/** @var string $base_url */
/** @var array{type:string,text:string}|null $pay_notice */
?>
<div class="<?php echo esc_attr($prefix); ?>wrap">
    <div class="<?php echo esc_attr($prefix); ?>head">
        <div class="<?php echo esc_attr($prefix); ?>nav_items" role="navigation" aria-label="بخش‌های کیف پول">
            <?php foreach ($sections as $section => $label) : ?>
                <a href="<?php echo esc_url(add_query_arg(['section' => $section], $base_url)); ?>"
                   class="<?php echo esc_attr($prefix); ?>nav_item<?php echo $active_section === $section ? ' active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (!empty($pay_notice) && is_array($pay_notice)) : ?>
        <div class="mtw-pay-notice mtw-pay-notice--<?php echo esc_attr((string) $pay_notice['type']); ?>" role="status">
            <?php echo esc_html((string) $pay_notice['text']); ?>
        </div>
    <?php endif; ?>
    <div class="<?php echo esc_attr($prefix); ?>section_content">
        <?php
        $file = MTW_PATH . 'templates/myaccount/section-' . $active_section . '.php';
        if (file_exists($file)) {
            include $file;
        }
        ?>
    </div>
</div>
