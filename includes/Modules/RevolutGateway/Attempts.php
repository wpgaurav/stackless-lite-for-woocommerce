<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * The Revolut orders created for one store order, saved before each is sent.
 *
 * An attempt: ref (our merchant_order_data.reference), id (Revolut order id, '' until the answer
 * arrives), mode, flow (checkout = hosted page, save = widget that saves the card for merchant
 * use, charge = saved-card charge), amount, currency, customer, user (the account the card is saved to), who (a hash of the buyer), at,
 * sent (time the charge was sent), closed (it can no longer take money, or was applied).
 *
 * A payment only changes a store order when its Revolut order is in this list.
 */
final class Attempts
{
    public const FLOW_CHECKOUT = 'checkout';
    public const FLOW_SAVE = 'save';
    public const FLOW_CHARGE = 'charge';

    /** @return array<int,array<string,mixed>> oldest first */
    public static function all(int $orderId): array
    {
        $list = Orders::metaList($orderId, Keys::ATTEMPTS);
        $attempts = [];
        foreach ($list as $attempt) {
            if (is_array($attempt) && isset($attempt['ref']) && is_string($attempt['ref']) && $attempt['ref'] !== '') {
                $attempts[] = $attempt + ['id' => '', 'mode' => 'test', 'flow' => self::FLOW_CHECKOUT, 'amount' => 0, 'currency' => '', 'customer' => '', 'user' => 0, 'who' => '', 'at' => 0, 'sent' => 0, 'closed' => false];
            }
        }
        return $attempts;
    }

    /** @return array<int,array<string,mixed>> attempts that may still take money */
    public static function open(int $orderId): array
    {
        return array_values(array_filter(self::all($orderId), static function ($attempt) {
            return empty($attempt['closed']);
        }));
    }

    /** @return array<string,mixed>|null */
    public static function byRef(int $orderId, string $ref): ?array
    {
        foreach (self::all($orderId) as $attempt) {
            if ($ref !== '' && hash_equals((string) $attempt['ref'], $ref)) {
                return $attempt;
            }
        }
        return null;
    }

    /**
     * The attempt a Revolut order belongs to: the same id and mode, or an attempt whose answer
     * never arrived with the same reference.
     *
     * @param array<string,mixed> $remote
     * @return array<string,mixed>|null
     */
    public static function match(int $orderId, array $remote, string $mode): ?array
    {
        $id = (string) ($remote['id'] ?? '');
        $ref = Remote::reference($remote);
        if ($id === '') {
            return null;
        }
        foreach (self::all($orderId) as $attempt) {
            if ((string) $attempt['mode'] !== $mode) {
                continue;
            }
            if ((string) $attempt['id'] === $id || ((string) $attempt['id'] === '' && $ref !== '' && (string) $attempt['ref'] === $ref)) {
                return $attempt;
            }
        }
        return null;
    }

    /**
     * Save the intent to create a Revolut order.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function begin(int $orderId, array $values): array
    {
        $attempt = array_merge(['id' => '', 'customer' => '', 'user' => 0, 'who' => '', 'sent' => 0, 'closed' => false], $values, [
            'ref' => 'stl-' . $orderId . '-' . bin2hex(random_bytes(8)),
            'at' => time(),
        ]);
        $all = self::all($orderId);
        $all[] = $attempt;
        Orders::setMeta($orderId, [Keys::ATTEMPTS => $all, Keys::MODE => (string) $attempt['mode']]);
        return $attempt;
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public static function update(int $orderId, array $attempt, array $changes): array
    {
        $all = self::all($orderId);
        $updated = array_merge($attempt, $changes);
        foreach ($all as $index => $stored) {
            if ((string) $stored['ref'] === (string) $attempt['ref']) {
                $updated = array_merge($stored, $changes);
                $all[$index] = $updated;
            }
        }
        Orders::setMeta($orderId, [Keys::ATTEMPTS => $all]);
        return $updated;
    }

    /** @param array<string,mixed> $attempt */
    public static function close(int $orderId, array $attempt): void
    {
        self::update($orderId, $attempt, ['closed' => true]);
    }

    /** Who an attempt was made for, without keeping the email on it. */
    public static function buyer(int $userId, string $email): string
    {
        return md5($userId . '|' . strtolower(trim($email)));
    }
}
