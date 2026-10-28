<?php

namespace Stackless\Modules\DodoGateway;

defined('ABSPATH') || exit;

/**
 * Copies of the Dodo Payments gateway: a second Dodo business, each with its own id
 * ("stackless_dodo_copyN"), settings, webhook URL, product catalog and orders.
 *
 * This module owns the list (one option), so copies keep taking payments, renewals,
 * refunds and webhooks even while the Payment Gateway Tools module that adds and
 * renames them is switched off. That module edits the list through the option named
 * in the stackless_gateway_copy_families declaration below.
 */
final class Copies
{
    public const OPTION = 'stackless_dodo_gateway_copies';

    /** @return array<string,array{number:int,label:string}> keyed by gateway id. */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        $copies = [];
        foreach (is_array($saved) ? $saved : [] as $id => $copy) {
            $number = is_array($copy) ? (int) ($copy['number'] ?? 0) : 0;
            if ($number < 2 || $id !== Gateway::ID . '_copy' . $number) {
                continue;
            }
            $copies[$id] = ['number' => $number, 'label' => (string) ($copy['label'] ?? '')];
        }
        return $copies;
    }

    /** The original gateway id and every copy's. @return string[] */
    public static function ids(): array
    {
        return array_merge([Gateway::ID], array_keys(self::all()));
    }

    public static function belongs(string $id): bool
    {
        return $id === Gateway::ID || isset(self::all()[$id]);
    }

    /** Option-name suffix for per-copy storage: '' for the original, '_copy2' for copy 2. */
    public static function suffix(string $id): string
    {
        return $id === Gateway::ID ? '' : (string) substr($id, strlen(Gateway::ID));
    }

    /** Badge text for a copy: its label, else "Copy N". '' for the original. */
    public static function badge(string $id): string
    {
        $copy = self::all()[$id] ?? null;
        if (!$copy) {
            return '';
        }
        /* translators: %d: copy number, starting at 2 */
        return $copy['label'] !== '' ? $copy['label'] : sprintf(__('Copy %d', 'stackless-lite'), $copy['number']);
    }

    /** Declares the family to Payment Gateway Tools. @param array $families */
    public static function declare($families): array
    {
        $families = is_array($families) ? $families : [];
        $families[Gateway::ID] = ['title' => __('Dodo Payments', 'stackless-lite'), 'option' => self::OPTION];
        return $families;
    }

    /** Payment Gateway Tools removed a copy that never took a payment: drop what it stored. */
    public static function removed(string $id): void
    {
        if ($id === Gateway::ID || strpos($id, Gateway::ID . '_copy') !== 0) {
            return;
        }
        $suffix = (string) substr($id, strlen(Gateway::ID));
        delete_option('woocommerce_' . $id . '_settings');
        delete_option(Catalog::OPTION . $suffix);
        foreach (['live', 'test'] as $mode) {
            delete_option('stackless_dodo_gateway_business_' . $mode . $suffix);
        }
    }
}
