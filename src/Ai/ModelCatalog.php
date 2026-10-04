<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Support\Arr;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Support\Settings;

class ModelCatalog
{
    public function __construct(private readonly Providers $providers) {}

    /**
     * @return array<string, list<array>>
     */
    public function all(): array
    {
        return collect(ModelRef::PROVIDERS)
            ->mapWithKeys(fn (string $provider) => [$provider => $this->forProvider($provider)])
            ->all();
    }

    /**
     * @return list<array>
     */
    public function forProvider(string $provider): array
    {
        if (! in_array($provider, ModelRef::PROVIDERS, true)) {
            return [];
        }

        return array_values(array_filter(Arr::wrap(config("tolk.models.{$provider}")), 'is_array'));
    }

    /**
     * The catalog as model selects show it, keyed by provider.
     *
     * @return array<string, list<array{value: string, label: string, tier: ?string, configured: bool}>>
     */
    public function grouped(): array
    {
        return collect($this->all())
            ->map(fn (array $entries, string $provider) => collect($entries)
                ->filter(fn (array $entry) => is_string($entry['id'] ?? null))
                ->map(function (array $entry) use ($provider) {
                    $model = new ModelRef($provider, $entry['id']);

                    return [
                        'value' => (string) $model,
                        'label' => $this->label($model),
                        'tier' => $entry['tier'] ?? null,
                        'configured' => $this->providers->isConfigured($provider),
                    ];
                })
                ->values()
                ->all())
            ->all();
    }

    public function find(ModelRef $model): ?array
    {
        return Arr::first($this->forProvider($model->provider), fn (array $entry) => ($entry['id'] ?? null) === $model->model);
    }

    public function has(ModelRef $model): bool
    {
        return $this->find($model) !== null;
    }

    public function default(string $provider, Tier $tier): ModelRef
    {
        $entry = Arr::first($this->forProvider($provider), fn (array $entry) => ($entry['tier'] ?? null) === $tier->value)
            ?? throw MissingDefaultModel::for($provider, $tier);

        return new ModelRef($provider, $entry['id']);
    }

    public function options(ModelRef $model): array
    {
        $options = $this->find($model)['options'] ?? [];

        return is_array($options) ? $options : [];
    }

    public function maxOutputTokens(ModelRef $model): ?int
    {
        $tokens = $this->find($model)['max_output_tokens'] ?? null;

        return is_numeric($tokens) ? (int) $tokens : null;
    }

    public function reasoningHeadroom(ModelRef $model): int
    {
        $tokens = $this->find($model)['reasoning_headroom'] ?? 0;

        return is_numeric($tokens) ? (int) $tokens : 0;
    }

    /**
     * @return array{input: float, output: float}|null
     */
    public function price(ModelRef $model): ?array
    {
        $price = $this->find($model)['price'] ?? null;

        if (! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        return ['input' => (float) $price['input'], 'output' => (float) $price['output']];
    }

    public function label(ModelRef $model): string
    {
        $label = $this->find($model)['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $model->model;
    }

    public function resolveTranslator(Settings $settings, ?ModelRef $override = null): ModelRef
    {
        if ($model = $override ?? $settings->translatorModel()) {
            return $model;
        }

        // The fake engine needs no key, so a sandbox without keys still gets a translator to plan with.
        $provider = Arr::first($this->providers->configured())
            ?? (FakeEngine::enabled() ? ModelRef::PROVIDERS[0] : throw NoProviderConfigured::make());

        return $this->default($provider, Tier::Quality);
    }

    public function resolveReviewer(Settings $settings, ModelRef $translator, ?ModelRef $override = null): ModelRef
    {
        if ($model = $override ?? $settings->reviewerModel()) {
            return $model;
        }

        $provider = Arr::first($this->providers->configured(), fn (string $provider) => $provider !== $translator->provider);

        return $provider ? $this->default($provider, Tier::Quality) : $translator;
    }

    public function resolveFallback(Settings $settings, ModelRef $translator): ?ModelRef
    {
        $model = $settings->fallbackModel();

        if (! $model || ! $this->providers->isConfigured($model->provider) || (string) $model === (string) $translator) {
            return null;
        }

        return $model;
    }
}
