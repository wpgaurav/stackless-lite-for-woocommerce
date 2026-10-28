<?php

namespace Stackless\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * Pure decisions for the shorter checkout on free and digital carts and the free-order button.
 *
 * Facts come from CartFacts::fromCart() merged with CartFacts::valueFromCart():
 * product_ids, needs_shipping, items_total, fees_total, total, subscription.
 *
 * - Zero value: nothing to pay for the items themselves (no item price, no fees) and no
 *   subscription to bill later. Coupons do not make a cart zero value.
 * - Zero total: nothing to pay at checkout after coupons, and no subscription to bill later.
 * - Free digital: nothing ships and the cart is zero value. Drops company, country and state.
 * - Paid digital: nothing ships. Drops country and state unless WooCommerce tax is on.
 * - Anything that ships keeps every field.
 */
final class LeanRules
{
    /** Cloudflare sends XX for an unknown location and T1 for Tor. */
    public const UNKNOWN_COUNTRIES = ['XX', 'T1'];

    public static function hasItems(array $facts): bool
    {
        return !empty($facts['product_ids']);
    }

    public static function digital(array $facts): bool
    {
        return self::hasItems($facts) && empty($facts['needs_shipping']);
    }

    public static function zeroValue(array $facts): bool
    {
        if (!self::hasItems($facts) || !empty($facts['subscription'])) {
            return false;
        }
        return self::nothing($facts['items_total'] ?? 0) && self::nothing($facts['fees_total'] ?? 0);
    }

    public static function zeroTotal(array $facts): bool
    {
        if (!self::hasItems($facts) || !empty($facts['subscription'])) {
            return false;
        }
        return self::nothing($facts['total'] ?? 0);
    }

    public static function freeDigital(array $facts, bool $freeOn): bool
    {
        return $freeOn && self::digital($facts) && self::zeroValue($facts);
    }

    /** The country (and the state, whose list depends on it) can go. */
    public static function hideCountry(array $facts, bool $freeOn, bool $countryOn, bool $taxEnabled): bool
    {
        if (!self::digital($facts)) {
            return false;
        }
        if (self::zeroValue($facts)) {
            return $freeOn;
        }
        // Tax is worked out from the buyer's country, so it stays while WooCommerce tax is on.
        return $countryOn && !$taxEnabled;
    }

    /**
     * Core address field keys to drop.
     *
     * @param bool $countryKnown A country can be saved on the order without asking. When none is
     *                           known the country (and state) stay, so no order goes in without one.
     * @return string[]
     */
    public static function hiddenFields(bool $freeDigital, bool $hideCountry, bool $countryKnown): array
    {
        $fields = [];
        if ($freeDigital) {
            $fields[] = 'company';
        }
        if ($hideCountry && $countryKnown) {
            $fields[] = 'country';
            $fields[] = 'state';
        }
        return $fields;
    }

    /**
     * Where the saved country for a hidden country field comes from, in order of preference:
     * a country the buyer entered on this checkout, then a signed-in customer's profile country,
     * then Cloudflare's visitor country, then WooCommerce's own session value, then the store base.
     *
     * WooCommerce seeds a guest's session country from "Default customer location", so for guests
     * the session value is only a default and Cloudflare beats it. For a signed-in customer the
     * session value is loaded from their profile, so it ranks with the profile, before Cloudflare.
     *
     * @param array{entered?: string, profile?: string, session?: string, cloudflare?: string, base?: string} $sources
     * @return string[]
     */
    public static function countryCandidates(array $sources, bool $signedIn): array
    {
        $order = $signedIn
            ? ['entered', 'profile', 'session', 'cloudflare', 'base']
            : ['entered', 'cloudflare', 'session', 'base'];
        $out = [];
        foreach ($order as $key) {
            $out[] = isset($sources[$key]) && is_scalar($sources[$key]) ? (string) $sources[$key] : '';
        }
        return $out;
    }

    /**
     * First usable two-letter country code. With $allowed set, the code must be one of them.
     *
     * @param array    $candidates In order of preference.
     * @param string[] $allowed    Country codes the store sells to; empty means any.
     */
    public static function pickCountry(array $candidates, array $allowed = []): string
    {
        foreach ($candidates as $candidate) {
            $code = strtoupper(trim(is_scalar($candidate) ? (string) $candidate : ''));
            if (!preg_match('/^[A-Z]{2}$/', $code) || in_array($code, self::UNKNOWN_COUNTRIES, true)) {
                continue;
            }
            if ($allowed && !in_array($code, $allowed, true)) {
                continue;
            }
            return $code;
        }
        return '';
    }

    /**
     * Place order label: the free-order text when there is nothing to pay, else the merchant's own
     * label, else what WooCommerce or the payment method would show.
     *
     * @param string $free '' when the free-order button is off.
     */
    public static function buttonText(string $current, string $custom, string $free, bool $zeroTotal): string
    {
        if ($free !== '' && $zeroTotal) {
            return $free;
        }
        return $custom !== '' ? $custom : $current;
    }

    /** @param mixed $amount */
    private static function nothing($amount): bool
    {
        return round(is_numeric($amount) ? (float) $amount : 0.0, 6) <= 0.0;
    }
}
