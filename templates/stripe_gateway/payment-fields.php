<?php
/**
 * Stripe payment fields (classic checkout, order-pay, add payment method, change payment method).
 *
 * Override by copying to yourtheme/stackless/stripe_gateway/payment-fields.php.
 * Keep the #stackless-stripe-payment-element container, its data attributes and the hidden input:
 * the script mounts the Stripe Payment Element there and fills the input with a
 * ConfirmationToken id on submit.
 *
 * @var \Stackless\Modules\StripeGateway\Gateway $gateway
 * @var array{flow:string, mode:string, amount:int, currency:string, requires_save:bool, capture:string} $context
 * @var string $description
 * @var bool $test_mode
 * @var bool $show_tokens
 * @var bool $show_save
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-stripe-fields" data-flow="<?php echo esc_attr($context['flow']); ?>">
    <?php if ($description) : ?>
        <div class="stackless-stripe-description"><?php echo wp_kses_post(wpautop(wptexturize($description))); ?></div>
    <?php endif; ?>

    <?php if ($test_mode) : ?>
        <p class="stackless-stripe-test-notice">
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: test card number */
                    __('Test mode: use card %s with any future expiry date and any CVC.', 'stackless-lite'),
                    '<code>4242 4242 4242 4242</code>'
                ),
                ['code' => []]
            );
            ?>
        </p>
    <?php endif; ?>

    <?php
    if ($show_tokens) {
        $gateway->saved_payment_methods();
    }
    ?>

    <fieldset id="wc-<?php echo esc_attr($gateway->id); ?>-cc-form" class="wc-payment-form stackless-stripe-new-method">
        <div
            id="stackless-stripe-payment-element"
            class="stackless-stripe-payment-element"
            data-flow="<?php echo esc_attr($context['flow']); ?>"
            data-mode="<?php echo esc_attr($context['mode']); ?>"
            data-amount="<?php echo esc_attr((string) $context['amount']); ?>"
            data-currency="<?php echo esc_attr(strtolower($context['currency'])); ?>"
            data-requires-save="<?php echo $context['requires_save'] ? '1' : '0'; ?>"
            data-capture="<?php echo esc_attr($context['capture']); ?>"
        ></div>
        <div class="stackless-stripe-errors" role="alert" aria-live="polite"></div>
        <input type="hidden" name="<?php echo esc_attr(\Stackless\Modules\StripeGateway\Gateway::FIELD_CONFIRMATION_TOKEN); ?>" class="stackless-stripe-confirmation-token" value="">
        <?php if ($context['requires_save'] && $context['flow'] !== 'add') : ?>
            <p class="stackless-stripe-save-notice"><?php esc_html_e('Your payment details will be saved securely by Stripe and used for future renewals.', 'stackless-lite'); ?></p>
        <?php endif; ?>
    </fieldset>

    <?php
    if ($show_save) {
        $gateway->save_payment_method_checkbox();
    }
    ?>
</div>
