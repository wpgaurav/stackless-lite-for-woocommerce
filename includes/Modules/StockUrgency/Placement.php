<?php

namespace Stackless\Modules\StockUrgency;

defined('ABSPATH') || exit;

/**
 * Prints a badge at a chosen spot on classic templates and on product blocks.
 *
 * Classic: woocommerce_single_product_summary and the shop loop hooks. The loop hooks are
 * skipped when WooCommerce replays them inside block rendering, so block cards get one badge.
 * Blocks: render_block filters on product-price, post-title, product-button and the
 * add-to-cart blocks, using the block's postId context.
 */
final class Placement
{
    public const SINGLE_POSITIONS = ['after_title', 'after_price', 'after_add_to_cart'];
    public const ARCHIVE_POSITIONS = ['after_title', 'after_price', 'before_button'];

    /** @var callable(\WC_Product, string): string */
    private $renderer;

    /** @var string */
    private $single;

    /** @var string */
    private $archive;

    /** @var array<int, bool> */
    private $singleDone = [];

    /**
     * @param callable(\WC_Product, string): string $renderer Returns escaped HTML for a product in "single" or "archive" context.
     * @param string $single  Single product position, or '' to skip single pages.
     * @param string $archive Loop position, or '' to skip loops.
     */
    public function __construct(callable $renderer, string $single, string $archive)
    {
        $this->renderer = $renderer;
        $this->single = in_array($single, self::SINGLE_POSITIONS, true) ? $single : '';
        $this->archive = in_array($archive, self::ARCHIVE_POSITIONS, true) ? $archive : '';
    }

    public function register(): void
    {
        $singlePriority = ['after_title' => 6, 'after_price' => 11, 'after_add_to_cart' => 31];
        if ($this->single !== '') {
            add_action('woocommerce_single_product_summary', [$this, 'classicSingle'], $singlePriority[$this->single]);
        }
        if ($this->archive === 'after_title') {
            add_action('woocommerce_after_shop_loop_item_title', [$this, 'classicLoop'], 4);
        } elseif ($this->archive === 'after_price') {
            add_action('woocommerce_after_shop_loop_item_title', [$this, 'classicLoop'], 11);
        } elseif ($this->archive === 'before_button') {
            add_action('woocommerce_after_shop_loop_item', [$this, 'classicLoop'], 9);
        }

        add_filter('render_block_woocommerce/product-price', [$this, 'blockPrice'], 20, 3);
        add_filter('render_block_core/post-title', [$this, 'blockTitle'], 20, 3);
        add_filter('render_block_woocommerce/product-button', [$this, 'blockButton'], 20, 3);
        add_filter('render_block_woocommerce/add-to-cart-form', [$this, 'blockAddToCart'], 20, 3);
        add_filter('render_block_woocommerce/add-to-cart-with-options', [$this, 'blockAddToCart'], 20, 3);
    }

    public function classicSingle(): void
    {
        global $product;
        if ($product instanceof \WC_Product) {
            echo $this->renderSingle($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
        }
    }

    public function classicLoop(): void
    {
        // On block templates WooCommerce replays the classic loop hooks inside a render_block
        // filter (ArchiveProductTemplatesCompatibility); the block filters below already place
        // the badge there, so printing here too would show it twice on each card.
        if (self::inBlockRender()) {
            return;
        }
        global $product;
        if ($product instanceof \WC_Product) {
            echo call_user_func($this->renderer, $product, 'archive'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
        }
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function blockPrice($content, $block, $instance = null)
    {
        return $this->blockAppend((string) $content, $instance, 'after_price', 'after_price', false);
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function blockTitle($content, $block, $instance = null)
    {
        if (!$instance instanceof \WP_Block || ($instance->context['postType'] ?? '') !== 'product') {
            return $content;
        }
        return $this->blockAppend((string) $content, $instance, 'after_title', 'after_title', false);
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function blockButton($content, $block, $instance = null)
    {
        return $this->blockAppend((string) $content, $instance, '', 'before_button', true);
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function blockAddToCart($content, $block, $instance = null)
    {
        return $this->blockAppend((string) $content, $instance, 'after_add_to_cart', '', false);
    }

    private function blockAppend(string $content, $instance, string $singlePos, string $archivePos, bool $prepend): string
    {
        if ($content === '') {
            return $content;
        }
        $product = $this->blockProduct($instance);
        if (!$product) {
            return $content;
        }
        if ($this->isMainProduct($product)) {
            if ($singlePos === '' || $this->single !== $singlePos) {
                return $content;
            }
            $html = $this->renderSingle($product);
        } else {
            if ($archivePos === '' || $this->archive !== $archivePos) {
                return $content;
            }
            $html = (string) call_user_func($this->renderer, $product, 'archive');
        }
        if ($html === '') {
            return $content;
        }
        return $prepend ? $html . $content : $content . $html;
    }

    private function renderSingle(\WC_Product $product): string
    {
        $id = $product->get_id();
        if (!empty($this->singleDone[$id])) {
            return '';
        }
        $html = (string) call_user_func($this->renderer, $product, 'single');
        if ($html !== '') {
            $this->singleDone[$id] = true;
        }
        return $html;
    }

    /** @param \WP_Block|null $instance */
    private function blockProduct($instance): ?\WC_Product
    {
        $id = 0;
        if ($instance instanceof \WP_Block && !empty($instance->context['postId'])) {
            $id = (int) $instance->context['postId'];
        }
        if ($id > 0) {
            $product = wc_get_product($id);
            return $product instanceof \WC_Product ? $product : null;
        }
        global $product;
        return $product instanceof \WC_Product ? $product : null;
    }

    private static function inBlockRender(): bool
    {
        return function_exists('doing_filter') && doing_filter('render_block');
    }

    private function isMainProduct(\WC_Product $product): bool
    {
        return function_exists('is_product') && is_product() && $product->get_id() === (int) get_queried_object_id();
    }
}
