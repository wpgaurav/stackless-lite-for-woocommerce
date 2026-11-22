<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Core\FreshOrder;

defined('ABSPATH') || exit;

/**
 * The WooCommerce side of the payment engine. Every call re-reads the order, because another
 * request may have settled it while this one waited for the lock.
 */
final class Orders
{
    private const PAYABLE = ['pending', 'failed', 'checkout-draft'];

    private static function get(int $orderId): ?\WC_Order
    {
        $order = FreshOrder::get($orderId);
        return $order instanceof \WC_Order ? $order : null;
    }

    /**
     * @return array{id:int, number:string, gateway:string, status:string, total:string, currency:string, user:int, email:string, first:string, last:string, transaction:string, paid:bool, payable:bool, completable:bool}|null
     */
    public static function summary(int $orderId): ?array
    {
        $order = self::get($orderId);
        if (!$order) {
            return null;
        }
        $paid = $order->is_paid() || $order->get_date_paid() || $order->has_status('refunded');
        $held = $order->has_status('on-hold');
        $payable = !$paid && !$held && ($order->has_status(self::PAYABLE) || $order->needs_payment());
        return [
            'id' => $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'gateway' => (string) $order->get_payment_method(),
            'status' => (string) $order->get_status(),
            'total' => (string) $order->get_total(),
            'currency' => strtoupper((string) $order->get_currency()),
            'user' => (int) $order->get_customer_id(),
            'email' => (string) $order->get_billing_email(),
            'first' => (string) $order->get_billing_first_name(),
            'last' => (string) $order->get_billing_last_name(),
            'transaction' => (string) $order->get_transaction_id(),
            'paid' => (bool) $paid,
            'payable' => $payable,
            'completable' => !$paid && ($payable || $held),
        ];
    }

    /** @return mixed '' when the key is not set */
    public static function meta(int $orderId, string $key)
    {
        $order = self::get($orderId);
        return $order ? $order->get_meta($key) : '';
    }

    /** @param array<string,mixed> $values null deletes a key */
    public static function setMeta(int $orderId, array $values): bool
    {
        $order = self::get($orderId);
        if (!$order) {
            return false;
        }
        foreach ($values as $key => $value) {
            if ($value === null) {
                $order->delete_meta_data($key);
            } else {
                $order->update_meta_data($key, $value);
            }
        }
        return $order->save() > 0;
    }

    public static function note(int $orderId, string $note): void
    {
        $order = self::get($orderId);
        if ($order) {
            $order->add_order_note($note);
        }
    }

    public static function complete(int $orderId, string $transaction): void
    {
        self::setMeta($orderId, [Meta::CONFIRMING => null]);
        $order = self::get($orderId);
        if ($order) {
            $order->payment_complete($transaction);
        }
    }

    /** Out of "pending" while Braintree confirms, so nothing invites a second payment. */
    public static function awaitProvider(int $orderId, string $note): void
    {
        $order = self::get($orderId);
        if (!$order || $order->is_paid() || !$order->has_status(self::PAYABLE)) {
            return;
        }
        $order->update_meta_data(Meta::CONFIRMING, 'yes');
        $order->save();
        $order->update_status('on-hold', $note);
    }

    public static function holdForReview(int $orderId, string $note): void
    {
        self::setMeta($orderId, [Meta::CONFIRMING => null]);
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

    /** Fail an order that was waiting on this charge. The decline meta is already written. */
    public static function fail(int $orderId, string $note): void
    {
        $order = self::get($orderId);
        if (!$order) {
            return;
        }
        $confirming = (string) $order->get_meta(Meta::CONFIRMING) !== '';
        if ($order->has_status('pending') || ($confirming && $order->has_status('on-hold'))) {
            $order->delete_meta_data(Meta::CONFIRMING);
            $order->save();
            $order->update_status('failed', $note);
        } else {
            $order->add_order_note($note);
        }
    }

    /** @return array<string,string> An AddressInput (empty values left out). */
    public static function billing(int $orderId): array
    {
        $order = self::get($orderId);
        if (!$order) {
            return [];
        }
        $postcode = trim((string) $order->get_billing_postcode());
        $country = strtoupper((string) $order->get_billing_country());
        $address = [
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name(),
            'addressLine1' => $order->get_billing_address_1(),
            'addressLine2' => $order->get_billing_address_2(),
            'adminArea2' => $order->get_billing_city(),
            'adminArea1' => $order->get_billing_state(),
            // Braintree takes nine letters or digits, plus spaces and hyphens.
            'postalCode' => preg_match('/^[A-Za-z0-9 -]+$/', $postcode) && strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $postcode)) <= 9 ? $postcode : '',
            'countryCode' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        ];
        $clean = [];
        foreach ($address as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $clean[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 255) : substr($value, 0, 255);
            }
        }
        return $clean;
    }

    public static function requiresSave(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order ? stackless_order_requires_saved_payment_method($order) : false;
    }

    public static function isRenewal(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order ? stackless_is_renewal_order($order) : false;
    }

    public static function payUrl(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? (string) $order->get_checkout_payment_url() : '';
    }

    public static function findByMeta(string $key, string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $orders = wc_get_orders(['limit' => 1, 'type' => 'shop_order', 'meta_key' => $key, 'meta_value' => $value]); // phpcs:ignore WordPress.DB.SlowDBQuery -- one lookup by transaction id.
        return $orders && $orders[0] instanceof \WC_Order ? $orders[0]->get_id() : 0;
    }

    /** @return int[] Orders carrying a key, newest first (for Billing Health). */
    public static function withMeta(string $key, int $days): array
    {
        $orders = wc_get_orders([
            'limit' => 20,
            'type' => 'shop_order',
            'date_created' => '>' . (time() - $days * DAY_IN_SECONDS),
            'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery -- an admin report over a bounded window.
            'meta_compare' => 'EXISTS', // phpcs:ignore WordPress.DB.SlowDBQuery
        ]);
        $ids = [];
        foreach ((array) $orders as $order) {
            if ($order instanceof \WC_Order) {
                $ids[] = $order->get_id();
            }
        }
        return $ids;
    }

    public static function refundable(int $orderId): float
    {
        $order = self::get($orderId);
        return $order ? (float) $order->get_remaining_refund_amount() : 0.0;
    }

    /**
     * A refund made at Braintree, written to the order without calling Braintree again.
     *
     * @return true|\WP_Error
     */
    public static function recordExternalRefund(int $orderId, float $amount, string $reason, string $remoteId)
    {
        $created = wc_create_refund([
            'order_id' => $orderId,
            'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
            'reason' => $reason,
            'refund_payment' => false,
            'restock_items' => false,
        ]);
        return is_wp_error($created) ? $created : true;
    }
}
