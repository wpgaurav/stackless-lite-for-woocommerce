<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * The gateway's own pages: the one with Revolut's checkout widget (for payments that save a
 * card) and the return from the widget or the hosted page.
 *
 * Their links are signed and carry no order key. Revolut may append its own ?… or &… to a
 * return URL, so every value is read up to the first character it cannot contain and the
 * signature comes last. Nothing a link says ever pays an order: the return only re-reads Revolut.
 */
final class Pages
{
    public const ACTION = 'stackless_revolut';

    /** Languages of Revolut's checkout widget. */
    private const LOCALES = ['en', 'nl', 'fr', 'de', 'cs', 'it', 'lt', 'pl', 'pt', 'es', 'hu', 'sk', 'ja', 'sv', 'bg', 'ro', 'ru', 'el', 'hr', 'tr'];

    /** @param array<string,mixed> $attempt */
    public static function payUrl(int $orderId, array $attempt): string
    {
        return self::url('pay', $orderId, (string) $attempt['ref']);
    }

    /** @param array<string,mixed> $attempt */
    public static function returnUrl(int $orderId, array $attempt): string
    {
        return self::url('return', $orderId, (string) $attempt['ref']);
    }

    /** @param array<string,mixed> $session */
    public static function addUrl(array $session): string
    {
        return self::url('add', 0, (string) $session['ref']);
    }

    /** @param array<string,mixed> $session */
    public static function addReturnUrl(array $session): string
    {
        return self::url('added', 0, (string) $session['ref']);
    }

    private static function url(string $view, int $orderId, string $ref): string
    {
        return Platform::pageUrl(['rv_view' => $view, 'rv_order' => $orderId, 'rv_ref' => $ref, 'rv_sig' => self::sign($view, $orderId, $ref)]);
    }

    public static function sign(string $view, int $orderId, string $ref): string
    {
        return substr(hash_hmac('sha256', 'revolut|' . $view . '|' . $orderId . '|' . $ref, wp_salt('auth')), 0, 32);
    }

    /**
     * The signed values of a request, tolerant of anything appended to the URL.
     *
     * @param array<int|string,mixed> $query
     * @return array{view:string, order:int, ref:string}|null
     */
    public static function parse(array $query): ?array
    {
        $values = [];
        foreach (['view' => '[a-z]+', 'order' => '\d+', 'ref' => '[A-Za-z0-9-]+', 'sig' => '[a-f0-9]{32}'] as $name => $pattern) {
            $raw = isset($query['rv_' . $name]) && is_scalar($query['rv_' . $name]) ? (string) $query['rv_' . $name] : '';
            if (!preg_match('/^(' . $pattern . ')/', $raw, $match)) {
                return null;
            }
            $values[$name] = $match[1];
        }
        $order = (int) $values['order'];
        if (!in_array($values['view'], ['pay', 'return', 'add', 'added'], true) || !hash_equals(self::sign($values['view'], $order, $values['ref']), $values['sig'])) {
            return null;
        }
        return ['view' => $values['view'], 'order' => $order, 'ref' => $values['ref']];
    }

    public static function route(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the link carries its own signature, checked in parse().
        $page = self::parse(wp_unslash($_GET));
        if (!$page) {
            Platform::redirect(Platform::checkoutUrl());
            return;
        }
        nocache_headers();
        if ($page['view'] === 'add' || $page['view'] === 'added') {
            $userId = get_current_user_id();
            $session = Adds::byRef($userId, $page['ref']);
            if (!$session) {
                Platform::redirect(Platform::methodsUrl());
            } elseif ($page['view'] === 'add') {
                self::addPage($session);
            } else {
                self::added($userId, $session);
            }
            return;
        }
        $attempt = Orders::exists($page['order']) ? Attempts::byRef($page['order'], $page['ref']) : null;
        if (!$attempt) {
            Platform::redirect(Platform::checkoutUrl());
        } elseif ($page['view'] === 'pay') {
            self::payPage($page['order'], $attempt);
        } else {
            self::returned($page['order'], $attempt);
        }
    }

    /** @param array<string,mixed> $attempt */
    private static function payPage(int $orderId, array $attempt): void
    {
        if (Orders::settled($orderId)) {
            Platform::redirect(Orders::successUrl($orderId));
            return;
        }
        if (!empty($attempt['closed']) || $attempt['flow'] !== Attempts::FLOW_SAVE || !Orders::needsPayment($orderId) || (string) $attempt['id'] === '') {
            Platform::redirect(Orders::payUrl($orderId));
            return;
        }
        $currency = Orders::currency($orderId);
        if ((string) $attempt['currency'] !== $currency || (int) $attempt['amount'] !== max(0, Money::toMinor(Orders::total($orderId), $currency))) {
            // The order changed: paying from its own link cancels this payment and starts one for the new total.
            Platform::redirect(Orders::payUrl($orderId));
            return;
        }
        $mode = (string) $attempt['mode'];
        $remote = Remote::read(Settings::api($mode), (string) $attempt['id']);
        $error = '';
        $config = [];
        if (is_wp_error($remote)) {
            $error = __('Revolut could not be reached. Reload the page to try again.', 'stackless-lite');
        } elseif (in_array(Remote::classify($remote), [Remote::PAID, Remote::PROCESSING, Remote::DEAD], true)) {
            Platform::redirect(self::returnUrl($orderId, $attempt));
            return;
        } else {
            $config = self::config($remote, self::returnUrl($orderId, $attempt), Orders::email($orderId), Orders::name($orderId), Orders::billing($orderId));
        }
        $amount = (int) $attempt['amount'];
        Platform::render([
            /* translators: %s: order number */
            'heading' => sprintf(__('Pay for order %s', 'stackless-lite'), Orders::number($orderId)),
            'lines' => [
                $amount > 0
                    /* translators: %s: amount */
                    ? sprintf(__('Enter your card in the Revolut window to pay %s.', 'stackless-lite'), Money::display($amount, (string) $attempt['currency']))
                    : __('Enter your card in the Revolut window. Nothing is charged now.', 'stackless-lite'),
                __('Your card is saved with Revolut so this store can charge it for your future payments, such as renewals.', 'stackless-lite'),
            ],
            'error' => $error,
            'button' => $amount > 0 ? __('Pay now', 'stackless-lite') : __('Save card', 'stackless-lite'),
            'cancel' => Orders::payUrl($orderId),
            'cancel_label' => __('Choose another payment method', 'stackless-lite'),
            'testmode' => $mode === 'test',
            'config' => $config,
            'sdk' => Api::host($mode) . '/embed.js',
        ]);
    }

    /** @param array<string,mixed> $session */
    private static function addPage(array $session): void
    {
        $mode = (string) $session['mode'];
        $remote = Remote::read(Settings::api($mode), (string) $session['id']);
        $error = '';
        $config = [];
        if (is_wp_error($remote)) {
            $error = __('Revolut could not be reached. Reload the page to try again.', 'stackless-lite');
        } elseif (in_array(Remote::classify($remote), [Remote::PAID, Remote::PROCESSING, Remote::DEAD], true)) {
            Platform::redirect(self::addReturnUrl($session));
            return;
        } else {
            $user = wp_get_current_user();
            $config = self::config($remote, self::addReturnUrl($session), (string) $user->user_email, trim($user->first_name . ' ' . $user->last_name), []);
        }
        Platform::render([
            'heading' => __('Save a card', 'stackless-lite'),
            'lines' => [__('Enter your card in the Revolut window. Nothing is charged now. The card is saved with Revolut so this store can charge it for your future payments, such as renewals.', 'stackless-lite')],
            'error' => $error,
            'button' => __('Save card', 'stackless-lite'),
            'cancel' => Platform::methodsUrl(),
            'cancel_label' => __('Cancel', 'stackless-lite'),
            'testmode' => $mode === 'test',
            'config' => $config,
            'sdk' => Api::host($mode) . '/embed.js',
        ]);
    }

    /**
     * Options for RevolutCheckout(token).payWithPopup(). The token is the order's public one.
     *
     * @param array<string,mixed>  $remote
     * @param array<string,string> $billing
     * @return array<string,mixed>
     */
    public static function config(array $remote, string $returnUrl, string $email, string $name, array $billing): array
    {
        $options = ['savePaymentMethodFor' => 'merchant', 'locale' => self::locale()];
        if ($email !== '') {
            $options['email'] = $email;
        }
        if (strpos($name, ' ') !== false) {
            // The widget wants "FirstName LastName".
            $options['name'] = $name;
        }
        if (($billing['countryCode'] ?? '') !== '' && ($billing['postcode'] ?? '') !== '') {
            $options['billingAddress'] = array_filter($billing, static function ($value) {
                return $value !== '';
            });
        }
        return [
            'token' => (string) ($remote['token'] ?? ''),
            'options' => $options,
            'returnUrl' => $returnUrl,
            'i18n' => [
                'failed' => __('The payment did not go through. You can try again.', 'stackless-lite'),
                'closed' => __('Card window closed. Use the button to open it again.', 'stackless-lite'),
                'unavailable' => __('Revolut could not load. Check your connection and reload the page.', 'stackless-lite'),
            ],
        ];
    }

    private static function locale(): string
    {
        $language = substr(function_exists('determine_locale') ? determine_locale() : get_locale(), 0, 2);
        return in_array($language, self::LOCALES, true) ? $language : 'auto';
    }

    /** @param array<string,mixed> $attempt */
    private static function returned(int $orderId, array $attempt): void
    {
        $outcome = 'open';
        if ((string) $attempt['id'] !== '') {
            $sync = new Sync();
            // Cards settle within seconds: a short wait saves showing a paid order as unpaid.
            for ($try = 0; $try < 3; $try++) {
                if ($try > 0) {
                    sleep(1);
                }
                $outcome = $sync->apply($orderId, (string) $attempt['id'], (string) $attempt['mode']);
                if ($outcome !== 'open' && $outcome !== 'waiting') {
                    break;
                }
            }
        }
        Orders::refresh($orderId);
        if (Orders::settled($orderId) || in_array($outcome, ['paid', 'waiting', 'retry', 'busy', 'mismatch', 'duplicate'], true)) {
            Platform::emptyCart();
            Platform::redirect(Orders::successUrl($orderId));
            return;
        }
        Platform::checkoutError($outcome === 'failed'
            ? __('Your payment was declined. Please try again or choose another payment method.', 'stackless-lite')
            : ($outcome === 'nosave'
                ? __('Your card could not be saved for renewals. Please try again with another card.', 'stackless-lite')
                : __('The payment was not completed. You can try again below.', 'stackless-lite')));
        Platform::redirect(Orders::payUrl($orderId));
    }

    /** @param array<string,mixed> $session */
    private static function added(int $userId, array $session): void
    {
        $result = (new Sync())->applyAdd($userId, (string) $session['id'], (string) $session['mode']);
        if ($result['state'] === 'saved') {
            Platform::notice(__('Payment method successfully added.', 'stackless-lite'), 'success');
        } elseif ($result['state'] === 'retry' || $result['state'] === 'open') {
            Platform::notice(__('Revolut is still confirming your card. It appears here once confirmed.', 'stackless-lite'), 'info');
        } else {
            Platform::notice(__('Unable to add payment method to your account.', 'stackless-lite'), 'error');
        }
        Platform::redirect(Platform::methodsUrl($result['state'] === 'saved' ? (int) ($session['subscription'] ?? 0) : 0));
    }
}
