<?php

namespace Stackless\Modules\BackInStock;

use Stackless\Core\Agent as Dispatcher;

defined('ABSPATH') || exit;

/** stock_alert.* commands for stackless_agent(): who is waiting, and sending the alerts now. */
final class Agent
{
    /** @var Repository */
    private $repo;

    /** @var callable(int): bool Queues the alert batch for a product (Module::queue). */
    private $queue;

    /** @var bool */
    private $backorders;

    public function __construct(Repository $repo, callable $queue, bool $backorders)
    {
        $this->repo = $repo;
        $this->queue = $queue;
        $this->backorders = $backorders;
    }

    public function register(): void
    {
        add_filter('stackless_agent_command', [$this, 'handle'], 10, 3);
        add_filter('stackless_agent_commands', [$this, 'describe']);
    }

    /** @param array<string,string> $commands */
    public function describe($commands): array
    {
        return (array) $commands + [
            'stock_alert.list' => 'Products with back-in-stock requests: waiting, unconfirmed, notified and failed counts, and whether each is in stock now.',
            'stock_alert.send' => 'Email everyone waiting for product_id now that it is back in stock (dry_run reports how many).',
        ];
    }

    /**
     * @param mixed $result
     * @param mixed $command
     * @param mixed $input
     * @return mixed
     */
    public function handle($result, $command, $input)
    {
        if ($result !== null || !in_array($command, ['stock_alert.list', 'stock_alert.send'], true)) {
            return $result;
        }
        if (!current_user_can('manage_woocommerce')) {
            return Dispatcher::error('This WordPress user cannot manage stock alerts.');
        }
        $input = is_array($input) ? $input : [];
        return Dispatcher::run(function () use ($command, $input) {
            return $command === 'stock_alert.list' ? $this->listing($input) : $this->send($input);
        });
    }

    private function inStock(?\WC_Product $product): bool
    {
        return $product instanceof \WC_Product && Batch::isAvailable($product->get_stock_status(), $this->backorders);
    }

    private function listing(array $input): array
    {
        $rows = [];
        foreach ($this->repo->waitingSummary(50) as $row) {
            $product = wc_get_product((int) $row['product_id']);
            $rows[] = [
                'product_id' => (int) $row['product_id'],
                'product' => $product instanceof \WC_Product ? $product->get_name() : '',
                'waiting' => (int) $row['confirmed'],
                'unconfirmed' => (int) $row['pending'],
                'notified' => (int) $row['notified'],
                'failed' => (int) $row['failed'],
                'last_request' => (string) $row['last_request'],
                'in_stock_now' => $this->inStock($product ?: null),
            ];
        }
        return ['products' => $rows];
    }

    private function send(array $input): array
    {
        $productId = Dispatcher::id($input, 'product_id');
        $product = wc_get_product($productId);
        $waiting = $this->repo->confirmedCount($productId);
        $available = $this->inStock($product ?: null);
        if (Dispatcher::dryRun($input)) {
            return ['dry_run' => true, 'product_id' => $productId, 'would_email' => $available ? $waiting : 0, 'in_stock_now' => $available];
        }
        if (!$available) {
            throw new \RuntimeException('This product is not purchasable yet. Alerts go out only when it is back in stock.');
        }
        $queued = $waiting > 0 ? (bool) call_user_func($this->queue, $productId) : false;
        return ['product_id' => $productId, 'waiting' => $waiting, 'queued' => $queued, 'note' => $queued || !$waiting ? '' : 'A send for this product is already queued.'];
    }
}
