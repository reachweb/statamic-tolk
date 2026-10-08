<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class NoProviderConfigured extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('No AI provider is configured. Set ANTHROPIC_API_KEY, OPENAI_API_KEY or GEMINI_API_KEY in .env.'));
    }
}
