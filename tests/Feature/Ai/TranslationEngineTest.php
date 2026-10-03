<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\EngineResult;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Prompts\PromptBuilder;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Ai\Throttle;
use Reach\StatamicTolk\Ai\TranslationEngine;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderTimeout;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\RequestRejected;
use Reach\StatamicTolk\Support\Language;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

beforeEach(function () {
    $this->useFileCache();
});

function engineCall(string $provider = 'anthropic', array $overrides = []): EngineCall
{
    return new EngineCall(...[
        'model' => app(ModelCatalog::class)->default($provider, Tier::Quality),
        'source' => new Language('en', 'English', 'en_US', 'en', null, 'ltr'),
        'target' => new Language('el', 'Greek', 'el_GR', 'el', null, 'ltr'),
        'context' => new PromptContext(instructions: 'Keep it short.'),
        'document' => ['type' => 'entry', 'title' => 'Home', 'container' => 'Pages', 'url' => '/', 'summary' => 'Welcome to Kyma Bay.'],
        'segments' => [
            ['id' => 's1', 'field' => 'Title', 'kind' => 'plain', 'text' => 'Welcome to Kyma Bay'],
            ['id' => 's2', 'field' => 'Intro', 'kind' => 'plain', 'text' => 'The <1>sea</1> is warm.<x1/>'],
        ],
        'timeout' => 45,
        ...$overrides,
    ]);
}

function engineTranslate(string $provider = 'anthropic', array $overrides = []): EngineResult
{
    return app(Engine::class)->translate(engineCall($provider, $overrides));
}

function engineFailure(string $provider = 'anthropic', array $overrides = []): Throwable
{
    try {
        engineTranslate($provider, $overrides);
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('The engine did not throw.');
}

function engineSegments(int $chars): array
{
    return [['id' => 's1', 'field' => 'Body', 'kind' => 'plain', 'text' => str_repeat('a', $chars)]];
}

function fakeEchoTranslations(): void
{
    TranslationAgent::fake(function (string $prompt) {
        $segments = json_decode($prompt, true)['segments'];

        return ['translations' => array_map(fn (array $segment) => ['id' => $segment['id'], 'text' => '[el] '.$segment['text']], array_reverse($segments))];
    });
}

it('is the engine bound in the container', function () {
    expect(app(Engine::class))->toBeInstanceOf(TranslationEngine::class);
});

it('returns the translations keyed by id', function () {
    fakeEchoTranslations();

    $result = engineTranslate();

    expect($result->items)->toBe([
        's2' => '[el] The <1>sea</1> is warm.<x1/>',
        's1' => '[el] Welcome to Kyma Bay',
    ])
        ->and($result->finish)->toBe(Finish::Stop)
        ->and($result->model)->toEqual(new ModelRef('anthropic', 'claude-sonnet-5-5'))
        ->and($result->usage->calls)->toBe(1);
});

it('sends the system prompt, the user json, the catalog options and the timeout', function () {
    fakeEchoTranslations();
    $call = engineCall('openai');

    app(Engine::class)->translate($call);

    $prompts = app(PromptBuilder::class);

    TranslationAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === $prompts->user($call->document, $call->segments)
        && $prompt->agent->instructions === $prompts->system($call->source, $call->target, $call->context)
        && $prompt->agent->providerOptions === ['reasoning' => ['effort' => 'low']]
        && $prompt->agent->model == $call->model
        && $prompt->timeout === 45);
});

it('drops items without a string id and text, ids that were not asked for and repeated ids', function () {
    TranslationAgent::fake([['translations' => [
        'junk',
        ['id' => 's1', 'text' => 'Καλώς ήρθατε'],
        ['id' => 's1', 'text' => 'Again'],
        ['id' => 's9', 'text' => 'Not asked for'],
        ['id' => 's2'],
        ['id' => 2, 'text' => 'Numeric id'],
        ['id' => 's2', 'text' => ['not a string']],
    ]]]);

    $result = engineTranslate();

    expect($result->items)->toBe(['s1' => 'Καλώς ήρθατε'])
        ->and($result->finish)->toBe(Finish::Stop);
});

it('stops without items when the result has no translations list', function () {
    TranslationAgent::fake([['result' => 'nothing']]);

    $result = engineTranslate();

    expect($result->items)->toBe([])
        ->and($result->finish)->toBe(Finish::Stop);
});

it('sizes the output cap from the source, the multiplier, the headroom and the catalog cap', function (string $model, int $chars, int $multiplier, int $expected) {
    fakeEchoTranslations();
    $call = engineCall(overrides: [
        'model' => ModelRef::fromString($model),
        'segments' => engineSegments($chars),
        'capMultiplier' => $multiplier,
    ]);

    app(Engine::class)->translate($call);

    expect(app(TranslationEngine::class)->outputCap($call))->toBe($expected);
    TranslationAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->maxTokens === $expected);
})->with([
    'floor' => ['anthropic:claude-sonnet-5-5', 100, 1, 2048],
    'floor, doubled' => ['anthropic:claude-sonnet-5-5', 100, 2, 4096],
    'from the source' => ['anthropic:claude-sonnet-5-5', 9000, 1, 9000],
    'from the source, doubled' => ['anthropic:claude-sonnet-5-5', 9000, 2, 18000],
    'with headroom' => ['openai:gpt-6.1-sol', 100, 1, 6048],
    'with headroom, doubled' => ['openai:gpt-6.1-sol', 9000, 2, 22000],
    'catalog cap' => ['gemini:gemini-3.8-flash', 40000, 2, 65536],
    'unknown model: no headroom, no cap' => ['anthropic:claude-custom', 100000, 2, 200000],
]);

it('reads tokens.chars_per_token for the cap', function () {
    config(['tolk.tokens.chars_per_token' => 4]);

    expect(app(TranslationEngine::class)->outputCap(engineCall(overrides: ['segments' => engineSegments(12000)])))->toBe(9000);
});

it('translates through each provider and reports its usage', function (string $provider, array $usage) {
    ProviderFixtures::fake($provider, ProviderFixtures::response($provider));

    $result = engineTranslate($provider);

    expect($result->items)->toBe(array_column(ProviderFixtures::TRANSLATIONS, 'text', 'id'))
        ->and($result->finish)->toBe(Finish::Stop)
        ->and($result->usage->toArray())->toBe($usage)
        ->and($result->model)->toEqual(app(ModelCatalog::class)->default($provider, Tier::Quality));
})->with([
    'anthropic' => ['anthropic', ['input' => 2520, 'output' => 48, 'cache_read' => 1800, 'cache_write' => 600, 'reasoning' => 0, 'calls' => 1]],
    'openai' => ['openai', ['input' => 2520, 'output' => 60, 'cache_read' => 1800, 'cache_write' => 600, 'reasoning' => 12, 'calls' => 1]],
    'gemini' => ['gemini', ['input' => 2520, 'output' => 60, 'cache_read' => 1800, 'cache_write' => 0, 'reasoning' => 12, 'calls' => 1]],
]);

it('takes usage from a faked response', function () {
    TranslationAgent::fake(fn () => new StructuredTextResponse(
        ['translations' => []],
        '{"translations":[]}',
        new TextUsage(900, 120, 300, null, 20),
        new Meta('tolk_anthropic', 'claude-sonnet-5-5-20260901'),
    ));

    $result = engineTranslate();

    expect($result->usage->toArray())->toBe(['input' => 900, 'output' => 120, 'cache_read' => 300, 'cache_write' => 0, 'reasoning' => 20, 'calls' => 1])
        ->and((string) $result->model)->toBe('anthropic:claude-sonnet-5-5');
});

it('reports a truncated response as length without throwing', function (string $provider) {
    ProviderFixtures::fake($provider, ProviderFixtures::truncated($provider));

    $result = engineTranslate($provider);

    expect($result->finish)->toBe(Finish::Length)
        ->and($result->items)->toBe([])
        ->and($result->usage->calls)->toBe(1);
})->with(ProviderFixtures::PROVIDERS);

it('reports undecodable output as empty', function (string $provider) {
    ProviderFixtures::fake($provider, ProviderFixtures::withText($provider, 'Sorry, here is no JSON.'));

    $result = engineTranslate($provider);

    expect($result->finish)->toBe(Finish::Empty)
        ->and($result->items)->toBe([]);
})->with(ProviderFixtures::PROVIDERS);

it('reports a refusal as refused', function (string $provider) {
    ProviderFixtures::fake($provider, ProviderFixtures::refusal($provider));

    $result = engineTranslate($provider);

    expect($result->finish)->toBe(Finish::Refused)
        ->and($result->items)->toBe([]);
})->with(ProviderFixtures::PROVIDERS);

it('pauses the provider for the retry-after of a 429 and throws ProviderPaused with that wait', function (string $provider, string $type) {
    ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 429, $type, 'Rate limit reached for requests.'), 429, ['retry-after' => '17']);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(ProviderPaused::class)
        ->and($exception->provider)->toBe($provider)
        ->and($exception->seconds)->toBe(17)
        ->and(app(Throttle::class)->pausedFor($provider))->toBe(17);
})->with([
    ['anthropic', 'rate_limit_error'],
    ['openai', 'rate_limit_exceeded'],
    ['gemini', 'RESOURCE_EXHAUSTED'],
]);

it('throws ProviderPaused without a request while the provider is paused', function () {
    app(Throttle::class)->pause('anthropic', 30);
    fakeEchoTranslations();

    $exception = engineFailure('anthropic');

    expect($exception)->toBeInstanceOf(ProviderPaused::class)
        ->and($exception->seconds)->toBe(30);
    TranslationAgent::assertNeverPrompted();

    expect(engineTranslate('openai')->finish)->toBe(Finish::Stop);
});

it('rejects a 429 that reports a spend cap or quota and does not pause', function (string $provider, string $type, array $extra) {
    ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 429, $type, 'You have reached your spend limit.', $extra), 429);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(ProviderRejected::class)
        ->and($exception->provider)->toBe($provider)
        ->and($exception->status)->toBe(429)
        ->and($exception->getMessage())->toContain("The AI provider [{$provider}] rejected the request (HTTP 429, ")
        ->and($exception->getMessage())->toContain('You have reached your spend limit.')
        ->and(app(Throttle::class)->pausedFor($provider))->toBe(0);
})->with([
    'anthropic' => ['anthropic', 'rate_limit_error', ['details' => ['error_code' => 'enforced_spend_limit_reached']]],
    'openai' => ['openai', 'organization_spend_limit_exceeded', []],
    'gemini' => ['gemini', 'RESOURCE_EXHAUSTED', ['details' => [['reason' => 'quota_exceeded']]]],
]);

it('pauses and throws ProviderPaused on a server error, an overload and an error inside a 200 response', function (string $provider, int $status, array $headers, int $seconds) {
    ProviderFixtures::fake($provider, ProviderFixtures::error($provider, $status, 'api_error', 'Internal error.'), $status, $headers);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(ProviderPaused::class)
        ->and($exception->seconds)->toBe($seconds)
        ->and(app(Throttle::class)->pausedFor($provider))->toBe($seconds);
})->with([
    'anthropic 500' => ['anthropic', 500, [], 1],
    'openai 500' => ['openai', 500, [], 1],
    'gemini 500' => ['gemini', 500, [], 1],
    'openai 408' => ['openai', 408, [], 1],
    'anthropic 529' => ['anthropic', 529, ['retry-after' => '4'], 4],
    'openai 529' => ['openai', 529, [], 1],
    'gemini 503 with retry-after' => ['gemini', 503, ['retry-after' => '3'], 3],
    'anthropic AiException' => ['anthropic', 200, [], 1],
    'openai AiException' => ['openai', 200, [], 1],
    'gemini AiException' => ['gemini', 200, [], 1],
]);

it('backs off by the attempt when the provider sends no retry-after', function () {
    mt_srand(7);
    ProviderFixtures::fake('openai', ProviderFixtures::error('openai', 500, 'api_error', 'Internal error.'), 500);
    $waits = [];

    foreach (range(1, 20) as $run) {
        cache()->forget('tolk:throttle:openai');
        $waits[] = engineFailure('openai', ['attempt' => 4])->seconds;
    }

    expect(min($waits))->toBeGreaterThanOrEqual(1)
        ->and(max($waits))->toBeLessThanOrEqual(8)
        ->and(max($waits))->toBeGreaterThan(1);
});

it('throws ProviderTimeout on a timeout and does not pause', function (string $provider) {
    Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection('cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received')]);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(ProviderTimeout::class)
        ->and($exception)->toBeInstanceOf(ProviderUnavailable::class)
        ->and($exception->provider)->toBe($provider)
        ->and($exception->getMessage())->toContain('did not answer within 45 seconds')
        ->and($exception->seconds)->toBe(1)
        ->and(app(Throttle::class)->pausedFor($provider))->toBe(0);
})->with(ProviderFixtures::PROVIDERS);

it('throws ProviderUnavailable on a connection failure and does not pause', function (string $provider) {
    Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection('cURL error 7: Failed to connect to the host')]);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(ProviderUnavailable::class)
        ->and($exception)->not->toBeInstanceOf(ProviderTimeout::class)
        ->and($exception->seconds)->toBe(1)
        ->and(app(Throttle::class)->pausedFor($provider))->toBe(0);
})->with(ProviderFixtures::PROVIDERS);

it('throws ProviderRejected on an invalid key, missing permission, missing credits and an unknown model', function (string $provider, int $status, string $message) {
    ProviderFixtures::fake($provider, ProviderFixtures::error($provider, $status, 'error', 'The provider says no.'), $status);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(ProviderRejected::class)
        ->and($exception->status)->toBe($status)
        ->and($exception->getMessage())->toBe($message)
        ->and(app(Throttle::class)->pausedFor($provider))->toBe(0);
})->with([
    'anthropic 401' => ['anthropic', 401, 'The AI provider [anthropic] rejected the request (HTTP 401): The provider says no.'],
    'openai 403' => ['openai', 403, 'The AI provider [openai] rejected the request (HTTP 403): The provider says no.'],
    'gemini 402' => ['gemini', 402, 'The AI provider [gemini] rejected the request (HTTP 402, insufficient_credits): The provider says no.'],
    'anthropic 404' => ['anthropic', 404, 'The AI provider [anthropic] does not offer the model [claude-sonnet-5-5] (HTTP 404): The provider says no.'],
    'openai 404' => ['openai', 404, 'The AI provider [openai] does not offer the model [gpt-6.1-sol] (HTTP 404): The provider says no.'],
    'gemini 404' => ['gemini', 404, 'The AI provider [gemini] does not offer the model [gemini-3.8-flash] (HTTP 404): The provider says no.'],
]);

it('throws ProviderRejected for an anthropic credit message on a 400', function () {
    ProviderFixtures::fake('anthropic', ProviderFixtures::error('anthropic', 400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API.'), 400);

    expect(engineFailure('anthropic'))->toBeInstanceOf(ProviderRejected::class);
});

it('throws ProviderRejected naming the provider when the model has no key, without a request', function () {
    $this->withProviderKeys(['ai.providers.openai.key' => 'test-key']);
    Http::fake();

    $exception = engineFailure('anthropic');

    expect($exception)->toBeInstanceOf(ProviderRejected::class)
        ->and($exception->provider)->toBe('anthropic')
        ->and($exception->status)->toBeNull()
        ->and($exception->getMessage())->toStartWith('The AI provider [anthropic] has no API key.');
    Http::assertNothingSent();
});

it('throws RequestRejected on a 400 and logs the shape of the request without its content', function (string $provider) {
    Log::spy();
    ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 400, 'invalid_request_error', 'Invalid schema.'), 400);

    $exception = engineFailure($provider);

    expect($exception)->toBeInstanceOf(RequestRejected::class)
        ->and($exception->status)->toBe(400)
        ->and($exception->tooLarge())->toBeFalse()
        ->and($exception->getMessage())->toBe("The AI provider [{$provider}] rejected the request (HTTP 400): Invalid schema.");

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['status'] === 400
        && $context['segments'] === 2
        && $context['kinds'] === ['plain' => 2]
        && $context['source_chars'] === 47
        && $context['max_tokens'] === app(TranslationEngine::class)->outputCap(engineCall($provider))
        && ! str_contains(json_encode($context), 'Kyma'));
})->with(ProviderFixtures::PROVIDERS);

it('throws RequestRejected that is too large on a 413', function () {
    ProviderFixtures::fake('openai', ProviderFixtures::error('openai', 413, 'request_too_large', 'Request too large.'), 413);

    $exception = engineFailure('openai');

    expect($exception)->toBeInstanceOf(RequestRejected::class)
        ->and($exception->tooLarge())->toBeTrue();
});

it('sanitises a provider message that contains the configured key', function (string $provider, string $key) {
    config(["tolk.providers.{$provider}.key" => $key]);
    ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 401, 'authentication_error', "Incorrect API key provided: {$key}."), 401);

    $message = engineFailure($provider)->getMessage();

    expect($message)->not->toContain($key)
        ->and($message)->toContain('Incorrect API key provided: [redacted].');
})->with([
    'anthropic' => ['anthropic', 'sk-ant-api03-Zx9Yw8Vu7Ts6Rq5Po4Nm3Lk2Ji1Hg0Fe'],
    'openai' => ['openai', 'sk-proj-Ab12Cd34Ef56Gh78Ij90Kl12Mn34'],
    'gemini, no known pattern' => ['gemini', 'plain-gemini-secret-0451'],
]);

it('lets an exception it does not know pass through', function () {
    TranslationAgent::fake(fn () => throw new DomainException('Something else.'));

    expect(fn () => engineTranslate())->toThrow(DomainException::class, 'Something else.');
});

it('does not review yet', function () {
    expect(fn () => app(Engine::class)->review(engineCall()))->toThrow(LogicException::class);
});
