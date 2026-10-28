<?php
/**
 * One Pay What You Like amount field: on the product page, under a classic cart line, and in the
 * classic checkout box.
 *
 * Override in your theme at stackless/pay_what_you_like/amount-field.php.
 * Keep the input name, the hidden product input and the data-stackless-pwyl* attributes: the
 * server reads the name, and front.js reads the attributes.
 *
 * @var string $id
 * @var string $name         input name
 * @var string $label
 * @var string $context      product, cart or checkout
 * @var string $value        amount shown in the field
 * @var string $min          plain decimal ("12.50") for the script
 * @var string $max
 * @var int    $decimals
 * @var string $min_label    formatted minimum
 * @var string $max_label    formatted maximum
 * @var string $help
 * @var string $symbol       currency symbol
 * @var bool   $symbol_after
 * @var array<int, array{value: string, label: string, pressed: bool}> $presets
 * @var string $note         line under the label (product page)
 * @var int    $product_id   product page only
 * @var bool   $variable     product page of a variable product: shown once a variation is chosen
 * @var string $error        optional message for an amount that no longer fits
 */

defined('ABSPATH') || exit;

$stackless_error = isset($error) ? (string) $error : '';
$stackless_symbol = '<span class="stackless-pwyl__symbol" aria-hidden="true">' . esc_html($symbol) . '</span>';
?>
<div class="stackless-pwyl stackless-pwyl--<?php echo esc_attr($context); ?>" data-stackless-pwyl data-context="<?php echo esc_attr($context); ?>" data-min="<?php echo esc_attr($min); ?>" data-max="<?php echo esc_attr($max); ?>" data-decimals="<?php echo esc_attr((string) $decimals); ?>" data-min-label="<?php echo esc_attr($min_label); ?>" data-max-label="<?php echo esc_attr($max_label); ?>"<?php echo $variable ? ' data-variable="1" hidden' : ''; ?>>
    <?php if ($context === 'product') : ?>
        <input type="hidden" name="<?php echo esc_attr(\Stackless\Modules\PayWhatYouLike\Cart::PRODUCT_MARKER); ?>" value="<?php echo esc_attr((string) $product_id); ?>">
    <?php endif; ?>
    <label class="stackless-pwyl__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
    <?php if ($note !== '') : ?>
        <p class="stackless-pwyl__note"><?php echo esc_html($note); ?></p>
    <?php endif; ?>
    <div class="stackless-pwyl__input">
        <?php echo $symbol_after ? '' : $stackless_symbol; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
        <input type="text" inputmode="decimal" autocomplete="off" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" aria-describedby="<?php echo esc_attr($id . '-help'); ?>" aria-invalid="<?php echo $stackless_error !== '' ? 'true' : 'false'; ?>" data-stackless-pwyl-amount>
        <?php echo $symbol_after ? $stackless_symbol : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
    </div>
    <div class="stackless-pwyl__presets" role="group" aria-label="<?php esc_attr_e('Quick amounts', 'stackless-lite'); ?>" data-stackless-pwyl-presets<?php echo $presets ? '' : ' hidden'; ?>>
        <?php foreach ($presets as $stackless_preset) : ?>
            <button type="button" class="stackless-pwyl__preset" data-stackless-pwyl-preset="<?php echo esc_attr($stackless_preset['value']); ?>" aria-pressed="<?php echo $stackless_preset['pressed'] ? 'true' : 'false'; ?>"><?php echo esc_html($stackless_preset['label']); ?></button>
        <?php endforeach; ?>
    </div>
    <p class="stackless-pwyl__help" id="<?php echo esc_attr($id . '-help'); ?>" data-stackless-pwyl-help><?php echo esc_html($help); ?></p>
    <p class="stackless-pwyl__status<?php echo $stackless_error !== '' ? ' is-error' : ''; ?>" role="status" aria-live="polite" data-stackless-pwyl-status><?php echo esc_html($stackless_error); ?></p>
</div>
