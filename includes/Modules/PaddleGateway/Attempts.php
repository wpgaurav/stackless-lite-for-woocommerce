<?php

namespace Stackless\Modules\PaddleGateway;

use Stackless\Core\FreshOrder;

defined('ABSPATH') || exit;

/**
 * An order's earlier Paddle payment.
 *
 * A new transaction is created only when the earlier one is known to be unpaid (and then
 * canceled, so it can't be paid later) or known not to exist: Paddle's own not_found from the
 * account that created it. Any other answer stops the checkout with "try again in a moment".
 *
 * So that no order is stranded: putting the key back resolves it, a sandbox transaction whose
 * key is gone doesn't block a live payment, and once a check has failed the merchant gets an
 * order action to allow a new payment after looking in Paddle.
 */
final class Attempts
{
    public const ACTION = 'stackless_paddle_new_payment';

    private const STATUSES = ['draft', 'ready', 'billed', 'past_due', 'paid', 'completed', 'canceled'];

    /** Order meta: it has an extra payment, and (prefix) the subscriptions those started. */
    private const META_EXTRA = '_stackless_paddle_has_extra';
    private const META_EXTRA_SUBSCRIPTION = '_stackless_paddle_extra_subscription_';

    /** Payment attempts that may have taken, or may still take, the money. */
    private const IN_FLIGHT = ['captured', 'authorized', 'authorized_flagged', 'pending_no_action_required', 'unknown'];

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function tryAgain(): \WP_Error
    {
        return new \WP_Error('stackless_paddle_unverified', __('We couldn’t check the earlier Paddle payment for this order, so you weren’t charged again. Please try again in a moment, or contact us if this keeps happening.', 'stackless-lite'));
    }

    /** Where the transaction stored on the order was created: gateway id and a fingerprint of its key. */
    public static function remember(\WC_Order $order, Gateway $gateway, string $mode): void
    {
        $order->update_meta_data(Gateway::META_SOURCE, $gateway->id . '|' . self::keyPrint($gateway->apiKey($mode)));
        $order->delete_meta_data(Gateway::META_UNVERIFIED);
        $order->delete_meta_data(Gateway::META_RELEASED);
    }

    /** @return array{0:string,1:string} gateway id and key fingerprint ('' on orders without a record) */
    public static function source(\WC_Order $order): array
    {
        $parts = explode('|', (string) $order->get_meta(Gateway::META_SOURCE));
        return [$parts[0], $parts[1] ?? ''];
    }

    private static function keyPrint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    /**
     * What became of the order's stored transaction.
     *
     * @return array{url?:string,open?:array,gateway?:Gateway}|\WP_Error "url" when it is paid,
     *         "open" when it is still payable, [] when a new one may be created.
     */
    public function earlier(\WC_Order $order)
    {
        $id = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if ($id === '') {
            return [];
        }
        $mode = $this->gateway->orderMode($order);
        [$sourceId, $keyPrint] = self::source($order);
        $source = $sourceId === '' ? $this->gateway : $this->account($sourceId);
        $sourceKey = $source ? $source->apiKey($mode) : '';

        $accounts = $source && $sourceKey !== '' ? [$source] : [];
        if ($sourceId === '') {
            // No record of the account: any copy with a key for this mode may have created it.
            foreach (Copies::ids() as $copyId) {
                $copy = $this->account($copyId);
                if ($copy && $copy->id !== $this->gateway->id && $copy->apiKey($mode) !== '') {
                    $accounts[] = $copy;
                }
            }
        }
        foreach ($accounts as $account) {
            $transaction = $account->api($mode)->request('GET', 'transactions/' . rawurlencode($id));
            if (!is_wp_error($transaction)) {
                return $this->classify($order, $id, $transaction, $account);
            }
            if (!Api::isNotFound($transaction)) {
                return $this->unknown($order, $id, $transaction->get_error_message());
            }
        }

        if ($sourceKey === '') {
            if ($mode === 'sandbox' && $this->gateway->mode() === 'live') {
                $this->gateway->log('Order ' . $order->get_id() . ': sandbox transaction ' . $id . ' has no key left to check it with; it cannot be a live payment.', 'warning');
                return [];
            }
            return $this->unknown($order, $id, __('the API key it was created with is no longer saved', 'stackless-lite'));
        }
        if ($keyPrint === '') {
            // Started before accounts were recorded: not found here may be paid in the account that created it.
            return $this->unknown($order, $id, __('there is no record of the API key it was created with', 'stackless-lite'));
        }
        if (!hash_equals($keyPrint, self::keyPrint($sourceKey))) {
            return $this->unknown($order, $id, __('the API key changed and the new one cannot see it', 'stackless-lite'));
        }
        return [];
    }

    /** @return array{url?:string,open?:array,gateway?:Gateway}|\WP_Error */
    private function classify(\WC_Order $order, string $id, array $transaction, Gateway $account)
    {
        $status = (string) ($transaction['status'] ?? '');
        if ((string) ($transaction['id'] ?? '') !== $id || !in_array($status, self::STATUSES, true)) {
            return $this->unknown($order, $id, __('Paddle’s answer could not be read', 'stackless-lite'));
        }
        $custom = (array) ($transaction['custom_data'] ?? []);
        $owner = (string) ($custom['wc_order_id'] ?? '');
        if ($owner !== '' && $owner !== (string) $order->get_id()) {
            return []; // Meta copied from another order: that order's payment, not this one's.
        }
        if (EventMap::isPaid($transaction)) {
            if (EventMap::fullyRefunded($transaction)) {
                return [];
            }
            self::adopt($order, $account);
            return ['url' => Checkout::returnUrl($order)];
        }
        if ($status === 'canceled') {
            return [];
        }
        return ['open' => $transaction, 'gateway' => $account];
    }

    /**
     * Cancel an open transaction before another replaces it, so only one can ever be paid.
     *
     * @return array|\WP_Error An empty array when a new one may be created.
     */
    public function close(\WC_Order $order, array $transaction, Gateway $account)
    {
        $id = (string) $transaction['id'];
        $attempts = array_column(array_filter((array) ($transaction['payments'] ?? []), 'is_array'), 'status');
        if ($transaction['status'] === 'past_due' || array_intersect($attempts, self::IN_FLIGHT)) {
            return $this->unknown($order, $id, __('a payment on it is still being processed', 'stackless-lite'));
        }
        if (Sync::otherSite((array) ($transaction['custom_data'] ?? []))) {
            // A copy of the store or a changed domain: not this site's to cancel, so not to replace either.
            return $this->unknown($order, $id, __('it was created on another site address', 'stackless-lite'));
        }
        $canceled = $account->api($this->gateway->orderMode($order))->request('PATCH', 'transactions/' . rawurlencode($id), ['status' => 'canceled']);
        if (is_wp_error($canceled)) {
            return $this->unknown($order, $id, $canceled->get_error_message());
        }
        if (($canceled['status'] ?? '') !== 'canceled') {
            return $this->unknown($order, $id, __('Paddle did not confirm it was canceled', 'stackless-lite'));
        }
        return [];
    }

    /** @return array|\WP_Error An empty array when the merchant allowed a new payment, else "try again". */
    private function unknown(\WC_Order $order, string $id, string $reason)
    {
        if ((string) $order->get_meta(Gateway::META_RELEASED) === $id) {
            $this->gateway->log('Order ' . $order->get_id() . ': starting a new payment although ' . $id . ' could not be checked, as the merchant allowed.', 'warning');
            return [];
        }
        $this->gateway->log('Order ' . $order->get_id() . ': no new Paddle transaction, because ' . $id . ' is not known to be unpaid (' . $reason . ').', 'warning');
        $source = $this->account(self::source($order)[0]);
        if ($source) {
            self::adopt($order, $source); // So that account's webhook can still settle it.
        }
        if ((string) $order->get_meta(Gateway::META_UNVERIFIED) !== $id) {
            $order->update_meta_data(Gateway::META_UNVERIFIED, $id);
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: Paddle transaction id, 2: reason */
                __('Stackless didn’t start another Paddle payment, because it couldn’t confirm that transaction %1$s is unpaid: %2$s. If this persists, check the transaction in Paddle. If it wasn’t paid, choose “Allow a new Paddle payment” under Order actions.', 'stackless-lite'),
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

    /** The order belongs to the account that holds its payment. */
    private static function adopt(\WC_Order $order, Gateway $account): void
    {
        if ($order->get_payment_method() !== $account->id) {
            $order->set_payment_method($account->id);
            $order->set_payment_method_title($account->get_title());
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
            if ($blocked !== '' && $blocked !== (string) $order->get_meta(Gateway::META_RELEASED)) {
                $actions[self::ACTION] = __('Allow a new Paddle payment (checked unpaid in Paddle)', 'stackless-lite');
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
        $order->update_meta_data(Gateway::META_RELEASED, $id);
        $order->save();
        /* translators: %s: Paddle transaction id */
        $order->add_order_note(sprintf(__('A new Paddle payment may now start: the merchant confirmed transaction %s is unpaid.', 'stackless-lite'), $id), 0, true);
    }

    /* ---- Payments the order can't take ------------------------------------------------- */

    public static function hasExtra(\WC_Order $order): bool
    {
        return (string) $order->get_meta(self::META_EXTRA) !== '';
    }

    /** Whether an extra payment started this Paddle subscription. */
    public static function extraSubscription(\WC_Order $order, string $id): bool
    {
        return $id !== '' && (string) $order->get_meta(self::META_EXTRA_SUBSCRIPTION . $id) !== '';
    }

    /**
     * Note, once, a paid transaction the order wasn't waiting for, and hold an order still
     * awaiting payment so it isn't charged again. Call with the order lock held.
     */
    public static function flagExtra(\WC_Order $order, array $transaction, string $mode, Gateway $gateway): string
    {
        $id = (string) ($transaction['id'] ?? '');
        $flag = '_stackless_paddle_extra_' . $id;
        $subscription = (string) ($transaction['subscription_id'] ?? '');
        // transaction.paid has no subscription id: transaction.completed names it later.
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

        $minor = (int) ($transaction['details']['totals']['grand_total'] ?? 0);
        $currency = strtoupper((string) ($transaction['currency_code'] ?? $order->get_currency()));
        $note = sprintf(
            /* translators: 1: amount, 2: Paddle transaction id, 3: sandbox or live */
            __('Paddle took %1$s in %3$s transaction %2$s, which this order was not waiting for. If the customer paid twice or the order won’t be fulfilled, refund it in Paddle.', 'stackless-lite'),
            wp_strip_all_tags(wc_price(Money::fromMinor($minor, $currency), ['currency' => $currency])),
            $id,
            $mode === 'sandbox' ? __('sandbox', 'stackless-lite') : __('live', 'stackless-lite')
        );
        if ($subscription !== '') {
            /* translators: %s: Paddle subscription id */
            $note .= ' ' . sprintf(__('Also cancel Paddle subscription %s there, or it keeps billing.', 'stackless-lite'), $subscription);
        }
        if ($seen) {
            $order->add_order_note($note); // Again, now naming the subscription to cancel.
            return 'extra payment';
        }
        $testOnLive = $mode === 'sandbox' && $gateway->orderMode($order) === 'live';
        if ($minor > 0 && !$testOnLive && $order->needs_payment()) {
            $order->update_status('on-hold', $note);
        } else {
            $order->add_order_note($note);
        }
        $gateway->log('Order ' . $order->get_id() . ': paid transaction ' . $id . ' is not the one it was waiting for.', 'warning');
        return 'extra payment';
    }
}
