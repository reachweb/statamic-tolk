<?php

namespace Reach\StatamicTolk\Exceptions;

use Reach\StatamicTolk\Ai\Providers;
use RuntimeException;

final class ProviderNotConfigured extends RuntimeException
{
    public static function for(string $provider): self
    {
        $variable = Providers::variable($provider);
        $override = Providers::variable($provider, addon: true);

        return new self(__('The AI provider [:provider] has no API key. Set :variable or :override in .env.', ['provider' => $provider, 'variable' => $variable, 'override' => $override]));
    }
}
