<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Reach\StatamicTolk\Ai\Agents\ConnectionTestAgent;
use Reach\StatamicTolk\Ai\Agents\ReviewAgent;
use Reach\StatamicTolk\Ai\Agents\SiteProfileAgent;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

function catalogTranslationAgent(string $provider, string $tier = 'quality', array $overrides = []): TranslationAgent
{
    $catalog = app(ModelCatalog::class);
    $model = $catalog->default($provider, Tier::from($tier));

    return new TranslationAgent(...[
        'model' => $model,
        'instructions' => 'Translate every segment into Greek.',
        'providerOptions' => $catalog->options($model),
        ...$overrides,
    ]);
}

function promptTranslationAgent(string $provider, string $tier = 'quality', array $overrides = []): Request
{
    ProviderFixtures::fake($provider, ProviderFixtures::response($provider));

    catalogTranslationAgent($provider, $tier, $overrides)->prompt('{"segments":[]}');

    return ProviderFixtures::sentRequest();
}

it('sends its schema, the model id, the catalog options and the output cap, and no sampling parameters', function (string $provider, string $formatPath, ?string $optionsPath, string $capPath, string $tier) {
    $model = ProviderFixtures::model($provider, $tier);
    $body = promptTranslationAgent($provider, $tier, ['maxTokens' => 8192])->data();
    $schema = data_get($body, $formatPath);

    expect($body['model'])->toBe($model['id'])
        ->and($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['translations'])
        ->and($schema['properties']['translations']['type'])->toBe('array')
        ->and($schema['properties']['translations']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['translations']['items']['required'])->toBe(['id', 'text'])
        ->and($schema['properties']['translations']['items']['properties'])->toBe([
            'id' => ['type' => 'string'],
            'text' => ['type' => 'string'],
        ])
        ->and(Arr::only(data_get($body, $optionsPath, []), array_keys($model['options'])))->toBe($model['options'])
        ->and(data_get($body, $capPath))->toBe(8192);

    foreach (['temperature', 'top_p', 'top_k'] as $key) {
        expect($body)->not->toHaveKey($key)
            ->and($body['generation_config'] ?? [])->not->toHaveKey($key);
    }
})->with([
    'anthropic' => ['anthropic', 'output_config.format.schema', null, 'max_tokens'],
    'openai' => ['openai', 'text.format.schema', null, 'max_output_tokens'],
    'gemini' => ['gemini', 'response_format.schema', 'generation_config', 'generation_config.max_output_tokens'],
])->with(['quality', 'fast']);

it('sends a strict json schema to OpenAI', function () {
    $body = promptTranslationAgent('openai')->data();

    expect($body['text']['format']['type'])->toBe('json_schema')
        ->and($body['text']['format']['strict'])->toBeTrue();
});

it('marks the instructions for caching on Anthropic', function () {
    $body = promptTranslationAgent('anthropic')->data();

    expect($body['system'])->toBe([[
        'type' => 'text',
        'text' => 'Translate every segment into Greek.',
        'cache_control' => ['type' => 'ephemeral'],
    ]]);
});

it('switches off OpenAI\'s implicit prompt caching from GPT-5.6 on, which writes every request to the cache and never reads it back', function (string $model, ?array $expected) {
    $model = ModelRef::fromString($model);
    ProviderFixtures::fake($model->provider, ProviderFixtures::response($model->provider));

    (new TranslationAgent($model, 'Translate every segment into Greek.', app(ModelCatalog::class)->options($model)))->prompt('{"segments":[]}');

    expect(ProviderFixtures::sentRequest()->data()['prompt_cache_options'] ?? null)->toBe($expected);
})->with([
    'GPT-6.1 Sol' => ['openai:gpt-6.1-sol', ['mode' => 'explicit']],
    'GPT-6 Luna' => ['openai:gpt-6-luna', ['mode' => 'explicit']],
    'GPT-5.6 Terra' => ['openai:gpt-5.6-terra', ['mode' => 'explicit']],
    'GPT-5.10' => ['openai:gpt-5.10', ['mode' => 'explicit']],
    'GPT-5 mini' => ['openai:gpt-5-mini', null],
    'GPT-5.5' => ['openai:gpt-5.5', null],
    'GPT-4.1' => ['openai:gpt-4.1', null],
    'an id without a version' => ['openai:o4-mini', null],
    'Claude' => ['anthropic:claude-sonnet-5-5', null],
    'Gemini' => ['gemini:gemini-3.8-flash', null],
]);

it('switches off OpenAI\'s implicit prompt caching for every agent', function (string $agent) {
    ProviderFixtures::fake('openai', ProviderFixtures::response('openai'));
    $model = ModelRef::fromString('openai:gpt-6.1-sol');

    (new $agent($model, 'Instructions.', app(ModelCatalog::class)->options($model)))->prompt('{}');

    $body = ProviderFixtures::sentRequest()->data();

    expect($body['prompt_cache_options'])->toBe(['mode' => 'explicit'])
        ->and($body['reasoning'])->toBe(['effort' => 'low'])
        ->and($body['input'][0])->toBe(['role' => 'system', 'content' => 'Instructions.']);
})->with([TranslationAgent::class, ReviewAgent::class, SiteProfileAgent::class, ConnectionTestAgent::class]);

it('lets a catalog entry\'s options choose OpenAI\'s cache mode', function () {
    config(['tolk.models.openai' => [['id' => 'gpt-6.1-sol', 'options' => ['reasoning' => ['effort' => 'low'], 'prompt_cache_options' => ['mode' => 'implicit']]]]]);

    $body = promptTranslationAgent('openai')->data();

    expect($body['prompt_cache_options'])->toBe(['mode' => 'implicit'])
        ->and($body['reasoning'])->toBe(['effort' => 'low']);
});

it('sends the instructions as the system prompt on OpenAI and Gemini', function (string $provider, string $path) {
    expect(data_get(promptTranslationAgent($provider)->data(), $path))->toBe('Translate every segment into Greek.');
})->with([
    ['openai', 'input.0.content'],
    ['gemini', 'system_instruction'],
]);

it('builds the provider on demand with the addon key and store false for OpenAI', function (string $provider, string $header, string $value) {
    config(["tolk.providers.{$provider}.key" => 'tolk-key']);

    $request = promptTranslationAgent($provider);

    expect($request->url())->toBe(ProviderFixtures::ENDPOINTS[$provider])
        ->and($request->hasHeader($header, $value))->toBeTrue()
        ->and($request->data()['store'] ?? null)->toBe($provider === 'anthropic' ? null : false);
})->with([
    ['anthropic', 'x-api-key', 'tolk-key'],
    ['openai', 'Authorization', 'Bearer tolk-key'],
    ['gemini', 'x-goog-api-key', 'tolk-key'],
]);

it('decodes the structured translations', function (string $provider) {
    ProviderFixtures::fake($provider, ProviderFixtures::response($provider));

    $response = catalogTranslationAgent($provider)->prompt('{"segments":[]}');

    expect($response->structured)->toBe(['translations' => ProviderFixtures::TRANSLATIONS]);
})->with(ProviderFixtures::PROVIDERS);

it('throws ProviderNotConfigured without sending a request when the provider has no key', function () {
    $this->withProviderKeys(['ai.providers.openai.key' => 'test-key']);
    Http::fake();

    expect(fn () => catalogTranslationAgent('anthropic')->prompt('{"segments":[]}'))
        ->toThrow(ProviderNotConfigured::class, 'The AI provider [anthropic] has no API key.');

    Http::assertNothingSent();
});

it('throws ProviderNotConfigured while faked, because the SDK resolves the provider before the fake', function () {
    $this->withProviderKeys();
    TranslationAgent::fake([['translations' => []]]);

    expect(fn () => catalogTranslationAgent('gemini')->prompt('{"segments":[]}'))
        ->toThrow(ProviderNotConfigured::class);

    TranslationAgent::assertNeverPrompted();
});

it('holds no API key in its properties', function () {
    config(['tolk.providers.gemini.key' => 'secret-gemini-key']);

    $agent = catalogTranslationAgent('gemini');

    ProviderFixtures::fake('gemini', ProviderFixtures::response('gemini'));
    $agent->prompt('{"segments":[]}');

    expect(ProviderFixtures::sentRequest()->hasHeader('x-goog-api-key', 'secret-gemini-key'))->toBeTrue()
        ->and(print_r($agent, true))->not->toContain('secret-gemini-key');
});

it('passes its timeout to the prompt', function () {
    TranslationAgent::fake([['translations' => []]]);

    catalogTranslationAgent('openai', 'fast', ['timeout' => 45])->prompt('{"segments":[]}');

    TranslationAgent::assertPrompted(fn (AgentPrompt $p) => $p->timeout === 45 && $p->model === 'gpt-6-luna');
});

it('passes its timeout to the HTTP client and defaults to 180 seconds', function (?int $timeout, int $expected) {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(ProviderFixtures::response('anthropic'));
    });

    catalogTranslationAgent('anthropic', overrides: array_filter(['timeout' => $timeout]))->prompt('{"segments":[]}');

    expect($options['timeout'])->toBe($expected)
        ->and(Arr::dot(ProviderFixtures::sentRequest()->data()))->not->toHaveKey('timeout');
})->with([
    'given' => [45, 45],
    'default' => [null, 180],
]);
