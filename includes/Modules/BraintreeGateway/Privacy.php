<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Policy text, and erasure of the Braintree customer ids this site keeps. WooCommerce's own
 * eraser removes saved cards, which also deletes them from the Braintree vault.
 */
final class Privacy
{
    public static function registerHooks(): void
    {
        add_action('admin_init', [self::class, 'policy']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Stackless: Braintree', 'stackless-lite'),
            wp_kses_post('<p>' . __('When you pay by card, your card details go straight to Braintree, a PayPal service, which processes the payment. We send Braintree the amount, your name, email and billing address, and, when fraud checks are on, data about your device. We keep Braintree’s payment and customer references and, if you save a card, its brand, last four digits and expiry date.', 'stackless-lite') . '</p>')
        );
    }

    /** @param array $erasers @return array */
    public static function erasers($erasers)
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['stackless-braintree_gateway'] = ['eraser_friendly_name' => __('Stackless: Braintree', 'stackless-lite'), 'callback' => [self::class, 'erase']];
        return $erasers;
    }

    /**
     * @param string $email
     * @param int $page
     * @return array{items_removed:bool, items_retained:bool, messages:string[], done:bool}
     */
    public static function erase($email, $page = 1): array
    {
        $user = get_user_by('email', (string) $email);
        $removed = false;
        if ($user) {
            foreach (array_keys((array) get_user_meta($user->ID)) as $key) {
                if (strpos((string) $key, Meta::USER_CUSTOMER) === 0) {
                    delete_user_meta($user->ID, (string) $key);
                    $removed = true;
                }
            }
        }
        $messages = $removed ? [__('The customer record and payment history at Braintree are kept there. Remove the customer in the Braintree Control Panel if needed.', 'stackless-lite')] : [];
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => $messages, 'done' => true];
    }
}
