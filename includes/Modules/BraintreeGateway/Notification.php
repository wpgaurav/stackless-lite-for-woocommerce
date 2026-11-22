<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Braintree webhook notifications, verified as the official SDK does (WebhookNotificationGateway):
 * bt_signature holds "public_key|signature" pairs joined with "&"; the signature is the hex
 * HMAC-SHA1 of bt_payload, keyed with the raw SHA-1 of the private key, and may have been made
 * over the payload plus a line feed. bt_payload is base64 XML. Braintree documents no timestamp
 * tolerance and retries for 24 hours, so replays are stopped by claiming the payload's hash.
 */
final class Notification
{
    private const MAX_BYTES = 262144;

    public static function verify(string $signature, string $payload, string $publicKey, string $privateKey): bool
    {
        if ($signature === '' || $payload === '' || $publicKey === '' || $privateKey === '' || strlen($payload) > self::MAX_BYTES) {
            return false;
        }
        if (preg_match('/[^A-Za-z0-9+=\/\n]/', $payload)) {
            return false;
        }
        $key = sha1($privateKey, true);
        foreach (explode('&', $signature) as $pair) {
            $parts = explode('|', $pair);
            if (count($parts) < 2 || !hash_equals($publicKey, $parts[0])) {
                continue;
            }
            // Evaluate both so timing does not tell which form matched.
            $exact = hash_equals(hash_hmac('sha1', $payload, $key), $parts[1]);
            $newline = hash_equals(hash_hmac('sha1', $payload . "\n", $key), $parts[1]);
            return $exact || $newline;
        }
        return false;
    }

    /**
     * The fields this module uses. They only say what to read back from Braintree.
     *
     * @return array{id:string, kind:string, timestamp:string, transaction:string}|null
     */
    public static function parse(string $payload): ?array
    {
        $decoded = base64_decode($payload, false);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }
        $root = self::xml($decoded);
        if (!$root || $root->nodeName !== 'notification') {
            return null;
        }
        $kind = self::child($root, 'kind');
        if ($kind === '') {
            return null;
        }
        $transaction = '';
        $subject = self::element($root, 'subject');
        if ($subject) {
            $subject = self::element($subject, 'api-error-response') ?: $subject;
            $dispute = self::element($subject, 'dispute');
            $review = self::element($subject, 'transaction-review');
            $node = self::element($dispute ?: $subject, 'transaction');
            if ($node) {
                $transaction = self::child($node, 'id');
            } elseif ($review) {
                $transaction = self::child($review, 'transaction-id');
            }
        }
        return [
            'id' => hash('sha256', $decoded),
            'kind' => substr($kind, 0, 100),
            'timestamp' => self::child($root, 'timestamp'),
            'transaction' => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $transaction) ? $transaction : '',
        ];
    }

    /** The document element of Braintree XML. No DTDs, entities or network access. */
    public static function xml(string $body): ?\DOMElement
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false || !class_exists(\DOMDocument::class)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML(trim($body), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $document->documentElement : null;
    }

    /** Text of a direct child element. */
    public static function child(\DOMElement $parent, string $name): string
    {
        $element = self::element($parent, $name);
        return $element ? trim($element->textContent) : '';
    }

    private static function element(\DOMElement $parent, string $name): ?\DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === $name) {
                return $node;
            }
        }
        return null;
    }
}
