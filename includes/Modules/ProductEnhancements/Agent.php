<?php

namespace Stackless\Modules\ProductEnhancements;

use Stackless\Core\Agent as Dispatcher;

defined('ABSPATH') || exit;

/** product.details.* commands for stackless_agent(): read and edit a product's details table. */
final class Agent
{
    public function register(): void
    {
        add_filter('stackless_agent_command', [$this, 'handle'], 10, 3);
        add_filter('stackless_agent_commands', [$this, 'describe']);
    }

    /** @param array<string,string> $commands */
    public function describe($commands): array
    {
        return (array) $commands + [
            'product.details.get' => 'A product\'s details table: preset, field values, hidden fields, custom rows and what shoppers see.',
            'product.details.set' => 'Merge values (by field key), preset, show, hidden and custom rows into a product\'s details (dry_run validates only).',
        ];
    }

    /**
     * @param mixed $result
     * @param mixed $command
     * @param mixed $input
     * @return mixed
     */
    public function handle($result, $command, $input)
    {
        if ($result !== null || !in_array($command, ['product.details.get', 'product.details.set'], true)) {
            return $result;
        }
        $input = is_array($input) ? $input : [];
        return Dispatcher::run(function () use ($command, $input) {
            $product = self::product($input);
            if ($command === 'product.details.get') {
                if (!current_user_can('edit_product', $product->get_id())) {
                    throw new \RuntimeException('This WordPress user cannot edit this product.');
                }
                return self::details($product);
            }
            return self::set($product, $input);
        });
    }

    private static function product(array $input): \WC_Product
    {
        $product = wc_get_product(Dispatcher::id($input, 'product_id'));
        if (!$product instanceof \WC_Product || $product->is_type('variation')) {
            throw new \RuntimeException('Product not found. Pass a parent product id, not a variation.');
        }
        return $product;
    }

    public static function details(\WC_Product $product): array
    {
        $stored = Repository::get($product);
        $presets = Fields::presets();
        $fields = Fields::all();
        return [
            'product_id' => $product->get_id(),
            'title' => $product->get_name(),
            'saved' => $stored['saved'],
            'show' => $stored['show'],
            'preset' => $stored['preset'],
            'presets' => array_keys($presets),
            'preset_fields' => array_map(static function ($key) use ($fields) {
                return ['key' => $key, 'label' => (string) ($fields[$key]['label'] ?? $key)];
            }, (array) ($presets[$stored['preset']]['fields'] ?? [])),
            'values' => $stored['values'],
            'hidden' => $stored['hidden'],
            'custom' => $stored['custom'],
            'shown' => array_map(static function ($row) {
                return ['key' => (string) $row['key'], 'label' => (string) $row['label'], 'value' => (string) $row['value']];
            }, Repository::rows($product)),
        ];
    }

    private static function set(\WC_Product $product, array $input): array
    {
        if (!current_user_can('edit_product', $product->get_id())) {
            throw new \RuntimeException('This WordPress user cannot edit this product.');
        }
        $current = Repository::get($product);
        $custom = array_key_exists('custom', $input) ? (array) $input['custom'] : $current['custom'];
        $next = [
            'preset' => Dispatcher::text($input, 'preset', 40) ?: $current['preset'],
            'show' => array_key_exists('show', $input) ? !empty($input['show']) && $input['show'] !== 'false' : ($current['saved'] ? $current['show'] : true),
            'values' => array_merge($current['values'], array_map('strval', array_filter((array) ($input['values'] ?? []), 'is_scalar'))),
            'hidden' => array_key_exists('hidden', $input) ? array_map('strval', (array) $input['hidden']) : $current['hidden'],
            'custom' => Repository::customText(array_values(array_filter(array_map(static function ($row) {
                return is_array($row) && isset($row['label'], $row['value']) ? ['label' => (string) $row['label'], 'value' => (string) $row['value']] : null;
            }, $custom)))),
        ];
        [$data, $errors] = Repository::validate($product, $next);
        if ($errors) {
            throw new \InvalidArgumentException(esc_html('Fix these fields: ' . implode('; ', $errors)));
        }
        if (Dispatcher::dryRun($input)) {
            return ['dry_run' => true, 'details' => $data];
        }
        $product->update_meta_data(Repository::META, $data);
        $product->save();
        return self::details(wc_get_product($product->get_id()) ?: $product);
    }
}
