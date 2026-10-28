<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/** A failed Stripe API call. Carries the parsed Stripe error object. */
final class StripeApiException extends \Exception
{
    /** @var int */
    private $httpStatus;

    /** @var string */
    private $stripeType;

    /** @var string */
    private $stripeCode;

    /** @var string */
    private $declineCode;

    /** @var string */
    private $param;

    /** @var string */
    private $requestId;

    /** @var array<string,mixed> */
    private $error;

    /**
     * @param array<string,mixed> $error Stripe "error" object.
     */
    public function __construct(string $message, int $http_status, array $error = [], string $request_id = '')
    {
        parent::__construct($message, $http_status);
        $this->httpStatus = $http_status;
        $this->error = $error;
        $this->stripeType = (string) ($error['type'] ?? '');
        $this->stripeCode = (string) ($error['code'] ?? '');
        $this->declineCode = (string) ($error['decline_code'] ?? '');
        $this->param = (string) ($error['param'] ?? '');
        $this->requestId = $request_id;
    }

    public static function connection(string $message): self
    {
        return new self($message, 0, ['type' => 'api_connection_error', 'code' => 'api_connection_error']);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getStripeType(): string
    {
        return $this->stripeType;
    }

    public function getStripeCode(): string
    {
        return $this->stripeCode;
    }

    public function getDeclineCode(): string
    {
        return $this->declineCode;
    }

    public function getParam(): string
    {
        return $this->param;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /** PaymentIntent attached to a card error, when Stripe includes one. */
    public function getPaymentIntent(): ?array
    {
        return isset($this->error['payment_intent']) && is_array($this->error['payment_intent']) ? $this->error['payment_intent'] : null;
    }

    /** SetupIntent attached to a card error, when Stripe includes one. */
    public function getSetupIntent(): ?array
    {
        return isset($this->error['setup_intent']) && is_array($this->error['setup_intent']) ? $this->error['setup_intent'] : null;
    }

    public function isRetryable(): bool
    {
        return ErrorMapper::isRetryable($this->httpStatus, $this->stripeType, $this->stripeCode);
    }

    public function isResourceMissing(): bool
    {
        return $this->stripeCode === 'resource_missing';
    }

    /** Safe summary for logs: type, code, decline code, request id. Never includes request data. */
    public function logSummary(): string
    {
        return sprintf(
            'HTTP %d type=%s code=%s decline=%s param=%s request=%s: %s',
            $this->httpStatus,
            $this->stripeType ?: '-',
            $this->stripeCode ?: '-',
            $this->declineCode ?: '-',
            $this->param ?: '-',
            $this->requestId ?: '-',
            $this->getMessage()
        );
    }
}
