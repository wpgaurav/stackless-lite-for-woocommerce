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
            'payments' => __('Payments', 'stackless-lite'),
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
            'stripe' => [
                'title' => __('Stripe', 'stackless-lite'),
                'group' => 'payments',
                'icon' => 'brand-stripe',
                'summary' => __('Cards, Apple Pay, Google Pay, Link and local payment methods through the Stripe Payment Element.', 'stackless-lite'),
                'features' => [
                    __('3D Secure, saved cards and express checkout buttons', 'stackless-lite'),
                    __('Automatic off-session subscription renewals', 'stackless-lite'),
                    __('Refunds from the order screen and signed webhooks', 'stackless-lite'),
                    __('Works on the Checkout block and the classic checkout', 'stackless-lite'),
                ],
                'docs' => 'payments/stripe/',
            ],
            'paypal' => [
                'title' => __('PayPal', 'stackless-lite'),
                'group' => 'payments',
                'icon' => 'brand-paypal',
                'summary' => __('PayPal Smart Buttons and Pay Later on the block and classic checkout, through PayPal’s REST APIs.', 'stackless-lite'),
                'features' => [
                    __('Smart Buttons and Pay Later messaging', 'stackless-lite'),
                    __('Saved PayPal accounts that renew subscriptions automatically', 'stackless-lite'),
                    __('Refunds from WooCommerce', 'stackless-lite'),
                ],
                'docs' => 'payments/paypal/',
            ],
            'polar' => [
                'title' => __('Polar', 'stackless-lite'),
                'group' => 'payments',
                'icon' => 'bolt',
                'summary' => __('Polar as a merchant of record: hosted checkout with sales tax and VAT handled for you.', 'stackless-lite'),
                'features' => [
                    __('One-time orders and pay links', 'stackless-lite'),
                    __('Single-product subscriptions with free trials that Polar renews', 'stackless-lite'),
                    __('Payments, renewals, refunds and cancellations sync both ways through signed webhooks', 'stackless-lite'),
                ],
                'docs' => 'payments/polar/',
            ],
            'paddle' => [
                'title' => __('Paddle', 'stackless-lite'),
                'group' => 'payments',
                'icon' => 'credit-card',
                'summary' => __('Paddle Billing as a merchant of record, with an overlay checkout and sales tax and VAT handled for you.', 'stackless-lite'),
                'features' => [
                    __('One-time orders and pay links', 'stackless-lite'),
                    __('Subscriptions that Paddle renews, with signup fees, free trials and one-time items in the same cart', 'stackless-lite'),
                    __('Customers update their card through Paddle from My Account', 'stackless-lite'),
                    __('Two-way sync of payments, renewals, refunds and cancellations', 'stackless-lite'),
                ],
                'docs' => '',
            ],
            'dodo-payments' => [
                'title' => __('Dodo Payments', 'stackless-lite'),
                'group' => 'payments',
                'icon' => 'wallet',
                'summary' => __('Dodo Payments as a merchant of record: hosted checkout with sales tax, VAT and GST handled for you.', 'stackless-lite'),
                'features' => [
                    __('Cards, UPI, wallets and local payment methods', 'stackless-lite'),
                    __('One-time orders in any currency Dodo supports, and pay links', 'stackless-lite'),
                    __('Single-product subscriptions with free trials that Dodo renews', 'stackless-lite'),
                    __('Two-way sync through signed webhooks', 'stackless-lite'),
                ],
                'docs' => 'payments/dodo-payments/',
            ],
            'payment-gateway-tools' => [
                'title' => __('Payment Gateway Tools', 'stackless-lite'),
                'group' => 'payments',
                'icon' => 'copy',
                'summary' => __('Run Polar, Paddle or Dodo Payments with a second account.', 'stackless-lite'),
                'features' => [
                    __('Duplicate a hosted gateway: each copy has its own keys, webhook URL, badge and orders', 'stackless-lite'),
                    __('Rename or remove copies at any time', 'stackless-lite'),
                    __('A Stackless label on its gateways in payment settings and order screens', 'stackless-lite'),
                ],
                'docs' => 'payments/payment-gateway-tools/',
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
