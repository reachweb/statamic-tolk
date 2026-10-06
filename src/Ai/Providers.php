<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Support\Str;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;

class Providers
{
    public const array LABELS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
    ];

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

    /**
     * What the settings show about a provider's key, never any part of it: whether there is one, where it comes from
     * (`addon` for Tolk's own override, `ai` for config/ai.php, null without one) and the variables that set them.
     *
     * @return array{provider: string, label: string, configured: bool, source: ?string, env: array{ai: string, addon: string}}
     */
    public function status(string $provider): array
    {
        $source = match (true) {
            ! in_array($provider, ModelRef::PROVIDERS, true) => null,
            self::filled(config("tolk.providers.{$provider}.key")) => 'addon',
            self::filled(config("ai.providers.{$provider}.key")) => 'ai',
            default => null,
        };

        return [
            'provider' => $provider,
            'label' => self::label($provider),
            'configured' => $source !== null,
            'source' => $source,
            'env' => ['ai' => self::variable($provider), 'addon' => self::variable($provider, addon: true)],
        ];
    }

    public static function label(string $provider): string
    {
        return self::LABELS[$provider] ?? $provider;
    }

    /**
     * The environment variable of the provider's key: the SDK's, or with `addon` Tolk's override.
     */
    public static function variable(string $provider, bool $addon = false): string
    {
        return ($addon ? 'TOLK_' : '').Str::upper(Str::slug($provider, '_')).'_API_KEY';
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

            if (self::filled($value)) {
                return trim($value);
            }
        }

        return null;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
