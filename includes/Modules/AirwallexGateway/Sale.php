<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\FreshOrder;

defined('ABSPATH') || exit;

/**
 * A WooCommerce order as the payment flows see it. Checkout, Sync, Renewals and Refunds only
 * reach orders through this class, and always load a fresh one inside the order's lock.
 */
final class Sale
{
    /** @var \WC_Order */
    private $order;

    private function __construct(\WC_Order $order)
    {
        $this->order = $order;
    }

    /** The order as it is in the database now. */
    public static function get(int $orderId): ?self
    {
        $order = FreshOrder::get($orderId);
        return $order && $order->get_type() !== 'stackless_sub' ? new self($order) : null;
    }

    /** The order a merchant_order_id belongs to: both the id inside it and the order's own copy must agree. */
    public static function fromReference(string $reference): ?self
    {
        if (!preg_match('/^' . Settings::REFERENCE_PREFIX . '-([1-9]\d*)-[a-f0-9]{16}$/D', $reference, $match)) {
            return null;
        }
        $sale = self::get((int) $match[1]);
        return $sale && hash_equals($sale->reference(), $reference) ? $sale : null;
    }

    public function id(): int
    {
        return (int) $this->order->get_id();
    }

    /** '' until a payment was started, and for a copy of another order's meta. */
    public function reference(): string
    {
        $reference = $this->text(Meta::REFERENCE);
        return strpos($reference, Settings::REFERENCE_PREFIX . '-' . $this->id() . '-') === 0 ? $reference : '';
    }

    public function newReference(): string
    {
        return Settings::REFERENCE_PREFIX . '-' . $this->id() . '-' . bin2hex(random_bytes(8));
    }

    public function number(): string
    {
        return (string) $this->order->get_order_number();
    }

    public function currency(): string
    {
        return strtoupper((string) $this->order->get_currency());
    }

    public function total(): string
    {
        return (string) $this->order->get_total();
    }

    public function isZero(): bool
    {
        return Money::toMinor($this->total(), $this->currency()) <= 0;
    }

    public function userId(): int
    {
        return (int) $this->order->get_customer_id();
    }

    public function email(): string
    {
        return (string) $this->order->get_billing_email();
    }

    public function firstName(): string
    {
        return (string) $this->order->get_billing_first_name();
    }

    public function lastName(): string
    {
        return (string) $this->order->get_billing_last_name();
    }

    public function country(): string
    {
        return strtoupper((string) $this->order->get_billing_country());
    }

    /**
     * @phpstan-impure
     * @return mixed '' when the key is not set
     */
    public function meta(string $key)
    {
        return $this->order->get_meta($key);
    }

    /** @phpstan-impure */
    public function text(string $key): string
    {
        $value = $this->meta($key);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @phpstan-impure
     * @return array<mixed>
     */
    public function data(string $key): array
    {
        $value = $this->meta($key);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $values meta key => value, null deletes it */
    public function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                $this->order->delete_meta_data($key);
            } else {
                $this->order->update_meta_data($key, $value);
            }
        }
        $this->order->save();
    }

    public function isOurs(): bool
    {
        return $this->order->get_payment_method() === Settings::GATEWAY_ID;
    }

    /**
     * Paid at least once (refunds and a later hold for a dispute included).
     *
     * @phpstan-impure
     */
    public function paid(): bool
    {
        return $this->order->is_paid() || (bool) $this->order->get_date_paid();
    }

    /**
     * Unpaid, and a payment may be started.
     *
     * @phpstan-impure
     */
    public function payable(): bool
    {
        return !$this->paid() && $this->order->has_status(['pending', 'failed']);
    }

    /**
     * Parked by this gateway while an asynchronous payment finishes.
     *
     * @phpstan-impure
     */
    public function waiting(): bool
    {
        return !$this->paid() && $this->order->has_status('on-hold') && $this->text(Meta::WAITING) === 'yes';
    }

    /**
     * Whether a verified payment may complete the order.
     *
     * @phpstan-impure
     */
    public function canSettle(): bool
    {
        return $this->payable() || $this->waiting();
    }

    public function requiresSave(): bool
    {
        return stackless_order_requires_saved_payment_method($this->order);
    }

    public function isRenewal(): bool
    {
        return stackless_is_renewal_order($this->order);
    }

    /** @return string[] The saved methods the order, then its subscriptions, point at. */
    public function tokenIds(): array
    {
        $ids = [$this->text(SubscriptionData::META_TOKEN_ID)];
        foreach (stackless_get_order_subscriptions($this->order) as $subscription) {
            if ($subscription instanceof \WC_Order) {
                $ids[] = (string) $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
            }
        }
        return array_values(array_filter($ids));
    }

    public function note(string $note): void
    {
        $this->order->add_order_note($note);
    }

    /** Waiting for an asynchronous payment: out of the unpaid-order cleanup and not payable again. */
    public function wait(string $note): void
    {
        $this->put([Meta::WAITING => 'yes']);
        $this->order->update_status('on-hold', $note);
    }

    /** A person has to look at it. */
    public function hold(string $note): void
    {
        if ($this->order->has_status('on-hold')) {
            $this->note($note);
            return;
        }
        $this->order->update_status('on-hold', $note);
    }

    /** A dispute pauses fulfillment. */
    public function dispute(string $disputeId, string $note): void
    {
        if ($this->paid() && $this->order->has_status(['processing', 'completed'])) {
            $this->order->update_status('on-hold', $note);
            return;
        }
        $this->note($note);
    }

    public function fail(string $note): void
    {
        if ($this->order->has_status('failed') || !$this->canSettle()) {
            $this->note($note);
            return;
        }
        $this->order->update_status('failed', $note);
    }

    public function complete(string $transactionId = ''): bool
    {
        $this->order->payment_complete($transactionId);
        return $this->paid();
    }

    public function payUrl(): string
    {
        return (string) $this->order->get_checkout_payment_url();
    }

    public function receiptUrl(): string
    {
        return (string) Gateway::instance()->get_return_url($this->order);
    }

    /** @param float|int|string $amount Plain text, for notes. */
    public function money($amount, string $currency = ''): string
    {
        return wp_strip_all_tags(wc_price((float) $amount, ['currency' => $currency !== '' ? $currency : $this->currency()]));
    }

    public function remainingRefund(): float
    {
        return (float) $this->order->get_remaining_refund_amount();
    }

    /** A refund made in Airwallex. It never calls the gateway back. */
    public function recordRefund(float $amount, string $refundId, string $reason): bool
    {
        $refund = wc_create_refund([
            'order_id' => $this->id(),
            'amount' => wc_format_decimal($amount, Money::decimals($this->currency())),
            'reason' => $reason,
            'refund_payment' => false,
            'restock_items' => false,
        ]);
        if (is_wp_error($refund)) {
            Settings::log('Could not record Airwallex refund ' . $refundId . ' on order ' . $this->id() . ': ' . $refund->get_error_message(), 'error');
            return false;
        }
        // WooCommerce changed the order's totals and perhaps its status.
        $fresh = FreshOrder::get($this->id());
        $this->order = $fresh ?: $this->order;
        return true;
    }
}
