<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/** Privacy policy text and the eraser for the Airwallex ids kept on accounts. */
final class Privacy
{
    public static function registerHooks(): void
    {
        add_action('admin_init', [self::class, 'policy']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
    }

    public static function policy(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content(
                __('Stackless for WooCommerce: Airwallex', 'stackless-lite'),
                wp_kses_post(wpautop(__('When you pay with Airwallex we send Airwallex the order total and number, and your name, email address and country, and you enter your payment details on Airwallex’s page. For payments that renew, Airwallex stores your card. We keep only its brand, last four digits, expiry date and Airwallex’s references, until you remove it.', 'stackless-lite')))
            );
        }
    }

    /**
     * @param mixed $erasers
     * @return array<string,mixed>
     */
    public static function erasers($erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['stackless-airwallex'] = [
            'eraser_friendly_name' => __('Airwallex customer references', 'stackless-lite'),
            'callback' => [self::class, 'erase'],
        ];
        return $erasers;
    }

    /** @return array{items_removed:bool, items_retained:bool, messages:string[], done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $removed = $user ? self::forgetUser((int) $user->ID) : false;
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    private static function forgetUser(int $userId): bool
    {
        $removed = false;
        foreach (array_keys((array) get_user_meta($userId)) as $key) {
            $key = (string) $key;
            if ($key === Meta::USER_PENDING_ADD || strpos($key, Meta::USER_CUSTOMER) === 0) {
                delete_user_meta($userId, $key);
                $removed = true;
            }
        }
        return $removed;
    }
}
