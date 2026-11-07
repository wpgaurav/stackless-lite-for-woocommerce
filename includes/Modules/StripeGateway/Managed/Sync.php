<?php

namespace Stackless\Modules\StripeGateway\Managed;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\FreshOrder;
use Stackless\Core\OrderQuery;
use Stackless\Modules\StripeGateway\Amount;
use Stackless\Modules\StripeGateway\Declines;
use Stackless\Modules\StripeGateway\ErrorMapper;
use Stackless\Modules\StripeGateway\Payments;
use Stackless\Modules\StripeGateway\Settings;
use Stackless\Modules\StripeGateway\StripeApiException;

defined('ABSPATH') || exit;

/**
 * Applies Managed Payments checkouts, renewals and subscription state to WooCommerce.
 *
 * Every entry point (browser return, webhooks) funnels through these methods and each is
 * safe to repeat: orders are settled under the Stripe module's order lock and only while
 * unpaid, renewals are keyed by Stripe invoice id, and objects are always read back from
 * Stripe before anything changes.
 */
class Sync
{
    /** Every subscription status: wc_get_orders() leaves wc-sub-* out of its default list. */
    private const SUBSCRIPTION_STATUSES = [
        'wc-' . SubscriptionData::STATUS_PENDING,
        'wc-' . SubscriptionData::STATUS_TRIAL,
        'wc-' . SubscriptionData::STATUS_ACTIVE,
        'wc-' . SubscriptionData::STATUS_ON_HOLD,
        'wc-' . SubscriptionData::STATUS_PENDING_CANCEL,
        'wc-' . SubscriptionData::STATUS_CANCELLED,
        'wc-' . SubscriptionData::STATUS_EXPIRED,
        'wc-' . SubscriptionData::STATUS_PAUSED,
    ];

    /** Thrown code that makes the webhook answer 409 so Stripe retries later. */
    public const DEFERRED = 409;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /* ---- First payments ---------------------------------------------------------- */

    /**
     * Reads a session back from Stripe and settles the order when it is paid (browser return).
     *
     * @return true|\WP_Error
     */
    public function confirmById(\WC_Order $order, string $sessionId)
    {
        $mode = (string) $order->get_meta(Payments::META_MODE);
        $mode = $mode === 'live' ? 'live' : 'test';
        try {
            $session = Gateway::client($mode)->get('checkout/sessions/' . rawurlencode($sessionId));
        } catch (StripeApiException $e) {
            return new \WP_Error('stackless_stripe_managed_lookup', $e->getMessage());
        }
        if (($session['status'] ?? '') === 'open') {
            return new \WP_Error('stackless_stripe_managed_open', __('The Stripe checkout is still open.', 'stackless-lite'));
        }
        $result = $this->confirmSession($order, $session, $mode);
        if ($result === 'busy') {
            return new \WP_Error('stackless_stripe_managed_busy', __('The order is being updated by another request.', 'stackless-lite'));
        }
        return true;
    }

    /**
     * Settles an order from its completed Checkout Session.
     *
     * @return string Outcome for logs: paid, processing, failed, expired, mismatch, busy…
     */
    public function confirmSession(\WC_Order $order, array $session, string $mode): string
    {
        $metadata = (array) ($session['metadata'] ?? []);
        if ((string) ($metadata['order_id'] ?? '') !== (string) $order->get_id() || !self::isOurs($metadata)) {
            return 'not ours';
        }
        $sessionId = (string) ($session['id'] ?? '');
        $lock = Payments::lock($order->get_id());
        if ($lock === null) {
            return 'busy';
        }
        try {
            $order = FreshOrder::get($order->get_id()) ?: $order;
            if ($order->get_payment_method() !== $this->gateway->id || (string) $order->get_meta(Gateway::META_SESSION) !== $sessionId) {
                return 'session mismatch';
            }
            $status = (string) ($session['status'] ?? '');
            $paymentStatus = (string) ($session['payment_status'] ?? '');
            if ($order->is_paid()) {
                $this->linkFromSession($order, $session, $mode);
                return 'already paid';
            }
            if ($status === 'expired') {
                if ($order->needs_payment()) {
                    /* translators: %s: Checkout Session id */
                    $order->add_order_note(sprintf(__('Stripe checkout %s expired before the customer paid.', 'stackless-lite'), $sessionId));
                }
                return 'expired';
            }
            if ($status !== 'complete') {
                return 'open';
            }

            $currency = strtoupper((string) ($session['currency'] ?? ''));
            if ($currency !== '' && $currency !== strtoupper($order->get_currency())) {
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: order currency, 2: charged currency */
                    __('Stripe charged in %2$s but the order is in %1$s. Check the payment in Stripe.', 'stackless-lite'),
                    strtoupper($order->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = strtoupper($order->get_currency());
            $order->update_meta_data(Payments::META_MODE, $mode);
            $order->update_meta_data(Gateway::META_TAX, wc_format_decimal(Amount::fromMinor((int) ($session['total_details']['amount_tax'] ?? 0), $currency)));
            $order->update_meta_data(Gateway::META_TOTAL, wc_format_decimal(Amount::fromMinor((int) ($session['amount_total'] ?? 0), $currency)));
            $this->linkFromSession($order, $session, $mode);

            if ((int) ($session['amount_subtotal'] ?? -1) !== Amount::toMinor($order->get_total(), $currency)) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount before tax Stripe charged, 2: order total */
                    __('Stripe charged %1$s before tax but the order total is %2$s. Check the payment in Stripe before fulfilling.', 'stackless-lite'),
                    Amount::fromMinor((int) ($session['amount_subtotal'] ?? 0), $currency) . ' ' . $currency,
                    $order->get_total() . ' ' . $currency
                ));
                return 'amount mismatch';
            }

            if ($paymentStatus === 'unpaid') {
                $order->save();
                if (!$order->has_status('on-hold')) {
                    wc_maybe_reduce_stock_levels($order->get_id());
                    $order->update_status('on-hold', __('Stripe is processing the payment. The order updates when the bank confirms it.', 'stackless-lite'));
                }
                return 'processing';
            }
            if (!in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
                $order->save();
                return 'unknown payment status';
            }

            $intent = $this->sessionPaymentIntent($session, $mode);
            if ($intent !== '') {
                self::rememberIntent($order, $intent);
            }
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: Checkout Session id, 2: total charged including tax */
                __('Stripe Managed Payments checkout %1$s paid (%2$s charged including tax).', 'stackless-lite'),
                $sessionId,
                wp_strip_all_tags(wc_price((float) Amount::fromMinor((int) ($session['amount_total'] ?? 0), $currency), ['currency' => $currency]))
            ));
            $order->payment_complete($intent !== '' ? $intent : $sessionId);
            return 'paid';
        } finally {
            Payments::unlock($order->get_id(), $lock);
        }
    }

    /** Async payment (bank debit) failed after the session completed. */
    public function sessionFailed(\WC_Order $order, array $session): string
    {
        if ($order->is_paid() || (string) $order->get_meta(Gateway::META_SESSION) !== (string) ($session['id'] ?? '')) {
            return 'ignored';
        }
        $order->update_status('failed', __('The customer’s bank declined the Stripe Managed Payments payment.', 'stackless-lite'));
        return 'failed';
    }

    /** Write the Stripe subscription and customer on the order and its subscription. */
    private function linkFromSession(\WC_Order $order, array $session, string $mode): void
    {
        $customer = EventMap::idOf($session['customer'] ?? '');
        $remote = EventMap::idOf($session['subscription'] ?? '');
        $invoice = EventMap::idOf($session['invoice'] ?? '');
        if ($invoice !== '') {
            $order->update_meta_data(Gateway::META_INVOICE, $invoice);
        }
        if ($customer !== '') {
            $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        if ($remote === '') {
            return;
        }
        $order->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote);
        foreach (stackless_get_order_subscriptions($order) as $subscription) {
            if ($subscription instanceof \WC_Order && $subscription->get_id() !== $order->get_id()) {
                $subscription->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote);
                if ($customer !== '') {
                    $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
                }
                $subscription->update_meta_data(Payments::META_MODE, $mode);
                $subscription->save();
            }
        }
    }

    /** PaymentIntent that paid a session: its own (payment mode) or its first invoice's (subscription mode). */
    private function sessionPaymentIntent(array $session, string $mode): string
    {
        $intent = EventMap::idOf($session['payment_intent'] ?? '');
        if ($intent !== '') {
            return $intent;
        }
        $invoice = EventMap::idOf($session['invoice'] ?? '');
        if ($invoice === '') {
            return '';
        }
        try {
            return EventMap::invoicePaymentIntent(Gateway::client($mode)->get('invoices/' . rawurlencode($invoice), ['expand' => ['payments']]));
        } catch (StripeApiException $e) {
            Settings::log('Could not read invoice ' . $invoice . ': ' . $e->logSummary(), 'warning');
            return '';
        }
    }

    private static function rememberIntent(\WC_Order $order, string $intent): void
    {
        $order->update_meta_data(Payments::META_INTENT, $intent);
        $history = $order->get_meta(Payments::META_INTENTS);
        $history = is_array($history) ? $history : [];
        if (!in_array($intent, $history, true)) {
            $history[] = $intent;
            $order->update_meta_data(Payments::META_INTENTS, array_slice($history, -20));
        }
    }

    /** Objects created by another site on the same Stripe account carry a different marker. */
    public static function isOurs(array $metadata): bool
    {
        $marker = (string) ($metadata['stackless_site'] ?? '');
        return $marker === '' || $marker === Settings::siteMarker();
    }

    /* ---- Lookups ------------------------------------------------------------------ */

    /** The WooCommerce subscription linked to a Stripe subscription (by stored id, else metadata). */
    public function findSubscription(string $remoteId, array $metadata): ?\WC_Order
    {
        if ($remoteId !== '') {
            $found = OrderQuery::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remoteId, ['type' => 'stackless_sub', 'status' => self::SUBSCRIPTION_STATUSES]);
            if ($found instanceof \WC_Order) {
                $subscription = stackless_get_subscription($found->get_id());
                return $subscription instanceof \WC_Order ? $subscription : $found;
            }
        }
        if (!self::isOurs($metadata) || empty($metadata['stackless_managed'])) {
            return null;
        }
        $id = absint($metadata['subscription_id'] ?? 0);
        $subscription = $id ? stackless_get_subscription($id) : null;
        if (!$subscription instanceof \WC_Order) {
            return null;
        }
        $parent = absint($metadata['order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_id() !== $parent) || ($linked !== '' && $remoteId !== '' && $linked !== $remoteId)) {
            return null;
        }
        return $subscription;
    }

    /* ---- Invoices ------------------------------------------------------------------ */

    /** invoice.paid: the first invoice settles the order; cycle invoices become renewal orders. */
    public function invoicePaid(array $invoice, string $mode): string
    {
        $kind = EventMap::invoiceKind($invoice);
        if ($kind === 'first') {
            $metadata = EventMap::invoiceSubscriptionMetadata($invoice);
            if (empty($metadata['stackless_managed']) || !self::isOurs($metadata)) {
                return 'not ours';
            }
            $order = wc_get_order(absint($metadata['order_id'] ?? 0));
            if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->gateway->id) {
                return 'no matching order';
            }
            if ($order->is_paid()) {
                return 'already paid';
            }
            $session = (string) $order->get_meta(Gateway::META_SESSION);
            $result = $session !== '' ? $this->confirmById($order, $session) : new \WP_Error('none', '');
            if (is_wp_error($result) && $result->get_error_code() === 'stackless_stripe_managed_busy') {
                throw new \RuntimeException('Order is locked by another request; retry later.', self::DEFERRED);
            }
            return 'first invoice: ' . (is_wp_error($result) ? $result->get_error_code() : 'confirmed');
        }
        if ($kind !== 'renewal') {
            return 'ignored ' . (string) ($invoice['billing_reason'] ?? '');
        }
        return $this->recordRenewal($invoice, $mode);
    }

    /** A renewal Stripe charged becomes a paid WooCommerce renewal order. */
    public function recordRenewal(array $invoice, string $mode): string
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $remoteId = EventMap::invoiceSubscription($invoice);
        if ($invoiceId === '' || $remoteId === '') {
            return 'no subscription';
        }
        $subscription = $this->findSubscription($remoteId, EventMap::invoiceSubscriptionMetadata($invoice));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }
        $lock = Payments::lock($subscription->get_id());
        if ($lock === null) {
            throw new \RuntimeException('Subscription is locked by another request; retry later.', self::DEFERRED);
        }
        try {
            if (OrderQuery::findByMeta(Gateway::META_INVOICE, $invoiceId)) {
                return 'duplicate';
            }
            $renewal = null;
            $awaiting = absint($subscription->get_meta(Gateway::META_AWAITING));
            if ($awaiting) {
                $candidate = wc_get_order($awaiting);
                if ($candidate instanceof \WC_Order && !$candidate->is_paid() && $candidate->get_meta(Gateway::META_AWAITING) === 'yes') {
                    $renewal = $candidate;
                }
            }
            if (!$renewal) {
                if (!$subscription instanceof SubscriptionData) {
                    // Thrown so Stripe retries once the Subscriptions module is active again.
                    throw new \RuntimeException('Subscriptions module is not active; cannot record the renewal.');
                }
                $created = stackless_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = $created;
            }
            $currency = strtoupper((string) ($invoice['currency'] ?? $renewal->get_currency()));
            $intent = EventMap::invoicePaymentIntent($invoice);
            $renewal->set_payment_method($this->gateway->id);
            $renewal->set_payment_method_title($this->gateway->get_title());
            $renewal->delete_meta_data(Gateway::META_AWAITING);
            $renewal->update_meta_data(Gateway::META_INVOICE, $invoiceId);
            $renewal->update_meta_data(Payments::META_MODE, $mode);
            $renewal->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remoteId);
            if ($intent !== '') {
                self::rememberIntent($renewal, $intent);
            }
            $renewal->update_meta_data(Gateway::META_TOTAL, wc_format_decimal(Amount::fromMinor((int) ($invoice['amount_paid'] ?? $invoice['total'] ?? 0), $currency)));
            $renewal->update_meta_data(Gateway::META_TAX, wc_format_decimal(Amount::fromMinor(self::invoiceTax($invoice), $currency)));
            $renewal->save();

            $subscription->delete_meta_data(Gateway::META_AWAITING);
            $subscription->save();

            if ($currency !== strtoupper($renewal->get_currency())) {
                $renewal->update_status('on-hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Stripe charged this renewal in %2$s but the subscription bills in %1$s. Check it in Stripe.', 'stackless-lite'),
                    strtoupper($renewal->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            $subtotal = (int) ($invoice['subtotal'] ?? -1);
            if ($subtotal !== Amount::toMinor($renewal->get_total(), $currency)) {
                // Stripe already took the money; record it and flag the difference.
                $renewal->add_order_note(sprintf(
                    /* translators: 1: amount before tax Stripe charged, 2: renewal total */
                    __('Stripe charged %1$s before tax for this renewal; the WooCommerce renewal total is %2$s.', 'stackless-lite'),
                    Amount::fromMinor(max(0, $subtotal), $currency) . ' ' . $currency,
                    $renewal->get_total() . ' ' . $currency
                ));
            }
            /* translators: %s: Stripe invoice id */
            $renewal->add_order_note(sprintf(__('Stripe charged this renewal (invoice %s).', 'stackless-lite'), $invoiceId));
            $renewal->payment_complete($intent !== '' ? $intent : $invoiceId);
            return 'renewed';
        } finally {
            Payments::unlock($subscription->get_id(), $lock);
        }
    }

    /**
     * invoice.payment_failed: Stripe retries the invoice itself (Smart Retries) and reports
     * past_due through the subscription, so the renewal order is not failed here. The decline
     * category is still recorded on it, for Billing Health and for Dunning if it runs.
     */
    public function invoiceFailed(array $invoice, string $mode): string
    {
        $kind = EventMap::invoiceKind($invoice);
        $remoteId = EventMap::invoiceSubscription($invoice);
        $subscription = $remoteId !== '' ? $this->findSubscription($remoteId, EventMap::invoiceSubscriptionMetadata($invoice)) : null;
        $target = null;
        if ($kind === 'renewal' && $subscription) {
            $awaiting = absint($subscription->get_meta(Gateway::META_AWAITING));
            $target = $awaiting ? wc_get_order($awaiting) : $subscription;
        } elseif ($kind === 'first') {
            $metadata = EventMap::invoiceSubscriptionMetadata($invoice);
            $order = self::isOurs($metadata) ? wc_get_order(absint($metadata['order_id'] ?? 0)) : false;
            $target = $order instanceof \WC_Order && $order->get_payment_method() === $this->gateway->id ? $order : null;
        }
        if (!$target instanceof \WC_Order) {
            return 'not ours';
        }
        [$category, $code] = $this->invoiceDecline($invoice, $mode);
        if ($target->get_type() !== 'stackless_sub' && !$target->is_paid()) {
            Declines::write($target, $category, $code);
            $target->save();
        }
        $target->add_order_note(sprintf(
            /* translators: 1: Stripe invoice id, 2: decline code */
            __('Stripe could not collect invoice %1$s (%2$s). Stripe retries it on its own schedule.', 'stackless-lite'),
            (string) ($invoice['id'] ?? ''),
            $code
        ));
        return 'noted ' . $category;
    }

    /** @return array{0:string,1:string} Category and code of an invoice's failed payment. */
    private function invoiceDecline(array $invoice, string $mode): array
    {
        $intent = EventMap::invoicePaymentIntent($invoice);
        if ($intent !== '') {
            try {
                $pi = Gateway::client($mode)->get('payment_intents/' . rawurlencode($intent));
                $error = isset($pi['last_payment_error']) && is_array($pi['last_payment_error']) ? $pi['last_payment_error'] : [];
                if ($error) {
                    $code = (string) ($error['decline_code'] ?? '') ?: (string) ($error['code'] ?? '');
                    return [ErrorMapper::declineCategoryFromIntentError($error), $code !== '' ? $code : 'declined'];
                }
            } catch (StripeApiException $e) {
                Settings::log('Could not read ' . $intent . ': ' . $e->logSummary(), 'warning');
            }
        }
        $code = EventMap::invoiceDeclineCode($invoice);
        return [ErrorMapper::declineCategory($code), $code !== '' ? $code : 'declined'];
    }

    private static function invoiceTax(array $invoice): int
    {
        if (isset($invoice['tax'])) {
            return (int) $invoice['tax'];
        }
        $tax = 0;
        foreach ((array) ($invoice['total_taxes'] ?? []) as $row) {
            $tax += is_array($row) ? (int) ($row['amount'] ?? 0) : 0;
        }
        return $tax;
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a WooCommerce subscription in line with a Stripe subscription. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $remoteId = (string) ($remote['id'] ?? '');
        if ($remoteId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($remoteId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        $subscription->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remoteId);
        $customer = EventMap::idOf($remote['customer'] ?? '');
        if ($customer !== '') {
            $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $subscription->update_meta_data(Payments::META_MODE, $mode);
        $subscription->update_meta_data(Gateway::META_CANCEL_AT_END, EventMap::cancelScheduled($remote) ? 'yes' : 'no');
        $end = EventMap::periodEnd($remote);
        if ($end > 0) {
            $subscription->update_meta_data(Gateway::META_PERIOD_END, (string) $end);
        }
        $next = EventMap::nextCharge($remote);
        // Stripe runs the schedule; let the Subscriptions module show Stripe's next charge date.
        if ($next > 0 && method_exists($subscription, 'set_next_payment_date')) {
            $subscription->set_next_payment_date($next);
        }
        $subscription->save();

        $target = EventMap::status($remote);
        if ($target === SubscriptionData::STATUS_PAUSED && !self::statusRegistered(SubscriptionData::STATUS_PAUSED)) {
            $target = SubscriptionData::STATUS_ON_HOLD;
        }
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];
        $parent = $subscription->get_parent_id() ? wc_get_order($subscription->get_parent_id()) : false;
        if ($target !== null && in_array($target, $active, true) && $parent instanceof \WC_Order && !$parent->is_paid()) {
            return 'awaiting first payment';
        }
        if ($target === null) {
            return 'no status change';
        }
        $current = (string) $subscription->get_status();
        if (EventMap::isTerminal($current)) {
            return 'terminal';
        }
        if ($current === $target) {
            return 'unchanged';
        }
        /* translators: %s: Stripe subscription status */
        $note = sprintf(__('Stripe subscription is now %s.', 'stackless-lite'), (string) ($remote['status'] ?? $event));
        if ($target === SubscriptionData::STATUS_PENDING_CANCEL) {
            $note = __('Stripe subscription is set to end at the close of the paid period.', 'stackless-lite');
        } elseif ($target === SubscriptionData::STATUS_PAUSED) {
            $note = __('Stripe paused collection for this subscription.', 'stackless-lite');
        }
        $subscription->update_status($target, $note);
        do_action('stackless_stripe_managed_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }

    private static function statusRegistered(string $slug): bool
    {
        return function_exists('wc_get_order_statuses') && array_key_exists('wc-' . $slug, wc_get_order_statuses());
    }
}
