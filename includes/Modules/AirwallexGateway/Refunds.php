<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * Refunds both ways.
 *
 * Out: one request_id per store refund. A refund without an answer is remembered, and a retry
 * finishes that one instead of sending another. In: refunds made in Airwallex are read from its
 * API and recorded once per refund id, only for the payment that paid the order.
 */
final class Refunds
{
    /** An unanswered refund that Airwallex still does not list after this long never happened. */
    private const UNKNOWN_FOR = 900;

    /**
     * @param string $key What makes this store refund unique (its id or sequence).
     * @return array{id:string,status:string}|\WP_Error
     */
    public function create(int $orderId, float $amount, string $key, string $reason = '')
    {
        return Lock::run('order-' . $orderId, function () use ($orderId, $amount, $key, $reason) {
            return $this->createLocked($orderId, $amount, $key, $reason);
        }, new \WP_Error('stackless_airwallex_refund', __('This order is being updated. Try the refund again in a moment.', 'stackless-lite')));
    }

    public static function refundable(Sale $sale): bool
    {
        return $sale->reference() !== '' && Api::id($sale->text(Meta::INTENT)) !== '';
    }

    /** @return array{id:string,status:string}|\WP_Error */
    private function createLocked(int $orderId, float $amount, string $key, string $reason)
    {
        $sale = Sale::get($orderId);
        if (!$sale || !self::refundable($sale)) {
            return new \WP_Error('stackless_airwallex_refund', __('This order has no Airwallex payment to refund.', 'stackless-lite'));
        }
        $currency = $sale->currency();
        $minor = Money::toMinor($amount, $currency);
        if ($minor <= 0) {
            return new \WP_Error('stackless_airwallex_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $api = Sync::apiFor($sale);
        if (is_wp_error($api)) {
            return $api;
        }
        $intent = $sale->text(Meta::INTENT);
        $round = (int) $sale->text(Meta::REFUND_ROUND);
        $requestId = Api::stableId($sale->reference(), 'refund', $key, (string) $minor, (string) $round);

        $pending = $sale->data(Meta::REFUND_PENDING);
        $resend = false;
        if ($pending) {
            $list = self::listRefunds($api, $intent);
            if (is_wp_error($list)) {
                return $list;
            }
            $found = self::findRequest($list, (string) $pending['request']);
            $same = (int) $pending['minor'] === $minor;
            if ($found && ($found['status'] ?? '') === 'FAILED') {
                ++$round;
                $sale->put([Meta::REFUND_PENDING => null, Meta::REFUND_ROUND => (string) $round]);
                $requestId = Api::stableId($sale->reference(), 'refund', $key, (string) $minor, (string) $round);
            } elseif ($found && $same) {
                return $this->accepted($sale, $found, $minor);
            } elseif ($found || (!$same && (int) $pending['at'] > time() - self::UNKNOWN_FOR)) {
                return new \WP_Error('stackless_airwallex_refund_pending', sprintf(
                    /* translators: %s: amount */
                    __('An earlier refund of %s has no confirmed result here. Refund that amount again to finish it: it is not sent twice.', 'stackless-lite'),
                    $sale->money((int) $pending['minor'] / (10 ** Money::decimals($currency)))
                ));
            } elseif ($same) {
                $requestId = (string) $pending['request'];
                $resend = true;
            }
        }

        $sale->put([Meta::REFUND_PENDING => ['request' => $requestId, 'minor' => $minor, 'at' => $resend ? (int) $pending['at'] : time()]]);
        $refund = $api->request('POST', 'pa/refunds/create', [
            'request_id' => $requestId,
            'payment_intent_id' => $intent,
            'amount' => Money::value($amount, $currency),
            'reason' => substr(trim(wp_strip_all_tags($reason)) ?: 'Refund for order ' . $sale->number(), 0, 128),
        ]);
        if (is_wp_error($refund)) {
            if (Api::isRejected($refund) || (!$resend && (!Api::wasSent($refund) || in_array(Api::status($refund), [401, 403, 429], true)))) {
                // Airwallex did not create it.
                $sale->put([Meta::REFUND_PENDING => null, Meta::REFUND_ROUND => (string) ($round + 1)]);
                Settings::log('Refund on order ' . $orderId . ' was refused: ' . $refund->get_error_message(), 'error');
                return $refund;
            }
            $list = self::listRefunds($api, $intent);
            $found = is_wp_error($list) ? null : self::findRequest($list, $requestId);
            if (!$found) {
                Settings::log('Refund on order ' . $orderId . ' has no answer: ' . $refund->get_error_message(), 'error');
                return new \WP_Error('stackless_airwallex_refund_pending', __('Airwallex did not confirm this refund. Do not refund another way. Try the same amount again: it is not sent twice.', 'stackless-lite'));
            }
            $refund = $found;
        }
        return $this->accepted($sale, $refund, $minor);
    }

    /** @return array{id:string,status:string}|\WP_Error */
    private function accepted(Sale $sale, array $refund, int $minor)
    {
        $id = Api::id($refund['id'] ?? null);
        $status = (string) ($refund['status'] ?? '');
        if ($status === 'FAILED') {
            $sale->put([Meta::REFUND_PENDING => null, Meta::REFUND_ROUND => (string) ((int) $sale->text(Meta::REFUND_ROUND) + 1)]);
            return new \WP_Error('stackless_airwallex_refund', __('Airwallex could not process this refund. Check the payment and your balance in Airwallex.', 'stackless-lite'));
        }
        $currency = $sale->currency();
        if ($id === '' || !is_numeric($refund['amount'] ?? null) || Money::toMinor($refund['amount'], $currency) !== $minor
            || (string) ($refund['payment_intent_id'] ?? '') !== $sale->text(Meta::INTENT)) {
            return new \WP_Error('stackless_airwallex_refund_pending', __('Airwallex’s answer did not match this refund. Check it in Airwallex before trying again.', 'stackless-lite'));
        }
        $sentBefore = in_array($id, $sale->data(Meta::REFUNDS), true);
        $sale->put([Meta::REFUNDS => self::with($sale, $id), Meta::REFUND_PENDING => null]);
        if ($sentBefore) {
            // The same store refund again (its record was not saved, or was deleted since).
            /* translators: %s: Airwallex refund id */
            $sale->note(sprintf(__('Airwallex refund %s was already made for this amount, so no more money was sent.', 'stackless-lite'), $id));
            return ['id' => $id, 'status' => $status];
        }
        $sale->note(sprintf(
            /* translators: 1: amount, 2: Airwallex refund id, 3: status */
            __('Refunded %1$s through Airwallex (refund %2$s, %3$s).', 'stackless-lite'),
            $sale->money($refund['amount']),
            $id,
            sanitize_text_field($status)
        ));
        return ['id' => $id, 'status' => $status];
    }

    /** @return string[] */
    private static function with(Sale $sale, string $id): array
    {
        return array_values(array_unique(array_merge($sale->data(Meta::REFUNDS), [$id])));
    }

    /** @return array<int,array>|\WP_Error */
    private static function listRefunds(Api $api, string $intent)
    {
        $list = $api->request('GET', 'pa/refunds', ['payment_intent_id' => $intent, 'page_size' => 100]);
        if (is_wp_error($list)) {
            return $list;
        }
        if (!is_array($list['items'] ?? null)) {
            return new \WP_Error('stackless_airwallex_refund', __('Airwallex returned an unreadable list of refunds.', 'stackless-lite'));
        }
        return array_values(array_filter($list['items'], static function ($refund) use ($intent) {
            return is_array($refund) && (string) ($refund['payment_intent_id'] ?? '') === $intent;
        }));
    }

    /** @param array<int,array> $list */
    private static function findRequest(array $list, string $requestId): ?array
    {
        foreach ($list as $refund) {
            if ($requestId !== '' && (string) ($refund['request_id'] ?? '') === $requestId) {
                return $refund;
            }
        }
        return null;
    }

    /**
     * A refund event for one of the order's payments: read every refund of it and record the new.
     *
     * @param array $intent The payment, as read from Airwallex.
     * @return string synced|ignored|error|busy
     */
    public function syncInbound(int $orderId, array $intent): string
    {
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $intent) {
            $sale = Sale::get($orderId);
            $id = Api::id($intent['id'] ?? null);
            if (!$sale || $id === '') {
                return 'ignored';
            }
            $paidBy = $id === $sale->text(Meta::INTENT) && $sale->paid();
            $flags = $sale->data(Meta::FLAGS);
            if (!$paidBy && !isset($flags[$id])) {
                return 'ignored';
            }
            $api = Sync::apiFor($sale);
            if (is_wp_error($api)) {
                return 'error';
            }
            $list = self::listRefunds($api, $id);
            if (is_wp_error($list)) {
                return 'error';
            }
            return $paidBy ? $this->record($sale, $list) : $this->clearFlag($sale, $intent, $list);
        }, 'busy');
    }

    /** @param array<int,array> $list */
    private function record(Sale $sale, array $list): string
    {
        $currency = $sale->currency();
        foreach ($list as $refund) {
            $id = Api::id($refund['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $failed = ($refund['status'] ?? '') === 'FAILED';
            $known = $sale->data(Meta::REFUNDS);
            if (in_array($id, $known, true)) {
                if ($failed && !in_array($id . ':failed', $known, true)) {
                    $sale->put([Meta::REFUNDS => self::with($sale, $id . ':failed')]);
                    /* translators: %s: Airwallex refund id */
                    $sale->note(sprintf(__('Airwallex could not complete refund %s, which is recorded on this order. The customer did not get that money back: check it in Airwallex.', 'stackless-lite'), $id));
                }
                continue;
            }
            $pending = $sale->data(Meta::REFUND_PENDING);
            $wasPending = $pending && (string) $pending['request'] === (string) ($refund['request_id'] ?? '');
            if ($failed) {
                if ($wasPending) {
                    $sale->put([Meta::REFUND_PENDING => null, Meta::REFUND_ROUND => (string) ((int) $sale->text(Meta::REFUND_ROUND) + 1)]);
                }
                continue;
            }

            // Remember first, so hooks of the store refund never send it back to Airwallex.
            $values = [Meta::REFUNDS => self::with($sale, $id)];
            if ($wasPending) {
                $values[Meta::REFUND_PENDING] = null;
            }
            $amount = is_numeric($refund['amount'] ?? null) ? (float) $refund['amount'] : 0.0;
            $sameCurrency = strtoupper((string) ($refund['currency'] ?? '')) === $currency;
            $amount = $sameCurrency ? min($amount, $sale->remainingRefund()) : 0.0;
            $sale->put($values);
            if (Money::toMinor($amount, $currency) <= 0) {
                /* translators: %s: Airwallex refund id */
                $sale->note(sprintf(__('Airwallex refund %s could not be added to this order (other currency, or nothing left to refund here).', 'stackless-lite'), $id));
                continue;
            }
            /* translators: %s: Airwallex refund id */
            if (!$sale->recordRefund($amount, $id, sprintf(__('Refunded in Airwallex (%s)', 'stackless-lite'), $id))) {
                $sale->put([Meta::REFUNDS => array_values(array_diff($sale->data(Meta::REFUNDS), [$id]))]);
                return 'error';
            }
        }
        return 'synced';
    }

    /** @param array<int,array> $list A payment that needed review is done once it is refunded in full. */
    private function clearFlag(Sale $sale, array $intent, array $list): string
    {
        $currency = (string) ($intent['currency'] ?? '');
        $refunded = 0;
        foreach ($list as $refund) {
            if (in_array($refund['status'] ?? '', ['ACCEPTED', 'SETTLED'], true) && is_numeric($refund['amount'] ?? null)) {
                $refunded += Money::toMinor($refund['amount'], $currency);
            }
        }
        if ($refunded <= 0 || $refunded < Money::toMinor((float) ($intent['amount'] ?? 0), $currency)) {
            return 'ignored';
        }
        $id = (string) $intent['id'];
        $flags = $sale->data(Meta::FLAGS);
        if ($flags[$id] === 'returned') {
            return 'ignored';
        }
        // "returned" stays, so this payment can never complete the order.
        $flags[$id] = 'returned';
        $sale->put([Meta::FLAGS => $flags, Meta::REVIEW => array_diff($flags, ['returned']) ? 'yes' : null]);
        /* translators: %s: Airwallex payment id */
        $sale->note(sprintf(__('Airwallex payment %s, which needed review, was refunded in full.', 'stackless-lite'), $id));
        return 'synced';
    }
}
