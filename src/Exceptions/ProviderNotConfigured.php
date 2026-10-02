<?php

namespace Reach\StatamicTolk\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

final class ProviderNotConfigured extends RuntimeException
{
    public static function for(string $provider): self
    {
        $variable = Str::upper(Str::slug($provider, '_')).'_API_KEY';

        return new self("The AI provider [{$provider}] has no API key. Set {$variable} or TOLK_{$variable} in .env.");
    }
}
