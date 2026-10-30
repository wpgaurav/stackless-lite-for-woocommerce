<?php

namespace Stackless\Modules\BackInStock;

defined('ABSPATH') || exit;

/**
 * WooCommerce → Stock alerts: demand per product with sign-up and mail counts.
 */
final class AdminScreen
{
    public const SLUG = 'stackless-stock-alerts';
    private const PER_PAGE = 20;

    /** @var Repository */
    private $repo;

    public function __construct(Repository $repo)
    {
        $this->repo = $repo;
    }

    public function menu(): void
    {
        add_submenu_page(\Stackless\Admin\SettingsPage::PARENT, __('Stock alerts', 'stackless-lite'), __('Stock alerts', 'stackless-lite'), 'manage_woocommerce', self::SLUG, [$this, 'render']);
    }

    public function assets(string $hook): void
    {
        if (strpos($hook, self::SLUG) !== false) {
            wp_enqueue_style('stackless-back_in_stock-admin', STACKLESS_PLUGIN_URL . 'assets/back_in_stock/admin.css', [], STACKLESS_VERSION);
        }
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return [
            'all' => __('All statuses', 'stackless-lite'),
            'pending' => __('Awaiting confirmation', 'stackless-lite'),
            'confirmed' => __('Waiting for stock', 'stackless-lite'),
            'notified' => __('Emailed', 'stackless-lite'),
            'failed' => __('Mail failed', 'stackless-lite'),
            'unsubscribed' => __('Unsubscribed', 'stackless-lite'),
        ];
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
        $search = isset($_GET['s']) ? substr(sanitize_text_field(wp_unslash($_GET['s'])), 0, 100) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $queued = isset($_GET['queued']) ? absint($_GET['queued']) : null;
        // phpcs:enable
        $labels = $this->labels();
        if (!isset($labels[$status])) {
            $status = 'all';
        }
        $counts = $this->repo->statusCounts();
        $report = $this->repo->report($search, $status, $page, self::PER_PAGE);

        echo '<div class="wrap stackless-stock-alerts"><h1 class="wp-heading-inline">' . esc_html__('Back-in-stock alerts', 'stackless-lite') . '</h1> ' . wp_kses_post(\Stackless\Admin\Documentation::link('back_in_stock', '', 'page-title-action')) . '<hr class="wp-header-end">';
        echo '<p class="stackless-stock-intro">' . esc_html__('Confirmed demand, notification progress and mail failures for each product or variation. Alert emails are separate from marketing lists.', 'stackless-lite') . '</p>';
        if ($queued !== null) {
            /* translators: %d: number of products */
            echo \Stackless\Admin\Toast::notice(sprintf(_n('Queued availability emails for %d product.', 'Queued availability emails for %d products.', $queued, 'stackless-lite'), $queued)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Toast::notice().
        }

        $metrics = [
            /* translators: %d: number of sign-ups */
            [__('Sign-ups', 'stackless-lite'), array_sum($counts), sprintf(__('%d in the last 30 days', 'stackless-lite'), $this->repo->recentCount(30))],
            [$labels['confirmed'], $counts['confirmed'], __('Confirmed addresses', 'stackless-lite')],
            [$labels['pending'], $counts['pending'], __('Not on the list until confirmed', 'stackless-lite')],
            [$labels['notified'], $counts['notified'], __('Accepted by the mail server', 'stackless-lite')],
            [$labels['failed'], $counts['failed'], __('Customers can sign up again', 'stackless-lite')],
        ];
        echo '<dl class="stackless-stock-metrics">';
        foreach ($metrics as $metric) {
            echo '<div><dt>' . esc_html($metric[0]) . '</dt><dd>' . esc_html(number_format_i18n((int) $metric[1])) . '</dd><p>' . esc_html($metric[2]) . '</p></div>';
        }
        echo '</dl>';

        echo '<div class="stackless-stock-run"><div><h2>' . esc_html__('Availability check', 'stackless-lite') . '</h2><p>' . esc_html__('Stock changes queue emails automatically. Run a check after a bulk inventory import.', 'stackless-lite') . '</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="stackless_back_in_stock_run">';
        wp_nonce_field('stackless_back_in_stock_run');
        echo '<button class="button" type="submit">' . esc_html__('Check products in stock now', 'stackless-lite') . '</button></form></div>';

        echo '<form method="get" class="stackless-stock-filters"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        echo '<label><span class="screen-reader-text">' . esc_html__('Search', 'stackless-lite') . '</span><input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Product, SKU or email', 'stackless-lite') . '"></label> ';
        echo '<label><span class="screen-reader-text">' . esc_html__('Status', 'stackless-lite') . '</span><select name="status">';
        foreach ($labels as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button" type="submit">' . esc_html__('Filter', 'stackless-lite') . '</button>';
        if ($search !== '' || $status !== 'all') {
            echo ' <a href="' . esc_url(add_query_arg('page', self::SLUG, admin_url('admin.php'))) . '">' . esc_html__('Clear filters', 'stackless-lite') . '</a>';
        }
        echo '</form>';

        $columns = ['pending', 'confirmed', 'notified', 'failed', 'unsubscribed'];
        echo '<div class="stackless-stock-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Product', 'stackless-lite') . '</th><th scope="col">' . esc_html__('Stock', 'stackless-lite') . '</th>';
        foreach ($columns as $key) {
            echo '<th scope="col" class="num">' . esc_html($labels[$key]) . '</th>';
        }
        echo '<th scope="col">' . esc_html__('Last sign-up', 'stackless-lite') . '</th></tr></thead><tbody>';
        foreach ($report['rows'] as $row) {
            $product = wc_get_product((int) $row['product_id']);
            $edit_id = (int) ($row['parent_id'] ?: $row['product_id']);
            /* translators: %d: product id */
            $name = $product ? $product->get_name() : sprintf(__('Deleted product #%d', 'stackless-lite'), (int) $row['product_id']);
            echo '<tr><td><a href="' . esc_url((string) get_edit_post_link($edit_id)) . '"><strong>' . esc_html($name) . '</strong></a>';
            if ($product && $product->get_sku()) {
                echo '<br><small>' . esc_html($product->get_sku()) . '</small>';
            }
            echo '</td><td>' . ($product ? wp_kses_post(wc_get_stock_html($product) ?: esc_html(wc_get_product_stock_status_options()[$product->get_stock_status()] ?? $product->get_stock_status())) : '&mdash;') . '</td>';
            foreach ($columns as $key) {
                echo '<td class="num">' . esc_html(number_format_i18n((int) $row[$key])) . '</td>';
            }
            $last = $row['last_request'] ? strtotime($row['last_request'] . ' UTC') : false;
            echo '<td>' . esc_html($last ? wp_date((string) get_option('date_format'), $last) : '—') . '</td></tr>';
        }
        if (!$report['rows']) {
            $empty = ($search !== '' || $status !== 'all') ? __('No products match these filters.', 'stackless-lite') : __('Sign-ups appear here after customers ask to be notified about an out-of-stock product.', 'stackless-lite');
            echo '<tr><td colspan="8">' . esc_html($empty) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        $pages = (int) ceil($report['total'] / self::PER_PAGE);
        if ($pages > 1) {
            $links = paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $page,
                'total' => $pages,
            ]);
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) $links) . '</div></div>';
        }
        echo '</div>';
    }
}
