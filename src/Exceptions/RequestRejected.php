<?php

namespace Reach\StatamicTolk\Exceptions;

use Reach\StatamicTolk\Ai\ProviderError;
use RuntimeException;

/**
 * The provider refused this one request (HTTP 400 and other 4xx). The unit's segments fail with `request_rejected`;
 * a request that was too large (413) is split instead (§A10).
 */
final class RequestRejected extends RuntimeException
{
    private function __construct(string $message, public readonly string $provider, public readonly ?int $status)
    {
        parent::__construct($message);
    }

    public static function from(string $provider, ProviderError $error): self
    {
        return new self("The AI provider [{$provider}] rejected the request ({$error->describe()}): {$error->message}", $provider, $error->status);
    }

    public function tooLarge(): bool
    {
        return $this->status === 413;
    }
}
