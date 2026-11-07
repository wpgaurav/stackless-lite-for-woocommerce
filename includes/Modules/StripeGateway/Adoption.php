<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * stackless_stripe_gateway_adopt_payment_method (2.0.0): turns a payment method saved by the
 * official WooCommerce Stripe plugin into a stripe_gateway token, so subscriptions moved from
 * WooCommerce Subscriptions keep renewing on the same card or bank account.
 *
 * Same Stripe account, so the PaymentMethod (pm_…, or a legacy card_/src_ id) is read back
 * from Stripe and must already be attached to the given customer. The token keeps the real
 * method type: SEPA Direct Debit stays a SEPA token, a card a card token.
 */
final class Adoption
{
    /**
     * @param mixed $value  null, or an earlier answer (kept as is).
     * @param mixed $args   ['payment_method' => 'pm_…', 'customer' => 'cus_…', 'user_id' => int, 'mode' => 'live'|'test']
     * @return int|\WP_Error|mixed Token id, or WP_Error.
     */
    public static function adoptPaymentMethod($value, $args = [])
    {
        if ($value !== null) {
            return $value;
        }
        $args = is_array($args) ? $args : [];
        $pm_id = trim((string) ($args['payment_method'] ?? ''));
        $customer = trim((string) ($args['customer'] ?? ''));
        $user_id = max(0, (int) ($args['user_id'] ?? 0));
        $mode = ($args['mode'] ?? '') === 'live' ? 'live' : (($args['mode'] ?? '') === 'test' ? 'test' : '');

        $problem = self::validate($pm_id, $customer, $mode);
        if ($problem !== '') {
            return new \WP_Error('stackless_stripe_adopt_invalid', $problem);
        }
        if (!Settings::isConfigured($mode)) {
            /* translators: %s: test or live */
            return new \WP_Error('stackless_stripe_adopt_keys', sprintf(__('Add the Stripe %s keys before moving saved payment methods.', 'stackless-lite'), $mode));
        }

        $existing = Tokens::findByPaymentMethod($pm_id, $user_id);
        if ($existing) {
            return (int) $existing[0]->get_id();
        }

        $client = Settings::client($mode);
        try {
            $pm = $client->get('payment_methods/' . rawurlencode($pm_id));
        } catch (StripeApiException $e) {
            Settings::log('Adopting ' . $pm_id . ' failed: ' . $e->logSummary(), 'warning');
            return new \WP_Error('stackless_stripe_adopt_lookup', $e->isResourceMissing()
                ? __('Stripe has no such payment method on this account.', 'stackless-lite')
                : $e->getMessage());
        }
        $attached = $pm['customer'] ?? '';
        $attached = is_array($attached) ? (string) ($attached['id'] ?? '') : (string) $attached;
        if ($attached === '' || !hash_equals($attached, $customer)) {
            return new \WP_Error('stackless_stripe_adopt_customer', __('The payment method is not attached to that Stripe customer, so it cannot be charged for renewals.', 'stackless-lite'));
        }

        $token = Tokens::saveFromPaymentMethod($pm, $user_id, $customer, $mode);
        if (!$token) {
            return new \WP_Error('stackless_stripe_adopt_save', __('The payment method could not be saved as a token.', 'stackless-lite'));
        }
        if ($user_id > 0 && Customers::forUser($user_id, $client, false) === '') {
            // Later checkouts reuse the customer the method is attached to.
            update_user_meta($user_id, Customers::userMetaKey($mode), $customer);
        }
        Settings::log(sprintf('Adopted %s (%s) as token %d for user %d.', $pm_id, (string) ($pm['type'] ?? ''), $token->get_id(), $user_id), 'info');
        return (int) $token->get_id();
    }

    /** Why the arguments cannot be adopted, or ''. */
    public static function validate(string $pm_id, string $customer, string $mode): string
    {
        if (!preg_match('/^(pm|card|src)_[A-Za-z0-9]+$/', $pm_id)) {
            return __('A Stripe payment method id (pm_…) is required.', 'stackless-lite');
        }
        if (!preg_match('/^cus_[A-Za-z0-9]+$/', $customer)) {
            return __('A Stripe customer id (cus_…) is required.', 'stackless-lite');
        }
        if ($mode === '') {
            return __('The Stripe mode (live or test) is required.', 'stackless-lite');
        }
        return '';
    }
}
