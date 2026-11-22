<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Refunds started in the store. A settled transaction is refunded, whole or in part. One that
 * has not settled can only be reversed whole (a void), so a partial refund before settlement is
 * refused with the reason.
 *
 * The operation is written on the order first. After an unknown answer the same amount repeats
 * that operation with its apiRequestKey, and any other amount is refused until it is known.
 */
final class Reversals
{
    private const REFUND = 'refund';
    private const REVERSE = 'reverse';
    private const KEY_LIFETIME = 29 * 86400;

    /**
     * @param string $sequence Names one store refund, so a repeat call for it refunds once.
     * @return array{id:string, voided:bool}|\WP_Error
     */
    public function refund(int $orderId, float $amount, string $sequence = '')
    {
        $result = Lock::run('order-' . $orderId, function () use ($orderId, $amount, $sequence) {
            return $this->refundLocked($orderId, $amount, $sequence);
        }, null);
        return $result ?? self::failure(__('The order is being updated by another request. Try the refund again in a moment.', 'stackless-lite'));
    }

    /** @return array{id:string, voided:bool}|\WP_Error */
    private function refundLocked(int $orderId, float $amount, string $sequence)
    {
        $order = Orders::summary($orderId);
        if (!$order) {
            return self::failure(__('Order not found.', 'stackless-lite'));
        }
        $transactionId = (string) Orders::meta($orderId, Meta::TRANSACTION);
        if ($transactionId === '') {
            return self::failure(__('This order has no Braintree payment to refund.', 'stackless-lite'));
        }
        $currency = $order['currency'];
        $minor = Money::minor($amount, $currency);
        if ($minor === null || $minor <= 0) {
            /* translators: %s: currency code */
            return self::failure(sprintf(__('Enter a refund above zero that Braintree can take exactly in %s.', 'stackless-lite'), $currency));
        }

        $done = Orders::meta($orderId, Meta::REFUNDS_DONE);
        $done = is_array($done) ? $done : [];
        if ($sequence !== '' && isset($done[$sequence]) && (int) $done[$sequence]['minor'] === $minor) {
            return ['id' => (string) $done[$sequence]['id'], 'voided' => !empty($done[$sequence]['voided'])];
        }

        $mode = (string) Orders::meta($orderId, Meta::MODE);
        if (!in_array($mode, Config::MODES, true)) {
            return self::failure(__('This order does not record which Braintree mode took the payment. Refund it in the Control Panel.', 'stackless-lite'));
        }
        if (Connection::account($mode) !== (string) Orders::meta($orderId, Meta::ACCOUNT)) {
            return self::failure(__('This payment was made with other Braintree credentials. Connect that account again, or refund it in its Control Panel.', 'stackless-lite'));
        }

        $operation = Orders::meta($orderId, Meta::REFUND_OPERATION);
        $operation = is_array($operation) && !empty($operation['key']) ? $operation : null;
        $api = Config::api($mode);
        $read = $api->query(Queries::HISTORY, ['id' => $transactionId]);
        if (is_wp_error($read)) {
            return $read;
        }
        $transaction = $read['node'] ?? null;
        $refunds = self::refundsOf($transaction);
        if ($refunds === null) {
            return self::failure(__('Braintree did not return this payment, so nothing was refunded.', 'stackless-lite'));
        }
        $total = Money::read($transaction['amount'] ?? null, $currency);
        if ($total === null) {
            return self::failure(__('The Braintree payment is in another currency than the order. Refund it in the Control Panel.', 'stackless-lite'));
        }
        $status = (string) ($transaction['status'] ?? '');

        $refunded = 0;
        foreach ($refunds as $refund) {
            if (!is_array($refund) || in_array($refund['status'] ?? '', Payments::UNPAID, true)) {
                continue;
            }
            $refundMinor = (int) Money::read($refund['amount'] ?? null, $currency);
            $refunded += $refundMinor;
            if ($operation && $operation['kind'] === self::REFUND && (string) ($refund['orderId'] ?? '') === $operation['ref']) {
                if ((int) $operation['minor'] === $minor) {
                    if (in_array((string) $refund['id'], self::known($orderId), true)) {
                        // The sync recorded it meanwhile: recording it again would count it twice.
                        Orders::setMeta($orderId, [Meta::REFUND_OPERATION => null]);
                        return self::failure(__('Braintree had already completed the earlier refund of this amount, and it is recorded on the order now. Nothing more was sent. To refund that amount again, repeat the refund.', 'stackless-lite'));
                    }
                    return $this->done($orderId, $sequence, $minor, (string) $refund['id'], false);
                }
                // The earlier refund did happen; the sync records it. This is another one.
                Orders::setMeta($orderId, [Meta::REFUND_OPERATION => null]);
                $operation = null;
            }
        }
        if ($operation && (int) $operation['minor'] !== $minor) {
            if (time() - (int) $operation['at'] < Payments::ABSENT_AFTER) {
                /* translators: %s: amount and currency */
                return self::failure(sprintf(__('An earlier refund of %s has no confirmed result yet. Refund that same amount to finish it, or wait half an hour.', 'stackless-lite'), Money::value((int) $operation['minor'], $currency) . ' ' . $currency));
            }
            // Long finished, and the read above shows no trace of it.
            $operation = null;
        }
        if ($operation && $operation['kind'] === self::REVERSE && $status === 'VOIDED') {
            return $this->done($orderId, $sequence, $minor, 'void:' . $transactionId, true);
        }
        if ($operation && time() - (int) $operation['at'] >= self::KEY_LIFETIME) {
            // Past the key's life, and the read above shows it never happened.
            $operation = null;
        }

        if (!$operation) {
            if ($status === 'SETTLED' || $status === 'SETTLING') {
                if ($minor > $total - $refunded) {
                    return self::failure(__('Braintree has less than that left to refund on this payment.', 'stackless-lite'));
                }
                $kind = self::REFUND;
            } elseif ($status === 'SUBMITTED_FOR_SETTLEMENT' || $status === 'AUTHORIZED') {
                if ($minor !== $total) {
                    return self::failure(sprintf(
                        /* translators: %s: amount and currency */
                        __('This payment has not settled yet, so Braintree cannot refund part of it. Refund the whole %s now to cancel it, or wait until it settles (usually within a day or two) and refund the part.', 'stackless-lite'),
                        Money::value($total, $currency) . ' ' . $currency
                    ));
                }
                $kind = self::REVERSE;
            } elseif ($status === 'VOIDED') {
                return self::failure(__('This payment was already voided in Braintree, so there is nothing to send back.', 'stackless-lite'));
            } elseif (in_array($status, Payments::UNPAID, true)) {
                return self::failure(__('Braintree did not collect this payment, so there is nothing to refund.', 'stackless-lite'));
            } else {
                return self::failure(__('Braintree is still processing this payment. Try the refund again once it has settled.', 'stackless-lite'));
            }
            $key = bin2hex(random_bytes(16));
            $operation = ['key' => $key, 'ref' => Attempt::reference($orderId, $key) . '-refund', 'kind' => $kind, 'minor' => $minor, 'at' => time()];
            if (!self::begin($orderId, $operation)) {
                return self::failure(__('The refund could not be recorded, so it was not sent to Braintree.', 'stackless-lite'));
            }
        }

        $input = ['apiRequestKey' => $operation['key'], 'transactionId' => $transactionId];
        if ($operation['kind'] === self::REFUND) {
            $input['refund'] = ['amount' => Money::value($minor, $currency), 'orderId' => $operation['ref']];
            $result = $api->request(Queries::REFUND, ['input' => $input], Api::WRITE_TIMEOUT);
            $object = $result['data']['refundTransaction']['refund'] ?? null;
            $type = 'Refund';
        } else {
            $result = $api->request(Queries::REVERSE, ['input' => $input], Api::WRITE_TIMEOUT);
            $object = $result['data']['reverseTransaction']['reversal'] ?? null;
            $type = is_array($object) ? (string) ($object['__typename'] ?? '') : '';
        }

        if (is_array($object) && !empty($object['id']) && !empty($object['status']) && $type !== '') {
            if ($type === 'Transaction') {
                if ($object['status'] === 'VOIDED') {
                    return $this->done($orderId, $sequence, $minor, 'void:' . $transactionId, true);
                }
            } elseif (in_array($object['status'], Payments::UNPAID, true)) {
                Orders::setMeta($orderId, [Meta::REFUND_OPERATION => null]);
                return self::failure(__('Braintree declined this refund. Check the payment in the Control Panel.', 'stackless-lite'));
            } else {
                return $this->done($orderId, $sequence, $minor, (string) $object['id'], false);
            }
        }

        $error = $result['error'];
        if ($error && Api::isDefinite($error)) {
            // A key refused on a repeat is only cleared when the read above showed no such refund.
            Orders::setMeta($orderId, [Meta::REFUND_OPERATION => null]);
            return self::failure(self::reason($error));
        }
        Config::log(sprintf('A refund on order %d has no known result yet.', $orderId), 'error');
        return self::failure(__('Braintree did not confirm the refund, so it may or may not have gone through. Refund the same amount again: Stackless repeats the same request, so the customer is refunded once.', 'stackless-lite'));
    }

    /** @return array{id:string, voided:bool} */
    private function done(int $orderId, string $sequence, int $minor, string $id, bool $voided): array
    {
        $meta = [Meta::REFUND_OPERATION => null, Meta::REFUNDS => self::withKnown($orderId, $id)];
        if ($sequence !== '') {
            $done = Orders::meta($orderId, Meta::REFUNDS_DONE);
            $done = is_array($done) ? $done : [];
            $done[$sequence] = ['id' => $id, 'minor' => $minor, 'voided' => $voided];
            $meta[Meta::REFUNDS_DONE] = $done;
        }
        Orders::setMeta($orderId, $meta);
        Orders::note($orderId, $voided
            ? __('The Braintree payment had not settled, so it was voided. The customer is not charged.', 'stackless-lite')
            /* translators: %s: refund id */
            : sprintf(__('Refunded through Braintree (refund %s).', 'stackless-lite'), $id));
        Sync::ensureScheduled();
        return ['id' => $id, 'voided' => $voided];
    }

    /**
     * Like Attempt::save(): a refund is only sent once it can be read back.
     *
     * @param array<string,mixed> $operation
     */
    private static function begin(int $orderId, array $operation): bool
    {
        if (!Orders::setMeta($orderId, [Meta::REFUND_OPERATION => $operation])) {
            return false;
        }
        $stored = Orders::meta($orderId, Meta::REFUND_OPERATION);
        return is_array($stored) && hash_equals((string) $operation['key'], (string) ($stored['key'] ?? ''));
    }

    /**
     * A transaction's refunds from a read without errors. The list is nullable: null is none.
     *
     * @param mixed $transaction
     * @return array<int,mixed>|null null when the transaction itself was not returned
     */
    public static function refundsOf($transaction): ?array
    {
        if (!is_array($transaction) || empty($transaction['id']) || !array_key_exists('refunds', $transaction)) {
            return null;
        }
        if ($transaction['refunds'] === null) {
            return [];
        }
        return is_array($transaction['refunds']) ? array_values($transaction['refunds']) : null;
    }

    /** @return string[] */
    public static function known(int $orderId): array
    {
        $known = Orders::meta($orderId, Meta::REFUNDS);
        return is_array($known) ? array_map('strval', $known) : [];
    }

    /** @return string[] */
    private static function withKnown(int $orderId, string $id): array
    {
        $known = self::known($orderId);
        if (!in_array($id, $known, true)) {
            $known[] = $id;
        }
        return $known;
    }

    public static function remember(int $orderId, string $id): void
    {
        Orders::setMeta($orderId, [Meta::REFUNDS => self::withKnown($orderId, $id)]);
    }

    private static function reason(\WP_Error $error): string
    {
        switch (Api::code($error)) {
            case '91506':
            case '91574':
                return __('This payment has not finished settling, so Braintree cannot refund it yet. Try again after it settles.', 'stackless-lite');
            case '91504':
                return __('This payment can no longer be voided. Try again: it will be refunded instead.', 'stackless-lite');
            case '91512':
                return __('Braintree already refunded this payment in full.', 'stackless-lite');
            case '91521':
                return __('Braintree has less than that left to refund on this payment.', 'stackless-lite');
        }
        return $error->get_error_message();
    }

    private static function failure(string $message): \WP_Error
    {
        return new \WP_Error('stackless_braintree_refund', $message);
    }
}
