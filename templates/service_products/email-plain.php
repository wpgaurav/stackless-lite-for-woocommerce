<?php
/**
 * Service instructions in plain-text customer emails.
 *
 * Override in your theme at stackless/service_products/email-plain.php.
 *
 * @var array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}> $blocks
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

foreach ($blocks as $block) {
    echo "\n" . esc_html(wc_strtoupper($block['heading'])) . "\n\n";
    $stackless_text = trim(wp_strip_all_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], "\n", $block['instructions'])));
    if ($stackless_text !== '') {
        echo esc_html(html_entity_decode($stackless_text, ENT_QUOTES, 'UTF-8')) . "\n";
    }
    if ($block['link'] !== '') {
        echo "\n" . esc_html($block['link_label']) . ': ' . esc_url_raw($block['link']) . "\n";
    }
    echo "\n----------------------------------------\n";
}
