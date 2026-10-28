<?php
/**
 * Cross-module API.
 *
 * Every function here is safe to call whether or not the owning module is enabled:
 * each returns a neutral default through a filter, and the owning module hooks that
 * filter to provide the real answer. Modules talk to each other only through these
 * functions, the actions documented in ARCHITECTURE.md, and the Contracts\ interfaces.
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;

/** True when a module is enabled and booted on this request. */
function stackless_module_active(string $id): bool
{
    return class_exists(\Stackless\Core\Plugin::class) && \Stackless\Core\Plugin::instance()->modules()->isActive($id);
}

/* ---------------------------------------------------------------------------
 * Subscriptions (answered by Stackless; neutral defaults in Lite)
 * ------------------------------------------------------------------------- */

/** @return \Stackless\Contracts\SubscriptionData|null */
function stackless_get_subscription($subscription_id)
{
    return apply_filters('stackless_get_subscription', null, (int) $subscription_id);
}

/**
 * @param array $args status => string[] (internal slugs from SubscriptionData), product_id => int
 * @return \Stackless\Contracts\SubscriptionData[]
 */
function stackless_get_customer_subscriptions(int $user_id, array $args = []): array
{
    return (array) apply_filters('stackless_get_customer_subscriptions', [], $user_id, $args);
}

/** Active, trialing or pending-cancel (still inside a paid period). $product_id 0 = any product. */
function stackless_customer_has_active_subscription(int $user_id, int $product_id = 0): bool
{
    return (bool) apply_filters('stackless_customer_has_active_subscription', false, $user_id, $product_id);
}

/** @param \WC_Product|int $product */
function stackless_is_subscription_product($product): bool
{
    return (bool) apply_filters('stackless_is_subscription_product', false, $product);
}

/** Subscriptions created by, or renewed by, this order. @return \Stackless\Contracts\SubscriptionData[] */
function stackless_get_order_subscriptions(\WC_Order $order): array
{
    return (array) apply_filters('stackless_get_order_subscriptions', [], $order);
}

function stackless_order_contains_subscription(\WC_Order $order): bool
{
    return (bool) apply_filters('stackless_order_contains_subscription', false, $order);
}

function stackless_cart_contains_subscription(): bool
{
    return (bool) apply_filters('stackless_cart_contains_subscription', false);
}

/** Number of distinct subscriptions the current cart will create (one per billing schedule). */
function stackless_cart_subscription_count(): int
{
    return (int) apply_filters('stackless_cart_subscription_count', 0);
}

function stackless_is_renewal_order(\WC_Order $order): bool
{
    return (bool) apply_filters('stackless_is_renewal_order', false, $order);
}

/**
 * True when the gateway must save a reusable payment method for this order
 * (it contains a subscription set to renew automatically). Gateways read this.
 */
function stackless_order_requires_saved_payment_method(\WC_Order $order): bool
{
    return (bool) apply_filters('stackless_order_requires_saved_payment_method', false, $order);
}

/** Cart-time version of the above, for showing "save card" UI and SetupIntent flows. */
function stackless_cart_requires_saved_payment_method(): bool
{
    return (bool) apply_filters('stackless_cart_requires_saved_payment_method', false);
}

/**
 * Create a pending renewal order for a subscription (copies items, addresses, currency,
 * payment method). Used by gateway-managed billing (Polar) when the gateway reports a charge.
 *
 * @param \Stackless\Contracts\SubscriptionData $subscription
 * @return \WC_Order|\WP_Error
 */
function stackless_create_renewal_order($subscription)
{
    return apply_filters('stackless_create_renewal_order', new \WP_Error('stackless_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite')), $subscription);
}

/**
 * Page through subscriptions for the REST API and other admin tooling.
 *
 * @param array $args status => string[] (internal slugs), customer_id => int, product_id => int,
 *                    page => int (1-based), per_page => int (1-100)
 * @return array{items: \Stackless\Contracts\SubscriptionData[], total: int}
 */
function stackless_query_subscriptions(array $args = []): array
{
    $result = apply_filters('stackless_query_subscriptions', null, $args);
    return is_array($result) ? $result + ['items' => [], 'total' => 0] : ['items' => [], 'total' => 0];
}

/**
 * The parent order plus the renewal and switch orders of a subscription, newest first.
 *
 * @return \WC_Order[]
 */
function stackless_get_subscription_orders(int $subscription_id): array
{
    return (array) apply_filters('stackless_get_subscription_orders', [], $subscription_id);
}

/**
 * Cancel a subscription now, or at the end of the paid period.
 *
 * @param string $by admin|customer|system (shown in the subscription notes)
 * @return true|\WP_Error
 */
function stackless_cancel_subscription(int $subscription_id, bool $at_period_end = true, string $by = 'admin')
{
    return apply_filters('stackless_cancel_subscription', new \WP_Error('stackless_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite')), $subscription_id, $at_period_end, $by);
}

/**
 * Withdraw a pending cancellation, or resume an on-hold subscription.
 *
 * @return true|\WP_Error
 */
function stackless_reactivate_subscription(int $subscription_id, string $by = 'admin')
{
    return apply_filters('stackless_reactivate_subscription', new \WP_Error('stackless_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite')), $subscription_id, $by);
}

/* ---------------------------------------------------------------------------
 * Software licensing (answered by Stackless; neutral defaults in Lite)
 * ------------------------------------------------------------------------- */

/**
 * One license as an array: id, license_key, product_id, variation_id, order_id, subscription_id,
 * user_id, customer_email, status (stored), effective_status (expiry applied), activation_limit,
 * activations (count), expires_at, created_at, updated_at (dates in UTC, Y-m-d H:i:s). Null when unknown.
 *
 * @return array<string,mixed>|null
 */
function stackless_get_license(int $license_id): ?array
{
    $license = apply_filters('stackless_get_license', null, $license_id);
    return is_array($license) ? $license : null;
}

/**
 * Licenses of an account, including guest purchases made with the same email, newest first.
 * Each item has the stackless_get_license() shape.
 *
 * @return array<int,array<string,mixed>>
 */
function stackless_get_customer_licenses(int $user_id, string $email = ''): array
{
    return (array) apply_filters('stackless_get_customer_licenses', [], $user_id, $email);
}

/*
 * Filters other plugins may call (answered by their owning module while it is active):
 *
 * apply_filters('stackless_subscription_admin_url', '', $subscription_id): string    (Subscriptions)
 *     The subscription's edit screen. Accepts the id or a SubscriptionData object.
 * apply_filters('stackless_license_admin_url', '', int $license_id): string          (Software Licensing)
 *     The license's screen under Stackless → Licenses.
 * apply_filters('stackless_license_product_terms', null, \WC_Product|int $product): ?array (Software Licensing)
 *     A licensed product's or variation's terms: activation_limit (int, 0 = unlimited sites),
 *     validity (lifetime|subscription|days), validity_days (int for "days", else null), version,
 *     requires_wp, tested_wp, requires_php ('' when unset) and software_type (plugin|theme).
 *     Null when the product is not licensed or the module is off.
 * apply_filters('stackless_invoice_admin_url', '', int $invoice_id): string          (Custom Invoices)
 *     The invoice's screen under Stackless → Invoices.
 */

/* ---------------------------------------------------------------------------
 * Invoices and quotes (owners: Modules/CustomInvoices, Modules/RequestQuotes)
 * ------------------------------------------------------------------------- */

/**
 * Issued invoices (never drafts) of an account, plus guest invoices sent to its email addresses
 * or to $email, newest first. Each item: id, number, title, status (sent|overdue|paid|cancelled|
 * refunded), status_label, open (awaiting payment or overdue), total (decimal string), currency,
 * due_date (Y-m-d, '' when due on receipt), issued_at (UTC Y-m-d H:i:s or ''), admin_url,
 * customer_url (the signed invoice page) and pay_url ('' unless it can be paid now).
 * Empty when Custom Invoices is off.
 *
 * @param array $args open => bool (only open invoices), number => int (1-100, default 20)
 * @return array<int,array<string,mixed>>
 */
function stackless_get_customer_invoices(int $user_id, string $email = '', array $args = []): array
{
    return (array) apply_filters('stackless_get_customer_invoices', [], $user_id, $email, $args);
}

/**
 * Quote requests made with an account's email addresses or with $email, newest first. Each item:
 * id, number, title (product, or the start of the message), status (requested|proposal|offered|
 * expired|accepted|declined|withdrawn), status_label, open (waiting on the store or the
 * customer), total (decimal string, '' before a proposal), currency, due_date (the proposal's
 * valid-through date, Y-m-d or ''), admin_url and customer_url (the proposal page once it was
 * sent, else ''). Empty when Request a Quote is off.
 *
 * @param array $args open => bool (only open quotes), number => int (1-100, default 20)
 * @return array<int,array<string,mixed>>
 */
function stackless_get_customer_quotes(int $user_id, string $email = '', array $args = []): array
{
    return (array) apply_filters('stackless_get_customer_quotes', [], $user_id, $email, $args);
}

/**
 * Output of the stackless_account_item_actions action for one item in My Account: buttons other
 * plugins add beside a subscription or a license (a help desk's "Get help"). Templates print it
 * inside their own actions area, so nothing renders when no one hooks in.
 *
 * @param string $type subscription|license
 * @param mixed  $data subscription: the \Stackless\Contracts\SubscriptionData object;
 *                     license: the license array in the stackless_get_license() shape
 */
function stackless_account_item_actions_html(string $type, int $id, $data): string
{
    ob_start();
    do_action('stackless_account_item_actions', $type, $id, $data);
    return trim((string) ob_get_clean());
}

/* ---------------------------------------------------------------------------
 * Currency (answered by Stackless; neutral defaults in Lite)
 * ------------------------------------------------------------------------- */

/** The settlement currency for the current visitor's cart. */
function stackless_current_currency(): string
{
    return (string) apply_filters('stackless_current_currency', get_woocommerce_currency());
}

/** Store base currency, unaffected by the visitor's choice. */
function stackless_base_currency(): string
{
    return (string) get_option('woocommerce_currency', 'USD');
}

/**
 * Decimals of the store's base currency. Runtime prices, add-on charges and bump amounts are
 * base-currency figures; wc_get_price_decimals() follows the shopper's currency (0 for INR or JPY).
 */
function stackless_base_price_decimals(): int
{
    return max(0, (int) get_option('woocommerce_price_num_decimals', 2));
}

/** @return string[] ISO codes enabled for checkout (base currency only when the module is off). */
function stackless_enabled_currencies(): array
{
    return (array) apply_filters('stackless_enabled_currencies', [stackless_base_currency()]);
}

/**
 * Convert an amount between currencies at the configured rate (no charm rounding).
 * $to defaults to the visitor's currency, $from to the base currency. Returns the input unchanged when the module is off.
 *
 * @param float|string $amount
 * @return float|string
 */
function stackless_convert_amount($amount, string $to = '', string $from = '')
{
    return apply_filters('stackless_convert_amount', $amount, $to, $from);
}

/** Effective rate (markup included) from base to $currency; 0 when unknown, 1 when the module is off. */
function stackless_currency_rate(string $currency = ''): float
{
    return (float) apply_filters('stackless_currency_rate', 1.0, $currency);
}

/**
 * Switch the visitor's cart currency. Result is a WP_Error or true; false when the module is off.
 *
 * @return true|false|\WP_Error
 */
function stackless_switch_currency(string $currency, string $source = 'api')
{
    return apply_filters('stackless_switch_currency', false, $currency, $source);
}

/** A subscription signup fee in $currency (price book first, then conversion). */
function stackless_currency_signup_fee(float $fee, \WC_Product $product, string $currency = ''): float
{
    return (float) apply_filters('stackless_currency_signup_fee', $fee, $product, $currency);
}

/* ---------------------------------------------------------------------------
 * AI agents (dispatcher: Core\Agent; families come from each module)
 * ------------------------------------------------------------------------- */

/**
 * Run an agent command as the signed-in user, e.g. stackless_agent('invoice.list') or
 * stackless_agent('subscription.get', ['id' => 42]). "commands" lists what this site supports.
 * Returns ['ok' => bool, ...]; never throws.
 */
function stackless_agent(string $command, array $input = []): array
{
    return \Stackless\Core\Agent::call($command, $input);
}

/* ---------------------------------------------------------------------------
 * Templates
 * ------------------------------------------------------------------------- */

/** Load a template, overridable from the theme at /stackless/{name}. */
function stackless_get_template(string $name, array $args = []): void
{
    wc_get_template($name, $args, 'stackless/', STACKLESS_PLUGIN_PATH . 'templates/');
}
