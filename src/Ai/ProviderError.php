<?php

namespace Reach\StatamicTolk\Ai;

use Reach\StatamicTolk\Enums\ProviderErrorKind;

/**
 * What ErrorClassifier read from a failed provider call. The message is already sanitised.
 */
final readonly class ProviderError
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const string INVALID_KEY = 'invalid_key';

    public function __construct(
        public ProviderErrorKind $kind,
        public string $message,
        public ?int $status = null,
        public ?string $code = null,
        public ?int $retryAfter = null,
    ) {}

    public function describe(): string
    {
        return implode(', ', array_filter([
            $this->status === null ? null : "HTTP {$this->status}",
            $this->code,
        ])) ?: 'no status';
    }
}
