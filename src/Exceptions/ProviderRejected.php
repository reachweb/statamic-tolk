<?php

namespace Reach\StatamicTolk\Exceptions;

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\ProviderError;
use RuntimeException;

/**
 * The provider refuses this account or model: a missing or invalid key, no credits, a spend cap, an unknown model. Runs
 * abort; it is never retried (§A10).
 */
final class ProviderRejected extends RuntimeException
{
    private function __construct(string $message, public readonly string $provider, public readonly ?int $status, public readonly ?string $errorCode)
    {
        parent::__construct($message);
    }

    public static function from(ModelRef $model, ProviderError $error): self
    {
        $message = match (true) {
            $error->code === ProviderError::NOT_CONFIGURED => $error->message,
            $error->status === 404 => "The AI provider [{$model->provider}] does not offer the model [{$model->model}] ({$error->describe()}): {$error->message}",
            default => "The AI provider [{$model->provider}] rejected the request ({$error->describe()}): {$error->message}",
        };

        return new self($message, $model->provider, $error->status, $error->code);
    }
}
