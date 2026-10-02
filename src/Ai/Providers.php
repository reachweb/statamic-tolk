<?php

namespace Reach\StatamicTolk\Ai;

use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;

class Providers
{
    /**
     * @return list<string>
     */
    public function configured(): array
    {
        return array_values(array_filter(ModelRef::PROVIDERS, fn (string $provider) => $this->isConfigured($provider)));
    }

    public function isConfigured(string $provider): bool
    {
        return $this->key($provider) !== null;
    }

    public function name(string $provider): string
    {
        return "tolk_{$provider}";
    }

    /**
     * The array for Ai::build(). It holds the API key, so never store it.
     */
    public function buildConfig(string $provider): array
    {
        $config = [
            'name' => $this->name($provider),
            'driver' => $provider,
            'key' => $this->key($provider) ?? throw ProviderNotConfigured::for($provider),
        ];

        if ($url = $this->setting($provider, 'url')) {
            $config['url'] = $url;
        }

        if ($provider === 'openai') {
            $config['store'] = false;
        }

        return $config;
    }

    private function key(string $provider): ?string
    {
        return in_array($provider, ModelRef::PROVIDERS, true) ? $this->setting($provider, 'key') : null;
    }

    private function setting(string $provider, string $key): ?string
    {
        foreach (["tolk.providers.{$provider}.{$key}", "ai.providers.{$provider}.{$key}"] as $path) {
            $value = config($path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
