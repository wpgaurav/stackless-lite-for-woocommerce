<?php
/**
 * Wishlist page, My Account tab and shared view.
 *
 * Override in your theme at stackless/wishlist/list.php.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var bool   $owner     the viewer owns this list
 * @var bool   $guest     the viewer is not signed in
 * @var string $share_url
 * @var string $nonce
 * @var string $login_url
 * @var string $shared_by (shared view only)
 * @var bool   $shared    (shared view only)
 */

defined('ABSPATH') || exit;

$stackless_button_class = function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '';

$stackless_shared = !empty($shared);
?>
<section class="stackless-wishlist" data-stackless-wishlist-list aria-label="<?php esc_attr_e('Wishlist', 'stackless-lite'); ?>">
    <?php if ($stackless_shared && !empty($shared_by)) : ?>
        <p class="stackless-wishlist-intro">
            <?php
            /* translators: %s: customer display name */
            echo esc_html(sprintf(__('Products saved by %s.', 'stackless-lite'), $shared_by));
            ?>
        </p>
    <?php endif; ?>

    <?php if (!$rows) : ?>
        <div class="stackless-wishlist-empty">
            <p><strong><?php esc_html_e('No saved products yet.', 'stackless-lite'); ?></strong></p>
            <?php if (!$stackless_shared) : ?>
                <p><?php esc_html_e('Tap the heart on any product to keep it here for later.', 'stackless-lite'); ?></p>
            <?php endif; ?>
            <p><a class="button<?php echo esc_attr($stackless_button_class); ?>" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Browse products', 'stackless-lite'); ?></a></p>
        </div>
    <?php else : ?>
        <ul class="stackless-wishlist-items">
            <?php foreach ($rows as $stackless_row) : ?>
                <li class="stackless-wishlist-item" data-key="<?php echo esc_attr($stackless_row['product_id'] . ':' . $stackless_row['variation_id']); ?>">
                    <a class="stackless-wishlist-thumb" href="<?php echo esc_url($stackless_row['url']); ?>"><?php echo wp_kses_post($stackless_row['image']); ?></a>
                    <div class="stackless-wishlist-info">
                        <a class="stackless-wishlist-name" href="<?php echo esc_url($stackless_row['url']); ?>"><?php echo esc_html($stackless_row['name']); ?></a>
                        <span class="stackless-wishlist-price"><?php echo wp_kses_post($stackless_row['price_html']); ?></span>
                        <?php echo wp_kses_post($stackless_row['stock_html']); ?>
                    </div>
                    <div class="stackless-wishlist-actions">
                        <?php if ($stackless_row['can_move']) : ?>
                            <form method="post" data-stackless-wishlist-action="move">
                                <input type="hidden" name="stackless_wishlist_op" value="move">
                                <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $stackless_row['product_id']); ?>">
                                <input type="hidden" name="variation_id" value="<?php echo esc_attr((string) $stackless_row['variation_id']); ?>">
                                <input type="hidden" name="_stackless_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
                                <button type="submit" class="button alt<?php echo esc_attr($stackless_button_class); ?>"><?php echo esc_html($owner ? __('Move to cart', 'stackless-lite') : __('Add to cart', 'stackless-lite')); ?></button>
                            </form>
                        <?php else : ?>
                            <a class="button<?php echo esc_attr($stackless_button_class); ?>" href="<?php echo esc_url($stackless_row['url']); ?>"><?php esc_html_e('View product', 'stackless-lite'); ?></a>
                        <?php endif; ?>
                        <?php if ($owner) : ?>
                            <form method="post" data-stackless-wishlist-action="remove">
                                <input type="hidden" name="stackless_wishlist_op" value="remove">
                                <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $stackless_row['product_id']); ?>">
                                <input type="hidden" name="variation_id" value="<?php echo esc_attr((string) $stackless_row['variation_id']); ?>">
                                <input type="hidden" name="_stackless_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
                                <button type="submit" class="stackless-wishlist-remove" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: product name */ __('Remove %s from wishlist', 'stackless-lite'), $stackless_row['name'])); ?>"><?php esc_html_e('Remove', 'stackless-lite'); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="stackless-wishlist-status" role="status" aria-live="polite"></p>

    <?php if ($guest && $rows && $login_url) : ?>
        <p class="stackless-wishlist-note">
            <?php esc_html_e('This list is saved on this device.', 'stackless-lite'); ?>
            <a href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('Sign in to keep it in your account.', 'stackless-lite'); ?></a>
        </p>
    <?php endif; ?>

    <?php if ($share_url) : ?>
        <div class="stackless-wishlist-share">
            <label for="stackless-wishlist-share-url"><strong><?php esc_html_e('Share this wishlist', 'stackless-lite'); ?></strong></label>
            <div class="stackless-wishlist-share-row">
                <input id="stackless-wishlist-share-url" type="text" readonly value="<?php echo esc_url($share_url); ?>" data-stackless-share-url>
                <button type="button" class="button<?php echo esc_attr($stackless_button_class); ?>" data-stackless-copy><?php esc_html_e('Copy link', 'stackless-lite'); ?></button>
            </div>
            <form method="post" class="stackless-wishlist-reshare">
                <input type="hidden" name="stackless_wishlist_op" value="reshare">
                <input type="hidden" name="_stackless_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="stackless-link-button"><?php esc_html_e('Create a new link (stops the old one working)', 'stackless-lite'); ?></button>
            </form>
        </div>
    <?php endif; ?>
</section>
