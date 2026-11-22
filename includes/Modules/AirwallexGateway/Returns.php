<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * The buyer coming back from Airwallex. The link only says which order to look at; whether it
 * is paid comes from the Airwallex API.
 */
final class Returns
{
    public const ACTION = 'stackless_airwallex_return';

    /** @param array<string,string|int> $args */
    public static function url(array $args): string
    {
        // A last argument nobody reads takes whatever a provider appends with "?" or "&".
        return add_query_arg($args + ['awx' => 1], WC()->api_request_url(self::ACTION));
    }

    /** woocommerce_api_stackless_airwallex_return */
    public static function handle(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Airwallex sends the buyer back; the signed key or the stored reference authenticates the request.
        $value = static function (string $name): string {
            return isset($_GET[$name]) && is_string($_GET[$name]) ? Checkout::param(sanitize_text_field(wp_unslash($_GET[$name]))) : '';
        };
        // phpcs:enable
        $back = $value('back') !== '';
        if ($value('flow') === 'add') {
            self::finishAdd(get_current_user_id(), $value('ref'), $back);
            return;
        }
        $sale = Checkout::returningOrder(absint($value('order_id')), $value('key'));
        if (!$sale) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $orderId = $sale->id();
        $outcome = (new Sync())->refresh($orderId);
        $sale = Sale::get($orderId);
        if ($sale && self::showsReceipt($sale, $outcome, $back)) {
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            wp_safe_redirect($sale->receiptUrl());
            exit;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice(!$back || $outcome === 'failed'
                ? __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite')
                : __('The payment was not completed. You can try again below.', 'stackless-lite'), 'error');
        }
        wp_safe_redirect($sale ? $sale->payUrl() : wc_get_checkout_url());
        exit;
    }

    /** Paid, or possibly paid: never send those buyers back to pay again. */
    public static function showsReceipt(Sale $sale, string $outcome, bool $back): bool
    {
        if ($sale->paid() || $sale->waiting() || $outcome === 'pending' || $outcome === 'held') {
            return true;
        }
        return !$back && ($outcome === 'error' || $outcome === 'busy');
    }

    private static function finishAdd(int $userId, string $ref, bool $back): void
    {
        $status = (new Checkout())->finishAdd($userId, $ref)['status'];
        if ($status === 'saved') {
            wc_add_notice(__('Payment method successfully added.', 'stackless-lite'));
        } elseif (($status === 'pending' && !$back) || $status === 'busy') {
            wc_add_notice(__('Airwallex is still confirming your card. It appears here once confirmed.', 'stackless-lite'), 'notice');
        } elseif ($status !== 'none') {
            wc_add_notice(__('Unable to add payment method to your account.', 'stackless-lite'), 'error');
        }
        wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
        exit;
    }
}
