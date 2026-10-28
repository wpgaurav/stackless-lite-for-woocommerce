<img src=".github/assets/icon-256x256.png" width="96" height="96" alt="Stackless Lite for WooCommerce logo">

# Stackless Lite for WooCommerce

Seventeen free WooCommerce store tools in one plugin: wishlist, saved carts, back-in-stock alerts, min/max quantities, buy links, pay what you like, checkout tweaks and catalog labels. Each one switches on and off on its own, and a switched-off module loads no code.

It runs on core WooCommerce alone, works with the Cart and Checkout blocks and the classic checkout, and supports HPOS.

**[Product page](https://gauravtiwari.org/product/stackless-lite-for-woocommerce/)** · [Download the latest ZIP](https://gauravtiwari.org/wp-json/gt-plugin-updates/v1/plugins/stackless-lite-for-woocommerce/download) · [Documentation](https://gauravtiwari.org/docs/stackless-for-woocommerce/) · [Upgrade to Stackless for WooCommerce](https://gauravtiwari.org/product/stackless-for-woocommerce/)

**Latest version:** 1.7.1 · **Requires:** WordPress 6.7+, WooCommerce 9.9+, PHP 7.4+ · **License:** GPL-2.0-or-later

## Modules

| Group | Module | What it does |
|---|---|---|
| Catalog | Free Price Text | "Free" (or your text) instead of a zero price |
| Catalog | Custom Add to Cart Text | Button text per product type, product or variation |
| Catalog | Sold Count | An "X sold" badge once a product passes a threshold |
| Catalog | Stock Urgency Labels | "Only 3 left" style labels when stock runs low |
| Catalog | Min/Max Quantity | Minimum, maximum and step quantities, enforced on block and classic carts |
| Catalog | Hide Price Until Login | Hide prices and buying from guests, store-wide, by category or per product |
| Catalog | External Product Extras | Link rel/target, price hiding and click counts for External/Affiliate products |
| Catalog | Product Details Table | A specifications table with presets that feeds product schema |
| Store tools | Wishlist | Guest and account wishlists that merge on sign-in, with share links |
| Store tools | Back-in-stock Alerts | A confirmed "Notify me" form and one email when stock returns |
| Store tools | Saved Carts & Order Again | Named saved carts and reorder from any past order |
| Store tools | Service Products | No shipping, after-payment instructions and a booking link |
| Store tools | WooCommerce Optimizer | WooCommerce assets only on store pages, leaner sessions and logs |
| Checkout | Checkout Enhancements | Consent checkboxes, a shorter checkout for free and digital orders, your own wording |
| Checkout | Extra Enhancements | Per-product payment methods, sticky ?coupon= links, internal test emails, a checkout guard |
| Sales | Buy Links | Signed links that fill the cart and open checkout, plus a Buy now button |
| Sales | Pay What You Like | Buyers choose the price above your minimum, checked on the server |

## Install

1. Download `stackless-lite-<version>.zip` from the [latest release](https://github.com/wpgaurav/stackless-lite-for-woocommerce/releases/latest) or the [product page](https://gauravtiwari.org/product/stackless-lite-for-woocommerce/). Use the release ZIP, not GitHub's "Download ZIP" button: the plugin folder must be named `stackless-lite` for updates to work.
2. In WordPress, go to Plugins → Add New → Upload Plugin, upload the ZIP and activate it.
3. Open **Stackless → Dashboard** and switch on the modules you need. Configure them under **Stackless → Settings**.

Updates arrive through the normal WordPress update screens from gauravtiwari.org. No account or license key is needed, and the update check sends nothing beyond the standard WordPress request.

## Upgrading to Stackless

[Stackless for WooCommerce](https://gauravtiwari.org/product/stackless-for-woocommerce/) is the full plugin. It adds subscriptions, Stripe, PayPal, Polar, Paddle and Dodo Payments gateways, software licensing, multi-currency, invoices and quotes, order bumps, product add-ons, delivery slots and more.

Both plugins use the same option names, product meta, tables, hooks and REST routes. Install and activate Stackless: it switches Lite off and keeps every setting, wishlist, saved cart and back-in-stock subscriber. Going back works too: Lite leaves the data of Stackless-only modules untouched.

## Documentation

Module guides live at [gauravtiwari.org/docs/stackless-for-woocommerce](https://gauravtiwari.org/docs/stackless-for-woocommerce/). Every module has a **Docs** link on the Dashboard.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and small, focused pull requests are welcome.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
