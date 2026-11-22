<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Refunds sent from the store. The refund is saved with its reference and Idempotency-Key before
 * the request; asking again for the same amount after an unknown answer sends the same key, and
 * looks the reference up first, so it cannot refund twice.
 */
final class RefundService
{
    /** An unanswered refund is given this long before a different one may replace it. */
    private const IN_FLIGHT = 120;

    public static function canRefund(int $orderId): bool
    {
        return Orders::isOurs($orderId) && Orders::meta($orderId, Keys::PAID) !== '' && Orders::total($orderId) > 0;
    }

    /**
     * @param bool $confirmLater Keep the refund saved until confirmed(): the store records its
     *                           own refund afterwards and may still fail to.
     * @return array{id:string, state:string}|\WP_Error
     */
    public function refund(int $orderId, float $amount, string $reason = '', bool $confirmLater = false)
    {
        if (!self::canRefund($orderId)) {
            return new \WP_Error('stackless_revolut_refund', __('This order has no Revolut payment to refund.', 'stackless-lite'));
        }
        $mode = Orders::mode($orderId);
        if ($mode === 'live' && !Settings::liveStore()) {
            return new \WP_Error('stackless_revolut_copy', __('This site is a copy of the live store, so it does not refund live payments.', 'stackless-lite'));
        }
        $currency = Orders::currency($orderId);
        $minor = Money::toMinor($amount, $currency);
        if ($minor <= 0) {
            return new \WP_Error('stackless_revolut_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $result = Lock::order($orderId, function () use ($orderId, $minor, $currency, $mode, $reason, $confirmLater) {
            return $this->refundLocked($orderId, $minor, $currency, $mode, $reason, $confirmLater);
        }, null);
        return $result === null
            ? new \WP_Error('stackless_revolut_busy', __('Revolut is updating this order. Try the refund again in a moment.', 'stackless-lite'))
            : $result;
    }

    /** @return array{id:string, state:string}|\WP_Error */
    private function refundLocked(int $orderId, int $minor, string $currency, string $mode, string $reason, bool $confirmLater)
    {
        $paid = Orders::meta($orderId, Keys::PAID);
        $api = Settings::api($mode);
        $intent = Orders::metaList($orderId, Keys::REFUND_INTENT);
        $found = null;

        if ($intent && !empty($intent['done'])) {
            if ((int) $intent['amount'] === $minor) {
                // Revolut refunded it and the store did not record it: this is that same refund.
                return ['id' => (string) $intent['done'], 'state' => 'done'];
            }
            $intent = [];
        } elseif ($intent) {
            $found = Remote::findByReference($api, (string) ($intent['ref'] ?? ''));
            if (is_wp_error($found)) {
                return $found;
            }
            if ($found && !self::failed($found)) {
                $id = (string) $found['id'];
                if (in_array($id, self::known($orderId), true)) {
                    Orders::deleteMeta($orderId, Keys::REFUND_INTENT);
                    return new \WP_Error('stackless_revolut_refund', __('That refund already went through and is recorded on this order. Reload the order before refunding again.', 'stackless-lite'));
                }
                if ((int) $intent['amount'] !== $minor) {
                    /* translators: 1: amount, 2: Revolut refund id */
                    return new \WP_Error('stackless_revolut_refund', sprintf(__('An earlier refund of %1$s (%2$s) reached Revolut. Refund that amount here to record it, or wait for Revolut to confirm it.', 'stackless-lite'), Money::display((int) $intent['amount'], $currency), $id));
                }
            } elseif ($found) {
                $intent = [];
                $found = null;
            } elseif ((int) ($intent['amount'] ?? 0) !== $minor) {
                if ((int) ($intent['at'] ?? 0) > time() - self::IN_FLIGHT) {
                    return new \WP_Error('stackless_revolut_refund', __('An earlier refund is still being sent to Revolut. Wait two minutes and try again.', 'stackless-lite'));
                }
                $intent = [];
            }
        }

        if (!$intent) {
            $intent = ['ref' => 'stl-refund-' . $orderId . '-' . bin2hex(random_bytes(8)), 'amount' => $minor, 'at' => time()];
            Orders::setMeta($orderId, [Keys::REFUND_INTENT => $intent]);
        }

        if (!$found) {
            $body = [
                'amount' => $minor,
                'currency' => $currency,
                'merchant_order_data' => ['reference' => (string) $intent['ref']],
                'metadata' => [Keys::REMOTE_ORDER => (string) $orderId],
                'description' => trim($reason) !== ''
                    ? Api::text(wp_strip_all_tags($reason), 255)
                    /* translators: %s: order number */
                    : sprintf(__('Refund for order %s', 'stackless-lite'), Orders::number($orderId)),
            ];
            $answer = $api->request('POST', 'orders/' . rawurlencode($paid) . '/refund', $body, 'sl-' . md5($paid . '|' . (string) $intent['ref']));
            if (is_wp_error($answer)) {
                Settings::log('Refund on order ' . $orderId . ' failed: ' . $answer->get_error_message(), 'error');
                $sent = Remote::findByReference($api, (string) $intent['ref']);
                if (is_array($sent)) {
                    // A declined card refund still creates a refund order.
                    $found = $sent;
                } elseif ($sent === null && !Api::isTransient($answer)) {
                    Orders::deleteMeta($orderId, Keys::REFUND_INTENT);
                    return $answer;
                } else {
                    /* translators: %s: error */
                    return new \WP_Error('stackless_revolut_refund_unknown', sprintf(__('It is not known whether Revolut took the refund (%s). Refund the same amount again: it cannot be refunded twice.', 'stackless-lite'), $answer->get_error_message()));
                }
            } else {
                $found = $answer;
            }
        }

        $id = (string) ($found['id'] ?? '');
        if (!Api::isId($id) || (isset($found['type']) && $found['type'] !== 'refund')
            || (isset($found['related_order_id']) && $found['related_order_id'] !== $paid)
            || !Money::matches($found, $minor, $currency)) {
            return new \WP_Error('stackless_revolut_refund_unknown', __('Revolut answered with a refund that does not match. Check the payment in Revolut Business before refunding again.', 'stackless-lite'));
        }
        if (self::failed($found)) {
            Orders::deleteMeta($orderId, Keys::REFUND_INTENT);
            $decline = Declines::fromOrder($found);
            /* translators: %s: reason code */
            return new \WP_Error('stackless_revolut_refund', sprintf(__('Revolut could not process this refund (%s). Check the payment in Revolut Business.', 'stackless-lite'), $decline['code']));
        }

        self::remember($orderId, $id);
        if ($confirmLater) {
            Orders::setMeta($orderId, [Keys::REFUND_INTENT => $intent + ['done' => $id]]);
        } else {
            Orders::deleteMeta($orderId, Keys::REFUND_INTENT);
        }
        $state = (string) ($found['state'] ?? '');
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Revolut refund id, 3: status */
            __('Refunded %1$s through Revolut (refund %2$s, %3$s).', 'stackless-lite'),
            Money::display($minor, $currency),
            $id,
            $state
        ));
        return ['id' => $id, 'state' => $state];
    }

    /** The store recorded the refund that Revolut made. */
    public static function confirmed(int $orderId): void
    {
        Orders::deleteMeta($orderId, Keys::REFUND_INTENT);
    }

    /** @param array<string,mixed> $refund A Revolut refund order. */
    public static function failed(array $refund): bool
    {
        if (in_array((string) ($refund['state'] ?? ''), ['failed', 'cancelled'], true)) {
            return true;
        }
        $payments = Remote::payments($refund);
        foreach ($payments as $payment) {
            if (!in_array((string) ($payment['state'] ?? ''), ['declined', 'soft_declined', 'failed', 'cancelled'], true)) {
                return false;
            }
        }
        return (bool) $payments;
    }

    /** @return string[] */
    public static function known(int $orderId): array
    {
        return array_values(array_map('strval', Orders::metaList($orderId, Keys::REFUNDS)));
    }

    public static function remember(int $orderId, string $id): void
    {
        $known = self::known($orderId);
        if (!in_array($id, $known, true)) {
            $known[] = $id;
            Orders::setMeta($orderId, [Keys::REFUNDS => $known]);
        }
    }

    public static function forget(int $orderId, string $id): void
    {
        Orders::setMeta($orderId, [Keys::REFUNDS => array_values(array_diff(self::known($orderId), [$id]))]);
    }
}
