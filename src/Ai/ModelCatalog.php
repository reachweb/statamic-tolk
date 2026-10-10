<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Support\Arr;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Support\Settings;

class ModelCatalog
{
    private static ?array $tolkModels = null;

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
     * Tolk's models of the provider with the entries of `tolk.models` merged in by id: an entry replaces the keys it
     * names of the model with its id, and a model Tolk does not have follows Tolk's. Entries without an id are left out.
     *
     * @return list<array>
     */
    public function forProvider(string $provider): array
    {
        if (! in_array($provider, ModelRef::PROVIDERS, true)) {
            return [];
        }

        $models = [];

        foreach ([...self::tolkModels($provider), ...Arr::wrap(config("tolk.models.{$provider}"))] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $models[$entry['id']] = array_replace($models[$entry['id']] ?? [], $entry);
            }
        }

        return array_values($models);
    }

    /**
     * Tolk's own models of the provider, which come with each release; a site changes them through `tolk.models`.
     *
     * @return list<array>
     */
    private static function tolkModels(string $provider): array
    {
        self::$tolkModels ??= require dirname(__DIR__, 2).'/resources/models.php';

        return self::$tolkModels[$provider] ?? [];
    }

    /**
     * The catalog as model selects show it, keyed by provider.
     *
     * @return array<string, list<array{value: string, label: string, tier: ?string, configured: bool}>>
     */
    public function grouped(): array
    {
        return collect($this->all())
            ->map(function (array $entries, string $provider) {
                $configured = $this->providers->isConfigured($provider);

                return collect($entries)
                    ->filter(fn (array $entry) => is_string($entry['id'] ?? null))
                    ->map(function (array $entry) use ($provider, $configured) {
                        $model = new ModelRef($provider, $entry['id']);

                        return [
                            'value' => (string) $model,
                            'label' => $this->label($model),
                            'tier' => $entry['tier'] ?? null,
                            'configured' => $configured,
                        ];
                    })
                    ->values()
                    ->all();
            })
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
     * The output tokens to allow a call that should answer in $tokens: those plus the model's reasoning headroom, at most
     * its maximum output.
     */
    public function outputCap(ModelRef $model, int $tokens): int
    {
        $cap = $tokens + $this->reasoningHeadroom($model);
        $max = $this->maxOutputTokens($model);

        return $max === null ? $cap : min($cap, $max);
    }

    /**
     * @return array{input: float, output: float, cache_read?: float, cache_write?: float}|null
     */
    public function price(ModelRef $model): ?array
    {
        $price = $this->find($model)['price'] ?? null;

        if (! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        $prices = ['input' => (float) $price['input'], 'output' => (float) $price['output']];

        foreach (['cache_read', 'cache_write'] as $key) {
            if (is_numeric($price[$key] ?? null)) {
                $prices[$key] = (float) $price[$key];
            }
        }

        return $prices;
    }

    public function label(ModelRef $model): string
    {
        $label = $this->find($model)['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $model->model;
    }

    /**
     * The label of each model reference, by reference; a string that is not a reference is its own label, and anything
     * else is left out.
     *
     * @param  iterable<mixed>  $references
     * @return array<string, string>
     */
    public function labels(iterable $references): array
    {
        $labels = [];

        foreach ($references as $reference) {
            if (! is_string($reference) || isset($labels[$reference])) {
                continue;
            }

            try {
                $labels[$reference] = $this->label(ModelRef::fromString($reference));
            } catch (InvalidModelRef) {
                $labels[$reference] = $reference;
            }
        }

        return $labels;
    }

    /**
     * The label of every catalog model, by reference, so the CP names a model as the model selects do.
     *
     * @return array<string, string>
     */
    public function catalogLabels(): array
    {
        $references = [];

        foreach ($this->all() as $provider => $entries) {
            foreach ($entries as $entry) {
                if (is_string($entry['id'] ?? null)) {
                    $references[] = $provider.':'.$entry['id'];
                }
            }
        }

        return $this->labels($references);
    }

    public function resolveTranslator(Settings $settings, ?ModelRef $override = null): ModelRef
    {
        return $override ?? $settings->translatorModel() ?? $this->automaticTranslator();
    }

    /**
     * The translator when the settings name none: the quality model of the first configured provider.
     */
    public function automaticTranslator(): ModelRef
    {
        // The fake engine needs no key, so a sandbox without keys still gets a translator to plan with.
        $provider = Arr::first($this->providers->configured())
            ?? (FakeEngine::enabled() ? ModelRef::PROVIDERS[0] : throw NoProviderConfigured::make());

        return $this->default($provider, Tier::Quality);
    }

    public function resolveReviewer(Settings $settings, ModelRef $translator, ?ModelRef $override = null): ModelRef
    {
        return $override ?? $settings->reviewerModel() ?? $this->automaticReviewer($translator);
    }

    /**
     * The reviewer when the settings name none: the quality model of another configured provider, else the translator.
     */
    public function automaticReviewer(ModelRef $translator): ModelRef
    {
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
