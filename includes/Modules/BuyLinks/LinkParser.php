<?php

namespace Stackless\Modules\BuyLinks;

defined('ABSPATH') || exit;

/**
 * Pure buy-link logic. Format: ?stackless-buy=ITEM[,ITEM…] where ITEM is
 *   product            → one of the product
 *   product:second     → variation of a variable product, otherwise a quantity
 *   product:variation:qty
 * Optional: coupon=CODE[,CODE], clear=1|0, to=checkout|cart, exp=UNIX, sig=HMAC.
 */
final class LinkParser
{
    public const MAX_ITEMS = 20;
    public const MAX_QTY = 9999;
    public const SIG_LENGTH = 32;

    /**
     * @return array{items: array[], errors: string[]} items: product, second, variation, qty, parts
     */
    public static function parse(string $raw): array
    {
        $items = [];
        $errors = [];
        $raw = trim(str_replace(' ', '', $raw));
        if ($raw === '') {
            return ['items' => [], 'errors' => ['empty']];
        }
        foreach (array_slice(explode(',', $raw), 0, self::MAX_ITEMS) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            if (!preg_match('/^\d+(?::\d+){0,2}$/', $chunk)) {
                $errors[] = 'format';
                continue;
            }
            $parts = array_map('intval', explode(':', $chunk));
            if ($parts[0] <= 0) {
                $errors[] = 'format';
                continue;
            }
            $item = ['product' => $parts[0], 'second' => 0, 'variation' => 0, 'qty' => 1, 'parts' => count($parts)];
            if (count($parts) === 2) {
                $item['second'] = $parts[1];
            } elseif (count($parts) === 3) {
                $item['variation'] = $parts[1];
                $item['qty'] = $parts[2];
            }
            if ($item['parts'] === 3 && $item['qty'] < 1) {
                $errors[] = 'qty';
                continue;
            }
            $item['qty'] = min(self::MAX_QTY, max(1, (int) $item['qty']));
            $items[] = $item;
        }
        if (count(explode(',', $raw)) > self::MAX_ITEMS) {
            $errors[] = 'too_many';
        }
        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * Settle "product:second": the second number is a variation for variable products, a quantity otherwise.
     *
     * @param callable $isVariable fn(int $productId): bool
     * @return array{product: int, variation: int, qty: int}
     */
    public static function resolve(array $item, callable $isVariable): array
    {
        $product = (int) $item['product'];
        $variation = (int) ($item['variation'] ?? 0);
        $qty = (int) ($item['qty'] ?? 1);
        if ((int) ($item['parts'] ?? 1) === 2) {
            $second = (int) $item['second'];
            if ($isVariable($product)) {
                $variation = $second;
            } else {
                $qty = $second;
            }
        }
        return ['product' => $product, 'variation' => max(0, $variation), 'qty' => min(self::MAX_QTY, max(1, $qty))];
    }

    /** @param array[] $items product, variation, qty */
    public static function itemsString(array $items): string
    {
        $out = [];
        foreach ($items as $item) {
            $product = (int) ($item['product'] ?? 0);
            if ($product <= 0) {
                continue;
            }
            $variation = max(0, (int) ($item['variation'] ?? 0));
            $qty = min(self::MAX_QTY, max(1, (int) ($item['qty'] ?? 1)));
            $out[] = $variation || $qty > 1 ? $product . ':' . $variation . ':' . $qty : (string) $product;
        }
        return implode(',', array_slice($out, 0, self::MAX_ITEMS));
    }

    /** @return string[] lower-cased, unique, sorted */
    public static function coupons(string $raw): array
    {
        $codes = [];
        foreach (explode(',', $raw) as $code) {
            $code = strtolower(trim(preg_replace('/[^\p{L}\p{N}_\-\.@ ]/u', '', $code) ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);
        return array_slice($codes, 0, 5);
    }

    /**
     * Canonical string covered by the signature. Items are normalized, so "12" and "12:0:1" sign the same.
     *
     * @param array $params items (array[] or string), coupon (string[]|string), clear ('', '0', '1'), to ('', 'checkout', 'cart'), exp (int)
     */
    public static function canonical(array $params): string
    {
        $items = $params['items'] ?? [];
        if (is_string($items)) {
            $parsed = self::parse($items);
            $items = array_map(static function ($item) {
                // Two-part items cannot be resolved without the catalog; keep them literal.
                return $item['parts'] === 2 ? ['product' => $item['product'], 'variation' => 0, 'qty' => 1, 'literal' => $item['product'] . ':' . $item['second']] : $item;
            }, $parsed['items']);
        }
        $chunks = [];
        foreach ((array) $items as $item) {
            if (!empty($item['literal'])) {
                $chunks[] = (string) $item['literal'];
                continue;
            }
            $chunks[] = (int) ($item['product'] ?? 0) . ':' . max(0, (int) ($item['variation'] ?? 0)) . ':' . min(self::MAX_QTY, max(1, (int) ($item['qty'] ?? 1)));
        }
        $coupons = $params['coupon'] ?? [];
        $coupons = is_array($coupons) ? $coupons : self::coupons((string) $coupons);
        $clear = isset($params['clear']) && $params['clear'] !== '' ? ((string) $params['clear'] === '1' ? '1' : '0') : '';
        $to = in_array($params['to'] ?? '', ['checkout', 'cart'], true) ? (string) $params['to'] : '';
        $exp = max(0, (int) ($params['exp'] ?? 0));
        return 'items=' . implode(',', $chunks) . '&coupon=' . implode(',', $coupons) . '&clear=' . $clear . '&to=' . $to . '&exp=' . ($exp ?: '');
    }

    public static function sign(array $params, string $secret): string
    {
        return substr(hash_hmac('sha256', self::canonical($params), $secret), 0, self::SIG_LENGTH);
    }

    public static function verify(array $params, string $signature, string $secret): bool
    {
        if ($secret === '' || strlen($signature) !== self::SIG_LENGTH) {
            return false;
        }
        return hash_equals(self::sign($params, $secret), strtolower($signature));
    }

    public static function expired(int $exp, int $now): bool
    {
        return $exp > 0 && $now > $exp;
    }
}
