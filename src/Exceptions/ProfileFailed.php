<?php

namespace Reach\StatamicTolk\Exceptions;

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use RuntimeException;

/**
 * Writing the site profile failed: the provider call failed, or its answer held no profile. The message is safe to show.
 */
final class ProfileFailed extends RuntimeException
{
    public static function from(ModelRef $model, ProviderError $error): self
    {
        if ($error->kind === ProviderErrorKind::Rejected) {
            return new self(ProviderRejected::from($model, $error)->getMessage());
        }

        return new self("The AI provider [{$model->provider}] could not write the site profile ({$error->describe()}): {$error->message}");
    }

    public static function cutOff(ModelRef $model): self
    {
        return new self("The answer of [{$model}] was cut off before it held a site profile. Try again, or try another model.");
    }

    public static function noProfile(ModelRef $model): self
    {
        return new self("The answer of [{$model}] held no site profile. Try again, or try another model.");
    }
}
