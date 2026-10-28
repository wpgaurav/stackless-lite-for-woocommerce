# Contributing to Stackless Lite for WooCommerce

Thanks for helping. A few things to know before you open an issue or a pull request.

## How this repository works

Stackless Lite is generated from the codebase of Stackless for WooCommerce, the full plugin. Each release here is a build of that codebase with only the free modules. That has two consequences:

- **Pull requests are ported, not merged directly.** When a change is accepted, it is applied upstream and arrives here with the next release, with credit to you in the changelog. Please keep pull requests small and focused on one fix.
- **The same code runs in both plugins.** A fix to a Lite module also fixes Stackless, and settings, meta keys, table names, hooks and REST routes must not change, because Stackless reads Lite's data unchanged.

## Reporting a bug

Use the bug report template and include:

- WordPress, WooCommerce and PHP versions
- Block checkout or classic checkout
- The module involved and the steps that show the problem
- What you expected and what happened, with any errors from WooCommerce → Status → Logs (sources starting with `stackless-`)

Security problems: please don't open a public issue. Email the details through the contact form at https://gauravtiwari.org/contact/ and you'll get a reply within a few days.

## Code rules

- PHP 7.4 syntax (no `match`, enums, `readonly`, named arguments, `str_contains`)
- WooCommerce CRUD and HPOS-safe APIs only; never query order posts directly
- Every cart or checkout change works on the Cart/Checkout blocks and the classic checkout
- Plain ES2017 JavaScript using WordPress globals; no build step
- Nonces and capability checks on every form and AJAX action, sanitized input, escaped output
- Text domain `stackless-lite`

Run `php -l` on changed files before you open the pull request.

## Feature requests

Feature ideas are welcome as issues. Some may land in Stackless rather than Lite.
