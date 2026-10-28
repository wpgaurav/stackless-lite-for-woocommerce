<?php
/**
 * Consent checkboxes on the classic checkout, above the Place order button.
 *
 * Override in your theme at stackless/checkout_enhancements/consents.php.
 *
 * @var array[] $consents id, type, label (limited HTML), required, version
 * @var array   $allowed  wp_kses allow-list for the wording
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-consents" data-stackless-consents>
    <?php foreach ($consents as $consent) :
        $field_id = 'stackless_consent_' . $consent['id']; ?>
        <p class="form-row stackless-consent-row<?php echo $consent['required'] ? ' validate-required' : ''; ?>" id="<?php echo esc_attr($field_id . '_field'); ?>">
            <label class="stackless-consent" for="<?php echo esc_attr($field_id); ?>">
                <input type="checkbox" id="<?php echo esc_attr($field_id); ?>" name="stackless_consent[<?php echo esc_attr($consent['id']); ?>]" value="1" data-stackless-consent<?php echo $consent['required'] ? ' data-required aria-required="true"' : ''; ?>>
                <span><?php echo wp_kses($consent['label'], $allowed); ?><?php if ($consent['required']) : ?> <abbr class="stackless-consent-required required" title="<?php esc_attr_e('required', 'stackless-lite'); ?>">*</abbr><?php endif; ?></span>
            </label>
        </p>
    <?php endforeach; ?>
</div>
