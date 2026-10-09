<?php

use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Support\Settings;

function modelCatalog(): ModelCatalog
{
    return app(ModelCatalog::class);
}

function modelRef(string $reference): ModelRef
{
    return ModelRef::fromString($reference);
}

it('reads Tolk\'s catalog per provider, in order', function () {
    $tolk = require dirname(__DIR__, 2).'/resources/models.php';

    expect(modelCatalog()->all())->toBe($tolk)
        ->and(modelCatalog()->forProvider('openai'))->toBe($tolk['openai'])
        ->and(modelCatalog()->forProvider('mistral'))->toBe([]);
});

it('merges the entries of the config into Tolk\'s catalog by id', function () {
    config(['tolk.models.openai' => [
        ['id' => 'gpt-6-astra', 'label' => 'GPT-6 Astra', 'tier' => 'quality'],
        ['id' => 'gpt-6.1-sol', 'options' => [], 'price' => ['input' => 1.5, 'output' => 8.0]],
        ['label' => 'No id'],
        'gpt-6-nova',
        ['id' => 'gpt-6-astra', 'max_output_tokens' => 64000],
    ]]);

    expect(array_column(modelCatalog()->forProvider('openai'), 'id'))->toBe(['gpt-6.1-sol', 'gpt-6-luna', 'gpt-6-astra'])
        ->and(modelCatalog()->find(modelRef('openai:gpt-6.1-sol')))->toBe([
            'id' => 'gpt-6.1-sol',
            'label' => 'GPT-6.1 Sol',
            'tier' => 'quality',
            'options' => [],
            'max_output_tokens' => 128000,
            'reasoning_headroom' => 4000,
            'price' => ['input' => 1.5, 'output' => 8.0],
        ])
        ->and(modelCatalog()->find(modelRef('openai:gpt-6-astra')))->toBe([
            'id' => 'gpt-6-astra',
            'label' => 'GPT-6 Astra',
            'tier' => 'quality',
            'max_output_tokens' => 64000,
        ])
        ->and(modelCatalog()->find(modelRef('openai:gpt-6-luna'))['options'])->toBe(['reasoning' => ['effort' => 'none']]);
});

it('ignores providers the addon does not support', function () {
    config(['tolk.models.mistral' => [['id' => 'mistral-large', 'tier' => 'quality']]]);

    expect(array_keys(modelCatalog()->all()))->toBe(['anthropic', 'openai', 'gemini'])
        ->and(modelCatalog()->forProvider('mistral'))->toBe([]);
});

it('describes a catalog model', function () {
    $sol = modelRef('openai:gpt-6.1-sol');

    expect(modelCatalog()->has($sol))->toBeTrue()
        ->and(modelCatalog()->find($sol))->toBe([
            'id' => 'gpt-6.1-sol',
            'label' => 'GPT-6.1 Sol',
            'tier' => 'quality',
            'options' => ['reasoning' => ['effort' => 'low']],
            'max_output_tokens' => 128000,
            'reasoning_headroom' => 4000,
            'price' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.1],
        ])
        ->and(modelCatalog()->options($sol))->toBe(['reasoning' => ['effort' => 'low']])
        ->and(modelCatalog()->maxOutputTokens($sol))->toBe(128000)
        ->and(modelCatalog()->reasoningHeadroom($sol))->toBe(4000)
        ->and(modelCatalog()->price($sol))->toBe(['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.1])
        ->and(modelCatalog()->label($sol))->toBe('GPT-6.1 Sol');
});

it('allows a model that is not in the catalog', function (string $reference) {
    $model = modelRef($reference);

    expect(modelCatalog()->has($model))->toBeFalse()
        ->and(modelCatalog()->find($model))->toBeNull()
        ->and(modelCatalog()->options($model))->toBe([])
        ->and(modelCatalog()->maxOutputTokens($model))->toBeNull()
        ->and(modelCatalog()->reasoningHeadroom($model))->toBe(0)
        ->and(modelCatalog()->price($model))->toBeNull()
        ->and(modelCatalog()->label($model))->toBe($model->model);
})->with([
    'unknown id' => ['anthropic:claude-opus-5-5'],
    'fine-tuned id' => ['openai:ft:gpt-6-luna:reach::abc123'],
    'id of another provider' => ['gemini:gpt-6.1-sol'],
]);

it('reads partial catalog entries leniently', function () {
    config(['tolk.models.anthropic' => [
        ['id' => 'claude-custom', 'tier' => 'quality', 'options' => 'none', 'price' => ['input' => 1.0]],
    ]]);

    $model = modelRef('anthropic:claude-custom');

    expect(modelCatalog()->has($model))->toBeTrue()
        ->and(modelCatalog()->options($model))->toBe([])
        ->and(modelCatalog()->maxOutputTokens($model))->toBeNull()
        ->and(modelCatalog()->reasoningHeadroom($model))->toBe(0)
        ->and(modelCatalog()->price($model))->toBeNull()
        ->and(modelCatalog()->label($model))->toBe('claude-custom');
});

it('groups the catalog by provider for model selects', function () {
    $this->withProviderKeys(['ai.providers.openai.key' => 'sdk-key']);
    config(['tolk.models.gemini' => [['tier' => 'fast'], ['id' => 'gemini-custom']]]);

    expect(modelCatalog()->grouped())->toBe([
        'anthropic' => [
            ['value' => 'anthropic:claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'tier' => 'quality', 'configured' => false],
            ['value' => 'anthropic:claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'tier' => 'fast', 'configured' => false],
        ],
        'openai' => [
            ['value' => 'openai:gpt-6.1-sol', 'label' => 'GPT-6.1 Sol', 'tier' => 'quality', 'configured' => true],
            ['value' => 'openai:gpt-6-luna', 'label' => 'GPT-6 Luna', 'tier' => 'fast', 'configured' => true],
        ],
        'gemini' => [
            ['value' => 'gemini:gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'tier' => 'quality', 'configured' => false],
            ['value' => 'gemini:gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite', 'tier' => 'fast', 'configured' => false],
            ['value' => 'gemini:gemini-custom', 'label' => 'gemini-custom', 'tier' => null, 'configured' => false],
        ],
    ]);
});

it('labels every catalog model by reference, configured or not', function () {
    config(['tolk.models.gemini' => [['tier' => 'fast'], ['id' => 'gemini-custom']]]);

    expect(modelCatalog()->catalogLabels())->toBe([
        'anthropic:claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'anthropic:claude-haiku-5-5' => 'Claude Haiku 5.5',
        'openai:gpt-6.1-sol' => 'GPT-6.1 Sol',
        'openai:gpt-6-luna' => 'GPT-6 Luna',
        'gemini:gemini-3.8-flash' => 'Gemini 3.8 Flash',
        'gemini:gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite',
        'gemini:gemini-custom' => 'gemini-custom',
    ]);
});

it('returns the first model of a tier as the default', function (string $provider, Tier $tier, string $expected) {
    expect(modelCatalog()->default($provider, $tier))->toEqual(modelRef($expected));
})->with([
    ['anthropic', Tier::Quality, 'anthropic:claude-sonnet-5-5'],
    ['anthropic', Tier::Fast, 'anthropic:claude-haiku-5-5'],
    ['openai', Tier::Quality, 'openai:gpt-6.1-sol'],
    ['openai', Tier::Fast, 'openai:gpt-6-luna'],
    ['gemini', Tier::Quality, 'gemini:gemini-3.8-flash'],
    ['gemini', Tier::Fast, 'gemini:gemini-3.5-flash-lite'],
]);

it('keeps Tolk\'s first model of a tier as its default, unless the config moves that model to another tier', function () {
    config(['tolk.models.anthropic' => [['id' => 'claude-opus-5-5', 'tier' => 'quality']]]);

    expect(modelCatalog()->default('anthropic', Tier::Quality))->toEqual(modelRef('anthropic:claude-sonnet-5-5'));

    config(['tolk.models.anthropic' => [
        ['id' => 'claude-opus-5-5', 'tier' => 'quality'],
        ['id' => 'claude-sonnet-5-5', 'tier' => 'fast'],
    ]]);

    expect(modelCatalog()->default('anthropic', Tier::Quality))->toEqual(modelRef('anthropic:claude-opus-5-5'))
        ->and(modelCatalog()->default('anthropic', Tier::Fast))->toEqual(modelRef('anthropic:claude-sonnet-5-5'));
});

it('throws when the catalog has no model of a tier', function () {
    config(['tolk.models.gemini' => [['id' => 'gemini-3.5-flash-lite', 'tier' => 'quality']]]);

    expect(fn () => modelCatalog()->default('gemini', Tier::Fast))
        ->toThrow(MissingDefaultModel::class, 'The model catalog (tolk.models) has no fast model for [gemini].');
});

it('resolves the translator from the override, then the setting, then the first configured provider', function () {
    $settings = $this->withSettings(['translator_model' => 'gemini:gemini-3.5-flash-lite']);

    expect(modelCatalog()->resolveTranslator($settings, modelRef('openai:gpt-6-luna')))->toEqual(modelRef('openai:gpt-6-luna'))
        ->and(modelCatalog()->resolveTranslator($settings))->toEqual(modelRef('gemini:gemini-3.5-flash-lite'))
        ->and(modelCatalog()->resolveTranslator($this->withSettings()))->toEqual(modelRef('anthropic:claude-sonnet-5-5'));

    $this->withProviderKeys(['tolk.providers.openai.key' => 'tolk-key']);

    expect(modelCatalog()->resolveTranslator($this->withSettings()))->toEqual(modelRef('openai:gpt-6.1-sol'));
});

it('names the automatic translator and reviewer whatever the settings name', function () {
    $this->withSettings(['translator_model' => 'gemini:gemini-3.5-flash-lite', 'reviewer_model' => 'gemini:gemini-3.8-flash']);

    expect(modelCatalog()->automaticTranslator())->toEqual(modelRef('anthropic:claude-sonnet-5-5'))
        ->and(modelCatalog()->automaticReviewer(modelRef('anthropic:claude-sonnet-5-5')))->toEqual(modelRef('openai:gpt-6.1-sol'));
});

it('throws when no provider is configured and nothing names a translator', function () {
    $this->withProviderKeys([]);

    expect(fn () => modelCatalog()->resolveTranslator($this->withSettings()))
        ->toThrow(NoProviderConfigured::class, 'No AI provider is configured.');
});

it('resolves the quality model of the first provider under the fake engine when no provider is configured', function () {
    $this->withProviderKeys([]);
    config(['tolk.fake_engine' => true]);

    expect(modelCatalog()->resolveTranslator($this->withSettings()))->toEqual(modelRef('anthropic:claude-sonnet-5-5'));

    $this->withProviderKeys(['ai.providers.gemini.key' => 'test-key']);

    expect(modelCatalog()->resolveTranslator($this->withSettings()))->toEqual(modelRef('gemini:gemini-3.8-flash'));
});

it('lets an unparseable model setting propagate', function (string $key, Closure $resolve) {
    $settings = $this->withSettings([$key => 'mistral:mistral-large']);

    expect(fn () => $resolve(modelCatalog(), $settings))->toThrow(InvalidModelRef::class, 'mistral:mistral-large');
})->with([
    'translator' => ['translator_model', fn (ModelCatalog $catalog, Settings $settings) => $catalog->resolveTranslator($settings)],
    'reviewer' => ['reviewer_model', fn (ModelCatalog $catalog, Settings $settings) => $catalog->resolveReviewer($settings, modelRef('anthropic:claude-sonnet-5-5'))],
    'fallback' => ['fallback_model', fn (ModelCatalog $catalog, Settings $settings) => $catalog->resolveFallback($settings, modelRef('anthropic:claude-sonnet-5-5'))],
]);

it('resolves the reviewer from the override, then the setting', function () {
    $settings = $this->withSettings(['reviewer_model' => 'gemini:gemini-3.8-flash']);
    $translator = modelRef('anthropic:claude-sonnet-5-5');

    expect(modelCatalog()->resolveReviewer($settings, $translator, modelRef('anthropic:claude-haiku-5-5')))->toEqual(modelRef('anthropic:claude-haiku-5-5'))
        ->and(modelCatalog()->resolveReviewer($settings, $translator))->toEqual(modelRef('gemini:gemini-3.8-flash'));
});

it('picks the quality model of another configured provider as the reviewer', function (string $translator, string $expected) {
    expect(modelCatalog()->resolveReviewer($this->withSettings(), modelRef($translator)))->toEqual(modelRef($expected));
})->with([
    ['anthropic:claude-sonnet-5-5', 'openai:gpt-6.1-sol'],
    ['openai:gpt-6-luna', 'anthropic:claude-sonnet-5-5'],
    ['gemini:gemini-3.8-flash', 'anthropic:claude-sonnet-5-5'],
]);

it('skips unconfigured providers when it picks the reviewer', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'sdk-key', 'tolk.providers.gemini.key' => 'tolk-key']);

    expect(modelCatalog()->resolveReviewer($this->withSettings(), modelRef('anthropic:claude-sonnet-5-5')))
        ->toEqual(modelRef('gemini:gemini-3.8-flash'));
});

it('falls back to the translator as the reviewer when only one provider is configured', function () {
    $this->withProviderKeys(['ai.providers.openai.key' => 'sdk-key']);

    $settings = $this->withSettings();
    $translator = modelCatalog()->resolveTranslator($settings);

    expect($translator)->toEqual(modelRef('openai:gpt-6.1-sol'))
        ->and(modelCatalog()->resolveReviewer($settings, $translator))->toBe($translator);
});

it('resolves the fallback model from the setting', function () {
    $translator = modelRef('anthropic:claude-sonnet-5-5');

    expect(modelCatalog()->resolveFallback($this->withSettings(), $translator))->toBeNull()
        ->and(modelCatalog()->resolveFallback($this->withSettings(['fallback_model' => 'openai:gpt-6.1-sol']), $translator))
        ->toEqual(modelRef('openai:gpt-6.1-sol'))
        ->and(modelCatalog()->resolveFallback($this->withSettings(['fallback_model' => 'anthropic:claude-haiku-5-5']), $translator))
        ->toEqual(modelRef('anthropic:claude-haiku-5-5'));
});

it('resolves no fallback when it is the translator', function () {
    $settings = $this->withSettings(['fallback_model' => 'anthropic:claude-sonnet-5-5']);

    expect(modelCatalog()->resolveFallback($settings, modelRef('anthropic:claude-sonnet-5-5')))->toBeNull();
});

it('resolves no fallback on an unconfigured provider', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'sdk-key']);

    $settings = $this->withSettings(['fallback_model' => 'gemini:gemini-3.8-flash']);

    expect(modelCatalog()->resolveFallback($settings, modelRef('anthropic:claude-sonnet-5-5')))->toBeNull();

    config(['tolk.providers.gemini.key' => 'tolk-key']);

    expect(modelCatalog()->resolveFallback($settings, modelRef('anthropic:claude-sonnet-5-5')))->toEqual(modelRef('gemini:gemini-3.8-flash'));
});
