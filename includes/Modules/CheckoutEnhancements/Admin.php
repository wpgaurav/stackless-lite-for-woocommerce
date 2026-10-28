<?php

namespace Stackless\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/** Settings tab fields, the consent editor, the media picker and the order screen box. */
final class Admin
{
    public const CONSENT_TYPE = 'stackless_ce_consents';
    public const MEDIA_TYPE = 'stackless_ce_media';

    public function register(): void
    {
        add_action('woocommerce_admin_field_' . self::CONSENT_TYPE, [$this, 'renderConsents']);
        add_action('woocommerce_admin_field_' . self::MEDIA_TYPE, [$this, 'renderMedia']);
        add_filter('woocommerce_admin_settings_sanitize_option_' . Settings::CONSENTS, [$this, 'sanitizeConsents'], 10, 3);
        add_filter('woocommerce_admin_settings_sanitize_option_' . Settings::TRUST_IMAGE, [$this, 'sanitizeMedia'], 10, 3);
        add_action('stackless_settings_saved_checkout_enhancements', [Settings::class, 'flush']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('add_meta_boxes', [$this, 'metaBox'], 30);
    }

    public function fields(): array
    {
        $p = Settings::PREFIX;
        $fields = [
            ['title' => __('Consent checkboxes', 'stackless-lite'), 'type' => 'title', 'id' => $p . 'consents_section', 'desc' => __('Shown above the Place order button on the classic checkout and in the order information step of the Checkout block. Each order stores the exact wording, its version, the answer and the time.', 'stackless-lite')],
            ['title' => __('Checkboxes', 'stackless-lite'), 'type' => self::CONSENT_TYPE, 'id' => Settings::CONSENTS, 'default' => []],
            ['title' => __('Show answers to customers', 'stackless-lite'), 'desc' => __('On the thank-you page and the order in My Account', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'show_account', 'default' => 'yes'],
            ['title' => __('Show answers in emails', 'stackless-lite'), 'desc' => __('In order emails to the customer and the store', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'show_emails', 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'consents_section'],

            ['title' => __('Shorter checkout for free and digital orders', 'stackless-lite'), 'type' => 'title', 'id' => $p . 'lean_section', 'desc' => __('Ask only for what an order needs. A cart with anything that ships keeps every field. The state goes with the country, since its list depends on it. The country is still saved on the order: a signed-in customer\'s saved country, then the buyer\'s location from Cloudflare, then WooCommerce\'s default customer location, then your store country. If none is known, the country field shows as usual. On paid orders it stays visible while WooCommerce tax is on, because tax is worked out from it.', 'stackless-lite')],
            ['title' => __('Free digital products', 'stackless-lite'), 'desc' => __('Hide the company, country, state and currency selector', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'lean_free', 'default' => 'yes', 'desc_tip' => __('When nothing ships and nothing is charged: no item price, no fees and no subscription to bill later.', 'stackless-lite')],
            ['title' => __('Paid digital products', 'stackless-lite'), 'desc' => __('Hide the country and state', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'lean_country', 'default' => 'yes'],
            ['title' => __('Coupons', 'stackless-lite'), 'desc' => __('Hide the coupon form when the products are free', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'lean_coupons', 'default' => 'yes'],
            ['title' => __('Free order button', 'stackless-lite'), 'desc' => __('Relabel the Place order button when there is nothing to pay', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'lean_free_button', 'default' => 'yes', 'desc_tip' => __('Applies when the order total is zero, after coupons, and nothing is billed later. It takes the place of your own Place order label on those orders.', 'stackless-lite')],
            ['title' => __('Free order button text', 'stackless-lite'), 'type' => 'text', 'id' => $p . 'lean_free_button_text', 'default' => __('Get it for free', 'stackless-lite'), 'placeholder' => __('Get it for free', 'stackless-lite'), 'custom_attributes' => ['maxlength' => 60]],
            ['type' => 'sectionend', 'id' => $p . 'lean_section'],

            ['title' => __('Shorter checkout for virtual-only carts', 'stackless-lite'), 'type' => 'title', 'id' => $p . 'virtual_section', 'desc' => __('When nothing in the cart ships, hide address fields a digital buyer does not need. The country is covered by the section above.', 'stackless-lite')],
            ['title' => __('Hide fields', 'stackless-lite'), 'desc' => __('Hide the fields below when every item is virtual or downloadable', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'virtual_enabled', 'default' => 'no'],
            ['title' => __('Fields to hide', 'stackless-lite'), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'id' => $p . 'virtual_hide', 'options' => Settings::virtualFields(), 'default' => ['address_1', 'address_2', 'city', 'postcode', 'state'], 'desc_tip' => __('Keep State if your tax rates depend on it.', 'stackless-lite')],
            ['type' => 'sectionend', 'id' => $p . 'virtual_section'],

            ['title' => __('Checkout wording', 'stackless-lite'), 'type' => 'title', 'id' => $p . 'wording_section', 'desc' => __('Leave a box empty to keep WooCommerce\'s text. Field names also change in the matching required-field messages. A custom Place order label replaces payment-method specific button text too.', 'stackless-lite')],
        ];
        foreach (Settings::fieldLabels() as $key => $label) {
            /* translators: %s: checkout field name */
            $fields[] = ['title' => sprintf(__('%s field', 'stackless-lite'), $label), 'type' => 'text', 'id' => $p . 'label_' . $key, 'default' => '', 'placeholder' => $label, 'custom_attributes' => ['maxlength' => 80]];
        }
        foreach (Settings::textLabels() as $slug => $row) {
            $fields[] = ['title' => $row[0], 'type' => 'text', 'id' => $p . 'text_' . $slug, 'default' => '', 'placeholder' => str_replace('&amp;', '&', $row[1][0]), 'custom_attributes' => ['maxlength' => 120]];
        }
        $fields[] = ['title' => __('Place order button', 'stackless-lite'), 'type' => 'text', 'id' => $p . 'button', 'default' => '', 'placeholder' => __('Place order', 'stackless-lite'), 'custom_attributes' => ['maxlength' => 60]];
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'wording_section'];

        return array_merge($fields, [
            ['title' => __('Trust block under payment', 'stackless-lite'), 'type' => 'title', 'id' => $p . 'trust_section', 'desc' => __('A short reassurance under the payment methods: what protects the payment, an optional payment methods image, and a link to your refund policy.', 'stackless-lite')],
            ['title' => __('Show trust block', 'stackless-lite'), 'desc' => __('Show the trust block at checkout', 'stackless-lite'), 'type' => 'checkbox', 'id' => $p . 'trust_enabled', 'default' => 'no'],
            ['title' => __('Heading', 'stackless-lite'), 'type' => 'text', 'id' => $p . 'trust_heading', 'default' => __('Secure checkout', 'stackless-lite'), 'custom_attributes' => ['maxlength' => 80]],
            ['title' => __('Reassurance lines', 'stackless-lite'), 'type' => 'textarea', 'id' => $p . 'trust_lines', 'default' => __('Payments are encrypted and processed by our payment provider.', 'stackless-lite'), 'desc' => __('One per line, up to 6. Keep them true for your store, for example your refund window or support hours.', 'stackless-lite'), 'css' => 'min-height:96px'],
            ['title' => __('Payment methods image', 'stackless-lite'), 'type' => self::MEDIA_TYPE, 'id' => Settings::TRUST_IMAGE, 'default' => 0, 'desc' => __('Use an image of the payment brands your gateways really accept. Its alt text from the media library is used at checkout.', 'stackless-lite')],
            ['title' => __('Refund policy page', 'stackless-lite'), 'type' => 'single_select_page', 'id' => $p . 'trust_policy_page', 'default' => '', 'class' => 'wc-enhanced-select-nostd', 'args' => ['show_option_none' => __('No link', 'stackless-lite'), 'option_none_value' => '']],
            ['title' => __('Link text', 'stackless-lite'), 'type' => 'text', 'id' => $p . 'trust_policy_label', 'default' => __('Refund policy', 'stackless-lite'), 'custom_attributes' => ['maxlength' => 60]],
            ['type' => 'sectionend', 'id' => $p . 'trust_section'],
        ]);
    }

    private function onScreen(): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $module = isset($_GET['module']) ? sanitize_key(wp_unslash($_GET['module'])) : (isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return in_array($page, [\Stackless\Admin\SettingsPage::SETTINGS, \Stackless\Admin\SettingsPage::PARENT], true) && $module === 'checkout_enhancements';
    }

    public function assets(): void
    {
        if (!$this->onScreen()) {
            return;
        }
        wp_enqueue_media();
        $url = STACKLESS_PLUGIN_URL . 'assets/checkout_enhancements/';
        wp_enqueue_style('stackless-checkout_enhancements-admin', $url . 'admin.css', [], STACKLESS_VERSION);
        wp_enqueue_script('stackless-checkout_enhancements-admin', $url . 'admin.js', ['jquery', 'wc-enhanced-select', 'media-views'], STACKLESS_VERSION, true);
        wp_localize_script('stackless-checkout_enhancements-admin', 'stacklessCeAdmin', [
            'presets' => Settings::consentPresets(),
            'types' => Settings::consentTypes(),
            'max' => ConsentRules::MAX_CONSENTS,
            'confirmRemove' => __('Remove this consent? Orders already placed keep their record.', 'stackless-lite'),
            'mediaTitle' => __('Choose a payment methods image', 'stackless-lite'),
            'mediaButton' => __('Use this image', 'stackless-lite'),
        ]);
    }

    public function sanitizeConsents($value, $option, $raw)
    {
        $previous = get_option(Settings::CONSENTS, []);
        return Settings::sanitizeConsents(is_array($raw) ? $raw : [], is_array($previous) ? $previous : []);
    }

    public function sanitizeMedia($value, $option, $raw)
    {
        $id = absint(is_scalar($raw) ? $raw : 0);
        return $id && wp_attachment_is_image($id) ? $id : 0;
    }

    public function renderConsents(array $field): void
    {
        $consents = Settings::consents();
        $name = $field['id'];
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp stackless-ce-forminp">';
        echo '<div class="stackless-ce-consents" data-ce-consents data-name="' . esc_attr($name) . '">';
        foreach ($consents as $index => $consent) {
            $this->consentRow($name, (string) $index, $consent);
        }
        echo '</div>';
        echo '<p class="stackless-ce-empty" data-ce-empty' . ($consents ? ' hidden' : '') . '>' . esc_html__('No consent checkboxes yet. Add one with the buttons below.', 'stackless-lite') . '</p>';
        echo '<div class="stackless-ce-add" role="group" aria-label="' . esc_attr__('Add a consent checkbox', 'stackless-lite') . '">';
        foreach (Settings::consentTypes() as $type => $label) {
            echo '<button type="button" class="button" data-ce-add="' . esc_attr($type) . '">+ ' . esc_html($label) . '</button>';
        }
        echo '</div>';
        /* translators: %d: maximum number of consent checkboxes */
        echo '<p class="description">' . esc_html(sprintf(__('Up to %d checkboxes. The suggested wording is a starting point; check it against your own policies and local law, especially the EU digital content waiver. Links in the wording show as plain text on the Checkout block.', 'stackless-lite'), ConsentRules::MAX_CONSENTS)) . '</p>';
        echo '<template id="stackless-ce-consent-template">';
        $this->consentRow($name, '__index__', ['id' => '', 'type' => 'custom', 'label' => '', 'required' => false, 'scope' => 'all', 'product_id' => 0, 'version' => 1]);
        echo '</template></td></tr>';
    }

    private function consentRow(string $name, string $index, array $consent): void
    {
        $prefix = $name . '[' . $index . ']';
        $id = 'stackless-ce-consent-' . $index;
        $types = Settings::consentTypes();
        $isNew = $index === '__index__';
        $product = !empty($consent['product_id']) ? wc_get_product((int) $consent['product_id']) : null;
        echo '<details class="stackless-ce-consent" data-ce-consent' . ($isNew ? ' open' : '') . '><summary><span data-ce-consent-title>' . esc_html($types[$consent['type']] ?? $types['custom']) . '</span>';
        echo '<span class="stackless-ce-badges"><span class="stackless-ce-badge" data-ce-required' . (empty($consent['required']) ? ' hidden' : '') . '>' . esc_html__('Required', 'stackless-lite') . '</span>';
        if (!$isNew) {
            /* translators: %d: wording version number */
            echo '<span class="stackless-ce-badge">' . esc_html(sprintf(__('Wording v%d', 'stackless-lite'), (int) $consent['version'])) . '</span>';
        }
        echo '</span></summary><div class="stackless-ce-consent-body">';
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[id]" value="' . esc_attr($consent['id']) . '">';
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[version]" value="' . (int) $consent['version'] . '">';
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[delete]" value="0" data-ce-delete>';
        echo '<div class="stackless-ce-grid"><p><label for="' . esc_attr($id) . '-type">' . esc_html__('Type', 'stackless-lite') . '</label><select id="' . esc_attr($id) . '-type" name="' . esc_attr($prefix) . '[type]" data-ce-type>';
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($consent['type'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p><p><label for="' . esc_attr($id) . '-scope">' . esc_html__('Show for', 'stackless-lite') . '</label><select id="' . esc_attr($id) . '-scope" name="' . esc_attr($prefix) . '[scope]" data-ce-scope>';
        foreach (Settings::consentScopes() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($consent['scope'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p><p data-ce-product' . ($consent['scope'] === 'product' ? '' : ' hidden') . '><label for="' . esc_attr($id) . '-product">' . esc_html__('Product', 'stackless-lite') . '</label>';
        echo '<select id="' . esc_attr($id) . '-product" name="' . esc_attr($prefix) . '[product_id]" class="' . ($isNew ? 'stackless-ce-product-search' : 'wc-product-search') . '" data-action="woocommerce_json_search_products" data-placeholder="' . esc_attr__('Search for a product', 'stackless-lite') . '" data-allow_clear="true" style="width:100%">';
        if ($product instanceof \WC_Product) {
            echo '<option value="' . (int) $product->get_id() . '" selected>' . esc_html(wp_strip_all_tags($product->get_formatted_name())) . '</option>';
        }
        echo '</select></p></div>';
        echo '<p class="stackless-ce-wording"><label for="' . esc_attr($id) . '-label">' . esc_html__('Checkbox text', 'stackless-lite') . '</label><textarea id="' . esc_attr($id) . '-label" name="' . esc_attr($prefix) . '[label]" rows="2" maxlength="600" data-ce-label>' . esc_textarea($consent['label']) . '</textarea>';
        echo '<small>' . esc_html__('Links, bold and italic are allowed on the classic checkout, for example <a href="/privacy-policy/">privacy policy</a>. Changing the text starts a new wording version.', 'stackless-lite') . '</small></p>';
        echo '<div class="stackless-ce-consent-footer"><label><input type="checkbox" name="' . esc_attr($prefix) . '[required]" value="1" data-ce-required-toggle' . checked(!empty($consent['required']), true, false) . '> ' . esc_html__('Customer must tick this to place the order', 'stackless-lite') . '</label>';
        echo '<button type="button" class="button-link button-link-delete" data-ce-remove>' . esc_html__('Remove', 'stackless-lite') . '</button></div></div></details>';
    }

    public function renderMedia(array $field): void
    {
        $id = absint(get_option($field['id'], 0));
        $url = $id ? (string) wp_get_attachment_image_url($id, 'medium') : '';
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($field['title'] ?? '') . '</th><td class="forminp"><div class="stackless-ce-media" data-ce-media>';
        echo '<img data-ce-media-preview src="' . esc_url($url) . '" alt=""' . ($url ? '' : ' hidden') . '>';
        echo '<input type="hidden" name="' . esc_attr($field['id']) . '" value="' . (int) $id . '" data-ce-media-id>';
        echo '<button type="button" class="button" data-ce-media-choose>' . esc_html__('Choose image', 'stackless-lite') . '</button> ';
        echo '<button type="button" class="button-link" data-ce-media-clear' . ($url ? '' : ' hidden') . '>' . esc_html__('Remove image', 'stackless-lite') . '</button></div>';
        if (!empty($field['desc'])) {
            echo '<p class="description">' . esc_html($field['desc']) . '</p>';
        }
        echo '</td></tr>';
    }

    public function metaBox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('stackless-checkout-consents', __('Checkout consents', 'stackless-lite'), [$this, 'renderMetaBox'], $screen, 'side', 'default');
    }

    /** @param \WP_Post|\WC_Order $object */
    public function renderMetaBox($object): void
    {
        $order = $object instanceof \WP_Post ? wc_get_order($object->ID) : $object;
        if (!$order instanceof \WC_Order || !current_user_can('edit_shop_orders')) {
            return;
        }
        $records = $order->get_meta(Module::META_CONSENTS, true);
        if (!is_array($records) || !$records) {
            echo '<p>' . esc_html__('No consent checkboxes were shown for this order.', 'stackless-lite') . '</p>';
            return;
        }
        echo '<ul class="stackless-ce-records">';
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['label'])) {
                continue;
            }
            $time = strtotime((string) ($record['at'] ?? '') . ' UTC');
            echo '<li><strong>' . esc_html(!empty($record['accepted']) ? __('Accepted', 'stackless-lite') : __('Not accepted', 'stackless-lite')) . '</strong>';
            echo !empty($record['required']) ? ' <span class="description">(' . esc_html__('required', 'stackless-lite') . ')</span>' : '';
            echo '<br>' . esc_html(wp_strip_all_tags((string) $record['label']));
            /* translators: 1: wording version, 2: date and time */
            echo '<br><small>' . esc_html(sprintf(__('Wording v%1$d · %2$s', 'stackless-lite'), (int) ($record['version'] ?? 1), $time ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '')) . '</small></li>';
        }
        echo '</ul>';
    }
}
