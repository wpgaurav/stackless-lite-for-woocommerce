<?php

namespace Stackless\Modules\DodoGateway;

defined('ABSPATH') || exit;

/**
 * Maps WooCommerce purchases onto Dodo Payments products.
 *
 * A Dodo checkout sells products from the Dodo catalog. One-time payments use a
 * pay-what-you-want product with no minimum, and each checkout passes the order
 * amount, so one product per variation (plus one shared store product for
 * multi-item carts) covers every price. Dodo cannot set a subscription price per
 * checkout, so subscription products are created per variation, billing schedule
 * and recurring amount. Products are created on first use and remembered per
 * environment, separately for each gateway copy (each copy is its own business).
 */
final class Catalog
{
    public const OPTION = 'stackless_dodo_gateway_products';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function option(): string
    {
        return self::OPTION . $this->gateway->suffix();
    }

    /** Map key: "{mode}|{once|Interval:count:amount}|{currency}|{v<variation>|p<product>|cart|t<hash>}". */
    public static function key(array $item, ?array $recurring, string $currency, int $minor, string $mode): string
    {
        $billing = $recurring ? $recurring[0] . ':' . (int) $recurring[1] . ':' . $minor : 'once';
        if (!empty($item['variation_id'])) {
            $target = 'v' . (int) $item['variation_id'];
        } elseif (!empty($item['product_id'])) {
            $target = 'p' . (int) $item['product_id'];
        } elseif (!empty($item['cart'])) {
            $target = 'cart';
        } else {
            $target = 't' . substr(md5((string) ($item['title'] ?? '')), 0, 12);
        }
        return $mode . '|' . $billing . '|' . strtolower($currency) . '|' . $target;
    }

    /** One order item becomes its own product; several share a store product. */
    public static function itemForOrder(\WC_Order $order, ?\WC_Order $subscription = null): array
    {
        $source = $subscription ?: $order;
        $items = array_values(array_filter($source->get_items(), static function ($item) {
            return $item instanceof \WC_Order_Item_Product;
        }));
        if (count($items) === 1) {
            /** @var \WC_Order_Item_Product $line */
            $line = $items[0];
            return [
                'title' => (string) $line->get_name(),
                'product_id' => (int) $line->get_product_id(),
                'variation_id' => (int) $line->get_variation_id(),
            ];
        }
        $store = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return [
            /* translators: %s: store name */
            'title' => sprintf(__('%s order', 'stackless-lite'), $store),
            'product_id' => 0,
            'variation_id' => 0,
            'cart' => true,
        ];
    }

    /** @return array<string,string> */
    public function map(): array
    {
        $map = get_option($this->option(), []);
        return is_array($map) ? $map : [];
    }

    /**
     * Resolve (or create) the Dodo product for a checkout.
     *
     * @param int $minor Recurring amount for subscriptions; ignored for one-time products.
     * @return string|\WP_Error Dodo product id.
     */
    public function productId(array $item, ?array $recurring, string $currency, int $minor, string $mode, bool $refresh = false)
    {
        $key = self::key($item, $recurring, $currency, $minor, $mode);
        $map = $this->map();
        if (!$refresh && !empty($map[$key])) {
            return (string) $map[$key];
        }
        $product = $this->create($item, $recurring, $currency, $minor, $mode);
        if (is_wp_error($product)) {
            return $product;
        }
        $map[$key] = (string) $product['product_id'];
        update_option($this->option(), $map, false);
        return (string) $product['product_id'];
    }

    public function forget(array $item, ?array $recurring, string $currency, int $minor, string $mode): void
    {
        $map = $this->map();
        unset($map[self::key($item, $recurring, $currency, $minor, $mode)]);
        update_option($this->option(), $map, false);
    }

    /** Drop remembered products for a mode, e.g. after the key moved to another business or the tax settings changed. */
    public function forgetMode(string $mode): void
    {
        $map = array_filter($this->map(), static function ($key) use ($mode) {
            return strpos((string) $key, $mode . '|') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        update_option($this->option(), $map, false);
    }

    /** @return array|\WP_Error */
    public function create(array $item, ?array $recurring, string $currency, int $minor, string $mode)
    {
        if ($recurring) {
            $price = [
                'type' => 'recurring_price',
                'currency' => strtoupper($currency),
                'price' => max(0, $minor),
                'discount_bps' => 0,
                'purchasing_power_parity' => false,
                'payment_frequency_interval' => $recurring[0],
                'payment_frequency_count' => (int) $recurring[1],
                // Renews until cancelled: Dodo's recommended (and longest) term.
                'subscription_period_interval' => 'Year',
                'subscription_period_count' => 20,
                'tax_inclusive' => $this->gateway->taxInclusive(),
            ];
        } else {
            // Pay what you want with no minimum: every checkout passes its own amount.
            $price = [
                'type' => 'one_time_price',
                'currency' => strtoupper($currency),
                'price' => 0,
                'pay_what_you_want' => true,
                'discount_bps' => 0,
                'purchasing_power_parity' => false,
                'tax_inclusive' => $this->gateway->taxInclusive(),
            ];
        }

        $body = [
            'name' => self::name((string) ($item['title'] ?? '')),
            'tax_category' => $this->gateway->taxCategory(),
            'price' => $price,
            'metadata' => array_map('strval', array_filter([
                'source' => 'stackless',
                'wc_product_id' => (int) ($item['product_id'] ?? 0),
                'wc_variation_id' => (int) ($item['variation_id'] ?? 0),
                'site' => self::siteHost(),
            ])),
        ];
        $body = (array) apply_filters('stackless_dodo_gateway_product_args', $body, $item, $recurring, $mode, $this->gateway);
        $product = $this->gateway->api($mode)->request('POST', 'products', $body);
        if (!is_wp_error($product) && empty($product['product_id'])) {
            return new \WP_Error('stackless_dodo_product', __('Dodo Payments did not return a product for this checkout.', 'stackless-lite'));
        }
        if (!is_wp_error($product)) {
            $this->gateway->log('Created Dodo product ' . $product['product_id'] . ' (' . self::key($item, $recurring, $currency, $minor, $mode) . ').');
        }
        return $product;
    }

    /** Dodo product names, trimmed to 100 characters. */
    public static function name(string $title): string
    {
        $title = trim(wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8')));
        if ($title === '') {
            $title = __('Purchase', 'stackless-lite');
        }
        if (function_exists('mb_substr')) {
            return mb_strlen($title) > 100 ? rtrim(mb_substr($title, 0, 99)) . '…' : $title;
        }
        return strlen($title) > 100 ? rtrim(substr($title, 0, 97)) . '...' : $title;
    }

    public static function siteHost(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }
}
