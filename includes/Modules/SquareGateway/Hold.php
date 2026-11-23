<?php

namespace Stackless\Modules\SquareGateway;

use Stackless\Core\FreshOrder;

defined('ABSPATH') || exit;

/**
 * While Square has not answered a payment request, that payment may still go through, so nothing
 * else may pay for the purchase: not another card or wallet, not another gateway, not a new order
 * from the same or a changed cart, and not the order's pay page. The hold is the order's
 * CheckoutAttempt record, which keeps no personal data.
 *
 * The hold settles from what Square shows: the same saved card sending the same request again, a
 * lookup of the order's payments (on every retry and on a schedule), the payment webhook, or the
 * store admin. Square showing no payment for ABSENT_AFTER, on two lookups EMPTY_GAP apart,
 * releases it. The order is never cancelled as unpaid while it is held.
 */
final class Hold
{
    public const CHECK_HOOK = 'stackless_square_gateway_check_hold';
    public const ACTION_CHECK = 'stackless_square_check_hold';
    public const ACTION_RELEASE = 'stackless_square_release_hold';

    /** Square shows a payment within seconds; no payment after this long means there is none. */
    public const ABSENT_AFTER = 1800;
    /** And only when an earlier lookup, at least this long before, found none either. */
    public const EMPTY_GAP = 300;
    private const CHECK_DELAYS = [180, 600, 1200, 3600, 21600, 86400];

    /** Per shopper: orders held, and the cart paid last (two tabs). */
    private const HELD = 'stackless_square_held_';
    private const PAID = 'stackless_square_paid_';
    private const PAID_FOR = 1800;

    public static function registerHooks(): void
    {
        add_action(self::CHECK_HOOK, [self::class, 'scheduledCheck']);
        add_action('woocommerce_after_checkout_validation', [self::class, 'guardClassic'], 5, 2);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'guardStoreApi'], 1);
        add_action('woocommerce_before_pay_action', [self::class, 'guardPay'], 1);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'gateways'], 70);
        add_action('template_redirect', [self::class, 'payPageNotice']);
        add_filter('stackless_order_pay_link_allowed', [self::class, 'payLink'], 10, 2);
        add_filter('woocommerce_my_account_my_orders_actions', [self::class, 'orderActions'], 20, 2);
        add_filter('woocommerce_cancel_unpaid_order', [self::class, 'keepUnpaid'], 10, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'onStatus'], 10, 3);
        add_filter('woocommerce_order_actions', [self::class, 'adminActions'], 10, 2);
        add_action('woocommerce_order_action_' . self::ACTION_CHECK, [self::class, 'adminCheck']);
        add_action('woocommerce_order_action_' . self::ACTION_RELEASE, [self::class, 'adminRelease']);
        add_action('woocommerce_privacy_remove_order_personal_data', [self::class, 'erase']);
        add_action('woocommerce_cart_emptied', [self::class, 'cartEmptied']);
    }

    public static function message(): string
    {
        return __('We are still confirming an earlier payment for this order with Square, so nothing was charged now. This usually takes a few minutes, and you will get an email when it is confirmed. Check your orders before you pay again. If Square shows no payment, you can pay after about 30 minutes, or contact the store.', 'stackless-lite');
    }

    /** For the request whose answer was lost: it may have charged, so the buyer must not pay again. */
    public static function pendingMessage(): string
    {
        return __('Square hasn\'t confirmed this payment yet, and it may still go through, so don\'t pay again. We are checking with Square, and you will get an email when it is confirmed. If Square shows no payment, you can pay after about 30 minutes, or contact the store.', 'stackless-lite');
    }

    public static function paidElsewhereMessage(): string
    {
        return __('This cart was just paid in another window or tab, so nothing was charged now. Check your orders or your email for the confirmation.', 'stackless-lite');
    }

    /** @param mixed $order */
    public static function held($order): bool
    {
        if (!$order instanceof \WC_Order || $order->is_paid()) {
            return false;
        }
        if (CheckoutAttempt::of($order)->hold) {
            return true;
        }
        // An automatic charge of this order is waiting to be sent again with its own key.
        return (int) $order->get_meta(Payments::META_TRANSIENT) > 0 && $order->needs_payment();
    }

    /* ---- The shopper: same browser session, or same account ---------------------------- */

    /** Identifies the shopper (WooCommerce's session customer id), or '' without a session. */
    public static function purchaseKey(): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }
        $id = (string) WC()->session->get_customer_id();
        return $id !== '' ? md5($id) : '';
    }

    /** Another held order of this shopper than $order, if any. */
    public static function heldFor(?\WC_Order $order): ?\WC_Order
    {
        $except = $order ? (int) $order->get_id() : 0;
        $ids = [];
        $key = self::purchaseKey();
        if ($key !== '') {
            $ids = array_map('intval', (array) self::read(self::HELD . $key));
        }
        if (function_exists('WC') && WC()->session) {
            $ids[] = (int) WC()->session->get('order_awaiting_payment');
            $ids[] = (int) WC()->session->get('store_api_draft_order');
        }
        $user = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user > 0 && function_exists('wc_get_orders')) {
            $ids = array_merge($ids, array_map('intval', (array) wc_get_orders([
                'customer_id' => $user,
                'status' => ['pending', 'failed', 'on-hold'],
                'meta_key' => CheckoutAttempt::META, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_compare' => 'EXISTS',
                'limit' => 10,
                'return' => 'ids',
            ])));
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            if ($id === $except) {
                continue;
            }
            $other = FreshOrder::get($id);
            if ($other && CheckoutAttempt::of($other)->hold && self::held($other)) {
                return $other;
            }
        }
        return null;
    }

    /** Two tabs: this order's cart was paid with another order, which was placed after this one. */
    /**
     * Whether this shopper just paid another order for the same cart: $order was created before
     * that payment (another tab), or the cart has not been emptied since (WooCommerce empties it
     * on the order received page). A cart bought again after that is a new purchase.
     */
    public static function paidElsewhere(\WC_Order $order): bool
    {
        $key = self::purchaseKey();
        $paid = $key !== '' ? self::read(self::PAID . $key) : null;
        if (!is_array($paid) || (int) ($paid['order'] ?? 0) === (int) $order->get_id() || time() - (int) ($paid['at'] ?? 0) > self::PAID_FOR) {
            return false;
        }
        if ((string) ($paid['cart'] ?? '') === '' || !hash_equals((string) $paid['cart'], (string) $order->get_cart_hash())) {
            return false;
        }
        return empty($paid['emptied']) || (int) $order->get_id() <= (int) ($paid['upto'] ?? 0);
    }

    public static function rememberPaid(\WC_Order $order): void
    {
        $key = self::purchaseKey();
        if ($key !== '' && (string) $order->get_cart_hash() !== '') {
            set_transient(self::PAID . $key, [
                'order' => (int) $order->get_id(),
                'cart' => (string) $order->get_cart_hash(),
                'at' => time(),
                'upto' => self::lastId(),
                'emptied' => false,
            ], self::PAID_FOR);
        }
    }

    /** The shopper's cart was emptied: an order made from now on is a new purchase. */
    public static function cartEmptied(): void
    {
        $key = self::purchaseKey();
        $paid = $key !== '' ? self::read(self::PAID . $key) : null;
        if (!is_array($paid) || !empty($paid['emptied'])) {
            return;
        }
        $left = self::PAID_FOR - (time() - (int) ($paid['at'] ?? 0));
        if ($left > 0) {
            $paid['emptied'] = true;
            set_transient(self::PAID . $key, $paid, $left);
        }
    }

    /**
     * The highest id given out so far. WooCommerce takes every order's id from the posts table,
     * with HPOS too, so any order created before now has an id up to this.
     */
    private static function lastId(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /** Transients bypassing this request's option cache: another request may have just written one. */
    private static function read(string $name)
    {
        if (!wp_using_ext_object_cache()) {
            wp_cache_delete('_transient_' . $name, 'options');
            wp_cache_delete('_transient_timeout_' . $name, 'options');
        }
        return get_transient($name);
    }

    /* ---- Holding and settling --------------------------------------------------------- */

    /** A request got no answer: tell the store, remember the order for this shopper, start checking. */
    public static function started(\WC_Order $order): void
    {
        $hold = CheckoutAttempt::of($order)->hold;
        if (!$hold) {
            return;
        }
        $key = self::purchaseKey();
        if ($key !== '') {
            $ids = array_map('intval', (array) self::read(self::HELD . $key));
            $ids[] = (int) $order->get_id();
            set_transient(self::HELD . $key, array_values(array_unique($ids)), 2 * DAY_IN_SECONDS);
        }
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Square idempotency key */
            __('Square did not answer the request to charge %1$s (idempotency key %2$s), so the order is held: nothing else can pay it until Square shows the result. Stackless checks Square every few minutes and releases the hold if Square shows no payment after 30 minutes. To release it sooner, check the Square Dashboard first, then use the order action "Release the Square hold".', 'stackless-lite'),
            wp_strip_all_tags(wc_price(Money::fromMinor((int) $hold['minor'], (string) $hold['currency']), ['currency' => (string) $hold['currency']])),
            (string) $hold['key']
        ));
        self::schedule((int) $order->get_id(), self::CHECK_DELAYS[0]);
    }

    /** Clear the hold without a payment. */
    public static function release(\WC_Order $order, string $note): void
    {
        $attempt = CheckoutAttempt::of($order);
        if (!$attempt->hold) {
            return;
        }
        $waiting = !empty($attempt->hold['renewal_waiting']);
        $attempt->hold = [];
        $attempt->write($order);
        $order->save();
        self::forget($order);
        $order->add_order_note($note);
        if ($waiting) {
            self::retryRenewal((int) $order->get_id());
        }
    }

    /** The hold is over: stop checking, and stop counting the order as this shopper's. */
    public static function forget(\WC_Order $order): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CHECK_HOOK, ['order_id' => (int) $order->get_id()], Payments::GROUP);
        }
    }

    /** An automatic renewal charge waited for the buyer's held payment; send it now. */
    public static function retryRenewal(int $orderId): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 60, Payments::RETRY_HOOK, ['order_id' => $orderId], Payments::GROUP);
        }
    }

    private static function schedule(int $orderId, int $delay): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        $args = ['order_id' => $orderId];
        if (function_exists('as_next_scheduled_action') && as_next_scheduled_action(self::CHECK_HOOK, $args, Payments::GROUP)) {
            return;
        }
        as_schedule_single_action(time() + $delay, self::CHECK_HOOK, $args, Payments::GROUP);
    }

    /** Ask Square about a held order. @return string paid|pending|released|held|none|busy */
    public static function check(int $orderId): string
    {
        return (string) Lock::run('charge-' . $orderId, static function () use ($orderId) {
            $order = FreshOrder::get($orderId);
            if (!$order) {
                return 'none';
            }
            if ($order->is_paid() || !CheckoutAttempt::of($order)->hold) {
                return 'none';
            }
            return (new Payments(Gateway::instance()))->settleHold($order);
        }, 'busy');
    }

    /** Action Scheduler: check, and keep checking while held. @param mixed $orderId */
    public static function scheduledCheck($orderId): void
    {
        $orderId = (int) $orderId;
        $state = self::check($orderId);
        $order = FreshOrder::get($orderId);
        $attempt = $order ? CheckoutAttempt::of($order) : null;
        if (!$order || !$attempt || !$attempt->hold || ($state !== 'held' && $state !== 'busy')) {
            return;
        }
        $checks = (int) ($attempt->hold['checks'] ?? 0) + 1;
        $attempt->hold['checks'] = $checks;
        $attempt->write($order);
        $order->save_meta_data();
        $delay = self::CHECK_DELAYS[min($checks, count(self::CHECK_DELAYS) - 1)];
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, self::CHECK_HOOK, ['order_id' => $orderId], Payments::GROUP);
        }
    }

    /* ---- Refusing everything else while held ------------------------------------------ */

    /**
     * Classic checkout, before an order is created. Only Square paying the same held order goes on:
     * it asks Square first (Payments).
     *
     * @param array<string,mixed> $data
     * @param \WP_Error $errors
     */
    public static function guardClassic($data, $errors): void
    {
        $held = self::heldFor(null);
        if (!$held || !$errors instanceof \WP_Error) {
            return;
        }
        $awaiting = function_exists('WC') && WC()->session ? (int) WC()->session->get('order_awaiting_payment') : 0;
        $sameOrder = $awaiting === (int) $held->get_id() && WC()->cart && $held->has_cart_hash(WC()->cart->get_cart_hash());
        if ($sameOrder && (string) ($data['payment_method'] ?? '') === Gateway::ID) {
            return;
        }
        $errors->add('stackless_square_held', self::message());
    }

    /** Checkout block: after the order is updated from the request, before any gateway runs. @param mixed $order */
    public static function guardStoreApi($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $refuse = self::heldFor($order) || (self::held($order) && $order->get_payment_method() !== Gateway::ID);
        if ($refuse && class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('stackless_square_held', esc_html(self::message()), 409);
        }
    }

    /** Pay page: only Square, which asks Square first, may pay a held order. @param mixed $order */
    public static function guardPay($order): void
    {
        if (!self::held($order)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the pay form's nonce before this action.
        $method = isset($_POST['payment_method']) ? sanitize_key(wp_unslash((string) $_POST['payment_method'])) : '';
        if ($method === Gateway::ID && CheckoutAttempt::of($order)->hold) {
            return;
        }
        wc_add_notice(self::message(), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /** @param mixed $gateways @return mixed */
    public static function gateways($gateways)
    {
        if (!is_array($gateways) || !function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return $gateways;
        }
        $order = wc_get_order(absint(get_query_var('order-pay')));
        return self::held($order) ? array_intersect_key($gateways, [Gateway::ID => true]) : $gateways;
    }

    public static function payPageNotice(): void
    {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            return;
        }
        $order = wc_get_order(absint(get_query_var('order-pay')));
        if (self::held($order) && !wc_has_notice(self::message(), 'notice') && !wc_has_notice(self::message(), 'error')) {
            wc_add_notice(self::message(), 'notice');
        }
    }

    /**
     * @param mixed $allowed
     * @param mixed $order
     */
    public static function payLink($allowed, $order = null): bool
    {
        return (bool) $allowed && !self::held($order);
    }

    /**
     * @param mixed $actions
     * @param mixed $order
     * @return mixed
     */
    public static function orderActions($actions, $order)
    {
        if (is_array($actions) && self::held($order)) {
            unset($actions['pay']);
        }
        return $actions;
    }

    /**
     * A held payment may still go through: don't cancel the order and restock it.
     *
     * @param mixed $cancel
     * @param mixed $order
     */
    public static function keepUnpaid($cancel, $order = null): bool
    {
        return (bool) $cancel && !self::held($order);
    }

    /**
     * Paid, or closed by the store: the hold is over.
     *
     * @param mixed $orderId
     * @param mixed $from
     * @param mixed $to
     */
    public static function onStatus($orderId, $from = '', $to = ''): void
    {
        if (!in_array((string) $to, ['processing', 'completed', 'cancelled', 'refunded'], true)) {
            return;
        }
        $order = wc_get_order((int) $orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $attempt = CheckoutAttempt::of($order);
        if (!$attempt->hold) {
            return;
        }
        $attempt->hold = [];
        $attempt->write($order);
        $order->save_meta_data();
        self::forget($order);
    }

    /* ---- Store admin ------------------------------------------------------------------- */

    /**
     * @param mixed $actions
     * @param mixed $order
     * @return mixed
     */
    public static function adminActions($actions, $order = null)
    {
        if (is_array($actions) && $order instanceof \WC_Order && CheckoutAttempt::of($order)->hold) {
            $actions[self::ACTION_CHECK] = __('Check Square for the held payment', 'stackless-lite');
            $actions[self::ACTION_RELEASE] = __('Release the Square hold (check the Square Dashboard first)', 'stackless-lite');
        }
        return $actions;
    }

    /** @param mixed $order */
    public static function adminCheck($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $state = self::check((int) $order->get_id());
        $fresh = FreshOrder::get((int) $order->get_id());
        if ($fresh && ($state === 'held' || $state === 'busy')) {
            $fresh->add_order_note(__('Checked Square for the held payment: no result yet. The hold stays.', 'stackless-lite'));
        }
    }

    /** @param mixed $order */
    public static function adminRelease($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $name = $user && $user->exists() ? $user->display_name : __('the store', 'stackless-lite');
        Lock::run('charge-' . $order->get_id(), static function () use ($order, $name) {
            $fresh = FreshOrder::get((int) $order->get_id());
            if ($fresh) {
                /* translators: %s: user name */
                self::release($fresh, sprintf(__('The Square hold was released by %s. The order can be paid again.', 'stackless-lite'), $name));
            }
            return true;
        });
    }

    /**
     * Privacy eraser: the hold keeps no email or address, only a hash of the held request, which is
     * dropped (the same card can't resend it after this; a lookup still settles it).
     *
     * @param mixed $order
     */
    public static function erase($order): void
    {
        if (!$order instanceof \WC_Order || (string) $order->get_meta(CheckoutAttempt::META) === '') {
            return;
        }
        $attempt = CheckoutAttempt::of($order); // also drops a request kept by 2.1.0
        if ($attempt->hold) {
            $attempt->hold['hash'] = '';
        }
        $attempt->write($order);
        $order->save_meta_data();
    }
}
