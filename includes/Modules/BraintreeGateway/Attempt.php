<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * One charge of one order, written on the order before Braintree is called.
 *
 * `key` is the apiRequestKey (Braintree answers a repeat with the first request's transaction
 * for 30 days) and `ref` the transaction's orderId, which the charge is searched by. A charge
 * stays open until Braintree shows it paid, unpaid or absent; while it is open the order gets
 * no other charge.
 */
final class Attempt
{
    public const FLOW_CHECKOUT = 'checkout';
    public const FLOW_RENEWAL = 'renewal';
    public const FLOW_SAVED = 'saved';

    /** Captured, but the order could not be completed with it. */
    public const HELD = 'held';

    /**
     * @param array<string,mixed> $fields flow, mode, account, merchant_account, minor, currency, token, save, user, present, initiator
     * @return array<string,mixed>
     */
    public static function create(int $orderId, array $fields): array
    {
        $key = bin2hex(random_bytes(16));
        $now = time();
        return [
            'key' => $key,
            'ref' => self::reference($orderId, $key),
            'flow' => (string) ($fields['flow'] ?? self::FLOW_CHECKOUT),
            'mode' => (string) ($fields['mode'] ?? 'test'),
            'account' => (string) ($fields['account'] ?? ''),
            'merchant_account' => (string) ($fields['merchant_account'] ?? ''),
            'minor' => (int) ($fields['minor'] ?? 0),
            'currency' => (string) ($fields['currency'] ?? ''),
            // The store's token id, never Braintree's card id or the one-time nonce.
            'token' => (int) ($fields['token'] ?? 0),
            'save' => !empty($fields['save']),
            'user' => (int) ($fields['user'] ?? 0),
            'present' => !empty($fields['present']),
            'initiator' => (string) ($fields['initiator'] ?? ''),
            'started' => $now,
            'sent' => $now,
            'empty_since' => 0,
            'replays' => 0,
            'checks' => 0,
            'transaction' => '',
            'state' => 'open',
        ];
    }

    /** Unique per site, order and attempt: several stores can share one Braintree merchant. */
    public static function reference(int $orderId, string $key): string
    {
        return 'order-' . $orderId . '-' . substr(hash('sha256', home_url('/')), 0, 8) . '-' . substr($key, 0, 10);
    }

    /** @return array<string,mixed>|null */
    public static function current(int $orderId): ?array
    {
        $attempt = Orders::meta($orderId, Meta::ATTEMPT);
        return is_array($attempt) && !empty($attempt['key']) && !empty($attempt['ref']) ? $attempt : null;
    }

    /**
     * Save an attempt and confirm it can be read back: a charge is only sent once it is on record.
     *
     * @param array<string,mixed> $attempt
     */
    public static function save(int $orderId, array $attempt): bool
    {
        if (!Orders::setMeta($orderId, [Meta::ATTEMPT => $attempt])) {
            return false;
        }
        $stored = self::current($orderId);
        return $stored !== null && hash_equals((string) $attempt['key'], (string) $stored['key']);
    }

    /** @param array<string,mixed> $attempt */
    public static function close(int $orderId, array $attempt): void
    {
        $current = self::current($orderId);
        if ($current && (string) $current['key'] === (string) $attempt['key']) {
            Orders::setMeta($orderId, [Meta::ATTEMPT => null]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function absent(int $orderId): array
    {
        $list = Orders::meta($orderId, Meta::ABSENT);
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** @param array<string,mixed> $attempt */
    public static function closeAbsent(int $orderId, array $attempt): void
    {
        $list = self::absent($orderId);
        $attempt['closed'] = time();
        $list[] = $attempt;
        Orders::setMeta($orderId, [Meta::ABSENT => array_slice($list, -10)]);
        self::close($orderId, $attempt);
    }
}
