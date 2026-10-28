<?php
/**
 * Express checkout buttons (Apple Pay, Google Pay, Link) for product, cart and checkout pages.
 *
 * Override by copying to yourtheme/stackless/stripe_gateway/express-buttons.php.
 * Keep the .stackless-stripe-express element and its data-config attribute; the script mounts the
 * Stripe Express Checkout Element inside it and reveals the wrapper only when a wallet is available.
 *
 * @var string $context product|cart|checkout
 * @var array<string,mixed> $config
 * @var string $terms_url
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-stripe-express-wrap stackless-stripe-express-<?php echo esc_attr($context); ?>" hidden>
    <div class="stackless-stripe-express" data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>"></div>
    <div class="stackless-stripe-express-errors" role="alert" aria-live="polite"></div>
    <?php if ($terms_url) : ?>
        <p class="stackless-stripe-express-terms">
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: terms and conditions URL */
                    __('By paying with an express method you agree to our <a href="%s" target="_blank" rel="noopener">terms and conditions</a>.', 'stackless-lite'),
                    esc_url($terms_url)
                ),
                ['a' => ['href' => [], 'target' => [], 'rel' => []]]
            );
            ?>
        </p>
    <?php endif; ?>
    <?php if ($context !== 'product') : ?>
        <p class="stackless-stripe-express-separator"><span><?php esc_html_e('or', 'stackless-lite'); ?></span></p>
    <?php endif; ?>
</div>
