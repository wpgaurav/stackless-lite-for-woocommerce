<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Recurring-method rules for Razorpay tokens: what each method can carry and the token block
 * sent with the authorization order.
 *
 * - card: card e-mandate. RBI lets a debit up to ₹15,000 run without the customer approving
 *   it again (AFA), so that is the default ceiling. Authorization of at least ₹1.
 * - upi: UPI Autopay (Intent or QR; UPI Collect closed to new mandates on 2026-02-28). The
 *   mandate can cover up to ₹99,999 for most businesses. Authorization of at least ₹1.
 * - emandate: eNACH by netbanking, debit card or Aadhaar, up to ₹1 crore. The authorization is
 *   ₹0, so the first payment is a separate debit once the bank confirms the mandate.
 *
 * Businesses in categories with higher limits raise them with the
 * stackless_razorpay_gateway_mandate_caps filter (amounts in rupees).
 */
final class Mandate
{
    public const METHODS = ['card', 'upi', 'emandate'];

    /** Default ceilings per method, in rupees. */
    public const CAPS = ['card' => 15000, 'upi' => 99999, 'emandate' => 10000000];

    /** Authorization amount (paise) when the order itself has nothing to charge. */
    public const VERIFY_MINOR = ['card' => 100, 'upi' => 100, 'emandate' => 0];

    /** Mandate validity in years for UPI and eNACH (cards follow the card's expiry). */
    public const VALIDITY_YEARS = 10;

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            'card' => __('Card', 'stackless-lite'),
            'upi' => __('UPI Autopay', 'stackless-lite'),
            'emandate' => __('Bank account (eNACH)', 'stackless-lite'),
        ];
    }

    public static function label(string $method): string
    {
        $labels = self::labels();
        return $labels[$method] ?? $method;
    }

    /** @return array<string,int> method => ceiling in rupees */
    public static function caps(): array
    {
        $caps = function_exists('apply_filters') ? apply_filters('stackless_razorpay_gateway_mandate_caps', self::CAPS) : self::CAPS;
        $clean = self::CAPS;
        foreach ((array) $caps as $method => $cap) {
            if (isset($clean[$method]) && (int) $cap > 0) {
                $clean[$method] = (int) $cap;
            }
        }
        return $clean;
    }

    /**
     * Mandate ceiling for a method: the largest renewal plus the plan-switch allowance,
     * rounded up to whole rupees and held to the method's ceiling. Null when even the largest
     * renewal is above the ceiling, so the method cannot carry this subscription.
     *
     * @param float $largestRenewal Largest single renewal, in rupees.
     * @param int   $allowancePercent Extra room for upgrades, e.g. 50 = 1.5x.
     * @return int|null max_amount in paise
     */
    public static function maxAmount(string $method, float $largestRenewal, int $allowancePercent): ?int
    {
        $caps = self::caps();
        if (!isset($caps[$method])) {
            return null;
        }
        $cap = $caps[$method];
        if ($largestRenewal > $cap) {
            return null;
        }
        $wanted = (int) ceil(max($largestRenewal, 1.0) * (1 + max(0, $allowancePercent) / 100) - 0.000001);
        return min($cap, max(1, $wanted)) * 100;
    }

    /**
     * Methods that can carry renewals up to $largestRenewal rupees, in the store's order.
     *
     * @param string[] $enabled
     * @return string[]
     */
    public static function eligible(array $enabled, float $largestRenewal): array
    {
        $caps = self::caps();
        return array_values(array_filter($enabled, static function ($method) use ($caps, $largestRenewal) {
            return isset($caps[$method]) && $largestRenewal <= $caps[$method];
        }));
    }

    /** Authorization amount (paise) for an order total (paise). */
    public static function authorizationMinor(string $method, int $orderMinor): int
    {
        if ($method === 'emandate') {
            return 0;
        }
        return max($orderMinor, self::VERIFY_MINOR[$method] ?? Money::MINIMUM_MINOR);
    }

    /**
     * The "token" block of an authorization order.
     *
     * @return array<string,mixed>
     */
    public static function tokenBlock(string $method, int $maxMinor, ?int $now = null): array
    {
        $expires = ($now ?? time()) + self::VALIDITY_YEARS * 365 * 86400;
        switch ($method) {
            case 'upi':
                return ['max_amount' => $maxMinor, 'expire_at' => $expires, 'frequency' => 'as_presented'];
            case 'emandate':
                return ['max_amount' => $maxMinor, 'expire_at' => $expires];
            case 'card':
            default:
                // Card mandates last as long as the card unless told otherwise.
                return ['max_amount' => $maxMinor, 'frequency' => 'as_presented'];
        }
    }

    /** Whether the first payment of an order needs its own debit after the mandate is confirmed. */
    public static function needsFirstDebit(string $method, int $orderMinor): bool
    {
        return $method === 'emandate' && $orderMinor > 0;
    }
}
