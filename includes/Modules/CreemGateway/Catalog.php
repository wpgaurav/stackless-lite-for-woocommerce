<?php

namespace Stackless\Modules\CreemGateway;

defined('ABSPATH') || exit;

/**
 * Maps WooCommerce purchases onto Creem products.
 *
 * A Creem checkout sells one product from the Creem catalog. One-time checkouts pass the
 * order amount as a custom price, so one product per variation (plus one shared store
 * product for multi-item carts) and currency covers every price. Creem cannot set a
 * subscription's price or trial per checkout, so subscription products are created per
 * variation, billing schedule, recurring amount and trial length. Products are created on
 * first use and remembered per environment, separately for each gateway copy.
 */
final class Catalog
{
    public const OPTION = 'stackless_creem_gateway_products';

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

    /**
     * Map key: "{mode}|{once|<schedule>:<amount>:t<trial days>}|{currency}|{v<variation>|p<product>|cart|t<hash>}".
     *
     * @param array<string,string|int>|null $billing
     */
    public static function key(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0): string
    {
        $schedule = $billing ? EventMap::intervalKey($billing) . ':' . $minor . ':t' . max(0, $trialDays) : 'once';
        if (!empty($item['variation_id'])) {
            $target = 'v' . (int) $item['variation_id'];
        } elseif (!empty($item['product_id'])) {
            $target = 'p' . (int) $item['product_id'];
        } elseif (!empty($item['cart'])) {
            $target = 'cart';
        } else {
            $target = 't' . substr(md5((string) ($item['title'] ?? '')), 0, 12);
        }
        return $mode . '|' . $schedule . '|' . strtolower($currency) . '|' . $target;
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
     * Resolve (or create) the Creem product for a checkout.
     *
     * @param array<string,string|int>|null $billing Creem billing fields for subscriptions, null for one-time.
     * @param int $minor Recurring amount for subscriptions; the first price of a one-time product.
     * @return string|\WP_Error Creem product id.
     */
    public function productId(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0, bool $refresh = false)
    {
        $key = self::key($item, $billing, $currency, $minor, $mode, $trialDays);
        $map = $this->map();
        if (!$refresh && !empty($map[$key])) {
            return (string) $map[$key];
        }
        $product = $this->create($item, $billing, $currency, $minor, $mode, $trialDays);
        if (is_wp_error($product)) {
            return $product;
        }
        $map[$key] = (string) $product['id'];
        update_option($this->option(), $map, false);
        return (string) $product['id'];
    }

    /** @param array<string,string|int>|null $billing */
    public function forget(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0): void
    {
        $map = $this->map();
        unset($map[self::key($item, $billing, $currency, $minor, $mode, $trialDays)]);
        update_option($this->option(), $map, false);
    }

    /** Drop remembered products for a mode, e.g. after the key moved to another store or the tax settings changed. */
    public function forgetMode(string $mode): void
    {
        $map = array_filter($this->map(), static function ($key) use ($mode) {
            return strpos((string) $key, $mode . '|') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        update_option($this->option(), $map, false);
    }

    /**
     * @param array<string,string|int>|null $billing
     * @return array|\WP_Error
     */
    public function create(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0)
    {
        $site = self::siteHost();
        $body = [
            'name' => self::name((string) ($item['title'] ?? '')),
            /* translators: %s: site address */
            'description' => sprintf(__('Sold on %s', 'stackless-lite'), $site),
            'price' => max(Money::MINIMUM, $minor),
            'currency' => strtoupper($currency),
            'billing_type' => $billing ? 'recurring' : 'onetime',
            'tax_mode' => $this->gateway->taxInclusive() ? 'inclusive' : 'exclusive',
            'tax_category' => $this->gateway->taxCategory(),
        ];
        if ($billing) {
            $body += $billing;
            if ($trialDays > 0) {
                $body['trial_period_days'] = $trialDays;
            }
        } else {
            $body['billing_period'] = 'once';
        }
        $body = (array) apply_filters('stackless_creem_gateway_product_args', $body, $item, $billing, $mode, $this->gateway);
        $product = $this->gateway->api($mode)->request('POST', 'v1/products', $body);
        if (!is_wp_error($product) && empty($product['id'])) {
            return new \WP_Error('stackless_creem_product', __('Creem did not return a product for this checkout.', 'stackless-lite'));
        }
        if (!is_wp_error($product)) {
            $this->gateway->log('Created Creem product ' . $product['id'] . ' (' . self::key($item, $billing, $currency, $minor, $mode, $trialDays) . ').');
        }
        return $product;
    }

    /** Creem product names, trimmed to 100 characters. */
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
