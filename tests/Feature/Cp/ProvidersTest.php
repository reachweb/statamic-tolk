<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Ai\ConnectionTester;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

const PROVIDER_TEST_KEY = 'sk-ant-api03-Tolk-Test-Key-0123456789abcdef';

beforeEach(function () {
    $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings']);
});

function testProvider(string $provider): TestResponse
{
    return test()->postJson(cp_route('tolk.providers.test'), ['provider' => $provider]);
}

function fakeReply(string $provider): void
{
    ProviderFixtures::fake($provider, ProviderFixtures::withText($provider, '{"reply":"ok"}'));
}

it('makes one small structured call with the provider\'s fast model', function (string $provider, string $model, string $label, string $maxTokens) {
    fakeReply($provider);

    $response = testProvider($provider)->assertOk();
    $body = ProviderFixtures::sentRequest()->data();

    expect($response->json())->toMatchArray(['ok' => true, 'model' => "{$provider}:{$model}", 'label' => $label, 'fake' => false])
        ->and($response->json('latency_ms'))->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($body['model'])->toBe($model)
        ->and(data_get($body, $maxTokens))->toBeLessThanOrEqual(64)
        ->and($body)->not->toHaveKey('temperature');
})->with([
    'anthropic' => ['anthropic', 'claude-haiku-5-5', 'Claude Haiku 5.5', 'max_tokens'],
    'openai' => ['openai', 'gpt-6-luna', 'GPT-6 Luna', 'max_output_tokens'],
    'gemini' => ['gemini', 'gemini-3.5-flash-lite', 'Gemini 3.5 Flash-Lite', 'generation_config.max_output_tokens'],
]);

it('gives the call 20 seconds', function () {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(ProviderFixtures::withText('anthropic', '{"reply":"ok"}'));
    });

    testProvider('anthropic')->assertOk()->assertJsonPath('ok', true);

    expect($options['timeout'])->toBe(ConnectionTester::TIMEOUT)->toBe(20);
});

it('records the call in the usage log as the editor\'s', function () {
    fakeReply('openai');

    testProvider('openai')->assertOk();

    $month = app(UsageLog::class)->month();

    expect(array_keys($month))->toBe(['openai:gpt-6-luna'])
        ->and($month['openai:gpt-6-luna']['calls'])->toBe(1)
        ->and(array_keys($month['openai:gpt-6-luna']['sources']))->toBe(['editor']);
});

it('tests the quality model of a provider whose catalog has no fast model', function () {
    config(['tolk.models.anthropic' => [['id' => 'claude-haiku-5-5', 'tier' => 'quality']]]);
    fakeReply('anthropic');

    testProvider('anthropic')->assertOk()->assertJsonPath('model', 'anthropic:claude-sonnet-5-5');
});

it('answers a rejected key with ok false and a readable message', function () {
    ProviderFixtures::fake('anthropic', ProviderFixtures::error('anthropic', 401, 'authentication_error', 'invalid x-api-key'), 401);

    testProvider('anthropic')
        ->assertOk()
        ->assertExactJson([
            'ok' => false,
            'model' => 'anthropic:claude-haiku-5-5',
            'label' => 'Claude Haiku 5.5',
            'error' => 'Anthropic refused the API key (HTTP 401): invalid x-api-key',
        ]);

    expect(app(UsageLog::class)->month())->toBe([]);
});

it('describes the other failures', function (string $provider, Closure $fake, string $error) {
    $fake($provider);

    testProvider($provider)->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error', $error);
})->with([
    'unknown model' => ['openai', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 404, 'model_not_found', 'The model does not exist.'), 404),
        'OpenAI does not offer the model gpt-6-luna (HTTP 404): The model does not exist.'],
    'no credit' => ['openai', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 429, 'insufficient_quota', 'You exceeded your current quota.'), 429),
        'OpenAI refused the account (HTTP 429, insufficient_quota): You exceeded your current quota.'],
    'rate limit' => ['anthropic', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 429, 'rate_limit_error', 'Slow down.'), 429),
        'Anthropic is limiting requests (HTTP 429): Slow down.'],
    'overloaded' => ['gemini', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 503, 'UNAVAILABLE', 'The model is overloaded.'), 503),
        'Google Gemini is busy or unavailable (HTTP 503): The model is overloaded.'],
    'bad request' => ['gemini', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 400, 'INVALID_ARGUMENT', 'Bad schema.'), 400),
        'Google Gemini refused the request (HTTP 400): Bad schema.'],
    'timeout' => ['anthropic', fn (string $provider) => Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received')]),
        'Anthropic did not answer within 20 seconds.'],
    'no connection' => ['anthropic', fn (string $provider) => Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection('cURL error 6: Could not resolve host: api.anthropic.com')]),
        'Anthropic could not be reached: cURL error 6: Could not resolve host: api.anthropic.com'],
]);

it('refuses a provider without a key with 422', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
    Http::fake();

    testProvider('gemini')
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_configured')
        ->assertJsonPath('message', 'The AI provider [gemini] has no API key. Set GEMINI_API_KEY or TOLK_GEMINI_API_KEY in .env.');

    Http::assertNothingSent();
});

it('refuses a provider the catalog has no model of either tier for with 422', function () {
    config(['tolk.models.gemini' => [['id' => 'gemini-3.8-flash', 'tier' => null], ['id' => 'gemini-3.5-flash-lite', 'tier' => null]]]);
    Http::fake();

    testProvider('gemini')->assertStatus(422)->assertJsonPath('code', 'no_model');

    Http::assertNothingSent();
});

it('refuses an unknown provider', function (mixed $provider) {
    $this->postJson(cp_route('tolk.providers.test'), ['provider' => $provider])->assertJsonValidationErrors('provider');
})->with(['mistral', '', null, [['anthropic']]]);

it('never returns the configured key', function (string $source) {
    $this->withProviderKeys([$source => PROVIDER_TEST_KEY]);

    Http::fake([ProviderFixtures::HOSTS['anthropic'] => Http::sequence()
        ->push(ProviderFixtures::withText('anthropic', '{"reply":"ok"}'))
        ->push(ProviderFixtures::error('anthropic', 401, 'authentication_error', 'invalid x-api-key: '.PROVIDER_TEST_KEY), 401)
        ->pushFailedConnection('Could not connect with key '.PROVIDER_TEST_KEY),
    ]);

    $responses = [
        testProvider('anthropic')->assertOk()->assertJsonPath('ok', true),
        $failure = testProvider('anthropic')->assertOk()->assertJsonPath('ok', false),
        testProvider('anthropic')->assertOk()->assertJsonPath('ok', false),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain(PROVIDER_TEST_KEY)->not->toContain(substr(PROVIDER_TEST_KEY, 0, 16));
    }

    expect($failure->json('error'))->toBe('Anthropic refused the API key (HTTP 401): invalid x-api-key: [redacted]');
})->with([
    'config/ai.php' => ['ai.providers.anthropic.key'],
    'addon override' => ['tolk.providers.anthropic.key'],
]);

it('answers with the fake engine without calling the provider, also for a provider without a key', function () {
    $this->withProviderKeys();
    config(['tolk.fake_engine' => true, 'tolk.fake_delay_ms' => 0]);
    Http::fake();

    testProvider('gemini')
        ->assertOk()
        ->assertJson(['ok' => true, 'model' => 'gemini:gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash-Lite', 'fake' => true]);

    Http::assertNothingSent();
    expect(app(UsageLog::class)->month()['gemini:gemini-3.5-flash-lite']['calls'])->toBe(1);
});

it('needs the addon\'s settings permission', function (array $permissions, int $status) {
    fakeReply('anthropic');
    $this->signInWithPermissions(['access cp', ...$permissions]);

    testProvider('anthropic')->assertStatus($status);
})->with([
    'settings permission' => [['edit reachweb/statamic-tolk settings'], 200],
    'configure addons' => [['configure addons'], 200],
    'translate content' => [['translate content', 'run bulk translations'], 403],
]);
