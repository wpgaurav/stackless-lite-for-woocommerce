<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * A user's recent add-a-card sessions. A zero-amount Revolut order saves a card for a user only
 * when it is in that user's list: the metadata on the order is not enough.
 */
final class Adds
{
    private const KEEP = 5;

    /** @return array<int,array<string,mixed>> */
    private static function all(int $userId): array
    {
        $list = $userId > 0 ? get_user_meta($userId, Keys::USER_ADDS, true) : [];
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /**
     * @param array<string,mixed> $values id, mode, currency, customer, subscription
     * @return array<string,mixed>
     */
    public static function begin(int $userId, array $values): array
    {
        $session = array_merge(['subscription' => 0, 'token' => 0], $values, ['ref' => bin2hex(random_bytes(12))]);
        $list = self::all($userId);
        $list[] = $session;
        update_user_meta($userId, Keys::USER_ADDS, array_slice($list, -self::KEEP));
        return $session;
    }

    /** @return array<string,mixed>|null */
    public static function byRef(int $userId, string $ref): ?array
    {
        foreach (self::all($userId) as $session) {
            if ($ref !== '' && hash_equals((string) ($session['ref'] ?? ''), $ref)) {
                return $session;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $userId, string $id, string $mode): ?array
    {
        foreach (self::all($userId) as $session) {
            if ($id !== '' && (string) ($session['id'] ?? '') === $id && (string) ($session['mode'] ?? '') === $mode) {
                return $session + ['token' => 0];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $changes
     */
    public static function update(int $userId, array $session, array $changes): void
    {
        $list = self::all($userId);
        foreach ($list as $index => $stored) {
            if (($stored['ref'] ?? '') === ($session['ref'] ?? null)) {
                $list[$index] = array_merge($stored, $changes);
            }
        }
        update_user_meta($userId, Keys::USER_ADDS, $list);
    }
}
