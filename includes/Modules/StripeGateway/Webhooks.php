<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/stripe/webhook
 *
 * permission_callback verifies Stripe-Signature against the test and live signing secrets
 * (HMAC-SHA256, 5-minute tolerance). The handler claims the event id first (replay guard),
 * then re-reads the object from Stripe with the pinned API version before acting on it, so
 * webhook payload versions and out-of-order delivery never matter. Only finished events are
 * acknowledged as duplicates; an event another request is processing (409) or one the event
 * log could not record (503) gets a retryable error so Stripe delivers it again.
 */
final class Webhooks
{
    public const REST_NAMESPACE = 'stackless/v1';
    public const ROUTE = '/stripe/webhook';
    public const OPTION_LAST = 'stackless_stripe_gateway_last_webhook';

    private const DEFERRED = 409;

    public static function register(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /**
     * @return true|\WP_Error
     */
    public static function authorize(\WP_REST_Request $request)
    {
        if (self::verifiedMode($request) === null) {
            Settings::log('Rejected a webhook with an invalid or missing Stripe-Signature.', 'warning');
            return new \WP_Error('stackless_stripe_invalid_signature', __('Invalid Stripe signature.', 'stackless-lite'), ['status' => 400]);
        }
        return true;
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handle(\WP_REST_Request $request)
    {
        $mode = self::verifiedMode($request);
        if ($mode === null) {
            return new \WP_Error('stackless_stripe_invalid_signature', __('Invalid Stripe signature.', 'stackless-lite'), ['status' => 400]);
        }
        $event = json_decode($request->get_body(), true);
        if (!is_array($event) || empty($event['id']) || empty($event['type']) || !isset($event['data']['object']) || !is_array($event['data']['object'])) {
            return new \WP_Error('stackless_stripe_invalid_event', __('Malformed event.', 'stackless-lite'), ['status' => 400]);
        }
        $event_id = (string) $event['id'];
        $type = (string) $event['type'];
        $livemode = !empty($event['livemode']);
        if (($livemode ? 'live' : 'test') !== $mode) {
            return new \WP_Error('stackless_stripe_mode_mismatch', __('Event mode does not match the signing secret.', 'stackless-lite'), ['status' => 400]);
        }
        $object = $event['data']['object'];

        update_option(self::OPTION_LAST, ['time' => time(), 'type' => $type, 'mode' => $mode], false);

        $claim = EventStore::claim($event_id, $type, $livemode, (string) ($object['id'] ?? ''));
        if ($claim === EventStore::PROCESSED) {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === EventStore::BUSY) {
            // Another request is on it. A non-2xx keeps Stripe retrying, so the event is still
            // handled if that request dies before finishing.
            return new \WP_REST_Response(['received' => false, 'retry' => true], 409);
        }
        if ($claim !== EventStore::CLAIMED) {
            Settings::log(sprintf('Webhook %s (%s) not processed: the event log could not be written.', $type, $event_id), 'error');
            return new \WP_REST_Response(['received' => false, 'retry' => true], 503);
        }

        try {
            $note = self::dispatch($type, $object, $mode);
            EventStore::complete($event_id, 'processed', $note);
            Settings::log(sprintf('Webhook %s (%s): %s', $type, $event_id, $note), 'info');
        } catch (\RuntimeException $e) {
            EventStore::release($event_id);
            if ($e->getCode() === self::DEFERRED) {
                return new \WP_REST_Response(['received' => false, 'retry' => true], 409);
            }
            Settings::log(sprintf('Webhook %s (%s) failed: %s', $type, $event_id, $e->getMessage()), 'error');
            return new \WP_REST_Response(['received' => false], 500);
        } catch (\Throwable $e) {
            EventStore::release($event_id);
            Settings::log(sprintf('Webhook %s (%s) failed: %s', $type, $event_id, $e->getMessage()), 'error');
            return new \WP_REST_Response(['received' => false], 500);
        }

        EventStore::maybePrune();
        return new \WP_REST_Response(['received' => true], 200);
    }

    /** Mode whose signing secret validates the request, or null. */
    public static function verifiedMode(\WP_REST_Request $request): ?string
    {
        $header = (string) $request->get_header('stripe_signature');
        $payload = $request->get_body();
        $tolerance = (int) apply_filters('stackless_stripe_gateway_webhook_tolerance', WebhookSignature::DEFAULT_TOLERANCE);
        foreach (['live', 'test'] as $mode) {
            $secret = Settings::webhookSecret($mode);
            if ($secret !== '' && WebhookSignature::verify($payload, $header, $secret, $tolerance) === null) {
                return $mode;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $object
     */
    public static function dispatch(string $type, array $object, string $mode): string
    {
        switch ($type) {
            case 'payment_intent.succeeded':
            case 'payment_intent.processing':
            case 'payment_intent.payment_failed':
            case 'payment_intent.canceled':
                return self::onPaymentIntent($object, $mode);
            case 'charge.refunded':
                return self::onChargeRefunded($object, $mode);
            case 'charge.dispute.created':
                return self::onDispute($object, $mode);
            case 'setup_intent.succeeded':
            case 'setup_intent.setup_failed':
                return self::onSetupIntent($object, $mode);
            case 'payment_method.detached':
                $count = Tokens::deleteForPaymentMethod((string) ($object['id'] ?? ''));
                return sprintf('removed %d token(s)', $count);
            case 'payment_method.updated':
            case 'payment_method.automatically_updated':
                $count = Tokens::updateFromPaymentMethod($object);
                return sprintf('updated %d token(s)', $count);
        }
        return 'ignored';
    }

    /** @param array<string,mixed> $object */
    private static function onPaymentIntent(array $object, string $mode): string
    {
        if (!self::isOurs($object)) {
            return 'other site';
        }
        $order = self::orderForIntent($object);
        if (!$order) {
            return 'no matching order';
        }
        $intent = Settings::client($mode)->get('payment_intents/' . rawurlencode((string) $object['id']), ['expand' => ['payment_method', 'latest_charge']]);
        $outcome = Payments::applyPaymentIntent($order, $intent, 'webhook');
        if ($outcome === Payments::OUTCOME_BUSY) {
            throw new \RuntimeException(esc_html('Order is locked by another request; retry later.'), (int) self::DEFERRED);
        }
        if ($outcome === Payments::OUTCOME_PAID || $outcome === Payments::OUTCOME_PROCESSING) {
            Module::gateway()->syncRenewalToken($order);
        }
        return 'order ' . $order->get_id() . ': ' . $outcome;
    }

    /** Sync refunds made in the Stripe Dashboard into WooCommerce. */
    private static function onChargeRefunded(array $charge, string $mode): string
    {
        $intent_id = self::idOf($charge['payment_intent'] ?? '');
        if ($intent_id === '') {
            return 'charge without PaymentIntent';
        }
        $intent = Settings::client($mode)->get('payment_intents/' . rawurlencode($intent_id));
        if (!self::isOurs($intent)) {
            return 'other site';
        }
        $order = self::orderForIntent($intent);
        if (!$order) {
            return 'no matching order';
        }
        if (get_transient('stackless_stripe_refunding_' . $order->get_id())) {
            throw new \RuntimeException(esc_html('Refund in progress on this site; retry later.'), (int) self::DEFERRED);
        }
        $currency = $order->get_currency();
        $stripe_refunded = (float) Amount::fromMinor((int) ($charge['amount_refunded'] ?? 0), $currency);
        $difference = round($stripe_refunded - (float) $order->get_total_refunded(), wc_get_price_decimals());
        if ($difference <= 0) {
            return 'refunds already in sync';
        }
        $difference = min($difference, (float) $order->get_total() - (float) $order->get_total_refunded());
        if ($difference <= 0) {
            return 'nothing left to refund';
        }
        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => wc_format_decimal($difference, wc_get_price_decimals()),
            'reason' => __('Refunded in the Stripe Dashboard.', 'stackless-lite'),
            'refund_payment' => false,
            'restock_items' => false,
        ]);
        if (is_wp_error($refund)) {
            throw new \RuntimeException(esc_html($refund->get_error_message()));
        }
        /* translators: %s: refunded amount */
        $order->add_order_note(sprintf(__('Recorded a %s refund made in Stripe.', 'stackless-lite'), wc_price($difference, ['currency' => $currency])));
        return 'order ' . $order->get_id() . ': refund recorded';
    }

    /** @param array<string,mixed> $dispute */
    private static function onDispute(array $dispute, string $mode): string
    {
        $intent_id = self::idOf($dispute['payment_intent'] ?? '');
        if ($intent_id === '') {
            return 'dispute without PaymentIntent';
        }
        $intent = Settings::client($mode)->get('payment_intents/' . rawurlencode($intent_id));
        if (!self::isOurs($intent)) {
            return 'other site';
        }
        $order = self::orderForIntent($intent);
        if (!$order) {
            return 'no matching order';
        }
        $order->update_meta_data(Payments::META_DISPUTE, (string) ($dispute['id'] ?? ''));
        $order->save();
        $note = sprintf(
            /* translators: 1: dispute amount, 2: dispute reason, 3: dispute id */
            __('The customer disputed this payment (%1$s, reason: %2$s). Respond in the Stripe Dashboard before the deadline. Dispute %3$s.', 'stackless-lite'),
            wc_price((float) Amount::fromMinor((int) ($dispute['amount'] ?? 0), $order->get_currency()), ['currency' => $order->get_currency()]),
            sanitize_text_field((string) ($dispute['reason'] ?? 'general')),
            sanitize_text_field((string) ($dispute['id'] ?? ''))
        );
        if ($order->has_status(['processing', 'completed']) && apply_filters('stackless_stripe_gateway_dispute_sets_on_hold', true, $order)) {
            $order->update_status('on-hold', $note);
        } else {
            $order->add_order_note($note);
        }
        do_action('stackless_stripe_gateway_dispute_created', $order, $dispute);
        return 'order ' . $order->get_id() . ': dispute noted';
    }

    /** @param array<string,mixed> $object */
    private static function onSetupIntent(array $object, string $mode): string
    {
        if (!self::isOurs($object)) {
            return 'other site';
        }
        $metadata = isset($object['metadata']) && is_array($object['metadata']) ? $object['metadata'] : [];
        $flow = (string) ($metadata['stackless_flow'] ?? '');
        $id = (string) ($object['id'] ?? '');
        $setup_intent = Settings::client($mode)->get('setup_intents/' . rawurlencode($id), ['expand' => ['payment_method']]);
        $status = (string) ($setup_intent['status'] ?? '');

        if ($flow === 'order' && !empty($metadata['order_id'])) {
            $order = wc_get_order(absint($metadata['order_id']));
            if (!$order instanceof \WC_Order || $order->get_meta(Payments::META_SETUP_INTENT) !== $id) {
                return 'no matching order';
            }
            $outcome = Payments::applySetupIntent($order, $setup_intent, 'webhook');
            if ($outcome === Payments::OUTCOME_BUSY) {
                throw new \RuntimeException(esc_html('Order is locked by another request; retry later.'), (int) self::DEFERRED);
            }
            return 'order ' . $order->get_id() . ': ' . $outcome;
        }

        if ($flow === 'change' && !empty($metadata['subscription_id'])) {
            $subscription = wc_get_order(absint($metadata['subscription_id']));
            if (!$subscription instanceof \WC_Order || $subscription->get_meta(Payments::META_SETUP_INTENT) !== $id) {
                return 'no matching subscription';
            }
            $pm_id = self::idOf($setup_intent['payment_method'] ?? '');
            if ($status !== 'succeeded' || $pm_id === '' || $subscription->get_meta(Payments::META_PM) === $pm_id) {
                return 'subscription ' . $subscription->get_id() . ': nothing to do';
            }
            Module::gateway()->completeSubscriptionSetup($subscription, $setup_intent);
            return 'subscription ' . $subscription->get_id() . ': method updated';
        }

        if ($flow === 'add' && !empty($metadata['wp_user_id']) && $status === 'succeeded' && is_array($setup_intent['payment_method'] ?? null)) {
            $user_id = absint($metadata['wp_user_id']);
            $customer = Customers::forUser($user_id, Settings::client($mode), false);
            if ($customer === '' || $customer !== (string) ($setup_intent['customer'] ?? '')) {
                return 'customer mismatch';
            }
            Tokens::saveFromPaymentMethod($setup_intent['payment_method'], $user_id, $customer, $mode);
            delete_user_meta($user_id, Gateway::USER_META_PENDING_SETUP);
            return 'user ' . $user_id . ': token saved';
        }
        return 'ignored setup intent';
    }

    /** Orders are matched through metadata.order_id and then checked against the intents we stored. */
    private static function orderForIntent(array $intent): ?\WC_Order
    {
        $intent_id = (string) ($intent['id'] ?? '');
        $metadata = isset($intent['metadata']) && is_array($intent['metadata']) ? $intent['metadata'] : [];
        if (!empty($metadata['order_id'])) {
            $order = wc_get_order(absint($metadata['order_id']));
            if ($order instanceof \WC_Order && Payments::orderOwnsIntent($order, $intent_id)) {
                return $order;
            }
        }
        return OrderQuery::findByMeta(Payments::META_INTENT, $intent_id);
    }

    /** Objects created by another site on the same Stripe account carry a different marker. */
    private static function isOurs(array $object): bool
    {
        $marker = isset($object['metadata']['stackless_site']) ? (string) $object['metadata']['stackless_site'] : '';
        return $marker === '' || $marker === Settings::siteMarker();
    }

    /** @param mixed $value Expandable field: id string or object. */
    private static function idOf($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }
        return is_string($value) ? $value : '';
    }
}
