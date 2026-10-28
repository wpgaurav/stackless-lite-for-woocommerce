<?php

namespace Stackless\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * Option names, defaults and readers. Every value is re-sanitized on read so a hand-edited
 * option can never reach the checkout unescaped or out of range.
 */
final class Settings
{
    public const PREFIX = 'stackless_checkout_enhancements_';
    public const CONSENTS = self::PREFIX . 'consents';
    public const TRUST_IMAGE = self::PREFIX . 'trust_image';

    /** @var array|null */
    private static $consents;

    public static function get(string $key, $default = '')
    {
        return get_option(self::PREFIX . $key, $default);
    }

    public static function flag(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'yes' : 'no');
        return $value === 'yes' || $value === true || $value === '1';
    }

    public static function text(string $key, int $max = 160, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? mb_substr(trim(sanitize_text_field((string) $value)), 0, $max) : '';
    }

    public static function allowedLabelHtml(): array
    {
        return ['a' => ['href' => true, 'target' => true, 'rel' => true], 'strong' => [], 'em' => []];
    }

    public static function consentTypes(): array
    {
        return [
            'privacy' => __('Privacy policy', 'stackless-lite'),
            'marketing' => __('Marketing emails', 'stackless-lite'),
            'withdrawal' => __('EU digital content waiver', 'stackless-lite'),
            'age' => __('Age confirmation', 'stackless-lite'),
            'custom' => __('Custom', 'stackless-lite'),
        ];
    }

    public static function consentScopes(): array
    {
        return [
            'all' => __('All carts', 'stackless-lite'),
            'physical' => __('Carts with items that ship', 'stackless-lite'),
            'digital' => __('Carts with virtual or downloadable items', 'stackless-lite'),
            'digital_only' => __('Carts where nothing ships', 'stackless-lite'),
            'product' => __('Carts with a specific product', 'stackless-lite'),
        ];
    }

    /** Starting wording per type. Merchants should check it against their own policies and local law. */
    public static function consentPresets(): array
    {
        return [
            'privacy' => ['label' => __('I have read the privacy policy.', 'stackless-lite'), 'required' => true, 'scope' => 'all'],
            'marketing' => ['label' => __('Email me product updates and offers. I can unsubscribe at any time.', 'stackless-lite'), 'required' => false, 'scope' => 'all'],
            'withdrawal' => ['label' => __('I agree that delivery of the digital content starts immediately, and I understand that I lose my right of withdrawal once it starts.', 'stackless-lite'), 'required' => true, 'scope' => 'digital'],
            'age' => ['label' => __('I confirm that I am 18 or older.', 'stackless-lite'), 'required' => true, 'scope' => 'all'],
            'custom' => ['label' => '', 'required' => false, 'scope' => 'all'],
        ];
    }

    /** Address and contact fields that can be renamed. Key => admin label. */
    public static function fieldLabels(): array
    {
        return [
            'email' => __('Email address', 'stackless-lite'),
            'first_name' => __('First name', 'stackless-lite'),
            'last_name' => __('Last name', 'stackless-lite'),
            'company' => __('Company', 'stackless-lite'),
            'country' => __('Country / Region', 'stackless-lite'),
            'address_1' => __('Street address', 'stackless-lite'),
            'address_2' => __('Apartment, suite, unit', 'stackless-lite'),
            'city' => __('Town / City', 'stackless-lite'),
            'state' => __('State / County', 'stackless-lite'),
            'postcode' => __('Postcode / ZIP', 'stackless-lite'),
            'phone' => __('Phone', 'stackless-lite'),
        ];
    }

    /** Fields a virtual-only cart can drop. Country is handled by the shorter checkout for free and digital orders. */
    public static function virtualFields(): array
    {
        return [
            'company' => __('Company', 'stackless-lite'),
            'address_1' => __('Street address', 'stackless-lite'),
            'address_2' => __('Apartment, suite, unit', 'stackless-lite'),
            'city' => __('Town / City', 'stackless-lite'),
            'state' => __('State / County', 'stackless-lite'),
            'postcode' => __('Postcode / ZIP', 'stackless-lite'),
            'phone' => __('Phone', 'stackless-lite'),
        ];
    }

    /**
     * WooCommerce's fixed checkout text. Source strings must match WooCommerce's msgids exactly.
     *
     * @return array<string, array{0: string, 1: string[]}> slug => [admin label, source strings]
     */
    public static function textLabels(): array
    {
        return [
            'contact' => [__('Contact information heading (block checkout)', 'stackless-lite'), ['Contact information']],
            'billing' => [__('Billing heading', 'stackless-lite'), ['Billing address', 'Billing details', 'Billing &amp; Shipping']],
            'shipping' => [__('Shipping address heading (block checkout)', 'stackless-lite'), ['Shipping address']],
            'ship_different' => [__('Ship to a different address (classic checkout)', 'stackless-lite'), ['Ship to a different address?']],
            'shipping_options' => [__('Shipping options heading (block checkout)', 'stackless-lite'), ['Shipping options']],
            'payment' => [__('Payment heading (block checkout)', 'stackless-lite'), ['Payment options']],
            'summary' => [__('Order summary heading', 'stackless-lite'), ['Order summary', 'Your order']],
            'note_toggle' => [__('Order note checkbox (block checkout)', 'stackless-lite'), ['Add a note to your order']],
            'additional' => [__('Additional information heading (classic checkout)', 'stackless-lite'), ['Additional information']],
        ];
    }

    /** @return array<string, string> renamed field key => label */
    public static function renamedFields(): array
    {
        $out = [];
        foreach (array_keys(self::fieldLabels()) as $key) {
            $value = self::text('label_' . $key, 80);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @return array<string, string> WooCommerce msgid => merchant text */
    public static function textMap(): array
    {
        $map = [];
        foreach (self::textLabels() as $slug => $row) {
            $value = self::text('text_' . $slug, 120);
            if ($value === '') {
                continue;
            }
            foreach ($row[1] as $source) {
                $map[$source] = $value;
            }
        }
        $button = self::buttonText();
        if ($button !== '') {
            $map['Place Order'] = $button;
            $map['Place order'] = $button;
        }
        return $map;
    }

    public static function buttonText(): string
    {
        return self::text('button', 60);
    }

    /**
     * Shorter checkout for free and digital orders. Every switch is on until the merchant turns it off.
     *
     * @return array{free: bool, country: bool, coupons: bool, free_button: bool, free_button_text: string}
     */
    public static function lean(): array
    {
        return [
            'free' => self::flag('lean_free', true),
            'country' => self::flag('lean_country', true),
            'coupons' => self::flag('lean_coupons', true),
            'free_button' => self::flag('lean_free_button', true),
            'free_button_text' => self::text('lean_free_button_text', 60) ?: __('Get it for free', 'stackless-lite'),
        ];
    }

    /** Place order label for orders with nothing to pay, or '' when that is turned off. */
    public static function freeButtonText(): string
    {
        $lean = self::lean();
        return $lean['free_button'] ? $lean['free_button_text'] : '';
    }

    public static function leanEnabled(): bool
    {
        $lean = self::lean();
        return $lean['free'] || $lean['country'];
    }

    /** @return string[] */
    public static function virtualHide(): array
    {
        if (!self::flag('virtual_enabled')) {
            return [];
        }
        $hide = self::get('virtual_hide', ['address_1', 'address_2', 'city', 'postcode', 'state']);
        return array_values(array_intersect(array_keys(self::virtualFields()), array_map('strval', (array) $hide)));
    }

    /** @return array[] sanitized consents */
    public static function consents(): array
    {
        if (self::$consents === null) {
            $saved = get_option(self::CONSENTS, []);
            self::$consents = self::sanitizeConsents(is_array($saved) ? $saved : [], is_array($saved) ? $saved : []);
        }
        return self::$consents;
    }

    public static function flush(): void
    {
        self::$consents = null;
    }

    /**
     * @param array $input    Posted or stored rows.
     * @param array $previous Stored rows, for versioning.
     * @return array[]
     */
    public static function sanitizeConsents(array $input, array $previous = []): array
    {
        $old = [];
        foreach ($previous as $row) {
            if (is_array($row) && !empty($row['id'])) {
                $old[(string) $row['id']] = $row;
            }
        }
        $out = [];
        $seen = [];
        foreach (array_values(array_filter($input, 'is_array')) as $row) {
            if (!empty($row['delete'])) {
                continue;
            }
            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
            $label = mb_substr(wp_kses($label, self::allowedLabelHtml()), 0, 600);
            if (trim(wp_strip_all_tags($label)) === '') {
                continue;
            }
            $type = in_array($row['type'] ?? '', ConsentRules::TYPES, true) ? (string) $row['type'] : 'custom';
            $id = sanitize_key((string) ($row['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                $id = $type . '_' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 6);
            }
            $seen[$id] = true;
            $scope = in_array($row['scope'] ?? '', ConsentRules::SCOPES, true) ? (string) $row['scope'] : 'all';
            $productId = $scope === 'product' ? absint($row['product_id'] ?? 0) : 0;
            if ($scope === 'product' && !$productId) {
                $scope = 'all';
            }
            $out[] = [
                'id' => $id,
                'type' => $type,
                'label' => $label,
                'required' => !empty($row['required']) && $row['required'] !== 'no',
                'scope' => $scope,
                'product_id' => $productId,
                'version' => ConsentRules::version($label, $old[$id] ?? null, (int) ($row['version'] ?? 1)),
            ];
            if (count($out) >= ConsentRules::MAX_CONSENTS) {
                break;
            }
        }
        return $out;
    }

    /** @return array{enabled: bool, heading: string, lines: string[], image_id: int, policy_page: int, policy_label: string} */
    public static function trust(): array
    {
        $lines = preg_split('/\r?\n/', (string) self::get('trust_lines', __('Payments are encrypted and processed by our payment provider.', 'stackless-lite'))) ?: [];
        $lines = array_slice(array_values(array_filter(array_map(static function ($line) {
            return mb_substr(trim(sanitize_text_field((string) $line)), 0, 160);
        }, $lines))), 0, 6);
        $image = absint(get_option(self::TRUST_IMAGE, 0));
        $page = absint(self::get('trust_policy_page', 0));
        return [
            'enabled' => self::flag('trust_enabled'),
            'heading' => self::text('trust_heading', 80, __('Secure checkout', 'stackless-lite')),
            'lines' => $lines,
            'image_id' => $image && wp_attachment_is_image($image) ? $image : 0,
            'policy_page' => $page && get_post_status($page) === 'publish' ? $page : 0,
            'policy_label' => self::text('trust_policy_label', 60) ?: __('Refund policy', 'stackless-lite'),
        ];
    }
}
