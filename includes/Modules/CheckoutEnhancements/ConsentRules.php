<?php

namespace Stackless\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * Pure consent logic: which consents a cart sees, how wording versions move, and the
 * record stored on the order. No WordPress calls here, so it is unit-tested directly.
 */
final class ConsentRules
{
    public const MAX_CONSENTS = 10;

    public const TYPES = ['privacy', 'marketing', 'withdrawal', 'age', 'custom'];

    /** all = every cart, physical = something ships, digital = has a virtual or downloadable item, digital_only = nothing ships, product = a chosen product. */
    public const SCOPES = ['all', 'physical', 'digital', 'digital_only', 'product'];

    /**
     * @param array $consent One sanitized consent.
     * @param array $facts   needs_shipping:bool, has_digital:bool, product_ids:int[]
     */
    public static function applies(array $consent, array $facts): bool
    {
        $scope = isset($consent['scope']) ? (string) $consent['scope'] : 'all';
        $needsShipping = !empty($facts['needs_shipping']);
        $hasDigital = !empty($facts['has_digital']);
        $ids = array_map('intval', isset($facts['product_ids']) ? (array) $facts['product_ids'] : []);
        if ($scope === 'physical') {
            return $needsShipping;
        }
        if ($scope === 'digital') {
            return $hasDigital;
        }
        if ($scope === 'digital_only') {
            return !$needsShipping && !empty($ids);
        }
        if ($scope === 'product') {
            $target = isset($consent['product_id']) ? (int) $consent['product_id'] : 0;
            return $target > 0 && in_array($target, $ids, true);
        }
        return true;
    }

    /**
     * @param array[] $consents
     * @return array[]
     */
    public static function applicable(array $consents, array $facts): array
    {
        return array_values(array_filter($consents, static function ($consent) use ($facts) {
            return is_array($consent) && self::applies($consent, $facts);
        }));
    }

    /**
     * Changed wording starts a new version, so the stored proof always matches what the customer saw.
     *
     * @param array|null $previous The stored consent with the same id, if any.
     */
    public static function version(string $label, ?array $previous, int $posted = 1): int
    {
        if ($previous === null) {
            return max(1, $posted);
        }
        $old = max(1, (int) ($previous['version'] ?? 1));
        return (string) ($previous['label'] ?? '') === $label ? $old : $old + 1;
    }

    /**
     * The JSON-schema rule that hides a block-checkout field when the consent does not apply.
     * Evaluated by WooCommerce against the checkout document on the server and in the browser.
     *
     * @param int[] $productIds For the product scope: the product and its variation ids.
     * @return array<string, mixed> Empty when the consent always shows.
     */
    public static function hiddenRule(array $consent, string $namespace, array $productIds = []): array
    {
        $scope = isset($consent['scope']) ? (string) $consent['scope'] : 'all';
        $extension = static function (string $flag, bool $hiddenWhen) use ($namespace): array {
            return ['cart' => ['properties' => ['extensions' => ['properties' => [$namespace => ['properties' => [$flag => ['const' => $hiddenWhen]]]]]]]];
        };
        if ($scope === 'physical') {
            return ['cart' => ['properties' => ['needs_shipping' => ['const' => false]]]];
        }
        if ($scope === 'digital_only') {
            return ['cart' => ['properties' => ['needs_shipping' => ['const' => true]]]];
        }
        if ($scope === 'digital') {
            return $extension('has_digital', false);
        }
        if ($scope === 'product') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $productIds ?: [(int) ($consent['product_id'] ?? 0)]))));
            return ['cart' => ['properties' => ['items' => ['not' => ['contains' => ['enum' => $ids ?: [0]]]]]]];
        }
        return [];
    }

    /**
     * The JSON-schema rule that makes a conditional consent required exactly when it shows:
     * the negation of its hidden rule.
     *
     * A required consent with a hidden rule must not be registered with required => true.
     * WooCommerce then adds enum [true] to the checkbox's Store API schema, which is checked
     * before the hidden rule, and the Checkout block posts false for a checkbox it hid, so the
     * order fails with "stackless/consent_x is not 1". With a rule, WooCommerce treats the field
     * as conditional: no enum, and the required check runs only when the consent shows.
     *
     * @param array<string, mixed> $hiddenRule From hiddenRule(); must not be empty.
     * @return array<string, mixed>
     */
    public static function requiredRule(array $hiddenRule): array
    {
        return ['not' => ['type' => 'object', 'properties' => $hiddenRule]];
    }

    /**
     * What an order stores for each consent the customer was shown.
     *
     * @param array[]            $consents  Applicable consents.
     * @param array<string,bool> $answers   consent id => ticked
     * @return array[]
     */
    public static function records(array $consents, array $answers, string $timestampUtc): array
    {
        $records = [];
        foreach ($consents as $consent) {
            $id = (string) ($consent['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $records[] = [
                'id' => $id,
                'type' => (string) ($consent['type'] ?? 'custom'),
                'label' => (string) ($consent['label'] ?? ''),
                'version' => max(1, (int) ($consent['version'] ?? 1)),
                'required' => !empty($consent['required']),
                'accepted' => !empty($answers[$id]),
                'at' => $timestampUtc,
            ];
        }
        return $records;
    }

    /** @return string[] ids of required consents that were not ticked */
    public static function missing(array $consents, array $answers): array
    {
        $missing = [];
        foreach ($consents as $consent) {
            $id = (string) ($consent['id'] ?? '');
            if ($id !== '' && !empty($consent['required']) && empty($answers[$id])) {
                $missing[] = $id;
            }
        }
        return $missing;
    }

    /** Values Store API and classic checkout send for a ticked box. */
    public static function isTicked($value): bool
    {
        return $value === true || $value === 1 || in_array($value, ['1', 'yes', 'true', 'on'], true);
    }
}
