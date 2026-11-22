<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * What happens at Braintree after payment. Card sales and refunds made in the Control Panel send
 * no webhook, so a job every six hours asks for new refunds, voids, failed settlements and
 * disputes; signed webhooks bring disputes sooner. Either way the transaction is read from
 * Braintree before an order changes.
 */
final class Sync
{
    public const SWEEP_HOOK = 'stackless_braintree_gateway_sweep';
    public const CURSOR_OPTION = 'stackless_braintree_gateway_swept_';

    private const EVERY = 6 * 3600;
    private const OVERLAP = 2 * 86400;
    private const MAX_LOOKBACK = 120 * 86400;
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 100;
    /** Leave a refund this store is still sending to the request that sends it. */
    private const OPERATION_GRACE = 900;

    /** Disputes that take or hold money. */
    private const HOLDING = ['CHARGEBACK', 'PRE_ARBITRATION'];

    public static function ensureScheduled(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_single_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::SWEEP_HOOK, [], Payments::GROUP)) {
            as_schedule_single_action(time() + self::EVERY, self::SWEEP_HOOK, [], Payments::GROUP);
        }
    }

    /** Restarts the job after the module was off. */
    public static function adminInit(): void
    {
        if (wp_doing_ajax() || get_transient(self::SWEEP_HOOK . '_seen')) {
            return;
        }
        set_transient(self::SWEEP_HOOK . '_seen', 1, 12 * HOUR_IN_SECONDS);
        if (Config::hasKeys(Config::mode())) {
            self::ensureScheduled();
        }
    }

    /** Action Scheduler callback. */
    public static function sweep(): void
    {
        if (LiveStore::hold(self::SWEEP_HOOK, [], Payments::GROUP)) {
            return;
        }
        $configured = false;
        try {
            foreach (Config::MODES as $mode) {
                if (!Config::hasKeys($mode)) {
                    continue;
                }
                $configured = true;
                (new self())->sweepMode($mode);
            }
        } finally {
            // Not ensureScheduled(): this very action still counts as scheduled while it runs.
            if ($configured && function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + self::EVERY, self::SWEEP_HOOK, [], Payments::GROUP);
            }
        }
    }

    public function sweepMode(string $mode): void
    {
        $connection = Connection::ensure($mode);
        if (!$connection) {
            return;
        }
        $option = self::CURSOR_OPTION . $mode;
        $stored = get_option($option, []);
        $now = time();
        $last = is_array($stored) && ($stored['account'] ?? '') === $connection['account'] ? (int) ($stored['at'] ?? 0) : 0;
        $from = max($now - self::MAX_LOOKBACK, ($last > 0 ? min($last, $now) : $now) - self::OVERLAP);
        $since = ['greaterThanOrEqualTo' => gmdate('c', $from)];

        $searches = [
            [Queries::RECENT_REFUNDS, 'refunds', ['createdAt' => $since], 'refundedTransaction'],
            [Queries::RECENT_TRANSACTIONS, 'transactions', ['statusTransition' => ['voidedAt' => $since]], ''],
            [Queries::RECENT_TRANSACTIONS, 'transactions', ['status' => ['is' => 'SETTLEMENT_DECLINED'], 'createdAt' => ['greaterThanOrEqualTo' => gmdate('c', $from - 14 * 86400)]], ''],
            [Queries::RECENT_DISPUTES, 'disputes', ['effectiveDate' => ['greaterThanOrEqualTo' => gmdate('Y-m-d', $from)]], 'transaction'],
        ];
        $api = Config::api($mode);
        $transactions = [];
        $complete = true;
        foreach ($searches as [$document, $root, $input, $child]) {
            $found = $this->transactionsFrom($api, $document, $root, $input, $child);
            if (is_wp_error($found)) {
                // One search may lack a permission; the others still run, and the window is read again.
                Config::log('Checking Braintree for ' . $root . ' (' . $mode . ') failed: ' . $found->get_error_message(), 'warning');
                // Waiting does not bring a permission back, so that alone does not hold the window.
                if (Api::errorClass($found) !== 'AUTHORIZATION') {
                    $complete = false;
                }
                continue;
            }
            foreach ($found as $id) {
                $transactions[$id] = true;
            }
        }
        foreach (array_keys($transactions) as $id) {
            $orderId = Orders::findByMeta(Meta::TRANSACTION, (string) $id);
            if ($orderId > 0 && !in_array($this->reconcile($orderId), ['ok', 'ignored'], true)) {
                $complete = false;
            }
        }
        if ($complete) {
            update_option($option, ['account' => $connection['account'], 'at' => $now], false);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return string[]|\WP_Error Global transaction ids.
     */
    private function transactionsFrom(Api $api, string $document, string $root, array $input, string $child)
    {
        $ids = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $variables = ['input' => $input, 'first' => self::PAGE_SIZE];
            if ($after !== null) {
                $variables['after'] = $after;
            }
            $data = $api->query($document, $variables);
            if (is_wp_error($data)) {
                return $data;
            }
            $connection = $data[$root] ?? null;
            if (!is_array($connection) || !is_array($connection['edges'] ?? null)) {
                return new \WP_Error('stackless_braintree_sweep', 'Braintree returned no list.');
            }
            foreach ($connection['edges'] as $edge) {
                $node = is_array($edge) ? ($edge['node'] ?? null) : null;
                if (is_array($node) && $child !== '') {
                    $node = $node[$child] ?? null;
                }
                if (is_array($node) && !empty($node['id'])) {
                    $ids[] = (string) $node['id'];
                }
            }
            if (empty($connection['pageInfo']['hasNextPage'])) {
                return $ids;
            }
            $after = (string) ($connection['pageInfo']['endCursor'] ?? '');
            if ($after === '') {
                break;
            }
        }
        return new \WP_Error('stackless_braintree_sweep', 'The Braintree list could not be read to the end.');
    }

    /**
     * Read an order's transaction and apply its refunds, void, failed settlement and disputes.
     *
     * @return string ok|ignored|busy|retry
     */
    public function reconcile(int $orderId): string
    {
        return (string) Lock::run('order-' . $orderId, function () use ($orderId) {
            return $this->reconcileLocked($orderId);
        }, 'busy');
    }

    private function reconcileLocked(int $orderId): string
    {
        $order = Orders::summary($orderId);
        $id = (string) Orders::meta($orderId, Meta::TRANSACTION);
        $mode = (string) Orders::meta($orderId, Meta::MODE);
        if (!$order || $id === '' || !in_array($mode, Config::MODES, true)) {
            return 'ignored';
        }
        $account = Connection::account($mode);
        if ($account === '') {
            return 'retry';
        }
        if ($account !== (string) Orders::meta($orderId, Meta::ACCOUNT)) {
            return 'ignored';
        }
        $api = Config::api($mode);
        $read = $api->query(Queries::HISTORY, ['id' => $id]);
        if (is_wp_error($read)) {
            return 'retry';
        }
        $transaction = $read['node'] ?? null;
        $refunds = Reversals::refundsOf($transaction);
        if ($refunds === null || (string) $transaction['id'] !== $id) {
            return 'retry';
        }
        $this->importRefunds($order, $transaction, $refunds);

        $read = $api->query(Queries::TRANSACTION_DISPUTES, ['id' => $id]);
        if (is_wp_error($read)) {
            // A key that may not see disputes still syncs refunds; waiting would not change that.
            return Api::errorClass($read) === 'AUTHORIZATION' ? 'ok' : 'retry';
        }
        $this->importDisputes($order, (array) ($read['node']['disputes'] ?? []));
        return 'ok';
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $transaction
     * @param array<int,mixed>    $refunds
     */
    private function importRefunds(array $order, array $transaction, array $refunds): void
    {
        $orderId = $order['id'];
        $currency = $order['currency'];
        $operation = Orders::meta($orderId, Meta::REFUND_OPERATION);
        $operation = is_array($operation) && !empty($operation['key']) ? $operation : null;
        if ($operation && time() - (int) $operation['at'] < self::OPERATION_GRACE) {
            return;
        }
        $known = Reversals::known($orderId);
        $status = (string) ($transaction['status'] ?? '');
        $total = Money::read($transaction['amount'] ?? null, (string) ($transaction['amount']['currencyCode'] ?? $currency));
        $returned = 0;

        foreach ($refunds as $refund) {
            if (!is_array($refund) || empty($refund['id'])) {
                continue;
            }
            $id = (string) $refund['id'];
            $minor = Money::read($refund['amount'] ?? null, $currency);
            if (in_array($refund['status'] ?? '', Payments::UNPAID, true)) {
                if (in_array($id, $known, true) && !in_array($id . ':failed', $known, true)) {
                    /* translators: %s: refund id */
                    Orders::note($orderId, sprintf(__('Braintree could not complete refund %s. The customer has not received it: check it in the Control Panel.', 'stackless-lite'), $id));
                    Reversals::remember($orderId, $id . ':failed');
                }
                continue;
            }
            $returned += (int) $minor;
            if (in_array($id, $known, true)) {
                continue;
            }
            if ($minor === null) {
                /* translators: 1: refund id, 2: amount and currency */
                Orders::note($orderId, sprintf(__('Braintree refund %1$s (%2$s) is in another currency and was not recorded here.', 'stackless-lite'), $id, Money::describe($refund['amount'] ?? null)));
                Reversals::remember($orderId, $id);
                continue;
            }
            if (!$order['paid']) {
                // A held payment never paid the order, so there is no sale to take it from.
                /* translators: 1: refund id, 2: amount and currency */
                Orders::note($orderId, sprintf(__('Braintree refund %1$s of %2$s returned a payment that never completed this order, so the order total is unchanged.', 'stackless-lite'), $id, Money::value($minor, $currency) . ' ' . $currency));
                Reversals::remember($orderId, $id);
                continue;
            }
            /* translators: %s: refund id */
            $this->record($orderId, $minor, $currency, $id, sprintf(__('Refunded in Braintree (%s)', 'stackless-lite'), $id));
            // An operation this refund answers stays, so repeating it in the store sends nothing.
        }

        if ($status === 'VOIDED') {
            $returned = (int) $total;
            $id = 'void:' . (string) $transaction['id'];
            if ($order['paid'] && !in_array($id, $known, true)) {
                $this->record($orderId, (int) $total, $currency, $id, __('Voided in Braintree', 'stackless-lite'));
                if ($operation) {
                    Orders::setMeta($orderId, [Meta::REFUND_OPERATION => null]);
                }
            }
        }
        if ($status === 'SETTLEMENT_DECLINED' && !in_array('settlement_declined', $known, true)) {
            Reversals::remember($orderId, 'settlement_declined');
            Orders::holdForReview($orderId, __('Braintree reports that settlement of this payment was declined: the money was not collected.', 'stackless-lite'));
        }

        $attempt = Attempt::current($orderId);
        if ($attempt && ($attempt['state'] ?? '') === Attempt::HELD && $attempt['transaction'] === $transaction['id'] && $total !== null && $returned >= $total) {
            // All the money went back, so the order may be paid again.
            Attempt::close($orderId, $attempt);
        }
    }

    private function record(int $orderId, int $minor, string $currency, string $id, string $reason): void
    {
        $amount = min(Money::toFloat($minor, $currency), Orders::refundable($orderId));
        Reversals::remember($orderId, $id);
        if ($amount <= 0) {
            return;
        }
        $recorded = Orders::recordExternalRefund($orderId, $amount, $reason, $id);
        if (is_wp_error($recorded)) {
            /* translators: 1: refund id, 2: amount and currency, 3: error */
            Orders::note($orderId, sprintf(__('Braintree refund %1$s of %2$s could not be recorded automatically (%3$s). Record it by hand, without refunding through Braintree again.', 'stackless-lite'), $id, Money::value($minor, $currency) . ' ' . $currency, $recorded->get_error_message()));
        }
    }

    /**
     * @param array<string,mixed> $order
     * @param array<int,mixed>    $disputes
     */
    private function importDisputes(array $order, array $disputes): void
    {
        $orderId = $order['id'];
        $seen = Orders::meta($orderId, Meta::DISPUTES);
        $seen = is_array($seen) ? $seen : [];
        foreach ($disputes as $dispute) {
            if (!is_array($dispute) || empty($dispute['id'])) {
                continue;
            }
            $id = (string) $dispute['id'];
            $status = strtoupper((string) ($dispute['status'] ?? ''));
            if (isset($seen[$id]) && $seen[$id] === $status) {
                continue;
            }
            $first = !isset($seen[$id]);
            $seen[$id] = $status;
            Orders::setMeta($orderId, [Meta::DISPUTES => $seen]);
            $amount = Money::describe($dispute['amountDisputed'] ?? null);
            $holds = in_array(strtoupper((string) ($dispute['type'] ?? '')), self::HOLDING, true);
            if ($first && $holds && $status !== 'WON') {
                /* translators: 1: dispute status, 2: amount and currency */
                Orders::holdForReview($orderId, sprintf(__('Braintree reports a dispute (%1$s) for %2$s on this payment. Answer it in the Control Panel.', 'stackless-lite'), strtolower($status), $amount));
            } else {
                /* translators: 1: dispute status, 2: amount and currency */
                Orders::note($orderId, sprintf(__('The Braintree dispute for %2$s is now: %1$s.', 'stackless-lite'), strtolower($status), $amount));
            }
        }
    }

    /**
     * A verified webhook: find the payment and read it back.
     *
     * @param array{id:string, kind:string, timestamp:string, transaction:string} $event
     * @return string ok|ignored|busy|retry
     */
    public function webhook(array $event, string $mode): string
    {
        if ($event['transaction'] === '') {
            return 'ignored';
        }
        $orderId = Orders::findByMeta(Meta::LEGACY_ID, $event['transaction']);
        if ($orderId < 1 && $event['kind'] === 'refund_failed') {
            // refund_failed names the refund: ask which transaction it refunded.
            $api = Config::api($mode);
            $ids = $api->query(Queries::LEGACY_IDS, ['input' => ['ids' => [['legacyId' => $event['transaction'], 'type' => 'REFUND']]]]);
            if (is_wp_error($ids)) {
                return 'retry';
            }
            $refund = (string) ($ids['idsFromLegacyIds'][0] ?? '');
            if ($refund === '') {
                return 'ignored';
            }
            $read = $api->query(Queries::REFUNDED_TRANSACTION, ['id' => $refund]);
            if (is_wp_error($read)) {
                return Api::errorClass($read) === 'NOT_FOUND' ? 'ignored' : 'retry';
            }
            $orderId = Orders::findByMeta(Meta::TRANSACTION, (string) ($read['node']['refundedTransaction']['id'] ?? ''));
        }
        if ($orderId < 1 || (string) Orders::meta($orderId, Meta::MODE) !== $mode) {
            return 'ignored';
        }
        return $this->reconcile($orderId);
    }
}
