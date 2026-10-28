<?php

namespace Stackless\Modules\GatewayTools;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Payment Gateway Tools: copies of the hosted gateways, and the "Stackless" label
 * that marks Stackless's own gateways wherever the admin lists payment methods.
 */
final class Module extends BaseModule
{
    public const AJAX = 'stackless_gateway_tools_copy';

    public function id(): string
    {
        return 'gateway_tools';
    }

    public function title(): string
    {
        return __('Payment Gateway Tools', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Duplicate the Polar, Paddle and Dodo Payments gateways to take payments with a second account, each copy with its own keys, webhook URL, badge and orders. Marks every gateway Stackless adds with a Stackless label in WooCommerce’s payment settings and order screens.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function boot(): void
    {
        if (!is_admin()) {
            return;
        }
        add_filter('stackless_gateway_copies_panel', [$this, 'panel'], 10, 2);
        add_action('wp_ajax_' . self::AJAX, [$this, 'ajax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 30);
    }

    private static function allowed(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    /** Admin screens that list or name payment methods. */
    private static function labelScreen(string $hook): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (in_array($page, ['wc-settings', 'wc-orders'], true) || strpos($page, 'wc-orders--') === 0 || strpos($page, 'stackless') === 0) {
            return true;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return in_array($hook, ['post.php', 'post-new.php', 'edit.php'], true) && $screen && in_array($screen->post_type, ['shop_order', 'stackless_sub'], true);
    }

    public function enqueue(string $hook): void
    {
        if (!self::allowed() || !self::labelScreen($hook)) {
            return;
        }
        wp_enqueue_style('stackless-gateway_tools-admin', STACKLESS_PLUGIN_URL . 'assets/gateway_tools/admin.css', [], STACKLESS_VERSION);
        wp_enqueue_script('stackless-gateway_tools-admin', STACKLESS_PLUGIN_URL . 'assets/gateway_tools/admin.js', [], STACKLESS_VERSION, true);
        wp_add_inline_script('stackless-gateway_tools-admin', 'window.stacklessGatewayTools=' . wp_json_encode(self::config()) . ';', 'before');
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX,
            'nonce' => wp_create_nonce(self::AJAX),
            'settingsUrl' => admin_url('admin.php?page=wc-settings&tab=checkout&section='),
            'marker' => '(Stackless)',
            'label' => __('Stackless', 'stackless-lite'),
            'gateways' => (object) self::stacklessTitles(),
            'i18n' => [
                'labelTitle' => __('Added by Stackless for WooCommerce.', 'stackless-lite'),
                'copyTitle' => __('A copy with its own account and settings.', 'stackless-lite'),
                'duplicatePrompt' => __('Badge label for the new copy (optional, for example “EU account”). It starts switched off; connect it from its settings.', 'stackless-lite'),
                'renamePrompt' => __('Badge label for this copy. Leave it empty to show “Copy N”.', 'stackless-lite'),
                'removeConfirm' => __('Remove this copy and its saved settings? This only works while it has no orders or subscriptions.', 'stackless-lite'),
                'failed' => __('That did not work. Reload the page and try again.', 'stackless-lite'),
            ],
        ];
    }

    /**
     * Customer-facing titles of Stackless gateways, for order screens: they show "Payment via
     * {title}" and a select of titles rather than the admin name with its "(Stackless)" marker.
     *
     * @return array<string,array{title:string,badge:string}>
     */
    private static function stacklessTitles(): array
    {
        $titles = [];
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return $titles;
        }
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            // Every gateway Stackless adds carries the marker in its admin name (Stripe's id has no prefix).
            if (!$gateway instanceof \WC_Payment_Gateway || strpos((string) $gateway->get_method_title(), '(Stackless)') === false) {
                continue;
            }
            $title = trim(wp_strip_all_tags((string) $gateway->get_title()));
            if ($title !== '') {
                $titles[(string) $id] = ['title' => $title, 'badge' => Copies::badge((string) $id)];
            }
        }
        return $titles;
    }

    /** Settings screen being shown for this gateway id (the panel is skipped everywhere else). */
    private static function onSettings(string $id): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen check only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        // phpcs:enable
        return $page === 'wc-settings' && $section === $id;
    }

    /**
     * The Copies panel inside a copyable gateway's settings (original or copy).
     *
     * @param string $html
     */
    public function panel($html, string $id): string
    {
        if (!self::allowed() || !self::onSettings($id)) {
            return (string) $html;
        }
        $family = Copies::familyOf($id);
        if ($family === '') {
            return (string) $html;
        }
        $title = Copies::families()[$family]['title'];
        $copies = Copies::of($family);
        $button = static function (string $task, string $target, string $text, string $class = 'button'): string {
            return '<button type="button" class="' . esc_attr($class) . '" data-stackless-copy-task="' . esc_attr($task) . '" data-stackless-copy-id="' . esc_attr($target) . '">' . esc_html($text) . '</button>';
        };

        $out = '<span class="stackless-copies">';
        if ($id !== $family) {
            $out .= sprintf(
                /* translators: 1: gateway name, 2: link to the original gateway */
                esc_html__('This is a copy of %1$s with its own account, keys, webhook URL and orders. %2$s', 'stackless-lite'),
                esc_html($title),
                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $family)) . '">' . esc_html__('Open the original', 'stackless-lite') . '</a>'
            );
            $out .= '<br><br>' . $button('rename', $id, __('Rename badge', 'stackless-lite')) . ' ';
            $out .= Copies::inUse($id)
                ? '<em>' . esc_html__('Has orders, so it cannot be removed. Switch it off instead.', 'stackless-lite') . '</em>'
                : $button('remove', $id, __('Remove copy', 'stackless-lite'), 'button button-link-delete');
            return $out . '</span>';
        }

        $out .= sprintf(
            /* translators: %s: gateway name */
            esc_html__('Take payments with a second %s account: each copy has its own keys, webhook URL, badge and orders, and starts switched off.', 'stackless-lite'),
            esc_html($title)
        ) . '<br><br>';
        foreach ($copies as $copyId => $copy) {
            $out .= '<span class="stackless-copies__row"><strong>' . esc_html($title) . '</strong> <span class="stackless-gateway-copy">' . esc_html(Copies::badge($copyId)) . '</span> ';
            $out .= '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $copyId)) . '">' . esc_html__('Manage', 'stackless-lite') . '</a> ';
            $out .= $button('rename', $copyId, __('Rename', 'stackless-lite')) . '</span><br>';
        }
        if (count($copies) < Copies::MAX_PER_FAMILY) {
            $out .= '<br>' . $button('duplicate', $family, __('Duplicate this gateway', 'stackless-lite'), 'button button-secondary');
        }
        return $out . '</span>';
    }

    public function ajax(): void
    {
        if (!self::allowed() || !check_ajax_referer(self::AJAX, 'nonce', false)) {
            wp_send_json_error(['message' => __('You are not allowed to change payment gateways.', 'stackless-lite')], 403);
        }
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        $id = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';

        if ($task === 'duplicate') {
            $result = Copies::add($id, $label);
        } elseif ($task === 'rename') {
            $result = Copies::rename($id, $label);
        } elseif ($task === 'remove') {
            $family = Copies::familyOf($id);
            $result = Copies::remove($id);
            if ($result === true) {
                $result = ['id' => $family ?: $id, 'removed' => true];
            }
        } else {
            $result = new \WP_Error('stackless_copy_task', __('Unknown action.', 'stackless-lite'));
        }
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success(is_array($result) ? $result : ['id' => $id]);
    }
}
