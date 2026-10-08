<?php

namespace Reach\StatamicTolk\Exceptions;

class ProviderUnavailable extends TransientProviderFailure
{
    public static function connection(string $provider, int $seconds): self
    {
        return new self(__('Could not connect to the AI provider [:provider]. Try again in :seconds seconds.', ['provider' => $provider, 'seconds' => $seconds]), $provider, $seconds);
    }
}
