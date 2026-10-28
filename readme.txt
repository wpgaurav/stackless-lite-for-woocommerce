=== Stackless Lite for WooCommerce ===
Contributors: gauravtiwari
Tags: woocommerce, stripe, paypal, wishlist, back in stock
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.9
WC tested up to: 11.2
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

23 free WooCommerce modules: Stripe, PayPal, Polar, Paddle and Dodo Payments, wishlists, saved carts, back-in-stock alerts and more.

== Description ==

Most WooCommerce stores collect a small plugin for every little job: one for the wishlist, one for back-in-stock emails, one for min/max quantities, one for the "Only 3 left" label. Stackless Lite puts those jobs, and five payment gateways, into one plugin that runs on core WooCommerce alone.

Switch on only what you use from Stackless → Dashboard. A switched-off module loads no code.

Every module that touches the cart or checkout works with the Cart and Checkout blocks and with the classic checkout. HPOS is supported.

= Catalog =

* **Free Price Text:** "Free" (or your text) instead of a zero price on product pages, shop grids, the cart and checkout.
* **Custom Add to Cart Text:** button text per product type, for free and sold-out products, and per product or variation.
* **Sold Count:** an "X sold" badge once a product passes a threshold, with an optional count multiplier.
* **Stock Urgency Labels:** "Only 3 left" style labels with separate low and very-low thresholds.
* **Min/Max Quantity:** minimum, maximum and step quantities store-wide, per product and per variation, enforced on the block and classic cart.
* **Hide Price Until Login:** hide prices and buying from guests, store-wide, by category or per product.
* **External Product Extras:** link rel and target, price hiding and click counts for External/Affiliate products, which can never be added to the cart.
* **Product Details Table:** a specifications table with presets for physical goods, downloads, books, software and services that feeds product schema.

= Store tools =

* **Wishlist:** guest and account wishlists that merge on sign-in, with buttons on product pages and shop grids, a wishlist page, a My Account tab, share links and move to cart.
* **Back-in-stock Alerts:** a confirmed "Notify me" form on out-of-stock products and variations, and one email when stock returns.
* **Saved Carts & Order Again:** customers save their cart under a name, restore it later and reorder from any past order.
* **Service Products:** no shipping, plus after-payment instructions and an optional booking or intake link.
* **WooCommerce Optimizer:** WooCommerce assets only on store pages, no cart and checkout prefetch, deferred store scripts and leaner sessions and logs.

= Checkout and sales =

* **Checkout Enhancements:** consent checkboxes with stored proof, a shorter checkout for free and digital orders, a "Get it for free" button, your own checkout wording and a trust block under the payment methods.
* **Extra Enhancements:** payment methods and an after-purchase link per product, ?coupon= links that wait for a cart, internal test emails that skip accounts and integrations, and a checkout guard against throwaway emails and card testing.
* **Buy Links:** shareable links that fill the cart and open checkout, with coupons, multiple products and tamper-proof signatures, plus an optional Buy now button.
* **Pay What You Like:** buyers choose what to pay, at or above a minimum, with a suggested amount, quick amounts and an optional maximum. The amount is checked on the server.

= Payments =

* **Stripe:** cards, Apple Pay, Google Pay, Link and local payment methods, with refunds from the order screen.
* **PayPal:** PayPal Smart Buttons and Pay Later at checkout, with refunds.
* **Polar:** Polar as a merchant of record, with hosted checkout and sales tax and VAT handled for you.
* **Paddle:** Paddle Billing as a merchant of record, with a checkout that handles sales tax and VAT for you.
* **Dodo Payments:** Dodo Payments as a merchant of record, with hosted checkout and sales tax, VAT and GST handled for you.
* **Payment Gateway Tools:** take payments with more than one Polar, Paddle or Dodo Payments account.

The email for failed live payments in Extra Enhancements watches these gateways.

= Need more? =

Stackless (the full plugin) adds subscriptions with automatic renewals and saved payment methods, software licensing, multi-currency, invoices and quotes, order bumps, product add-ons, delivery slots and more. The Dashboard lists those modules with a short description of each; nothing in Lite is locked, time-limited or reduced.

= Privacy =

Stackless Lite sends no usage data and has no tracking.
To offer updates it asks gauravtiwari.org about the latest Stackless Lite release about twice a day, with the standard WordPress request that every update check makes.
Back-in-stock alerts and wishlists store the email addresses and products customers give them in your own database.

== External services ==

The payment modules connect to the payment provider you switch on, and only that one. Nothing is sent until you add your API keys and a customer pays with that provider.

= Stripe =

When a customer pays with Stripe, the checkout loads Stripe.js from js.stripe.com so card details go straight to Stripe and never reach your server. Your site sends the order amount, currency, order ID, and the customer's name and email to the Stripe API (api.stripe.com) to create the payment, and Stripe sends payment events back to your site's webhook. Stripe's [Services Agreement](https://stripe.com/legal/ssa) and [Privacy Policy](https://stripe.com/privacy) apply.

= PayPal =

When a customer pays with PayPal, the checkout loads the PayPal JavaScript SDK from www.paypal.com and your site creates and captures the order through the PayPal API (api-m.paypal.com, or the sandbox in test mode), sending the order amount, currency, a description of the purchase and the order ID. PayPal sends payment events back to your site's webhook. PayPal's [User Agreement](https://www.paypal.com/us/legalhub/useragreement-full) and [Privacy Statement](https://www.paypal.com/us/legalhub/privacy-full) apply.

= Polar =

When a customer pays with Polar, your site creates a checkout through the Polar API (api.polar.sh, or sandbox-api.polar.sh in test mode), sending the products, amount, currency, order ID and the customer's email, and sends the customer to Polar's hosted checkout. Polar sends payment events back to your site's webhook. Polar's [Terms of Service](https://polar.sh/legal/terms) and [Privacy Policy](https://polar.sh/legal/privacy) apply.

= Paddle =

When a customer pays with Paddle, the pay page loads Paddle.js from cdn.paddle.com and your site creates a transaction through the Paddle API (api.paddle.com, or sandbox-api.paddle.com in test mode), sending the products, amount, currency, order ID and the customer's email. Paddle sends payment events back to your site's webhook. Paddle's [Terms](https://www.paddle.com/legal/terms) and [Privacy Policy](https://www.paddle.com/legal/privacy) apply.

= Dodo Payments =

When a customer pays with Dodo Payments, your site creates a checkout through the Dodo Payments API (live.dodopayments.com, or test.dodopayments.com in test mode), sending the products, amount, currency, order ID and the customer's name and email, and sends the customer to Dodo's hosted checkout. Dodo Payments sends payment events back to your site's webhook. Dodo Payments' [Terms of Use](https://dodopayments.com/legal/terms-of-use) and [Privacy Policy](https://dodopayments.com/legal/privacy-policy) apply.

= Links =

* [Product page and free download](https://gauravtiwari.org/product/stackless-lite-for-woocommerce/)
* [Documentation](https://gauravtiwari.org/docs/stackless-for-woocommerce/)
* [Source code on GitHub](https://github.com/wpgaurav/stackless-lite-for-woocommerce)
* [Stackless for WooCommerce](https://gauravtiwari.org/product/stackless-for-woocommerce/), the full plugin

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the ZIP from Plugins → Add New → Upload Plugin, or upload the `stackless-lite` folder to `/wp-content/plugins/`.
3. Activate Stackless Lite for WooCommerce.
4. Open Stackless → Dashboard and switch on the modules you need.
5. Configure each module under Stackless → Settings.

== Frequently Asked Questions ==

= Is anything in Lite locked or time-limited? =

No. Every Lite module is complete and free forever. The Dashboard also describes the modules that only Stackless has, but those are descriptions with a link, not disabled features.

= How do I upgrade to Stackless? =

Buy Stackless from gauravtiwari.org, then upload and activate it from Plugins → Add New → Upload Plugin. You don't need to deactivate Lite first: Stackless switches Lite off on activation and shows "Stackless replaced Stackless Lite; your settings and data carried over". You can then delete Stackless Lite.

= Do I lose my settings, wishlists or saved carts when I upgrade? =

No. Stackless and Stackless Lite use the same option names, product meta, database tables, hooks and REST routes. Everything you set up in Lite (module switches, settings, wishlists, saved carts, back-in-stock subscribers, quantity limits and other product settings) is read by Stackless unchanged.

= Can I go back from Stackless to Lite? =

Yes. Deactivate Stackless, then activate Lite. Lite keeps working with its own modules and leaves everything else Stackless stored (subscriptions, licenses, invoices and their settings) untouched in the database, so switching back to Stackless later picks it up again.

= Can both plugins be active at the same time? =

No, and you don't need that: Stackless contains every Lite module. While Stackless is active, Lite stays idle and Stackless deactivates it.

= Does deleting Lite delete my data? =

No. Data is kept unless you define `STACKLESS_REMOVE_ALL_DATA` as true in wp-config.php before deleting the plugin, and even then nothing is removed while Stackless is installed, because it uses the same data.

= Does it work with the Cart and Checkout blocks? =

Yes. Every module that touches the cart or checkout supports the Cart and Checkout blocks and the classic checkout.

= How does Lite update? =

Through the normal WordPress update screens, from gauravtiwari.org. No license key or account is needed.

== Changelog ==

= 1.7.1 =
* New: Stripe, PayPal, Polar, Paddle and Dodo Payments gateways and Payment Gateway Tools are now part of Stackless Lite.
* New: each payment gateway's settings have buttons that open the provider's API key page for live and test mode. Saving Stripe, PayPal or Paddle keys on a live HTTPS site creates the webhook and saves its signing secret or ID for you.
* New: activating Stackless switches off Stackless Lite, keeps all of its settings and data, and turns on Stackless updates.
* Fixed: when a payment webhook and the customer's return arrived at the same moment, an order could be completed twice and send duplicate emails.
* Fixed: PayPal never captures a second payment for an order another tab already paid.
* Fixed: a Paddle payment that used a Paddle discount code no longer counts as paying the full order, and the Paddle checkout no longer shows a discount field.
* Fixed: Hide Price Until Login no longer lets Store API price filters reveal hidden prices.
* Fixed: a Buy link or a Saved Carts "Replace" that cannot add anything keeps the shopper's cart.
* Fixed: a shared wishlist shows who saved it again.
* Changed: passes WordPress Plugin Check.

= 1.7.0 =
* First public release.
