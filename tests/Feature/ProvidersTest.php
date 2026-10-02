<?php

use Laravel\Ai\Ai;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;

it('lists every provider with a key in a fixed order', function () {
    expect(app(Providers::class)->configured())->toBe(['anthropic', 'openai', 'gemini']);
});

it('lists no provider when there are no keys', function () {
    $this->withProviderKeys();

    $providers = app(Providers::class);

    expect($providers->configured())->toBe([])
        ->and($providers->isConfigured('anthropic'))->toBeFalse()
        ->and(fn () => $providers->buildConfig('anthropic'))
        ->toThrow(ProviderNotConfigured::class, 'The AI provider [anthropic] has no API key. Set ANTHROPIC_API_KEY or TOLK_ANTHROPIC_API_KEY in .env.');
});

it('lists only the providers with a key, from either config', function () {
    $this->withProviderKeys([
        'ai.providers.gemini.key' => 'gemini-sdk-key',
        'tolk.providers.anthropic.key' => 'anthropic-tolk-key',
    ]);

    expect(app(Providers::class)->configured())->toBe(['anthropic', 'gemini']);
});

it('treats empty and blank keys as missing', function (mixed $key) {
    $this->withProviderKeys(['ai.providers.openai.key' => $key, 'tolk.providers.openai.key' => $key]);

    expect(app(Providers::class)->configured())->toBe([]);
})->with([
    'empty string' => [''],
    'blank' => ['   '],
    'false' => [false],
    'array' => [['secret']],
]);

it('prefers the override key to the SDK key', function () {
    config([
        'ai.providers.anthropic.key' => 'sdk-key',
        'tolk.providers.anthropic.key' => 'tolk-key',
    ]);

    expect(app(Providers::class)->buildConfig('anthropic')['key'])->toBe('tolk-key');
});

it('uses the SDK key when the override is empty', function (?string $override) {
    config([
        'ai.providers.anthropic.key' => 'sdk-key',
        'tolk.providers.anthropic.key' => $override,
    ]);

    expect(app(Providers::class)->buildConfig('anthropic')['key'])->toBe('sdk-key');
})->with([null, '', ' ']);

it('builds the config for each provider', function (string $provider, array $expected) {
    config([
        "ai.providers.{$provider}.key" => "{$provider}-key",
        "ai.providers.{$provider}.url" => null,
    ]);

    expect(app(Providers::class)->buildConfig($provider))->toBe($expected);
})->with([
    'anthropic' => ['anthropic', ['name' => 'tolk_anthropic', 'driver' => 'anthropic', 'key' => 'anthropic-key']],
    'openai' => ['openai', ['name' => 'tolk_openai', 'driver' => 'openai', 'key' => 'openai-key', 'store' => false]],
    'gemini' => ['gemini', ['name' => 'tolk_gemini', 'driver' => 'gemini', 'key' => 'gemini-key']],
]);

it('sends store false to OpenAI even when the host stores responses', function () {
    config(['ai.providers.openai.store' => true]);

    expect(app(Providers::class)->buildConfig('openai')['store'])->toBeFalse();
});

it('prefers the override url to the SDK url', function () {
    config([
        'ai.providers.openai.url' => 'https://sdk.example.com/v1',
        'tolk.providers.openai.url' => 'https://tolk.example.com/v1',
    ]);

    expect(app(Providers::class)->buildConfig('openai')['url'])->toBe('https://tolk.example.com/v1');

    config(['tolk.providers.openai.url' => null]);

    expect(app(Providers::class)->buildConfig('openai')['url'])->toBe('https://sdk.example.com/v1');
});

it('treats a provider outside the catalog as not configured', function () {
    config(['ai.providers.mistral' => ['driver' => 'mistral', 'key' => 'mistral-key']]);

    $providers = app(Providers::class);

    expect($providers->isConfigured('mistral'))->toBeFalse()
        ->and(fn () => $providers->buildConfig('mistral'))->toThrow(ProviderNotConfigured::class);
});

it('builds an on-demand SDK provider under the addon name', function (string $provider) {
    config([
        "ai.providers.{$provider}.key" => 'sdk-key',
        "tolk.providers.{$provider}.key" => 'tolk-key',
        "tolk.providers.{$provider}.url" => 'https://tolk.example.com/v1',
    ]);

    $built = Ai::build(app(Providers::class)->buildConfig($provider));

    expect($built->name())->toBe("tolk_{$provider}")
        ->and($built->driver())->toBe($provider)
        ->and($built->providerCredentials())->toBe(['key' => 'tolk-key'])
        ->and($built->additionalConfiguration()['url'])->toBe('https://tolk.example.com/v1');
})->with(ModelRef::PROVIDERS);

it('cannot build when the host configures a provider under the addon name', function () {
    config(['ai.providers.tolk_openai' => ['driver' => 'openai', 'key' => 'host-key']]);

    expect(fn () => Ai::build(app(Providers::class)->buildConfig('openai')))
        ->toThrow(InvalidArgumentException::class, 'The provider name [tolk_openai] is already taken.');
});
