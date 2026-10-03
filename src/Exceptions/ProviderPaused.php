<?php

namespace Reach\StatamicTolk\Exceptions;

final class ProviderPaused extends TransientProviderFailure
{
    public static function for(string $provider, int $seconds): self
    {
        return new self("The AI provider [{$provider}] is paused after a rate limit or an overload. Try again in {$seconds} seconds.", $provider, $seconds);
    }
}
