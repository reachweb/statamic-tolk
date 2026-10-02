<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Reach\StatamicTolk\Tests\Support\Agents\ProbeAgent;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

it('calls a fake closure with the prompt, the attachments, the provider and the model', function (string $provider) {
    $calls = [];

    ProbeAgent::fake(function (string $prompt, Collection $attachments, TextProvider $textProvider, string $model) use (&$calls) {
        $calls[] = [$prompt, $attachments->all(), $textProvider->name(), $model];

        return ['translations' => [['id' => 's1', 'text' => 'Γεια']]];
    });

    ProviderFixtures::agent($provider)->prompt('{"segments":[{"id":"s1","text":"Hello"}]}');

    expect($calls)->toBe([[
        '{"segments":[{"id":"s1","text":"Hello"}]}',
        [],
        "tolk_{$provider}",
        ProviderFixtures::MODELS[$provider]['quality']['id'],
    ]]);
})->with(ProviderFixtures::PROVIDERS);

it('rejects a fake closure typed with AgentPrompt, as the docs show it', function () {
    ProbeAgent::fake(fn (AgentPrompt $prompt) => ['translations' => []]);

    ProviderFixtures::agent('anthropic')->prompt('Hello');
})->throws(TypeError::class, 'must be of type Laravel\\Ai\\Prompts\\AgentPrompt, string given');

it('turns an array returned by a closure into a structured response', function () {
    ProbeAgent::fake(fn (string $prompt) => ['translations' => [['id' => 's1', 'text' => mb_strtoupper($prompt)]]]);

    $response = ProviderFixtures::agent('anthropic')->prompt('καλημέρα');

    expect($response)->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($response->structured)->toBe(['translations' => [['id' => 's1', 'text' => 'ΚΑΛΗΜΈΡΑ']]])
        ->and($response->meta->provider)->toBe('tolk_anthropic')
        ->and($response->meta->model)->toBe('claude-sonnet-5-5')
        ->and($response->usage->toArray())->toBe([
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ])
        ->and($response->raw)->toBeNull();
});

it('returns array responses in sequence', function () {
    ProbeAgent::fake([
        ['translations' => [['id' => 's1', 'text' => 'Ένα']]],
        ['translations' => [['id' => 's1', 'text' => 'Δύο']]],
    ]);

    $agent = ProviderFixtures::agent('openai');

    expect($agent->prompt('one')->structured['translations'][0]['text'])->toBe('Ένα')
        ->and($agent->prompt('two')->structured['translations'][0]['text'])->toBe('Δύο');
});

it('generates data that matches the schema when no response is left', function () {
    ProbeAgent::fake();

    $response = ProviderFixtures::agent('gemini')->prompt('Hello');

    expect($response->structured)->toHaveKey('translations')
        ->and($response->structured['translations'])->toBeArray();
});

it('throws on a stray prompt once stray prompts are prevented', function () {
    ProbeAgent::fake()->preventStrayPrompts();

    ProviderFixtures::agent('anthropic')->prompt('Hello');
})->throws(RuntimeException::class, 'Attempted prompt [Hello] without a fake agent response.');

it('intercepts the call although provider() returns an on-demand provider, without any HTTP request', function (string $provider) {
    Http::fake();

    ProbeAgent::fake([['translations' => []]]);

    ProviderFixtures::agent($provider)->prompt('Hello');

    Http::assertNothingSent();
    ProbeAgent::assertPromptedTimes(1);
})->with(ProviderFixtures::PROVIDERS);

it('needs no API key while faked', function () {
    config(['ai.providers.anthropic.key' => null]);

    ProbeAgent::fake([['translations' => []]]);

    expect(ProviderFixtures::agent('anthropic')->prompt('Hello')->structured)->toBe(['translations' => []]);
});

it('passes an AgentPrompt with the timeout, the model and the provider to assertPrompted', function () {
    ProbeAgent::fake([['translations' => []]]);

    ProviderFixtures::agent('openai', 'fast', ['timeoutSeconds' => 45])->prompt('{"segments":[]}');

    $seen = null;

    ProbeAgent::assertPrompted(function ($prompt) use (&$seen) {
        $seen = $prompt;

        return $prompt->timeout === 45 && $prompt->model === 'gpt-6-luna';
    });

    expect($seen)->toBeInstanceOf(AgentPrompt::class)
        ->and($seen->prompt)->toBe('{"segments":[]}')
        ->and($seen->provider->name())->toBe('tolk_openai')
        ->and($seen->provider->driver())->toBe('openai')
        ->and($seen->agent)->toBeInstanceOf(ProbeAgent::class)
        ->and($seen->contains('segments'))->toBeTrue();
});

it('records prompts per agent class', function () {
    ProbeAgent::fake([['translations' => []]]);

    ProbeAgent::assertNeverPrompted();

    ProviderFixtures::agent('anthropic')->prompt('Hello');

    ProbeAgent::assertPrompted('Hello');
    ProbeAgent::assertNotPrompted('Goodbye');
});
