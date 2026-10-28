<?php

namespace Stackless\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * Pure builder for the non-catalog items of a Paddle transaction.
 *
 * Paddle bills whatever prices the transaction carries, so nothing has to be
 * created in the Paddle catalog first. The rules that keep the money exact:
 *
 * - One-time orders: one item per order line, shipping and fee when they add up
 *   to the order total to the lowest denomination; otherwise one item for the
 *   whole order (negative fees, tax WooCommerce added itself, rounding drift).
 * - Subscriptions: one recurring item for the subscription's recurring total
 *   (what Paddle charges every cycle) plus, when the first payment is larger, one
 *   one-time item for the difference (signup fee, one-time products, first
 *   shipping). A first payment smaller than a full cycle (outside a free trial)
 *   cannot be represented and is refused.
 */
final class Items
{
    public const ERROR_FIRST_PAYMENT = 'first_payment';
    public const ERROR_TRIAL_CHARGE = 'trial_charge';
    public const ERROR_ZERO = 'zero';

    /**
     * @param int    $totalMinor Order total in the lowest denomination.
     * @param array<int,array{name:string,amount:int}> $lines One-time order lines (line totals, shipping, fees).
     * @param array{name:string,amount:int,cycle:array{interval:string,frequency:int},trial_days:int}|null $recurring
     * @param array{currency:string,label:string,tax_mode:string,tax_category:string} $context
     * @return array<int,array>|string Paddle items, or an ERROR_* code.
     */
    public static function build(int $totalMinor, array $lines, ?array $recurring, array $context)
    {
        if ($recurring === null) {
            if ($totalMinor <= 0) {
                return self::ERROR_ZERO;
            }
            $split = self::splitLines($totalMinor, $lines);
            $items = [];
            foreach ($split as $line) {
                $items[] = self::item($line['name'], $line['amount'], null, 0, $context);
            }
            return $items;
        }

        if ($recurring['amount'] <= 0) {
            return self::ERROR_ZERO;
        }
        $trialDays = max(0, (int) $recurring['trial_days']);
        $first = $trialDays > 0 ? 0 : $recurring['amount'];
        $oneTime = $totalMinor - $first;
        if ($oneTime < 0) {
            return self::ERROR_FIRST_PAYMENT;
        }
        if ($oneTime > 0 && $trialDays > 0) {
            return self::ERROR_TRIAL_CHARGE;
        }

        $items = [self::item($recurring['name'], $recurring['amount'], $recurring['cycle'], $trialDays, $context)];
        if ($oneTime > 0) {
            $items[] = self::item(self::oneTimeName($context['label']), $oneTime, null, 0, $context);
        }
        return $items;
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
     * One non-catalog item.
     *
     * @param array{interval:string,frequency:int}|null $cycle
     * @param array{currency:string,label:string,tax_mode:string,tax_category:string} $context
     */
    public static function item(string $name, int $amount, ?array $cycle, int $trialDays, array $context): array
    {
        $name = self::clip($name !== '' ? $name : (string) $context['label'], 150);
        $price = [
            'description' => str_pad($name, 2, '.'),
            'name' => $name,
            'unit_price' => ['amount' => (string) $amount, 'currency_code' => strtoupper((string) $context['currency'])],
            'tax_mode' => in_array($context['tax_mode'], ['account_setting', 'external', 'internal'], true) ? $context['tax_mode'] : 'account_setting',
            'quantity' => ['minimum' => 1, 'maximum' => 1],
            'product' => [
                'name' => $name,
                'tax_category' => $context['tax_category'] !== '' ? $context['tax_category'] : 'standard',
            ],
        ];
        if ($cycle !== null) {
            $price['billing_cycle'] = ['interval' => $cycle['interval'], 'frequency' => (int) $cycle['frequency']];
            if ($trialDays > 0) {
                $price['trial_period'] = ['interval' => 'day', 'frequency' => $trialDays];
            }
        }
        return ['quantity' => 1, 'price' => $price];
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
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)) ?? '');
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
