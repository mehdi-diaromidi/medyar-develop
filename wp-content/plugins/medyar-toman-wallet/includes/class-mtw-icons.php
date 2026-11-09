<?php
/**
 * Render Medyar wallet icons in theme drplus-simple-icon style.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_Icons')) {
    return;
}

class MTW_Icons
{
    /**
     * @param string $icon Icon slug without drplus-icon- prefix (e.g. wallet-add).
     * @param string $extra_wrap_class Extra classes on wrap.
     */
    public static function render(string $icon, string $extra_wrap_class = ''): string
    {
        $icon = sanitize_html_class($icon);
        $bare = strpos($extra_wrap_class, 'mtw-section-icon--bare') !== false;
        $wrap = $bare
            ? trim('drplus-simple-icon-wrap mtw-section-icon ' . $extra_wrap_class)
            : trim('drplus-simple-icon-wrap icon-has-bg mtw-section-icon ' . $extra_wrap_class);
        return sprintf(
            '<span class="%s"><i class="drplus-icon-%s drplus-simple-icon" aria-hidden="true"></i></span>',
            esc_attr($wrap),
            esc_attr($icon)
        );
    }

    public static function echo(string $icon, string $extra_wrap_class = ''): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
        echo self::render($icon, $extra_wrap_class);
    }
}
