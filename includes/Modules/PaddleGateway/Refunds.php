<?php

namespace Stackless\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * Refunds through Paddle adjustments, both ways.
 *
 * WooCommerce refund amounts are in the order's own terms. When Paddle added
 * tax on top, the buyer paid more than the order total, so a refund is scaled
 * by Paddle's grand total over the order total and sent tax-inclusive. Partial
 * refunds are spread over the transaction's line items. Paddle may hold a
 * refund for approval; a later rejection is flagged on the order.
 */
class Refunds
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @return true|\WP_Error
     */
    public function refund(\WC_Order $order, ?float $amount, string $reason)
    {
        $transactionId = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if ($transactionId === '') {
            return new \WP_Error('stackless_paddle_refund', __('This payment has no Paddle transaction to refund.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_paddle_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }

        $api = $this->gateway->api($this->gateway->orderMode($order));
        $transaction = $api->request('GET', 'transactions/' . rawurlencode($transactionId), ['include' => 'adjustments']);
        if (is_wp_error($transaction)) {
            return $transaction;
        }
        if (($transaction['status'] ?? '') !== 'completed') {
            return new \WP_Error('stackless_paddle_refund', __('Paddle can refund this payment once it has finished processing. Try again in a few minutes.', 'stackless-lite'));
        }

        $currency = strtoupper($order->get_currency());
        $orderMinor = Money::toMinor($order->get_total(), $currency);
        $paddleTotal = (int) ($transaction['details']['totals']['grand_total'] ?? $orderMinor);
        $wooMinor = Money::toMinor($amount, $currency);
        $refunded = self::refundedPerItem((array) ($transaction['adjustments'] ?? []));
        $lines = [];
        foreach ((array) ($transaction['details']['line_items'] ?? []) as $line) {
            if (!is_array($line) || empty($line['id'])) {
                continue;
            }
            $original = (int) ($line['totals']['total'] ?? 0);
            $lines[] = ['id' => (string) $line['id'], 'total' => max(0, $original - ($refunded[(string) $line['id']] ?? 0)), 'original' => $original];
        }

        $body = [
            'action' => 'refund',
            'transaction_id' => $transactionId,
            'reason' => EventMap::refundReason($reason),
        ];
        if (!$refunded && $wooMinor >= $orderMinor) {
            $body['type'] = 'full';
        } else {
            $items = self::split(self::paddleAmount($wooMinor, $orderMinor, $paddleTotal), $lines);
            if (!$items) {
                return new \WP_Error('stackless_paddle_refund', __('This refund is more than Paddle has left to refund on the payment.', 'stackless-lite'));
            }
            $body['type'] = 'partial';
            $body['tax_mode'] = 'internal';
            $body['items'] = $items;
        }

        // Held while the id is recorded, so an auto-approved refund's webhook can't mirror it a second time.
        $adjustment = Lock::run('refund-' . $order->get_id(), static function () use ($api, $body, $order) {
            $created = $api->request('POST', 'adjustments', $body);
            if (!is_wp_error($created) && ($created['status'] ?? '') !== 'rejected') {
                self::recordId($order, (string) ($created['id'] ?? ''));
            }
            return $created;
        }, new \WP_Error('stackless_paddle_refund', __('Another refund on this order is still being processed. Try again in a moment.', 'stackless-lite')));
        if (is_wp_error($adjustment)) {
            $this->gateway->log('Refund on order ' . $order->get_id() . ' failed: ' . $adjustment->get_error_message(), 'error');
            return $adjustment;
        }
        $status = (string) ($adjustment['status'] ?? '');
        if ($status === 'rejected') {
            return new \WP_Error('stackless_paddle_refund', __('Paddle rejected this refund. Check the transaction in your Paddle dashboard.', 'stackless-lite'));
        }
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Paddle adjustment id, 3: status */
            __('Refunded %1$s through Paddle (adjustment %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
            (string) ($adjustment['id'] ?? ''),
            $status === 'pending_approval' ? __('waiting for Paddle approval', 'stackless-lite') : $status
        ));
        return true;
    }

    /** Amounts already refunded per transaction item. @return array<string,int> */
    public static function refundedPerItem(array $adjustments): array
    {
        $refunded = [];
        foreach ($adjustments as $adjustment) {
            if (!is_array($adjustment) || ($adjustment['action'] ?? '') !== 'refund' || in_array($adjustment['status'] ?? '', ['rejected', 'reversed'], true)) {
                continue;
            }
            foreach ((array) ($adjustment['items'] ?? []) as $item) {
                if (is_array($item) && !empty($item['item_id'])) {
                    $id = (string) $item['item_id'];
                    $refunded[$id] = ($refunded[$id] ?? 0) + (int) ($item['totals']['total'] ?? ($item['amount'] ?? 0));
                }
            }
        }
        return $refunded;
    }

    /** WooCommerce minor amount → what Paddle should refund (scaled by any tax Paddle added). */
    public static function paddleAmount(int $wooMinor, int $orderMinor, int $paddleTotal): int
    {
        if ($orderMinor <= 0 || $paddleTotal <= 0) {
            return $wooMinor;
        }
        return (int) min($paddleTotal, round($wooMinor * $paddleTotal / $orderMinor));
    }

    /** Paddle refund amount → WooCommerce minor amount (the inverse of paddleAmount). */
    public static function wooAmount(int $paddleMinor, int $orderMinor, int $paddleTotal): int
    {
        if ($orderMinor <= 0 || $paddleTotal <= 0) {
            return $paddleMinor;
        }
        return (int) min($orderMinor, round($paddleMinor * $orderMinor / $paddleTotal));
    }

    /**
     * Spread a tax-inclusive refund over line items, first lines first.
     *
     * @param array<int,array{id:string,total:int,original?:int}> $lines Remaining refundable amount per item.
     * @return array<int,array{item_id:string,type:string,amount?:string}> Empty when the amount cannot be refunded.
     */
    public static function split(int $amount, array $lines): array
    {
        if ($amount <= 0) {
            return [];
        }
        $items = [];
        foreach ($lines as $line) {
            if ($amount <= 0) {
                break;
            }
            $available = (int) $line['total'];
            if ($available <= 0) {
                continue;
            }
            $take = min($amount, $available);
            $untouched = $available === (int) ($line['original'] ?? $available);
            $items[] = $take === $available && $untouched
                ? ['item_id' => (string) $line['id'], 'type' => 'full']
                : ['item_id' => (string) $line['id'], 'type' => 'partial', 'amount' => (string) $take];
            $amount -= $take;
        }
        return $amount > 0 ? [] : $items;
    }

    public static function recordId(\WC_Order $order, string $id): void
    {
        if ($id === '') {
            return;
        }
        $ids = array_filter(explode(',', (string) $order->get_meta(Gateway::META_ADJUSTMENT_IDS)));
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            $order->update_meta_data(Gateway::META_ADJUSTMENT_IDS, implode(',', $ids));
            $order->save();
        }
    }

    public static function hasId(\WC_Order $order, string $id): bool
    {
        return in_array($id, array_filter(explode(',', (string) $order->get_meta(Gateway::META_ADJUSTMENT_IDS))), true);
    }

    /**
     * Mirror a Paddle adjustment once: refunds made in the Paddle dashboard become
     * WooCommerce refunds when approved; a rejected refund made here is flagged.
     */
    public function recordAdjustment(array $adjustment, string $mode): string
    {
        $action = (string) ($adjustment['action'] ?? '');
        $order = Sync::findOrderByMeta(Gateway::META_TRANSACTION_ID, (string) ($adjustment['transaction_id'] ?? ''));
        if (!$order || $order->get_payment_method() !== $this->gateway->id) {
            return 'not ours';
        }
        $id = (string) ($adjustment['id'] ?? '');
        $status = (string) ($adjustment['status'] ?? '');

        if (strpos($action, 'chargeback') === 0) {
            /* translators: 1: Paddle adjustment action, 2: adjustment id */
            $order->add_order_note(sprintf(__('Paddle reported a %1$s (adjustment %2$s). Review it in Paddle.', 'stackless-lite'), str_replace('_', ' ', $action), $id));
            return 'chargeback noted';
        }
        if ($action !== 'refund') {
            return 'ignored';
        }

        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $adjustment, $id, $status) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $known = self::hasId($order, $id);
            if ($status === 'rejected' || $status === 'reversed') {
                if ($known && !$order->get_meta('_stackless_paddle_flagged_' . $id)) {
                    $order->update_meta_data('_stackless_paddle_flagged_' . $id, 'yes');
                    $order->save();
                    /* translators: 1: adjustment id, 2: status */
                    $order->add_order_note(sprintf(__('Paddle %2$s refund %1$s. The WooCommerce refund recorded for it did not reach the customer; delete it or refund another way.', 'stackless-lite'), $id, $status));
                    $this->gateway->log('Paddle ' . $status . ' refund ' . $id . ' on order ' . $order->get_id() . '.', 'warning');
                    return 'flagged ' . $status;
                }
                return $status;
            }
            if ($known) {
                // The refund note said "waiting for Paddle approval"; close that loop once.
                if ($status === 'approved' && !$order->get_meta('_stackless_paddle_approved_' . $id)) {
                    $order->update_meta_data('_stackless_paddle_approved_' . $id, 'yes');
                    $order->save();
                    /* translators: %s: Paddle adjustment id */
                    $order->add_order_note(sprintf(__('Paddle approved refund %s.', 'stackless-lite'), $id));
                    return 'approved';
                }
                return 'duplicate';
            }
            if ($status !== 'approved') {
                return 'waiting for approval';
            }

            $currency = strtoupper($order->get_currency());
            if (strtoupper((string) ($adjustment['currency_code'] ?? $currency)) !== $currency) {
                self::recordId($order, $id);
                $order->add_order_note(__('Paddle reported a refund in a different currency. Record it manually.', 'stackless-lite'));
                return 'currency mismatch';
            }
            $orderMinor = Money::toMinor($order->get_total(), $currency);
            $paddleTotal = Money::toMinor((string) $order->get_meta(Gateway::META_TOTAL), $currency);
            $wooMinor = self::wooAmount((int) ($adjustment['totals']['total'] ?? 0), $orderMinor, $paddleTotal);
            $amount = min(Money::fromMinor($wooMinor, $currency), (float) $order->get_remaining_refund_amount());
            if ($amount <= 0) {
                self::recordId($order, $id);
                return 'nothing left';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                /* translators: %s: Paddle adjustment id */
                'reason' => sprintf(__('Refunded in Paddle (%s)', 'stackless-lite'), $id),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            self::recordId($order, $id);
            return 'refunded';
        }, 'busy');
    }
}
