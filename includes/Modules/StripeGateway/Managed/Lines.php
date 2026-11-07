<?php

namespace Stackless\Modules\StripeGateway\Managed;

defined('ABSPATH') || exit;

/**
 * Pure builder for the inline line items of a Managed Payments Checkout Session.
 *
 * Nothing is created in the Stripe catalog: every line carries price_data. The rules keep
 * the money exact to the lowest denomination:
 *
 * - One-time orders: one line per order line (item totals after discounts, fees) when they
 *   add up to the order total; otherwise one line for the whole order (negative fees,
 *   WooCommerce tax, rounding drift).
 * - Subscriptions: one recurring line for the subscription's recurring total plus, when the
 *   first payment is larger than a cycle, one one-time line for the difference (signup fee,
 *   one-time products). Stripe puts one-time lines on the first invoice only. During a free
 *   trial the whole first payment is one-time. A first payment smaller than a full cycle
 *   outside a trial cannot be represented and is refused.
 *
 * Stripe, as merchant of record, adds sales tax or VAT according to tax_behavior.
 */
final class Lines
{
    public const ERROR_FIRST_PAYMENT = 'first_payment';
    public const ERROR_ZERO = 'zero';
    public const ERROR_INTERVAL = 'interval';

    /** Longest billing interval Stripe allows: three years. */
    private const MAX_COUNT = ['day' => 1095, 'week' => 156, 'month' => 36, 'year' => 3];

    /**
     * @param int $totalMinor Order total in the lowest denomination.
     * @param array<int,array{name:string,amount:int}> $lines One-time order lines.
     * @param array{name:string,amount:int,period:string,interval:int,trial_days:int}|null $recurring
     * @param array{currency:string,label:string,tax_code:string,tax_behavior:string} $context
     * @return array{line_items:array<int,array>,trial_days:int}|string Line items, or an ERROR_* code.
     */
    public static function build(int $totalMinor, array $lines, ?array $recurring, array $context)
    {
        if ($recurring === null) {
            if ($totalMinor <= 0) {
                return self::ERROR_ZERO;
            }
            $items = [];
            foreach (self::splitLines($totalMinor, $lines) as $line) {
                $items[] = self::item($line['name'], $line['amount'], null, $context);
            }
            return ['line_items' => $items, 'trial_days' => 0];
        }

        if ($recurring['amount'] <= 0) {
            return self::ERROR_ZERO;
        }
        $cycle = self::cycle($recurring['period'], (int) $recurring['interval']);
        if ($cycle === null) {
            return self::ERROR_INTERVAL;
        }
        $trialDays = max(0, (int) $recurring['trial_days']);
        $first = $trialDays > 0 ? 0 : $recurring['amount'];
        $oneTime = $totalMinor - $first;
        if ($oneTime < 0) {
            return self::ERROR_FIRST_PAYMENT;
        }

        $items = [self::item($recurring['name'], $recurring['amount'], $cycle, $context)];
        if ($oneTime > 0) {
            $items[] = self::item(self::oneTimeName($context['label']), $oneTime, null, $context);
        }
        return ['line_items' => $items, 'trial_days' => $trialDays];
    }

    /**
     * WooCommerce period and interval → Stripe recurring[interval]/[interval_count], or null
     * when Stripe cannot bill it (longer than three years, or an unknown period).
     *
     * @return array{interval:string,interval_count:int}|null
     */
    public static function cycle(string $period, int $interval): ?array
    {
        $period = strtolower($period);
        if (!isset(self::MAX_COUNT[$period]) || $interval < 1 || $interval > self::MAX_COUNT[$period]) {
            return null;
        }
        return ['interval' => $period, 'interval_count' => $interval];
    }

    /**
     * Order lines when they add up exactly, else one line for the whole total.
     *
     * @param array<int,array{name:string,amount:int}> $lines
     * @return array<int,array{name:string,amount:int}>
     */
    public static function splitLines(int $totalMinor, array $lines): array
    {
        $kept = [];
        $sum = 0;
        foreach ($lines as $line) {
            $amount = (int) ($line['amount'] ?? 0);
            if ($amount < 0) {
                $kept = [];
                break;
            }
            if ($amount === 0) {
                continue;
            }
            $kept[] = ['name' => (string) $line['name'], 'amount' => $amount];
            $sum += $amount;
        }
        if (!$kept || $sum !== $totalMinor) {
            return [['name' => '', 'amount' => $totalMinor]];
        }
        return $kept;
    }

    /**
     * One Checkout line with inline price_data.
     *
     * @param array{interval:string,interval_count:int}|null $cycle
     * @param array{currency:string,label:string,tax_code:string,tax_behavior:string} $context
     */
    public static function item(string $name, int $amount, ?array $cycle, array $context): array
    {
        $name = self::clip($name !== '' ? $name : (string) $context['label'], 250);
        $product = ['name' => $name !== '' ? $name : '-'];
        if ($context['tax_code'] !== '') {
            $product['tax_code'] = $context['tax_code'];
        }
        $price = [
            'currency' => strtolower((string) $context['currency']),
            'unit_amount' => $amount,
            'product_data' => $product,
            'tax_behavior' => $context['tax_behavior'] === 'inclusive' ? 'inclusive' : 'exclusive',
        ];
        if ($cycle !== null) {
            $price['recurring'] = $cycle;
        }
        return ['price_data' => $price, 'quantity' => 1];
    }

    public static function oneTimeName(string $label): string
    {
        /* translators: %s: order label such as "Order #123" */
        return sprintf(__('%s: signup fee and one-time items', 'stackless-lite'), $label);
    }

    /** Whole days left in a trial (0 when none). */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) ceil($seconds / 86400) : 0;
    }

    public static function clip(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
