<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Core\FreshOrder;
use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Everything the payment flows read or write on a WooCommerce order, by order id, so the flows
 * themselves are the same code in the WooCommerce and EDD editions.
 */
final class Orders
{
    public static function get(int $orderId): ?\WC_Order
    {
        $order = $orderId > 0 ? wc_get_order($orderId) : null;
        // Subscriptions are orders of their own type and are never paid.
        return $order instanceof \WC_Order && $order->get_type() === 'shop_order' ? $order : null;
    }

    public static function exists(int $orderId): bool
    {
        return self::get($orderId) !== null;
    }

    /** Forget cached copies: called once an order lock is held. */
    public static function refresh(int $orderId): void
    {
        FreshOrder::forget($orderId);
    }

    public static function meta(int $orderId, string $key): string
    {
        $order = self::get($orderId);
        $value = $order ? $order->get_meta($key) : '';
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array<int|string,mixed> */
    public static function metaList(int $orderId, string $key): array
    {
        $order = self::get($orderId);
        $value = $order ? $order->get_meta($key) : [];
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $values */
    public static function setMeta(int $orderId, array $values): void
    {
        $order = self::get($orderId);
        if (!$order) {
            return;
        }
        foreach ($values as $key => $value) {
            $order->update_meta_data($key, $value);
        }
        $order->save_meta_data();
        self::refresh($orderId);
    }

    public static function deleteMeta(int $orderId, string $key): void
    {
        $order = self::get($orderId);
        if ($order) {
            $order->delete_meta_data($key);
            $order->save_meta_data();
            self::refresh($orderId);
        }
    }

    /* ---- Reading --------------------------------------------------------------- */

    public static function currency(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? strtoupper((string) $order->get_currency()) : '';
    }

    public static function total(int $orderId): float
    {
        $order = self::get($orderId);
        return $order ? (float) $order->get_total() : 0.0;
    }

    public static function user(int $orderId): int
    {
        $order = self::get($orderId);
        return $order ? (int) $order->get_customer_id() : 0;
    }

    public static function email(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? (string) $order->get_billing_email() : '';
    }

    public static function name(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '';
    }

    public static function number(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? (string) $order->get_order_number() : (string) $orderId;
    }

    /** live|test: the mode the order was paid in, or is being paid in. */
    public static function mode(int $orderId): string
    {
        $mode = self::meta($orderId, Keys::MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : Settings::mode();
    }

    /** @return array<string,string> billingAddress for the checkout widget */
    public static function billing(int $orderId): array
    {
        $order = self::get($orderId);
        if (!$order) {
            return [];
        }
        return [
            'countryCode' => strtoupper((string) $order->get_billing_country()),
            'postcode' => (string) $order->get_billing_postcode(),
            'region' => (string) $order->get_billing_state(),
            'city' => (string) $order->get_billing_city(),
            'streetLine1' => (string) $order->get_billing_address_1(),
            'streetLine2' => (string) $order->get_billing_address_2(),
        ];
    }

    /** @return array<int,array{name:string, quantity:float, total:float, type:string}> */
    public static function lines(int $orderId): array
    {
        $order = self::get($orderId);
        if (!$order) {
            return [];
        }
        $lines = [];
        foreach ($order->get_items(['line_item', 'shipping', 'fee']) as $item) {
            $total = 0.0;
            $type = '';
            if ($item instanceof \WC_Order_Item_Product) {
                $product = $item->get_product();
                $type = $product instanceof \WC_Product ? ($product->is_virtual() ? 'service' : 'physical') : '';
                $total = (float) $item->get_total() + (float) $item->get_total_tax();
            } elseif ($item instanceof \WC_Order_Item_Shipping || $item instanceof \WC_Order_Item_Fee) {
                $type = 'service';
                $total = (float) $item->get_total() + (float) $item->get_total_tax();
            }
            $lines[] = ['name' => (string) $item->get_name(), 'quantity' => (float) $item->get_quantity(), 'total' => $total, 'type' => $type];
        }
        return $lines;
    }

    /** @return array<string,mixed> Revolut shipping, or [] without a complete address */
    public static function shipping(int $orderId): array
    {
        $order = self::get($orderId);
        if (!$order) {
            return [];
        }
        $address = [
            'street_line_1' => Api::text((string) $order->get_shipping_address_1(), 100),
            'city' => Api::text((string) $order->get_shipping_city(), 100),
            'country_code' => strtoupper((string) $order->get_shipping_country()),
            'postcode' => Api::text((string) $order->get_shipping_postcode(), 100),
        ];
        if (in_array('', $address, true) || !preg_match('/^[A-Z]{2}$/', $address['country_code'])) {
            return [];
        }
        foreach (['street_line_2' => (string) $order->get_shipping_address_2(), 'region' => (string) $order->get_shipping_state()] as $key => $value) {
            if ($value !== '') {
                $address[$key] = Api::text($value, 100);
            }
        }
        $shipping = ['address' => $address];
        if (is_email($order->get_billing_email())) {
            $contact = ['email' => (string) $order->get_billing_email()];
            $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            if ($name !== '') {
                $contact['name'] = Api::text($name, 250);
            }
            $shipping['contact'] = $contact;
        }
        return $shipping;
    }

    /* ---- Status ---------------------------------------------------------------- */

    /** Paid at least once (refunds and a later hold for a dispute included). */
    public static function settled(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && ($order->is_paid() || $order->has_status('refunded') || $order->get_date_paid());
    }

    /** By status only: a free order that must save a card still needs the gateway. */
    public static function needsPayment(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && $order->has_status(['pending', 'failed']) && !self::settled($orderId);
    }

    public static function isPending(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && $order->has_status('pending');
    }

    public static function underReview(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && $order->has_status('on-hold');
    }

    public static function isOurs(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && $order->get_payment_method() === Keys::GATEWAY_ID;
    }

    /* ---- Writing --------------------------------------------------------------- */

    public static function note(int $orderId, string $note): void
    {
        $order = self::get($orderId);
        if ($order) {
            $order->add_order_note($note);
        }
    }

    public static function hold(int $orderId, string $note): void
    {
        $order = self::get($orderId);
        if (!$order) {
            return;
        }
        if ($order->has_status('on-hold')) {
            $order->add_order_note($note);
        } else {
            $order->update_status('on-hold', $note);
        }
    }

    public static function fail(int $orderId, string $note): void
    {
        $order = self::get($orderId);
        if ($order) {
            $order->update_status('failed', $note);
        }
    }

    /** Complete the order, moving it to this gateway when the money arrived here. True once it is paid. */
    public static function complete(int $orderId, string $transactionId): bool
    {
        $order = self::get($orderId);
        if (!$order) {
            return false;
        }
        if ($order->get_payment_method() !== Keys::GATEWAY_ID) {
            $title = Settings::get('title');
            $order->set_payment_method(Keys::GATEWAY_ID);
            $order->set_payment_method_title($title !== '' ? $title : 'Revolut');
            $order->save();
        }
        $order->payment_complete($transactionId);
        self::refresh($orderId);
        // payment_complete() also answers true for a status it leaves alone (trash, draft).
        return self::settled($orderId);
    }

    public static function remainingRefund(int $orderId): float
    {
        $order = self::get($orderId);
        return $order ? (float) $order->get_remaining_refund_amount() : 0.0;
    }

    /** A refund made in Revolut, recorded without calling Revolut again. */
    public static function recordRefund(int $orderId, float $amount, string $refundId, string $note): bool
    {
        $refund = wc_create_refund([
            'order_id' => $orderId,
            'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
            'reason' => $note,
            'refund_payment' => false,
            'restock_items' => false,
        ]);
        if (is_wp_error($refund)) {
            Settings::log(sprintf('Could not record Revolut refund %s on order %d: %s', $refundId, $orderId, $refund->get_error_message()), 'error');
            return false;
        }
        return true;
    }

    public static function dispute(int $orderId, string $disputeId, string $note): void
    {
        $order = self::get($orderId);
        if (!$order) {
            return;
        }
        if (self::settled($orderId) && !$order->has_status(['refunded', 'on-hold'])) {
            $order->update_status('on-hold', $note);
        } else {
            $order->add_order_note($note);
        }
    }

    /* ---- Subscriptions --------------------------------------------------------- */

    /** @return mixed the first subscription of the order, or null */
    public static function subscriptionFor(int $orderId)
    {
        $order = self::get($orderId);
        $subscriptions = $order ? stackless_get_order_subscriptions($order) : [];
        return $subscriptions ? reset($subscriptions) : null;
    }

    /** @param mixed $subscription */
    public static function subscriptionValue($subscription, string $key): string
    {
        $value = $subscription instanceof \WC_Order ? $subscription->get_meta($key) : '';
        return is_scalar($value) ? (string) $value : '';
    }

    /* ---- Links ----------------------------------------------------------------- */

    public static function successUrl(int $orderId): string
    {
        $order = self::get($orderId);
        if (!$order) {
            return wc_get_checkout_url();
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied the way WC_Payment_Gateway::get_return_url() does.
        return (string) apply_filters('woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order);
    }

    public static function payUrl(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? (string) $order->get_checkout_payment_url() : wc_get_checkout_url();
    }

    /* ---- Billing Health -------------------------------------------------------- */

    /** @return string[] order numbers of saved-card charges still unresolved */
    public static function stuckCharges(int $before): array
    {
        return self::numbers(Keys::CHARGE, ['status' => ['pending'], 'payment_method' => Keys::GATEWAY_ID, 'date_created' => '<' . $before]);
    }

    /** @return string[] order numbers of payments to review */
    public static function toReview(int $since): array
    {
        return self::numbers(Keys::REVIEW, ['limit' => 50], $since);
    }

    /**
     * @param array<string,mixed> $args
     * @return string[]
     */
    private static function numbers(string $key, array $args, int $since = 0): array
    {
        $args = $args + ['type' => 'shop_order', 'limit' => 20];
        $args['meta_query'] = [['key' => $key, 'value' => 'yes']]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Billing Health only, at most 20 rows.
        $numbers = [];
        foreach ((array) wc_get_orders(OrderQuery::args($args)) as $order) {
            if ($order instanceof \WC_Order && $order->get_meta($key) === 'yes' && ($since === 0 || (int) $order->get_meta(Keys::REVIEW_AT) >= $since)) {
                $numbers[] = '#' . $order->get_order_number();
            }
        }
        return $numbers;
    }
}
