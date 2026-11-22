<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Starts customer payments.
 *
 * A one-time payment uses Revolut's hosted page (the order's checkout_url). A payment that must
 * save the card uses Revolut's checkout widget on a Stackless page instead: only the widget can
 * ask for savePaymentMethodFor=merchant, and without it a saved card cannot be charged for
 * renewals. A free order that must save a card is a Revolut order for 0.
 */
final class Checkout
{
    /**
     * Create (or reuse) the Revolut order for a store order.
     *
     * @return string|\WP_Error Where to send the buyer.
     */
    public function start(int $orderId, bool $save)
    {
        $result = Lock::order($orderId, function () use ($orderId, $save) {
            return $this->startLocked($orderId, $save);
        }, null);
        return $result === null
            ? new \WP_Error('stackless_revolut_busy', __('This payment is already being processed. Try again in a moment.', 'stackless-lite'))
            : $result;
    }

    /** @return string|\WP_Error */
    private function startLocked(int $orderId, bool $save)
    {
        if (!Orders::exists($orderId)) {
            return new \WP_Error('stackless_revolut_order', __('Order not found.', 'stackless-lite'));
        }
        if (Orders::settled($orderId)) {
            return Orders::successUrl($orderId);
        }
        if (!Orders::needsPayment($orderId)) {
            return new \WP_Error('stackless_revolut_order', __('This order cannot be paid in its current status.', 'stackless-lite'));
        }
        $mode = Settings::mode();
        $currency = Orders::currency($orderId);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_revolut_currency', sprintf(__('Revolut does not take payments in %s here. Choose another payment method.', 'stackless-lite'), $currency));
        }
        $amount = max(0, Money::toMinor(Orders::total($orderId), $currency));
        $flow = $save ? Attempts::FLOW_SAVE : Attempts::FLOW_CHECKOUT;
        if ($amount === 0 && !$save) {
            return new \WP_Error('stackless_revolut_amount', __('There is nothing to pay with Revolut.', 'stackless-lite'));
        }
        $who = Attempts::buyer(Orders::user($orderId), Orders::email($orderId));
        $sync = new Sync();

        foreach (Attempts::open($orderId) as $attempt) {
            $same = (string) $attempt['id'] !== '' && $attempt['flow'] === $flow && $attempt['mode'] === $mode
                && (int) $attempt['amount'] === $amount && $attempt['currency'] === $currency && $attempt['who'] === $who;
            if ($same) {
                // Placing the order again (back button, double click, a declined card) reuses the order.
                $remote = Remote::read(Settings::api($mode), (string) $attempt['id']);
                if (is_wp_error($remote)) {
                    if (Api::status($remote) !== 404) {
                        return $remote;
                    }
                } else {
                    $class = Remote::classify($remote);
                    if ($class === Remote::PROCESSING) {
                        return Pages::returnUrl($orderId, $attempt);
                    }
                    if (in_array($class, [Remote::OPEN, Remote::DECLINED, Remote::CHALLENGE, Remote::ACTIVE], true) && Money::matches($remote, $amount, $currency)) {
                        $url = $save ? Pages::payUrl($orderId, $attempt) : self::hostedUrl($remote);
                        if ($url !== '') {
                            self::customerPays($orderId);
                            return $url;
                        }
                    }
                }
            }
            $resolved = Remote::resolve($orderId, $attempt);
            if ($resolved['state'] === 'paid') {
                $settled = $sync->settle($orderId, $attempt, $resolved['remote']);
                if ($settled === 'nosave' || strpos($settled, 'ignored') === 0) {
                    continue;
                }
                return Pages::returnUrl($orderId, $attempt);
            }
            if ($resolved['state'] !== 'closed') {
                Sync::waiting($orderId);
                return new \WP_Error('stackless_revolut_processing', __('An earlier payment for this order is still being processed. Wait a few minutes before paying again.', 'stackless-lite'));
            }
        }

        $customer = '';
        if ($save) {
            $customer = Customers::forOrder($orderId, $mode);
            if (is_wp_error($customer)) {
                return $customer;
            }
        }
        $attempt = Attempts::begin($orderId, [
            'mode' => $mode,
            'flow' => $flow,
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customer,
            'user' => Orders::user($orderId),
            'who' => $who,
        ]);
        $api = Settings::api($mode);
        $created = self::createOrder($api, $orderId, $attempt);
        if (is_wp_error($created)) {
            if (!Api::isTransient($created)) {
                Attempts::close($orderId, $attempt);
            }
            return $created;
        }
        $attempt = Attempts::update($orderId, $attempt, ['id' => (string) $created['id']]);
        $url = $save ? Pages::payUrl($orderId, $attempt) : self::hostedUrl($created);
        if ($url === '') {
            return new \WP_Error('stackless_revolut_checkout', __('Revolut did not return a checkout page for this payment.', 'stackless-lite'));
        }
        self::customerPays($orderId);
        Jobs::at($orderId, Jobs::ABANDONED_AFTER);
        Settings::log(sprintf('Revolut order %s created for order %d (%s, %s).', (string) $created['id'], $orderId, Money::display($amount, $currency), $flow));
        return $url;
    }

    /** The customer pays from here: a pending charge of a saved card no longer applies. */
    private static function customerPays(int $orderId): void
    {
        foreach ([Keys::CHARGE, Keys::WAITING, Keys::CHECKS] as $key) {
            if (Orders::meta($orderId, $key) !== '') {
                Orders::deleteMeta($orderId, $key);
            }
        }
    }

    /**
     * POST /api/orders for an attempt.
     *
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>|\WP_Error
     */
    public static function createOrder(Api $api, int $orderId, array $attempt)
    {
        $amount = (int) $attempt['amount'];
        $currency = (string) $attempt['currency'];
        $body = [
            'amount' => $amount,
            'currency' => $currency,
            'capture_mode' => 'automatic',
            /* translators: 1: order number, 2: site name */
            'description' => Api::text(sprintf(__('Order %1$s, %2$s', 'stackless-lite'), Orders::number($orderId), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)), 255),
            'merchant_order_data' => ['reference' => (string) $attempt['ref']],
            'metadata' => [Keys::REMOTE_ORDER => (string) $orderId],
        ];
        if ((string) $attempt['customer'] !== '') {
            $body['customer'] = ['id' => (string) $attempt['customer']];
        } elseif (is_email(Orders::email($orderId))) {
            $customer = ['email' => Orders::email($orderId)];
            if (Orders::name($orderId) !== '') {
                $customer['full_name'] = Api::text(Orders::name($orderId), 250);
            }
            $body['customer'] = $customer;
        }
        if ($amount > 0) {
            $body['line_items'] = self::lineItems(Orders::lines($orderId), $amount, $currency, Orders::number($orderId));
            $shipping = Orders::shipping($orderId);
            if ($shipping) {
                $body['shipping'] = $shipping;
            }
        }
        if ($attempt['flow'] === Attempts::FLOW_CHECKOUT) {
            $return = Pages::returnUrl($orderId, $attempt);
            if (self::acceptsUrl($return)) {
                $body['redirect_url'] = $return;
            }
        }
        $created = $api->request('POST', 'orders', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        if (!Api::isId((string) ($created['id'] ?? '')) || !Money::matches($created, $amount, $currency)) {
            return new \WP_Error('stackless_revolut_created', __('Revolut did not create the order as requested.', 'stackless-lite'), ['status' => 0, 'code' => 'create_mismatch']);
        }
        return $created;
    }

    /**
     * Line items, which must add up to the amount: the order's own lines, or one line for the order.
     *
     * @param array<int,array{name:string, quantity:float, total:float, type:string}> $lines
     * @return array<int,array<string,mixed>>
     */
    public static function lineItems(array $lines, int $amount, string $currency, string $number): array
    {
        $items = [];
        $sum = 0;
        $usable = count($lines) <= 250;
        foreach ($lines as $line) {
            $total = Money::toMinor($line['total'], $currency);
            $quantity = $line['quantity'] > 0 ? $line['quantity'] : 1;
            $name = Api::text(wp_strip_all_tags($line['name']), 250);
            if ($total < 0 || $name === '') {
                $usable = false;
                break;
            }
            $item = [
                'name' => $name,
                'quantity' => ['value' => floor($quantity) === (float) $quantity ? (int) $quantity : $quantity],
                'unit_price_amount' => (int) round($total / $quantity),
                'total_amount' => $total,
            ];
            if ($line['type'] === 'physical' || $line['type'] === 'service') {
                $item['type'] = $line['type'];
            }
            $items[] = $item;
            $sum += $total;
        }
        if ($usable && $items && $sum === $amount) {
            return $items;
        }
        return [[
            /* translators: %s: order number */
            'name' => sprintf(__('Order %s', 'stackless-lite'), $number),
            'quantity' => ['value' => 1],
            'unit_price_amount' => $amount,
            'total_amount' => $amount,
        ]];
    }

    /**
     * Account page → add a card: a Revolut order for 0 on the user's customer.
     *
     * @return string|\WP_Error The page with the widget.
     */
    public function startAdd(int $userId, int $subscriptionId = 0)
    {
        if ($userId <= 0) {
            return new \WP_Error('stackless_revolut_user', __('Sign in to save a payment method.', 'stackless-lite'));
        }
        $mode = Settings::mode();
        $currency = Platform::currency();
        if (!Money::supported($currency)) {
            $currency = 'EUR';
        }
        $customer = Customers::forUser($userId, $mode);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $created = Settings::api($mode)->request('POST', 'orders', [
            'amount' => 0,
            'currency' => $currency,
            'capture_mode' => 'automatic',
            /* translators: %s: site name */
            'description' => Api::text(sprintf(__('Save a card for %s', 'stackless-lite'), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)), 255),
            'customer' => ['id' => $customer],
            'metadata' => [Keys::REMOTE_USER => (string) $userId],
        ]);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['id'] ?? '');
        if (!Api::isId($id) || !Money::matches($created, 0, $currency)) {
            return new \WP_Error('stackless_revolut_created', __('Revolut did not create the order as requested.', 'stackless-lite'));
        }
        return Pages::addUrl(Adds::begin($userId, [
            'id' => $id,
            'mode' => $mode,
            'currency' => $currency,
            'customer' => $customer,
            'subscription' => max(0, $subscriptionId),
        ]));
    }

    /**
     * The hosted page of an order, only on Revolut's own domain.
     *
     * @param array<string,mixed> $remote
     */
    public static function hostedUrl(array $remote): string
    {
        $url = isset($remote['checkout_url']) && is_string($remote['checkout_url']) ? $remote['checkout_url'] : '';
        $parts = wp_parse_url($url);
        if ($url === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])) {
            return '';
        }
        return preg_match('/(^|\.)revolut\.com$/i', (string) ($parts['host'] ?? '')) ? $url : '';
    }

    /** Revolut refuses URLs on localhost or an IP address, and URLs over 2000 characters. */
    public static function acceptsUrl(string $url): bool
    {
        $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '[]'));
        return $host !== '' && $host !== 'localhost' && strlen($url) <= 2000 && !filter_var($host, FILTER_VALIDATE_IP);
    }
}
