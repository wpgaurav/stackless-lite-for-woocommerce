<?php

namespace Stackless\Lite;

defined('ABSPATH') || exit;

/**
 * What Stackless (the full plugin) adds to Lite, as static text for the Dashboard cards and the
 * read-only details screens. Descriptions only: no Pro code, settings or data live here.
 */
final class ProCatalog
{
    public const UPGRADE_URL = 'https://gauravtiwari.org/product/stackless-for-woocommerce/';
    public const DOCS = 'https://gauravtiwari.org/docs/stackless-for-woocommerce/';

    /** @return array<string,string> group key => label, in display order */
    public static function groups(): array
    {
        return [
            'recurring' => __('Subscriptions', 'stackless-lite'),
            'checkout' => __('Checkout', 'stackless-lite'),
            'sales' => __('Sales & invoices', 'stackless-lite'),
            'currency' => __('Currency', 'stackless-lite'),
            'access' => __('Access & licensing', 'stackless-lite'),
            'store' => __('Store tools', 'stackless-lite'),
            'admin' => __('Admin', 'stackless-lite'),
        ];
    }

    /**
     * slug => title, group, icon (assets/icons), summary, features, docs (path under DOCS).
     *
     * @return array<string,array{title:string,group:string,icon:string,summary:string,features:string[],docs:string}>
     */
    public static function all(): array
    {
        return [
            'subscription-products' => [
                'title' => __('Subscriptions', 'stackless-lite'),
                'group' => 'recurring',
                'icon' => 'repeat',
                'summary' => __('Recurring billing on simple and variable products, with automatic or invoiced renewals and a customer portal.', 'stackless-lite'),
                'features' => [
                    __('Daily, weekly, monthly or yearly plans with free trials, signup fees and fixed-length terms', 'stackless-lite'),
                    __('Mixed carts: several subscriptions and one-time products in one checkout', 'stackless-lite'),
                    __('Renewals charge a saved payment method, or email the customer a pay link with any gateway', 'stackless-lite'),
                    __('Failed-payment retries on your schedule, plan switching with proration and recurring coupons', 'stackless-lite'),
                    __('Customers cancel, reactivate, switch plans and change their card from My Account', 'stackless-lite'),
                    __('Ten subscription emails, admin list and edit screens, and an MRR and churn widget', 'stackless-lite'),
                ],
                'docs' => 'subscriptions/subscription-products/',
            ],
            'wcs-migration' => [
                'title' => __('WooCommerce Subscriptions Migration', 'stackless-lite'),
                'group' => 'recurring',
                'icon' => 'transfer-in',
                'summary' => __('Move your WooCommerce Subscriptions subscriptions into Stackless with their dates, prices and past orders.', 'stackless-lite'),
                'features' => [
                    __('Scan first, then import after switching WooCommerce Subscriptions off, so nothing renews twice', 'stackless-lite'),
                    __('Saved Stripe and PayPal payment methods carry over on the same accounts', 'stackless-lite'),
                    __('Resumable imports, checked one by one afterwards', 'stackless-lite'),
                ],
                'docs' => 'subscriptions/migrate-from-woocommerce-subscriptions/',
            ],
            'billing-health' => [
                'title' => __('Billing Health', 'stackless-lite'),
                'group' => 'recurring',
                'icon' => 'heart-rate-monitor',
                'summary' => __('Failed and stuck renewals, missing schedules and payment provider problems on one screen.', 'stackless-lite'),
                'features' => [
                    __('On-hold and overdue subscriptions, stuck charges and webhook failures', 'stackless-lite'),
                    __('Safe fixes with a log of every fix, plus a Site Health test', 'stackless-lite'),
                    __('Why customers cancelled lately', 'stackless-lite'),
                ],
                'docs' => 'subscriptions/billing-health/',
            ],
            'subscription-save-offers' => [
                'title' => __('Subscription Save Offers', 'stackless-lite'),
                'group' => 'recurring',
                'icon' => 'heart',
                'summary' => __('Ask why customers cancel and make 1 offer that fits the reason, before the subscription ends.', 'stackless-lite'),
                'features' => [
                    __('A renewal discount, a pause, a skipped payment, a cheaper or yearly plan, or your own message', 'stackless-lite'),
                    __('Declining cancels with no more questions; every attempt is logged with the save rate by reason', 'stackless-lite'),
                    __('1 optional win-back email after a subscription ends, with a one-click unsubscribe', 'stackless-lite'),
                ],
                'docs' => 'subscriptions/save-offers/',
            ],
            'checkout-builder' => [
                'title' => __('Checkout Builder', 'stackless-lite'),
                'group' => 'checkout',
                'icon' => 'layout-columns',
                'summary' => __('Five ready-made checkout layouts in your colours, previewed and switched on without an editor.', 'stackless-lite'),
                'features' => [
                    __('Five layouts for the Checkout block and the classic checkout', 'stackless-lite'),
                    __('Your brand colours, with a live preview before you switch', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/checkout-builder/',
            ],
            'on-page-checkout' => [
                'title' => __('On-page Checkout', 'stackless-lite'),
                'group' => 'checkout',
                'icon' => 'browser-check',
                'summary' => __('Plan cards and your checkout on any page, so buyers choose and pay without leaving it.', 'stackless-lite'),
                'features' => [
                    __('A block and a shortcode for any page', 'stackless-lite'),
                    __('Each plan gets its own cart, so the store cart is left alone', 'stackless-lite'),
                    __('After payment the whole page moves to the receipt', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/on-page-checkout/',
            ],
            'modal-checkout' => [
                'title' => __('Modal Checkout', 'stackless-lite'),
                'group' => 'checkout',
                'icon' => 'app-window',
                'summary' => __('Buy buttons and checkout links open your checkout in a modal on the same page.', 'stackless-lite'),
                'features' => [
                    __('Turn it on with a class, a link hash, or for every Add to cart and checkout link', 'stackless-lite'),
                    __('Classes that limit the payment methods a modal offers, enforced on the server', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/modal-checkout/',
            ],
            'checkout-recovery' => [
                'title' => __('Checkout Recovery', 'stackless-lite'),
                'group' => 'checkout',
                'icon' => 'mail-forward',
                'summary' => __('Emails that bring shoppers back to a checkout they left, with their cart restored.', 'stackless-lite'),
                'features' => [
                    __('Up to three reminders, with an optional coupon in the last one', 'stackless-lite'),
                    __('Only with consent, with one-click unsubscribe', 'stackless-lite'),
                    __('Recovered revenue in the admin and FluentCRM triggers', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/checkout-recovery/',
            ],
            'vat-numbers' => [
                'title' => __('VAT Numbers', 'stackless-lite'),
                'group' => 'checkout',
                'icon' => 'file-certificate',
                'summary' => __('A VAT number field with EU VIES checks and reverse charge for business buyers.', 'stackless-lite'),
                'features' => [
                    __('Works on the Checkout block and the classic checkout', 'stackless-lite'),
                    __('Validated numbers and their proof are kept on the order', 'stackless-lite'),
                    __('Reverse charge for valid business buyers in other EU countries', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/vat-numbers/',
            ],
            'cart-goals' => [
                'title' => __('Cart Goals', 'stackless-lite'),
                'group' => 'checkout',
                'icon' => 'truck-delivery',
                'summary' => __('A progress bar toward spend goals in the cart and checkout.', 'stackless-lite'),
                'features' => [
                    __('Up to 3 goals with free shipping or a discount as the reward', 'stackless-lite'),
                    __('Rewards added and removed automatically, checked on the server', 'stackless-lite'),
                    __('Works on the Cart and Checkout blocks and the classic cart, mini cart and checkout', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/cart-goals/',
            ],
            'order-bumps' => [
                'title' => __('Order Bumps', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'shopping-cart-plus',
                'summary' => __('One-click add-on offers at checkout.', 'stackless-lite'),
                'features' => [
                    __('Shown by cart rules such as products, categories and cart total', 'stackless-lite'),
                    __('Discounted prices calculated on the server', 'stackless-lite'),
                    __('Take rates per bump', 'stackless-lite'),
                    __('Works on the Checkout block and the classic checkout', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/order-bumps/',
            ],
            'post-purchase-offers' => [
                'title' => __('Post-purchase Offers', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'hand-click',
                'summary' => __('One-click offers on the thank-you page, charged to the payment method the customer just used.', 'stackless-lite'),
                'features' => [
                    __('Offers shown by rules, priced on the server, with take rates', 'stackless-lite'),
                    __('Charged only on the customer\'s click, never twice', 'stackless-lite'),
                    __('Falls back to a pay link when a one-click charge isn\'t possible', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/post-purchase-offers/',
            ],
            'sale-campaigns' => [
                'title' => __('Sale Campaigns', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'tag',
                'summary' => __('Scheduled sales that start and end by themselves, with sale prices, a badge and a countdown to the real end.', 'stackless-lite'),
                'features' => [
                    __('A percentage or fixed amount off the whole store, chosen categories or chosen products', 'stackless-lite'),
                    __('Regular prices back on time, because product prices are never changed', 'stackless-lite'),
                    __('A countdown block and shortcode, and an optional announcement bar', 'stackless-lite'),
                    __('Works with the Cart and Checkout blocks, the classic checkout and Multi-Currency', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/sale-campaigns/',
            ],
            'gift-cards' => [
                'title' => __('Gift Cards and Store Credit', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'gift-card',
                'summary' => __('Sell gift cards and keep store credit for customers.', 'stackless-lite'),
                'features' => [
                    __('Gift card products that email a code to the recipient', 'stackless-lite'),
                    __('Store credit balances with a full history', 'stackless-lite'),
                    __('Refunds to store credit, and partial redemption at checkout', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/gift-cards-and-store-credit/',
            ],
            'custom-invoices' => [
                'title' => __('Custom Invoices', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'file-invoice',
                'summary' => __('Invoice customers for products or custom items with a branded invoice and a secure pay link.', 'stackless-lite'),
                'features' => [
                    __('Numbered, printable invoices in six layouts', 'stackless-lite'),
                    __('Recurring invoices, deposits with automatic balance invoices and due-date reminders', 'stackless-lite'),
                    __('A library of reusable invoice-only items and remembered customer details', 'stackless-lite'),
                    __('VAT and company registration numbers on the invoice', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/custom-invoices/',
            ],
            'request-a-quote' => [
                'title' => __('Request a Quote', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'message-2-dollar',
                'summary' => __('Customers ask for a quote from any page or product, and accept your proposal online.', 'stackless-lite'),
                'features' => [
                    __('A quote form for any page or product', 'stackless-lite'),
                    __('Reply with a proposal the customer accepts online', 'stackless-lite'),
                    __('Accepting issues the invoice and takes the customer to payment', 'stackless-lite'),
                ],
                'docs' => 'checkout-and-sales/request-a-quote/',
            ],
            'coupon-affiliates' => [
                'title' => __('Coupon Affiliates', 'stackless-lite'),
                'group' => 'sales',
                'icon' => 'tag',
                'summary' => __('Customers and partners earn a commission on every paid order that uses their coupon.', 'stackless-lite'),
                'features' => [
                    __('Personal codes from My Account, or any coupon you assign', 'stackless-lite'),
                    __('A refund hold and automatic reversal on refunds and cancellations', 'stackless-lite'),
                    __('Manual payouts with CSV export', 'stackless-lite'),
                ],
                'docs' => '',
            ],
            'multi-currency' => [
                'title' => __('Multi-Currency Checkout', 'stackless-lite'),
                'group' => 'currency',
                'icon' => 'world',
                'summary' => __('Sell in several currencies with one settlement currency per cart and order.', 'stackless-lite'),
                'features' => [
                    __('Exact prices per product and per variation in each currency', 'stackless-lite'),
                    __('Manual or Frankfurter exchange rates, with markup and rounding', 'stackless-lite'),
                    __('Rules for products without a price in the shopper’s currency', 'stackless-lite'),
                    __('Subscription renewals keep the currency they started in', 'stackless-lite'),
                ],
                'docs' => 'multi-currency/multi-currency/',
            ],
            'currency-selector' => [
                'title' => __('Storefront Currency Selector', 'stackless-lite'),
                'group' => 'currency',
                'icon' => 'currency-dollar',
                'summary' => __('Let shoppers pick their currency.', 'stackless-lite'),
                'features' => [
                    __('A shortcode, a block, or a selector above the checkout payment methods', 'stackless-lite'),
                    __('Switching reprices the cart on the server', 'stackless-lite'),
                ],
                'docs' => 'multi-currency/currency-selector/',
            ],
            'currency-gateways' => [
                'title' => __('Currency-Aware Payment Gateways', 'stackless-lite'),
                'group' => 'currency',
                'icon' => 'arrows-exchange',
                'summary' => __('Choose which payment gateways handle each currency.', 'stackless-lite'),
                'features' => [
                    __('Pick the gateways, and the one shown first, per currency', 'stackless-lite'),
                    __('Unsupported gateways are removed at checkout and rejected on the server', 'stackless-lite'),
                ],
                'docs' => 'multi-currency/currency-gateways/',
            ],
            'software-licensing' => [
                'title' => __('Software Licensing', 'stackless-lite'),
                'group' => 'access',
                'icon' => 'key',
                'summary' => __('Sell plugins and themes with license keys and automatic updates from your own store.', 'stackless-lite'),
                'features' => [
                    __('License keys per order item or subscription', 'stackless-lite'),
                    __('Site activation limits that customers manage from My Account', 'stackless-lite'),
                    __('A license API to validate, activate and deactivate', 'stackless-lite'),
                    __('An update server for your plugins and themes', 'stackless-lite'),
                ],
                'docs' => 'software-and-access/software-licensing/',
            ],
            'page-access' => [
                'title' => __('Page Access', 'stackless-lite'),
                'group' => 'access',
                'icon' => 'lock',
                'summary' => __('Unlock chosen pages for customers who buy a product.', 'stackless-lite'),
                'features' => [
                    __('Published pages show an access notice to everyone else', 'stackless-lite'),
                    __('Private pages stay hidden from everyone except buyers', 'stackless-lite'),
                    __('Subscriptions keep access while they are active', 'stackless-lite'),
                    __('A My pages tab in My Account', 'stackless-lite'),
                ],
                'docs' => 'software-and-access/page-access/',
            ],
            'purchase-rules' => [
                'title' => __('Purchase Rules', 'stackless-lite'),
                'group' => 'access',
                'icon' => 'shield-lock',
                'summary' => __('Choose who can buy a product, and from which countries.', 'stackless-lite'),
                'features' => [
                    __('Limit buyers by role, FluentCRM tag or WP Fusion tag, or to one purchase each', 'stackless-lite'),
                    __('Country lock by visitor location from Cloudflare and other CDN headers', 'stackless-lite'),
                    __('Country lock by billing or shipping country, with checkout lists trimmed to match', 'stackless-lite'),
                    __('Store-wide rule with per-product and per-variation overrides', 'stackless-lite'),
                ],
                'docs' => 'software-and-access/purchase-rules/',
            ],
            'product-addons' => [
                'title' => __('Fields & Paid Add-ons', 'stackless-lite'),
                'group' => 'store',
                'icon' => 'puzzle',
                'summary' => __('Product add-ons with prices, and checkout fields with optional fees.', 'stackless-lite'),
                'features' => [
                    __('Checkbox, dropdown, text and number add-ons', 'stackless-lite'),
                    __('Per-item or one-off add-on prices', 'stackless-lite'),
                    __('Collapsible checkout fields with optional fees', 'stackless-lite'),
                    __('Works on the Checkout block and the classic checkout', 'stackless-lite'),
                ],
                'docs' => 'store-tools/product-addons/',
            ],
            'frequently-bought-together' => [
                'title' => __('Frequently Bought Together', 'stackless-lite'),
                'group' => 'store',
                'icon' => 'shopping-cart-plus',
                'summary' => __('The product with up to 3 companions, a combined total and one button that adds the checked items.', 'stackless-lite'),
                'features' => [
                    __('Your picks per product first, then products from paid order history', 'stackless-lite'),
                    __('An optional set discount that comes off when an item leaves the cart', 'stackless-lite'),
                    __('Automatic placement on the product page, a block and a shortcode', 'stackless-lite'),
                ],
                'docs' => 'catalog/frequently-bought-together/',
            ],
            'wholesale-pricing' => [
                'title' => __('Wholesale Pricing', 'stackless-lite'),
                'group' => 'store',
                'icon' => 'building-warehouse',
                'summary' => __('Prices by customer role and quantity tiers.', 'stackless-lite'),
                'features' => [
                    __('Role prices and quantity breaks per product, variation or category', 'stackless-lite'),
                    __('A tier table on the product page', 'stackless-lite'),
                    __('Works with Hide Price Until Login and multi-currency', 'stackless-lite'),
                ],
                'docs' => 'catalog/wholesale-pricing/',
            ],
            'delivery-slots' => [
                'title' => __('Delivery & Pickup Slots', 'stackless-lite'),
                'group' => 'store',
                'icon' => 'truck-delivery',
                'summary' => __('Customers pick a delivery or pickup time at checkout.', 'stackless-lite'),
                'features' => [
                    __('Weekly time windows with a capacity per slot', 'stackless-lite'),
                    __('Lead time and closed dates', 'stackless-lite'),
                    __('Availability per shipping method', 'stackless-lite'),
                ],
                'docs' => 'store-tools/delivery-slots/',
            ],
            'webhooks-and-rest-api' => [
                'title' => __('Webhooks and REST API', 'stackless-lite'),
                'group' => 'admin',
                'icon' => 'arrows-exchange',
                'summary' => __('Connect your store to Zapier, Make, n8n or your own code.', 'stackless-lite'),
                'features' => [
                    __('Subscription and license events in WooCommerce webhooks', 'stackless-lite'),
                    __('A subscriptions REST API that works with WooCommerce REST API keys', 'stackless-lite'),
                ],
                'docs' => '',
            ],
            'fluentcrm-sync' => [
                'title' => __('FluentCRM', 'stackless-lite'),
                'group' => 'admin',
                'icon' => 'sparkles',
                'summary' => __('Connect subscriptions, licenses, invoices and quotes to FluentCRM.', 'stackless-lite'),
                'features' => [
                    __('Automation triggers for subscriptions, renewals, licenses, invoices and quotes', 'stackless-lite'),
                    __('Tags and lists that follow subscription status', 'stackless-lite'),
                    __('Smart codes for emails', 'stackless-lite'),
                    __('Subscriptions and licenses on each contact’s profile', 'stackless-lite'),
                ],
                'docs' => '',
            ],
            'ai-agents' => [
                'title' => __('AI Agents', 'stackless-lite'),
                'group' => 'admin',
                'icon' => 'sparkles',
                'summary' => __('Let Claude, ChatGPT, Cursor and other agents run your store through the free Site Agent plugin.', 'stackless-lite'),
                'features' => [
                    __('Sales reports, orders, invoices, quotes, subscriptions and license keys', 'stackless-lite'),
                    __('Stock alerts, product details and page access', 'stackless-lite'),
                    __('Agents work as a WordPress user you choose', 'stackless-lite'),
                    __('They ask you before anything reaches a customer', 'stackless-lite'),
                ],
                'docs' => '',
            ],
            'woo-redesign' => [
                'title' => __('Woo Redesign', 'stackless-lite'),
                'group' => 'admin',
                'icon' => 'palette',
                'summary' => __('A cleaner WooCommerce admin in the same look as the Stackless screens.', 'stackless-lite'),
                'features' => [
                    __('Orders, products, coupons, settings, status and analytics restyled', 'stackless-lite'),
                    __('Pick the areas, the accent colour and the table density', 'stackless-lite'),
                    __('Nothing changes on your storefront', 'stackless-lite'),
                ],
                'docs' => '',
            ],
        ];
    }

    /** @return array{title:string,group:string,icon:string,summary:string,features:string[],docs:string}|null */
    public static function get(string $slug): ?array
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    public static function docsUrl(string $slug): string
    {
        $entry = self::get($slug);
        return self::DOCS . ($entry ? $entry['docs'] : '');
    }
}
