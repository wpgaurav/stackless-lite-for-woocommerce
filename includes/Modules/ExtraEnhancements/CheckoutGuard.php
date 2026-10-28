<?php

namespace Stackless\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Spam and fraud guard at checkout: refuses throwaway email addresses and slows down
 * a connection that tries to place too many orders in an hour (card testing). Runs on
 * the classic checkout and the Checkout block. Store managers are never limited.
 */
final class CheckoutGuard
{
    /** Well-known throwaway inbox services. Add more in the module settings. */
    public const DISPOSABLE = [
        '10minutemail.com', '1secmail.com', '1secmail.net', '1secmail.org', '20minutemail.com', 'burnermail.io', 'discard.email', 'dispostable.com',
        'dropmail.me', 'emailfake.com', 'emailondeck.com', 'fakeinbox.com', 'fakemail.net', 'getnada.com', 'grr.la', 'guerrillamail.biz',
        'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'inboxkitten.com',
        'mail.tm', 'mail7.io', 'mailcatch.com', 'maildrop.cc', 'mailinator.com', 'mailinator.net', 'mailnesia.com', 'mailpoof.com', 'mailsac.com',
        'minuteinbox.com', 'mintemail.com', 'moakt.com', 'mohmal.com', 'mytemp.email', 'pokemail.net', 'sharklasers.com', 'spam4.me', 'spambox.us',
        'spamgourmet.com', 'tempail.com', 'tempinbox.com', 'tempmail.com', 'tempmail.net', 'tempmailaddress.com', 'tempmailo.com', 'temp-mail.io',
        'temp-mail.org', 'tempr.email', 'throwawaymail.com', 'tmpmail.net', 'tmpmail.org', 'trash-mail.com', 'trashmail.com', 'trashmail.de',
        'wegwerfmail.de', 'yopmail.com', 'yopmail.fr', 'yopmail.net',
    ];

    /** @var bool */
    private $blockDisposable;

    /** @var string[] */
    private $extraDomains;

    /** @var int */
    private $hourlyLimit;

    /** @var bool Counted once per request, whichever checkout hook runs first. */
    private $counted = false;

    /** @param string[] $extraDomains */
    public function __construct(bool $blockDisposable, array $extraDomains, int $hourlyLimit)
    {
        $this->blockDisposable = $blockDisposable;
        $this->extraDomains = $extraDomains;
        $this->hourlyLimit = max(0, $hourlyLimit);
    }

    /** One domain per line, comma or space; "@example.com" also works. @return string[] */
    public static function parseDomains(string $value): array
    {
        $domains = [];
        foreach ((array) preg_split('/[\s,;]+/', strtolower($value)) as $domain) {
            $domain = ltrim(trim((string) $domain), '@');
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                $domains[] = $domain;
            }
        }
        return array_values(array_unique($domains));
    }

    public function register(): void
    {
        if (!$this->blockDisposable && !$this->extraDomains && $this->hourlyLimit === 0) {
            return;
        }
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassic'], 5, 2);
        add_action('woocommerce_checkout_validate_order_before_payment', [$this, 'validateStoreApi'], 5, 2);
    }

    public function blockedDomain(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, $at + 1)));
        $blocked = array_merge($this->blockDisposable ? self::DISPOSABLE : [], $this->extraDomains);
        foreach ($blocked as $entry) {
            // Subdomains of a blocked service count too.
            if ($domain === $entry || substr($domain, -strlen('.' . $entry)) === '.' . $entry) {
                return true;
            }
        }
        return false;
    }

    /** Null when the attempt may go ahead, else the message shown to the buyer. */
    public function refusal(string $email): ?string
    {
        if (current_user_can('manage_woocommerce')) {
            return null;
        }
        if ($email !== '' && $this->blockedDomain($email)) {
            return __('Please use an email address you keep. We send your receipt and downloads there.', 'stackless-lite');
        }
        if ($this->hourlyLimit > 0 && !$this->counted) {
            $this->counted = true;
            if (!$this->withinLimit(self::clientIp())) {
                return __('Too many order attempts from your connection. Please try again in an hour, or contact us for help.', 'stackless-lite');
            }
        }
        return null;
    }

    /**
     * @param mixed $data
     * @param mixed $errors
     */
    public function validateClassic($data, $errors): void
    {
        if (!$errors instanceof \WP_Error || !is_array($data)) {
            return;
        }
        $message = $this->refusal((string) ($data['billing_email'] ?? ''));
        if ($message !== null) {
            $errors->add('stackless_checkout_guard', $message);
        }
    }

    /**
     * @param mixed $order
     * @param mixed $errors
     */
    public function validateStoreApi($order, $errors): void
    {
        if (!$order instanceof \WC_Order || !$errors instanceof \WP_Error) {
            return;
        }
        $message = $this->refusal((string) $order->get_billing_email());
        if ($message !== null) {
            $errors->add('stackless_checkout_guard', $message);
        }
    }

    public static function clientIp(): string
    {
        $ip = class_exists(\WC_Geolocation::class) ? (string) \WC_Geolocation::get_ip_address() : '';
        if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return $ip;
    }

    /** Counts order attempts per visitor IP in a rolling hour. Only a salted hash of the IP is stored. */
    public function withinLimit(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $key = 'stackless_extra_enhancements_attempts_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 24);
        $now = time();
        $attempts = array_values(array_filter((array) get_transient($key), static function ($time) use ($now) {
            return (int) $time > $now - HOUR_IN_SECONDS;
        }));
        if (count($attempts) >= $this->hourlyLimit) {
            return false;
        }
        $attempts[] = $now;
        set_transient($key, $attempts, HOUR_IN_SECONDS);
        return true;
    }
}
