<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * Disputes reported by Airwallex. A chargeback puts the order on hold once; a request for
 * information and every later stage only add a note.
 */
final class Disputes
{
    private const CHARGEBACK_STAGES = ['PRE_CHARGEBACK', 'CHARGEBACK', 'PRE_ARBITRATION', 'ARBITRATION'];
    private const RESOLVED_FOR_STORE = ['WON', 'REVERSED'];

    /**
     * @param array $dispute GET pa/payment_disputes/{id}
     * @return string noted|held|ignored|busy
     */
    public function apply(int $orderId, array $dispute): string
    {
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $dispute) {
            $sale = Sale::get($orderId);
            $id = Api::id($dispute['id'] ?? ($dispute['dispute_id'] ?? null));
            $intent = (string) ($dispute['payment_intent_id'] ?? '');
            if (!$sale || $id === '' || ($intent !== $sale->text(Meta::INTENT) && !isset($sale->data(Meta::FLAGS)[$intent]))) {
                return 'ignored';
            }
            $stage = strtoupper(sanitize_text_field((string) ($dispute['stage'] ?? '')));
            $status = strtoupper(sanitize_text_field((string) ($dispute['status'] ?? '')));
            $seen = $sale->data(Meta::DISPUTES);
            $previous = is_array($seen[$id] ?? null) ? $seen[$id] : [];
            if (($previous['state'] ?? '') === $stage . ':' . $status) {
                return 'ignored';
            }
            $hold = empty($previous['held'])
                && in_array($stage, self::CHARGEBACK_STAGES, true)
                && !in_array($status, self::RESOLVED_FOR_STORE, true)
                && $intent === $sale->text(Meta::INTENT);
            $seen[$id] = ['state' => $stage . ':' . $status, 'held' => $hold || !empty($previous['held'])];
            $sale->put([Meta::DISPUTES => $seen]);

            $amount = is_numeric($dispute['amount'] ?? null) ? $sale->money($dispute['amount'], (string) ($dispute['currency'] ?? '')) : '';
            /* translators: 1: dispute id, 2: stage, 3: status, 4: amount */
            $note = sprintf(__('Airwallex dispute %1$s: %2$s, %3$s %4$s. Respond in Airwallex.', 'stackless-lite'), $id, $stage, $status, $amount);
            if ($hold) {
                $sale->dispute($id, $note);
                return 'held';
            }
            $sale->note($note);
            return 'noted';
        }, 'busy');
    }
}
