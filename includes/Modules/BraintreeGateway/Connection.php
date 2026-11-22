<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * What Braintree says about the connected keys: the merchant they belong to (saved cards and
 * payments are bound to it) and its merchant accounts. A merchant account has exactly one
 * currency and a charge without one lands in the default account's currency, so every charge
 * names the account for the order's currency and the gateway hides where there is none.
 */
final class Connection
{
    public const OPTION = 'stackless_braintree_gateway_connection_';
    private const BACKOFF = 'stackless_braintree_gateway_connection_failed_';
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 10;

    public static function option(string $mode): string
    {
        return self::OPTION . ($mode === 'live' ? 'live' : 'test');
    }

    /** Changes with any of the three credentials, and gives none of them away. */
    public static function fingerprint(string $mode): string
    {
        if (!Config::hasKeys($mode)) {
            return '';
        }
        return hash_hmac('sha256', $mode . '|' . Config::merchantId($mode) . '|' . Config::publicKey($mode), Config::privateKey($mode));
    }

    /**
     * The stored answer for the current credentials, or null.
     *
     * @return array{fingerprint:string, account:string, accounts:array<int,array{id:string,currency:string,status:string,default:bool,secure:bool}>, checked:int}|null
     */
    public static function cached(string $mode): ?array
    {
        $fingerprint = self::fingerprint($mode);
        $stored = get_option(self::option($mode), []);
        if ($fingerprint === '' || !is_array($stored) || ($stored['fingerprint'] ?? '') !== $fingerprint || empty($stored['account'])) {
            return null;
        }
        return $stored;
    }

    /**
     * The cached answer, fetched when there is none (keys pinned in wp-config.php never pass
     * through a settings save) or when it is older than $maxAge.
     *
     * @return array<string,mixed>|null
     */
    public static function ensure(string $mode, int $maxAge = 0): ?array
    {
        $cached = self::cached($mode);
        if ($cached && ($maxAge <= 0 || time() - (int) $cached['checked'] < $maxAge)) {
            return $cached;
        }
        $fingerprint = self::fingerprint($mode);
        if ($fingerprint === '') {
            return null;
        }
        $backoff = self::BACKOFF . substr($fingerprint, 0, 20);
        if (get_transient($backoff)) {
            return $cached;
        }
        $fresh = self::refresh($mode);
        if (is_wp_error($fresh)) {
            set_transient($backoff, 1, 5 * MINUTE_IN_SECONDS);
            Config::log('Could not read the Braintree merchant accounts (' . $mode . '): ' . $fresh->get_error_message(), 'warning');
            return $cached;
        }
        return $fresh;
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function refresh(string $mode)
    {
        $api = Config::api($mode);
        $account = '';
        $accounts = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $variables = ['first' => self::PAGE_SIZE];
            if ($after !== null) {
                $variables['after'] = $after;
            }
            $data = $api->query(Queries::MERCHANT, $variables);
            if (is_wp_error($data)) {
                return $data;
            }
            $merchant = $data['viewer']['merchant'] ?? null;
            $connection = is_array($merchant) ? ($merchant['merchantAccounts'] ?? null) : null;
            if (!is_array($merchant) || empty($merchant['id']) || !is_array($connection)) {
                return new \WP_Error('stackless_braintree_connection', __('Braintree did not return this account’s merchant accounts. The API key’s user needs permission to view them.', 'stackless-lite'));
            }
            $account = (string) $merchant['id'];
            foreach ((array) ($connection['edges'] ?? []) as $edge) {
                $node = is_array($edge) ? ($edge['node'] ?? null) : null;
                if (!is_array($node) || empty($node['id'])) {
                    continue;
                }
                $accounts[] = [
                    'id' => (string) $node['id'],
                    'currency' => strtoupper((string) ($node['currencyCode'] ?? '')),
                    'status' => (string) ($node['status'] ?? ''),
                    'default' => ($node['isDefault'] ?? false) === true,
                    'secure' => !empty($node['threeDSecure']['v2']['supportedCardBrands']),
                ];
            }
            if (empty($connection['pageInfo']['hasNextPage']) || empty($connection['pageInfo']['endCursor'])) {
                break;
            }
            $after = (string) $connection['pageInfo']['endCursor'];
        }
        $stored = ['fingerprint' => self::fingerprint($mode), 'account' => $account, 'accounts' => $accounts, 'checked' => time()];
        update_option(self::option($mode), $stored, false);
        return $stored;
    }

    public static function account(string $mode): string
    {
        $connection = self::ensure($mode);
        return $connection ? (string) $connection['account'] : '';
    }

    /**
     * The merchant account to charge a currency with, or null.
     *
     * The owner's choice wins and must be real, active and in that currency. Otherwise: the only
     * active account in the currency, or the default one when it is among several.
     *
     * @param array<string,mixed>|null $connection
     * @return array{id:string,currency:string,status:string,default:bool,secure:bool}|null
     */
    public static function merchantAccount(string $mode, string $currency, ?array $connection = null): ?array
    {
        $currency = strtoupper($currency);
        if (!Money::supported($currency)) {
            return null;
        }
        $connection = $connection ?? self::cached($mode);
        if (!$connection) {
            return null;
        }
        $candidates = [];
        foreach ((array) $connection['accounts'] as $row) {
            if ($row['status'] === 'ACTIVE' && $row['currency'] === $currency) {
                $candidates[] = $row;
            }
        }
        $chosen = Config::merchantAccounts($mode)[$currency] ?? '';
        if ($chosen !== '') {
            foreach ($candidates as $row) {
                if ($row['id'] === $chosen) {
                    return $row;
                }
            }
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        foreach ($candidates as $row) {
            if ($row['default']) {
                return $row;
            }
        }
        return null;
    }

    /** @return string[] Currencies that can be charged in a mode. */
    public static function currencies(string $mode): array
    {
        $connection = self::cached($mode);
        if (!$connection) {
            return [];
        }
        $currencies = [];
        foreach ((array) $connection['accounts'] as $row) {
            if (!in_array($row['currency'], $currencies, true) && self::merchantAccount($mode, $row['currency'], $connection)) {
                $currencies[] = $row['currency'];
            }
        }
        return $currencies;
    }

    /** The currency a card is verified in when no order sets one: the store's, else the first this account takes. */
    public static function cardCurrency(string $mode, string $preferred): string
    {
        $preferred = strtoupper($preferred);
        self::ensure($mode);
        $currencies = self::currencies($mode);
        return !$currencies || in_array($preferred, $currencies, true) ? $preferred : $currencies[0];
    }

    /**
     * The connection check run on save.
     *
     * @return string[] What is wrong.
     */
    public static function check(string $mode): array
    {
        $label = $mode === 'live' ? __('Production', 'stackless-lite') : __('Sandbox', 'stackless-lite');
        $connection = self::refresh($mode);
        if (is_wp_error($connection)) {
            /* translators: 1: Production or Sandbox, 2: error */
            return [sprintf(__('%1$s connection failed: %2$s', 'stackless-lite'), $label, $connection->get_error_message())];
        }
        $errors = [];
        $first = '';
        foreach ((array) $connection['accounts'] as $row) {
            if ($first === '' || $row['default']) {
                $first = $row['id'];
            }
        }
        if ($first !== '') {
            $merchant = Config::api($mode)->checkMerchant($first);
            if (is_wp_error($merchant)) {
                $errors[] = $merchant->get_error_message();
            }
        }
        foreach (Config::merchantAccounts($mode) as $currency => $id) {
            if (!self::merchantAccount($mode, $currency, $connection)) {
                /* translators: 1: Production or Sandbox, 2: merchant account id, 3: currency code */
                $errors[] = sprintf(__('%1$s: %2$s is not an active merchant account in %3$s, so Braintree is hidden for that currency.', 'stackless-lite'), $label, $id, $currency);
            }
        }
        $currencies = self::currencies($mode);
        if (!$currencies) {
            /* translators: %s: Production or Sandbox */
            $errors[] = sprintf(__('%s: no merchant account can take payments. Each currency needs one active account; choose one under “Merchant accounts” when a currency has several and none is the default.', 'stackless-lite'), $label);
        }
        if (Config::threeDSecure() !== ThreeDSecure::OFF) {
            foreach ($currencies as $currency) {
                $row = self::merchantAccount($mode, $currency, $connection);
                if ($row && !$row['secure']) {
                    /* translators: 1: Production or Sandbox, 2: merchant account id */
                    $errors[] = sprintf(__('%1$s: 3-D Secure is required, but Braintree reports none for merchant account %2$s. Ask Braintree to switch it on, or card payments there will fail.', 'stackless-lite'), $label, $row['id']);
                }
            }
        }
        return $errors;
    }
}
