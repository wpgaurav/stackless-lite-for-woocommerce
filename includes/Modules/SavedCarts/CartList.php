<?php

namespace Stackless\Modules\SavedCarts;

defined('ABSPATH') || exit;

/**
 * Pure rules for saved carts: what is kept from a cart line, how lists are cleaned,
 * and the limits on lists and lines.
 */
final class CartList
{
    public const MAX_CARTS = 10;
    public const MAX_ITEMS = 50;
    public const MAX_QTY = 10000;
    public const MAX_NAME = 100;

    /**
     * Cart item data worth keeping: only this plugin's own keys (stackless_*), with scalar
     * or nested-scalar values, so restoring never resurrects objects or another plugin's state.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function keepData(array $item): array
    {
        $out = [];
        foreach ($item as $key => $value) {
            if (!is_string($key) || strpos($key, 'stackless_') !== 0 || !self::plain($value)) {
                continue;
            }
            $out[$key] = $value;
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($out) : json_encode($out);
        return is_string($json) && strlen($json) <= 8000 ? $out : [];
    }

    /** @param mixed $value */
    private static function plain($value, int $depth = 0): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }
        if (!is_array($value) || $depth > 3) {
            return false;
        }
        foreach ($value as $v) {
            if (!self::plain($v, $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Turn cart contents into storable lines.
     *
     * @param array<string, array<string, mixed>> $contents WC()->cart->get_cart()
     * @return array<int, array<string, mixed>>
     */
    public static function fromCart(array $contents): array
    {
        $lines = [];
        foreach ($contents as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product_id = (int) ($item['product_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }
            $variation = [];
            foreach ((array) ($item['variation'] ?? []) as $attr => $value) {
                if (is_string($attr) && strpos($attr, 'attribute_') === 0 && is_scalar($value)) {
                    $variation[$attr] = (string) $value;
                }
            }
            $lines[] = [
                'product_id' => $product_id,
                'variation_id' => max(0, (int) ($item['variation_id'] ?? 0)),
                'variation' => $variation,
                'quantity' => (float) min(self::MAX_QTY, $quantity),
                'data' => self::keepData($item),
            ];
            if (count($lines) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $lines;
    }

    /**
     * Clean a stored list (user meta can be anything).
     *
     * @param mixed $raw
     * @return array<string, array{name:string, created:int, items: array<int, array<string, mixed>>}>
     */
    public static function normalise($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $entry) {
            if (!is_string($key) || !preg_match('/^[a-f0-9\-]{8,40}$/', $key) || !is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            $items = [];
            foreach ((array) ($entry['items'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $product = (int) ($line['product_id'] ?? 0);
                $qty = (float) ($line['quantity'] ?? 0);
                if ($product <= 0 || $qty <= 0) {
                    continue;
                }
                $variation = [];
                foreach ((array) ($line['variation'] ?? []) as $attr => $value) {
                    if (is_string($attr) && strpos($attr, 'attribute_') === 0 && is_scalar($value)) {
                        $variation[$attr] = (string) $value;
                    }
                }
                $items[] = [
                    'product_id' => $product,
                    'variation_id' => max(0, (int) ($line['variation_id'] ?? 0)),
                    'variation' => $variation,
                    'quantity' => (float) min(self::MAX_QTY, $qty),
                    'data' => is_array($line['data'] ?? null) ? self::keepData($line['data']) : [],
                ];
                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
            }
            if ($name === '' || !$items) {
                continue;
            }
            $out[$key] = [
                'name' => mb_substr($name, 0, self::MAX_NAME),
                'created' => max(0, (int) ($entry['created'] ?? 0)),
                'items' => $items,
            ];
            if (count($out) >= self::MAX_CARTS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Add a cart to the list.
     *
     * @param array<string, array<string, mixed>> $list normalised
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     * @throws \LengthException when the list is full
     * @throws \InvalidArgumentException when name or items are empty
     */
    public static function add(array $list, string $key, string $name, array $items, int $now): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || !$items) {
            throw new \InvalidArgumentException('empty');
        }
        if (count($list) >= self::MAX_CARTS) {
            throw new \LengthException('full');
        }
        $list[$key] = ['name' => mb_substr($name, 0, self::MAX_NAME), 'created' => $now, 'items' => array_slice($items, 0, self::MAX_ITEMS)];
        return $list;
    }

    /** Total quantity of a saved cart. @param array<string, mixed> $cart */
    public static function quantity(array $cart): float
    {
        return array_sum(array_map(function ($line) {
            return (float) $line['quantity'];
        }, (array) ($cart['items'] ?? [])));
    }
}
