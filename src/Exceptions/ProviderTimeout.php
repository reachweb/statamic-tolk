<?php

namespace Reach\StatamicTolk\Exceptions;

final class ProviderTimeout extends ProviderUnavailable
{
    public static function after(string $provider, int $timeout, int $seconds): self
    {
        return new self("The AI provider [{$provider}] did not answer within {$timeout} seconds. Try again in {$seconds} seconds.", $provider, $seconds);
    }
}
