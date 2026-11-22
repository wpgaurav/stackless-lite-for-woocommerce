<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * The check that runs when settings are saved. It signs in with a fresh token and only reads:
 * payments, the account's Checkout Sessions (which also proves the account id and API access)
 * and disputes.
 */
final class Connection
{
    /** @return string[] Problems to show. */
    public static function problems(bool $enabled): array
    {
        $problems = [];
        foreach (['live', 'test'] as $mode) {
            $values = [
                Settings::credential('client_id', $mode),
                Settings::credential('api_key', $mode),
                Settings::credential('account_id', $mode),
            ];
            $current = $enabled && $mode === Settings::mode();
            if (!array_filter($values) && !$current) {
                continue;
            }
            $label = $mode === 'live' ? __('Live', 'stackless-lite') : __('Sandbox', 'stackless-lite');
            if (count(array_filter($values)) < count($values)) {
                /* translators: %s: Live or Sandbox */
                $problems[] = sprintf(__('%s: Airwallex needs the client ID, the API key and the account ID.', 'stackless-lite'), $label);
                continue;
            }
            foreach (self::check(Settings::api($mode)) as $problem) {
                $problems[] = $label . ': ' . $problem;
            }
            if ($current && Settings::secret($mode) === '') {
                /* translators: %s: Live or Sandbox */
                $problems[] = sprintf(__('%s: add the webhook secret. Without it every webhook is refused.', 'stackless-lite'), $label);
            }
        }
        return $problems;
    }

    /** @return string[] */
    public static function check(Api $api): array
    {
        $api->forgetToken();
        $payments = $api->request('GET', 'pa/payment_intents', ['page_size' => 1]);
        if (is_wp_error($payments)) {
            return [self::explain($payments, __('Payment Acceptance (write)', 'stackless-lite'))];
        }
        $problems = [];
        $sessions = $api->request('GET', 'checkout/checkout_sessions', ['account_id' => $api->account(), 'page_size' => 1]);
        if (is_wp_error($sessions)) {
            $problems[] = self::explain($sessions, __('Checkout Sessions (write)', 'stackless-lite'))
                . ' ' . __('Also check the account ID, and ask Airwallex whether Checkout Session is available for this account.', 'stackless-lite');
        }
        $disputes = $api->request('GET', 'pa/payment_disputes', ['size' => 1]);
        if (is_wp_error($disputes)) {
            $problems[] = self::explain($disputes, __('Payment Disputes (read)', 'stackless-lite'));
        }
        return $problems;
    }

    private static function explain(\WP_Error $error, string $permission): string
    {
        if (in_array(Api::status($error), [401, 403], true) && Api::wasSent($error)) {
            /* translators: %s: API key permission */
            return sprintf(__('Airwallex refused access. The API key needs %s for this account; if it covers several accounts, tick that option.', 'stackless-lite'), $permission);
        }
        return $error->get_error_message();
    }
}
