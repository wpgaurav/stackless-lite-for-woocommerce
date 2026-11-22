<?php

namespace Stackless\Modules\CreemGateway;

use Stackless\Core\FreshOrder;

defined('ABSPATH') || exit;

/**
 * An order's earlier Creem checkouts.
 *
 * A new checkout is created only when every earlier one is known to be unpaid or known not to
 * exist: Creem's own 404 from the store that created it. Any other answer stops the checkout
 * with "try again in a moment". Creem cannot close a checkout, so replaced ones stay listed on
 * the order and are read again before another is created.
 *
 * So that no order is stranded: putting the key back resolves it, a test checkout whose key is
 * gone doesn't block a live payment, and once a check has failed the merchant gets an order
 * action to allow a new payment after looking in Creem.
 */
final class Attempts
{
    public const ACTION = 'stackless_creem_new_payment';

    /** Order meta: it has an extra payment, and (prefix) the subscriptions those started. */
    private const META_EXTRA = '_stackless_creem_has_extra';
    private const META_EXTRA_SUBSCRIPTION = '_stackless_creem_extra_subscription_';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function tryAgain(): \WP_Error
    {
        return new \WP_Error('stackless_creem_unverified', __('We couldn’t check the earlier Creem payment for this order, so you weren’t charged again. Please try again in a moment, or contact us if this keeps happening.', 'stackless-lite'));
    }

    /**
     * Record a new checkout's store, and the replaced ones a buyer could still pay.
     *
     * @param array<int,array{id:string,mode:string,gateway:string,key:string}> $payable
     */
    public static function remember(\WC_Order $order, Gateway $gateway, string $mode, array $payable): void
    {
        $order->update_meta_data(Gateway::META_SOURCE, $gateway->id . '|' . self::keyPrint($gateway->apiKey($mode)));
        if ($payable) {
            $order->update_meta_data(Gateway::META_REPLACED, $payable);
        } else {
            $order->delete_meta_data(Gateway::META_REPLACED);
        }
        // The merchant's releases stay: they name checkouts that may still be on the replaced list.
        $order->delete_meta_data(Gateway::META_UNVERIFIED);
    }

    private static function keyPrint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    /** The order id a checkout was created for, '' when it doesn't say. */
    public static function orderIdOf(array $checkout): string
    {
        if (is_array($checkout['metadata'] ?? null) && !empty($checkout['metadata']['wc_order_id'])) {
            return (string) $checkout['metadata']['wc_order_id'];
        }
        return preg_match('/^wc(\d+)-/', (string) ($checkout['request_id'] ?? ''), $match) ? $match[1] : '';
    }

    /** @return array<int,array{id:string,mode:string,gateway:string,key:string}> The current checkout first. */
    private function sessions(\WC_Order $order): array
    {
        $sessions = [];
        $id = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        if ($id !== '') {
            $source = explode('|', (string) $order->get_meta(Gateway::META_SOURCE));
            $sessions[] = ['id' => $id, 'mode' => $this->gateway->orderMode($order), 'gateway' => $source[0], 'key' => $source[1] ?? ''];
        }
        $replaced = $order->get_meta(Gateway::META_REPLACED);
        foreach (is_array($replaced) ? $replaced : [] as $session) {
            if (is_array($session) && !empty($session['id'])) {
                $sessions[] = [
                    'id' => (string) $session['id'],
                    'mode' => (string) ($session['mode'] ?? ''),
                    'gateway' => (string) ($session['gateway'] ?? ''),
                    'key' => (string) ($session['key'] ?? ''),
                ];
            }
        }
        return $sessions;
    }

    /**
     * What became of every checkout the order started.
     *
     * @return array{url?:string,open?:array,payable?:array}|\WP_Error "url" when one is paid,
     *         "open" the current one while payable, "payable" every one still payable.
     */
    public function earlier(\WC_Order $order)
    {
        $current = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        $result = ['payable' => []];
        foreach ($this->sessions($order) as $session) {
            $found = $this->inspect($order, $session);
            if (is_wp_error($found) || isset($found['url'])) {
                return $found;
            }
            if (empty($found['status'])) {
                continue;
            }
            if ($session['id'] === $current) {
                $result['open'] = $found;
            } elseif ($found['status'] === 'processing') {
                $held = $this->processing($order, $found['session']);
                if (is_wp_error($held)) {
                    return $held;
                }
            }
            $result['payable'][] = $found['session'];
        }
        return $result;
    }

    /**
     * A checkout with a payment under way can't be replaced.
     *
     * @return array|\WP_Error
     */
    public function processing(\WC_Order $order, array $session)
    {
        return $this->unknown($order, $session, __('a payment on it is still being processed', 'stackless-lite'));
    }

    /** @return array{url?:string,status?:string,session?:array,gateway?:string}|\WP_Error */
    private function inspect(\WC_Order $order, array $session)
    {
        $mode = $session['mode'];
        $source = $session['gateway'] === '' ? $this->gateway : $this->account($session['gateway']);
        $sourceKey = $source ? $source->apiKey($mode) : '';

        $stores = $source && $sourceKey !== '' ? [$source] : [];
        if ($session['gateway'] === '') {
            // No record of the store: any copy with a key for this mode may have created it.
            foreach (Copies::ids() as $copyId) {
                $copy = $this->account($copyId);
                if ($copy && $copy->id !== $this->gateway->id && $copy->apiKey($mode) !== '') {
                    $stores[] = $copy;
                }
            }
        }
        foreach ($stores as $store) {
            $checkout = $store->api($mode)->request('GET', 'v1/checkouts', ['checkout_id' => $session['id']]);
            if (!is_wp_error($checkout)) {
                return $this->classify($order, $session, $checkout, $store);
            }
            if (!Api::isNotFound($checkout)) {
                return $this->unknown($order, $session, $checkout->get_error_message());
            }
        }

        if ($sourceKey === '') {
            if ($mode === 'test' && $this->gateway->mode() === 'live') {
                $this->gateway->log('Order ' . $order->get_id() . ': test checkout ' . $session['id'] . ' has no key left to check it with; it cannot be a live payment.', 'warning');
                return [];
            }
            return $this->unknown($order, $session, __('the API key it was created with is no longer saved', 'stackless-lite'));
        }
        if ($session['key'] === '') {
            // Started before stores were recorded: not found here may be paid in the store that created it.
            return $this->unknown($order, $session, __('there is no record of the API key it was created with', 'stackless-lite'));
        }
        if (!hash_equals($session['key'], self::keyPrint($sourceKey))) {
            return $this->unknown($order, $session, __('the API key changed and the new one cannot see it', 'stackless-lite'));
        }
        return [];
    }

    /** @return array{url?:string,status?:string,session?:array,gateway?:string}|\WP_Error */
    private function classify(\WC_Order $order, array $session, array $checkout, Gateway $store)
    {
        $status = (string) ($checkout['status'] ?? '');
        if ((string) ($checkout['id'] ?? '') !== $session['id'] || !in_array($status, ['pending', 'processing', 'completed', 'expired'], true)) {
            return $this->unknown($order, $session, __('Creem’s answer could not be read', 'stackless-lite'));
        }
        $owner = self::orderIdOf($checkout);
        if ($owner !== '' && $owner !== (string) $order->get_id()) {
            return []; // Meta copied from another order: that order's payment, not this one's.
        }
        if ($status === 'completed') {
            return $this->settle($order, $session, $checkout, $store);
        }
        if (is_array($checkout['order'] ?? null) && ($checkout['order']['status'] ?? '') === 'paid') {
            return $this->unknown($order, $session, __('Creem shows a paid order on it', 'stackless-lite'));
        }
        if ($status === 'expired') {
            return [];
        }
        return ['status' => $status, 'session' => $session, 'gateway' => $store->id];
    }

    /** @return array{url?:string}|\WP_Error */
    private function settle(\WC_Order $order, array $session, array $checkout, Gateway $store)
    {
        $mode = $session['mode'];
        $sync = new Sync($store);
        $refunded = $sync->refundedCheckout($checkout, $mode);
        if ($refunded === true) {
            return [];
        }
        $recorded = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if (is_wp_error($refunded) && $recorded !== '' && $recorded === EventMap::id($checkout['order']['transaction'] ?? '')) {
            // Recorded once already, so it may have been refunded since: don't pay the order with it unchecked.
            return $this->unknown($order, $session, $refunded->get_error_message());
        }
        self::adopt($order, $store);
        try {
            $outcome = $sync->applyCheckout($checkout, $mode, $order);
        } catch (\Throwable $e) {
            return $this->unknown($order, $session, $e->getMessage());
        }
        $fresh = FreshOrder::get($order->get_id());
        if ($fresh instanceof \WC_Order && ($fresh->is_paid() || $fresh->has_status('on-hold'))) {
            return ['url' => $store->get_return_url($fresh)];
        }
        if ($outcome === 'other mode' && $mode === 'test') {
            return []; // Noted on the order; a test payment never pays a live one.
        }
        /* translators: %s: what stopped it, such as "no order yet" */
        return $this->unknown($fresh instanceof \WC_Order ? $fresh : $order, $session, sprintf(__('it completed but could not be applied (%s)', 'stackless-lite'), $outcome));
    }

    /** @return array|\WP_Error An empty array when the merchant allowed a new payment, else "try again". */
    private function unknown(\WC_Order $order, array $session, string $reason)
    {
        $id = $session['id'];
        $released = $order->get_meta(Gateway::META_RELEASED);
        if (is_array($released) && in_array($id, $released, true)) {
            $this->gateway->log('Order ' . $order->get_id() . ': starting a new payment although ' . $id . ' could not be checked, as the merchant allowed.', 'warning');
            return [];
        }
        $this->gateway->log('Order ' . $order->get_id() . ': no new Creem checkout, because ' . $id . ' is not known to be unpaid (' . $reason . ').', 'warning');
        $source = $this->account($session['gateway']);
        if ($source) {
            self::adopt($order, $source); // So that store's webhook can still settle it.
        }
        if ((string) $order->get_meta(Gateway::META_UNVERIFIED) !== $id) {
            $order->update_meta_data(Gateway::META_UNVERIFIED, $id);
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: Creem checkout id, 2: reason */
                __('Stackless didn’t start another Creem payment, because it couldn’t confirm that checkout %1$s is unpaid: %2$s. If this persists, check the checkout in Creem. If it wasn’t paid, choose “Allow a new Creem payment” under Order actions.', 'stackless-lite'),
                $id,
                $reason
            ));
        }
        return self::tryAgain();
    }

    private function account(string $id): ?Gateway
    {
        if ($id === $this->gateway->id) {
            return $this->gateway;
        }
        return Copies::belongs($id) ? Gateway::instance($id) : null;
    }

    /** The order belongs to the store that holds its payment. */
    private static function adopt(\WC_Order $order, Gateway $store): void
    {
        if ($order->get_payment_method() !== $store->id) {
            $order->set_payment_method($store->id);
            $order->set_payment_method_title($store->get_title());
            $order->save();
        }
    }

    /* ---- The merchant's way out ------------------------------------------------------- */

    public static function register(): void
    {
        add_filter('woocommerce_order_actions', [self::class, 'orderActions'], 10, 2);
        add_action('woocommerce_order_action_' . self::ACTION, [self::class, 'allow']);
    }

    /**
     * @param array $actions
     * @param mixed $order
     */
    public static function orderActions($actions, $order = null): array
    {
        $actions = is_array($actions) ? $actions : [];
        if ($order instanceof \WC_Order && !$order->is_paid()) {
            $blocked = (string) $order->get_meta(Gateway::META_UNVERIFIED);
            $released = $order->get_meta(Gateway::META_RELEASED);
            if ($blocked !== '' && !(is_array($released) && in_array($blocked, $released, true))) {
                $actions[self::ACTION] = __('Allow a new Creem payment (checked unpaid in Creem)', 'stackless-lite');
            }
        }
        return $actions;
    }

    /** Runs from the order screen, which checks the nonce and capability. @param mixed $order */
    public static function allow($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $order = FreshOrder::get($order->get_id());
        $id = $order instanceof \WC_Order ? (string) $order->get_meta(Gateway::META_UNVERIFIED) : '';
        if (!$order instanceof \WC_Order || $id === '') {
            return;
        }
        // One at a time: each checkout the merchant looked up is allowed by its own id.
        $released = $order->get_meta(Gateway::META_RELEASED);
        $released = is_array($released) ? $released : [];
        $released[] = $id;
        $order->update_meta_data(Gateway::META_RELEASED, array_values(array_unique($released)));
        $order->save();
        /* translators: %s: Creem checkout id */
        $order->add_order_note(sprintf(__('A new Creem payment may now start: the merchant confirmed checkout %s is unpaid.', 'stackless-lite'), $id), 0, true);
    }

    /* ---- Payments the order can't take ------------------------------------------------- */

    public static function hasExtra(\WC_Order $order): bool
    {
        return (string) $order->get_meta(self::META_EXTRA) !== '';
    }

    /** Whether an extra payment started this Creem subscription. */
    public static function extraSubscription(\WC_Order $order, string $id): bool
    {
        return $id !== '' && (string) $order->get_meta(self::META_EXTRA_SUBSCRIPTION . $id) !== '';
    }

    /**
     * Note, once, a payment the order wasn't waiting for, and hold an order still awaiting
     * payment so it isn't charged again. Call with the order lock held.
     */
    public static function flagExtra(\WC_Order $order, string $reference, int $minor, string $currency, string $mode, Gateway $gateway, string $subscription = ''): string
    {
        $flag = '_stackless_creem_extra_' . $reference;
        $named = $subscription !== '' && !self::extraSubscription($order, $subscription);
        $seen = (bool) $order->get_meta($flag);
        if ($seen && !$named) {
            return 'extra payment';
        }
        $order->update_meta_data($flag, 'yes');
        $order->update_meta_data(self::META_EXTRA, 'yes');
        if ($named) {
            $order->update_meta_data(self::META_EXTRA_SUBSCRIPTION . $subscription, 'yes');
        }
        $order->save();

        $label = $mode === 'test' ? __('test', 'stackless-lite') : __('live', 'stackless-lite');
        $note = $minor > 0
            ? sprintf(
                /* translators: 1: amount, 2: Creem payment id, 3: test or live */
                __('Creem took %1$s in %3$s payment %2$s, which this order was not waiting for. If the customer paid twice or the order won’t be fulfilled, refund it in Creem.', 'stackless-lite'),
                wp_strip_all_tags(wc_price(Money::fromMinor($minor), ['currency' => $currency !== '' ? strtoupper($currency) : $order->get_currency()])),
                $reference,
                $label
            )
            : sprintf(
                /* translators: 1: Creem payment or subscription id, 2: test or live */
                __('Creem reported %2$s payment or subscription %1$s, which this order was not waiting for. Check it in Creem and refund anything it took.', 'stackless-lite'),
                $reference,
                $label
            );
        if ($subscription !== '') {
            /* translators: %s: Creem subscription id */
            $note .= ' ' . sprintf(__('Also cancel Creem subscription %s there, or it keeps billing.', 'stackless-lite'), $subscription);
        }
        if ($seen) {
            $order->add_order_note($note); // Again, now naming the subscription to cancel.
            return 'extra payment';
        }
        $testOnLive = $mode === 'test' && $gateway->orderMode($order) === 'live';
        if ($minor > 0 && !$testOnLive && $order->needs_payment()) {
            $order->update_status('on-hold', $note);
        } else {
            $order->add_order_note($note);
        }
        $gateway->log('Order ' . $order->get_id() . ': payment ' . $reference . ' is not the one it was waiting for.', 'warning');
        return 'extra payment';
    }
}
