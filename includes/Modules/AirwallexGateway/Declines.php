<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps a failed Airwallex payment to a Subscriptions decline category (SubscriptionData::DECLINE_*)
 * and the code kept for notes: the issuer's response code for card declines, Airwallex's own
 * error or failure code otherwise.
 */
final class Declines
{
    /**
     * Issuer response codes (docs: issuer response codes). 46 and 65 depend on the card brand
     * and are handled in forResponse().
     */
    private const RESPONSE = [
        '04' => SubscriptionData::DECLINE_HARD, // pick up card
        '07' => SubscriptionData::DECLINE_HARD, // pick up card, fraud account
        '14' => SubscriptionData::DECLINE_HARD, // invalid card number
        '15' => SubscriptionData::DECLINE_HARD, // invalid issuer
        '41' => SubscriptionData::DECLINE_HARD, // lost card
        '43' => SubscriptionData::DECLINE_HARD, // stolen card
        '54' => SubscriptionData::DECLINE_HARD, // expired card
        'R0' => SubscriptionData::DECLINE_HARD, // stop payment order
        'R1' => SubscriptionData::DECLINE_HARD, // authorization revoked
        'R3' => SubscriptionData::DECLINE_HARD, // all authorizations revoked
        '51' => SubscriptionData::DECLINE_FUNDS, // insufficient funds
        '61' => SubscriptionData::DECLINE_FUNDS, // amount limit
        '1A' => SubscriptionData::DECLINE_AUTH, // authentication required
        '91' => SubscriptionData::DECLINE_NETWORK, // issuer unavailable
        '96' => SubscriptionData::DECLINE_NETWORK, // system malfunction
    ];

    /** Airwallex error codes and PaymentAttempt failure codes. */
    private const CODES = [
        'authentication_declined' => SubscriptionData::DECLINE_AUTH,
        'authentication_failed' => SubscriptionData::DECLINE_AUTH,
        'authentication_required' => SubscriptionData::DECLINE_AUTH,
        '3ds_not_supported' => SubscriptionData::DECLINE_AUTH,
        'no_3ds_liability_shift' => SubscriptionData::DECLINE_AUTH,
        'provider_unavailable' => SubscriptionData::DECLINE_NETWORK,
        'issuer_unavailable' => SubscriptionData::DECLINE_NETWORK,
        'system_unavailable' => SubscriptionData::DECLINE_NETWORK,
        'internal_error' => SubscriptionData::DECLINE_NETWORK,
        'no_response' => SubscriptionData::DECLINE_NETWORK,
        'card_brand_not_supported' => SubscriptionData::DECLINE_HARD,
        'resource_not_found' => SubscriptionData::DECLINE_HARD,
        'no_payment_method' => SubscriptionData::DECLINE_HARD,
        'account_changed' => SubscriptionData::DECLINE_HARD,
        'currency_not_supported' => SubscriptionData::DECLINE_HARD,
    ];

    /** Merchant advice codes that say never to try this card again. */
    private const STOP_ADVICE = ['03', '21'];

    /**
     * @param array $intent A PaymentIntent.
     * @return array{category:string, code:string}
     */
    public static function fromIntent(array $intent): array
    {
        $attempt = is_array($intent['latest_payment_attempt'] ?? null) ? $intent['latest_payment_attempt'] : [];
        $failure = is_array($attempt['failure_details'] ?? null) ? $attempt['failure_details'] : [];
        $details = is_array($failure['details'] ?? null) ? $failure['details'] : [];
        $response = (string) ($attempt['provider_original_response_code'] ?? ($details['original_response_code'] ?? ''));
        $code = (string) ($failure['code'] ?? ($attempt['failure_code'] ?? ''));
        $brand = (string) ($attempt['payment_method']['card']['brand'] ?? ($details['card_brand'] ?? ''));
        if ($code === '') {
            $code = strtolower((string) ($attempt['status'] ?? ($intent['status'] ?? ''))) ?: 'failed';
        }
        return self::decline($code, $response, $brand, (string) ($attempt['merchant_advice_code'] ?? ''));
    }

    /** @return array{category:string, code:string} */
    public static function fromError(\WP_Error $error): array
    {
        $status = Api::status($error);
        $code = Api::detail($error);
        if ($code === '') {
            $code = $status > 0 ? 'http_' . $status : 'no_response';
        }
        $decline = self::decline($code, Api::detail($error, 'response_code'), Api::detail($error, 'card_brand'));
        if ($status === 0 || $status === 429 || $status >= 500) {
            $decline['category'] = SubscriptionData::DECLINE_NETWORK;
        }
        return $decline;
    }

    /** @return array{category:string, code:string} */
    public static function decline(string $code, string $response = '', string $brand = '', string $advice = ''): array
    {
        $response = strtoupper($response);
        $declined = $response !== '' && $response !== '00';
        if (in_array($advice, self::STOP_ADVICE, true)) {
            $category = SubscriptionData::DECLINE_HARD;
        } elseif ($declined) {
            $category = self::forResponse($response, strtolower($brand)) ?? (self::CODES[$code] ?? SubscriptionData::DECLINE_SOFT);
        } else {
            $category = self::CODES[$code] ?? SubscriptionData::DECLINE_SOFT;
        }
        return ['category' => $category, 'code' => $declined ? $response : $code];
    }

    private static function forResponse(string $response, string $brand): ?string
    {
        if ($response === '46') {
            // "Closed account", except Cartes Bancaires, which uses it for any business reason.
            return strpos($brand, 'cartes') !== false ? SubscriptionData::DECLINE_SOFT : SubscriptionData::DECLINE_HARD;
        }
        if ($response === '65') {
            // Mastercard asks for authentication with it; Visa means a usage limit.
            return $brand === 'mastercard' ? SubscriptionData::DECLINE_AUTH : SubscriptionData::DECLINE_FUNDS;
        }
        return self::RESPONSE[$response] ?? null;
    }

    /** Write the decline details on an order. Call before it turns failed. */
    public static function record(Sale $sale, array $decline): void
    {
        $category = in_array($decline['category'] ?? '', SubscriptionData::DECLINES, true) ? (string) $decline['category'] : SubscriptionData::DECLINE_SOFT;
        $sale->put([
            SubscriptionData::META_DECLINE => $category,
            SubscriptionData::META_DECLINE_CODE => substr(sanitize_text_field((string) ($decline['code'] ?? '')), 0, 64),
        ]);
    }
}
