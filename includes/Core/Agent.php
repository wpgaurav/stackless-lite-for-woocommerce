<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Dispatcher behind stackless_agent(): commands for AI agents and trusted integrations,
 * run as the signed-in WordPress user.
 *
 * Modules add command families through three filters:
 * - stackless_agent_command ($result, $command, $input): return an array for commands you own,
 *   return $result unchanged otherwise. Check your own capabilities.
 * - stackless_agent_commands ($commands): add 'family.verb' => one-line description.
 * - stackless_agent_context ($context): add facts an agent needs before it acts.
 *
 * Every command returns ['ok' => bool, ...] and never throws. Writes that touch customers
 * (emails, cancellations, refunds) are separate commands, and most accept dry_run.
 */
final class Agent
{
    public const VERSION = 2;

    public static function call(string $command, array $input = []): array
    {
        if (!is_user_logged_in()) {
            return ['ok' => false, 'error' => 'Sign in as a WordPress user first. Agent commands run with that user\'s permissions.'];
        }
        $command = strtolower(trim($command));
        try {
            if ($command === 'context') {
                return ['ok' => true] + self::context();
            }
            if ($command === 'commands') {
                return ['ok' => true, 'commands' => self::commands()];
            }
            $result = apply_filters('stackless_agent_command', null, $command, $input);
        } catch (\Throwable $error) {
            return ['ok' => false, 'error' => $error->getMessage()];
        }
        if (is_array($result)) {
            return $result;
        }
        return ['ok' => false, 'error' => 'Unknown command: ' . $command . '. Run "commands" to list what this site supports; the module that owns it may be off.'];
    }

    /** @return array<string,string> */
    public static function commands(): array
    {
        $commands = [
            'context' => 'Site, user, store and module facts to read before anything else.',
            'commands' => 'This list.',
        ];
        $commands = (array) apply_filters('stackless_agent_commands', $commands);
        ksort($commands);
        return $commands;
    }

    public static function context(): array
    {
        $modules = [];
        foreach (['ai_agents', 'custom_invoices', 'request_quotes', 'subscriptions', 'software_licensing', 'back_in_stock', 'product_enhancements', 'page_access', 'multi_currency'] as $id) {
            $modules[$id] = stackless_module_active($id);
        }
        $context = [
            'api_version' => self::VERSION,
            'site_url' => home_url('/'),
            'user' => wp_get_current_user()->user_login,
            'can_manage_store' => current_user_can('manage_woocommerce'),
            'stackless_version' => defined('STACKLESS_VERSION') ? STACKLESS_VERSION : '',
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
            'store_currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'timezone' => wp_timezone_string(),
            'today' => wp_date('Y-m-d'),
            'modules' => $modules,
        ];
        return (array) apply_filters('stackless_agent_context', $context);
    }

    /**
     * Run a command body: ['ok' => true] + its result, or ['ok' => false, 'error' => …].
     *
     * @param callable(): array $body
     */
    public static function run(callable $body): array
    {
        try {
            return ['ok' => true] + $body();
        } catch (\Throwable $error) {
            return ['ok' => false, 'error' => $error->getMessage()];
        }
    }

    public static function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }

    /** A required positive integer from the input. */
    public static function id(array $input, string $key = 'id'): int
    {
        $id = isset($input[$key]) && is_numeric($input[$key]) ? (int) $input[$key] : 0;
        if ($id < 1) {
            throw new \InvalidArgumentException(esc_html('Pass a numeric ' . $key . '.'));
        }
        return $id;
    }

    /** A short plain-text value from the input. */
    public static function text(array $input, string $key, int $max = 200): string
    {
        $value = isset($input[$key]) && is_scalar($input[$key]) ? sanitize_text_field((string) $input[$key]) : '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    public static function dryRun(array $input): bool
    {
        return !empty($input['dry_run']) && $input['dry_run'] !== 'false';
    }

    /** Page and per_page from the input, capped. @return array{0:int,1:int} */
    public static function paging(array $input, int $default = 20, int $max = 100): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min($max, max(1, (int) ($input['per_page'] ?? $default)));
        return [$page, $perPage];
    }

    /**
     * A YYYY-MM-DD range in the site timezone (default: the last 30 days) and its UTC bounds.
     *
     * @return array{from:string,to:string,start_gmt:string,end_gmt:string}
     */
    public static function range(array $input, int $defaultDays = 30): array
    {
        $from = self::text($input, 'from', 10) ?: wp_date('Y-m-d', time() - ($defaultDays - 1) * DAY_IN_SECONDS);
        $to = self::text($input, 'to', 10) ?: wp_date('Y-m-d');
        foreach ([$from, $to] as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
                throw new \InvalidArgumentException('Dates use YYYY-MM-DD.');
            }
        }
        if ($from > $to) {
            throw new \InvalidArgumentException('from must be on or before to.');
        }
        return [
            'from' => $from,
            'to' => $to,
            'start_gmt' => get_gmt_from_date($from . ' 00:00:00'),
            'end_gmt' => get_gmt_from_date($to . ' 23:59:59'),
        ];
    }

    /** A money amount as a decimal string in the store's price decimals. */
    public static function money($amount): string
    {
        return wc_format_decimal((string) $amount, wc_get_price_decimals());
    }
}
