<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Reach\StatamicTolk\Ai\Agents\ReviewAgent;
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
use Reach\StatamicTolk\Exceptions\RequestRejected;
use Reach\StatamicTolk\Support\Language;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

beforeEach(function () {
    $this->useFileCache();
});

function reviewCall(string $provider = 'anthropic', array $overrides = []): EngineCall
{
    return new EngineCall(...[
        'model' => app(ModelCatalog::class)->default($provider, Tier::Quality),
        'source' => new Language('en', 'English', 'en_US', 'en', null, 'ltr'),
        'target' => new Language('el', 'Greek', 'el_GR', 'el', null, 'ltr'),
        'context' => new PromptContext(instructions: 'Keep it short.'),
        'document' => ['type' => 'entry', 'title' => 'Home', 'container' => 'Pages', 'url' => '/', 'summary' => 'Welcome to Kyma Bay.'],
        'segments' => [
            ['id' => 's1', 'field' => 'Title', 'kind' => 'plain', 'source' => 'Welcome to Kyma Bay', 'translation' => 'Καλώς ήρθατε στο Kyma Bay'],
            ['id' => 's2', 'field' => 'Intro', 'kind' => 'inline', 'source' => 'The <1>sea</1> is warm.<x1/>', 'translation' => 'Η θάλασσα <1>είναι</1> ζεστή.<x1/>'],
        ],
        'timeout' => 45,
        ...$overrides,
    ]);
}

function engineReview(string $provider = 'anthropic', array $overrides = []): EngineResult
{
    return app(Engine::class)->review(reviewCall($provider, $overrides));
}

function reviewFailure(string $provider = 'anthropic', array $overrides = []): Throwable
{
    try {
        engineReview($provider, $overrides);
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('The engine did not throw.');
}

function reviewSegments(int $sourceChars, int $translationChars): array
{
    return [[
        'id' => 's1',
        'field' => 'Body',
        'kind' => 'plain',
        'source' => str_repeat('a', $sourceChars),
        'translation' => str_repeat('α', $translationChars),
    ]];
}

it('returns the corrections keyed by id with their reasons', function () {
    $this->fakeReview([
        's2' => ['text' => 'Η <1>θάλασσα</1> είναι ζεστή.<x1/>', 'reason' => 'The formatting wraps the wrong word.'],
        's1' => 'Καλώς ορίσατε στο Kyma Bay',
        's9' => 'Not in the call',
    ]);

    $result = engineReview();

    expect($result->items)->toBe([
        's1' => ['text' => 'Καλώς ορίσατε στο Kyma Bay', 'reason' => $this::FAKE_REVIEW_REASON],
        's2' => ['text' => 'Η <1>θάλασσα</1> είναι ζεστή.<x1/>', 'reason' => 'The formatting wraps the wrong word.'],
    ])
        ->and($result->finish)->toBe(Finish::Stop)
        ->and($result->model)->toEqual(new ModelRef('anthropic', 'claude-sonnet-5-5'))
        ->and($result->usage->calls)->toBe(1);

    TranslationAgent::assertNeverPrompted();
});

it('gives a review closure the source, the translation and the segment', function () {
    $seen = [];

    $this->fakeReview(function (string $source, string $translation, array $segment) use (&$seen) {
        $seen[] = [$source, $translation, $segment['kind']];

        return $segment['id'] === 's2' ? ['text' => 'Η <1>θάλασσα</1> είναι ζεστή.<x1/>', 'reason' => 'Formatting.'] : null;
    });

    expect(engineReview()->items)->toBe(['s2' => ['text' => 'Η <1>θάλασσα</1> είναι ζεστή.<x1/>', 'reason' => 'Formatting.']])
        ->and($seen)->toBe([
            ['Welcome to Kyma Bay', 'Καλώς ήρθατε στο Kyma Bay', 'plain'],
            ['The <1>sea</1> is warm.<x1/>', 'Η θάλασσα <1>είναι</1> ζεστή.<x1/>', 'inline'],
        ]);
});

it('accepts an empty list of corrections', function () {
    $this->fakeReview();

    $result = engineReview();

    expect($result->items)->toBe([])
        ->and($result->finish)->toBe(Finish::Stop);
});

it('reads corrections from each provider', function (string $provider) {
    ProviderFixtures::fake($provider, ProviderFixtures::withText($provider, json_encode(['corrections' => [
        ['id' => 's1', 'text' => 'Καλώς ορίσατε στο Kyma Bay', 'reason' => 'More natural greeting.'],
    ]], JSON_UNESCAPED_UNICODE)));

    $result = engineReview($provider);

    expect($result->items)->toBe(['s1' => ['text' => 'Καλώς ορίσατε στο Kyma Bay', 'reason' => 'More natural greeting.']])
        ->and($result->finish)->toBe(Finish::Stop)
        ->and($result->usage->calls)->toBe(1);
})->with(ProviderFixtures::PROVIDERS);

it('reads an empty list of corrections from each provider as a valid answer', function (string $provider) {
    ProviderFixtures::fake($provider, ProviderFixtures::withText($provider, '{"corrections":[]}'));

    $result = engineReview($provider);

    expect($result->items)->toBe([])
        ->and($result->finish)->toBe(Finish::Stop);
})->with(ProviderFixtures::PROVIDERS);

it('sends the review prompt, the user json, the reviewer and its options', function () {
    $this->fakeReview();
    $call = reviewCall('gemini');

    app(Engine::class)->review($call);

    $prompts = app(PromptBuilder::class);

    ReviewAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === $prompts->user($call->document, $call->segments)
        && $prompt->agent->instructions === $prompts->reviewSystem($call->source, $call->target, $call->context)
        && $prompt->agent->providerOptions === ['thinking_level' => 'low']
        && $prompt->agent->model == new ModelRef('gemini', 'gemini-3.8-flash')
        && $prompt->agent->maxTokens === app(TranslationEngine::class)->reviewCap($call)
        && $prompt->timeout === 45);

    expect(json_decode($prompts->user($call->document, $call->segments), true)['segments'][1])->toBe([
        'id' => 's2',
        'field' => 'Intro',
        'kind' => 'inline',
        'source' => 'The <1>sea</1> is warm.<x1/>',
        'translation' => 'Η θάλασσα <1>είναι</1> ζεστή.<x1/>',
    ]);
});

it('sends the corrections schema to the reviewer\'s model', function (string $provider, string $model, string $path) {
    ProviderFixtures::fake($provider, ProviderFixtures::withText($provider, '{"corrections":[]}'));

    engineReview($provider, ['model' => ModelRef::fromString("{$provider}:{$model}")]);

    $body = ProviderFixtures::sentRequest()->data();
    $schema = data_get($body, $path);

    expect($body['model'])->toBe($model)
        ->and($schema['required'])->toBe(['corrections'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['corrections']['items']['required'])->toBe(['id', 'text', 'reason'])
        ->and($schema['properties']['corrections']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['corrections']['items']['properties'])->toBe([
            'id' => ['type' => 'string'],
            'text' => ['type' => 'string'],
            'reason' => ['type' => 'string'],
        ]);
})->with([
    'anthropic' => ['anthropic', 'claude-haiku-5-5', 'output_config.format.schema'],
    'openai' => ['openai', 'gpt-6-luna', 'text.format.schema'],
    'gemini' => ['gemini', 'gemini-3.5-flash-lite', 'response_format.schema'],
]);

it('drops corrections without a string id, text and reason, ids that were not asked for and repeated ids', function () {
    ReviewAgent::fake([['corrections' => [
        'junk',
        ['id' => 's1', 'text' => 'Καλώς ορίσατε'],
        ['id' => 's1', 'text' => 'Καλώς ορίσατε', 'reason' => ['not a string']],
        ['id' => 's1', 'text' => 'Καλώς ορίσατε', 'reason' => 'Greeting.'],
        ['id' => 's1', 'text' => 'Again', 'reason' => 'Again.'],
        ['id' => 's9', 'text' => 'Not asked for', 'reason' => 'No.'],
        ['id' => 2, 'text' => 'Numeric id', 'reason' => 'No.'],
        ['id' => 's2', 'text' => null, 'reason' => 'No text.'],
    ]]]);

    $result = engineReview();

    expect($result->items)->toBe(['s1' => ['text' => 'Καλώς ορίσατε', 'reason' => 'Greeting.']])
        ->and($result->finish)->toBe(Finish::Stop);
});

it('stops without corrections when the result has no corrections list', function () {
    ReviewAgent::fake([['translations' => [['id' => 's1', 'text' => 'Καλώς ορίσατε']]]]);

    expect(engineReview()->items)->toBe([]);
});

it('sizes the output cap from the translations, the multiplier, the headroom and the catalog cap', function (string $model, int $sourceChars, int $translationChars, int $multiplier, int $expected) {
    $this->fakeReview();
    $call = reviewCall(overrides: [
        'model' => ModelRef::fromString($model),
        'segments' => reviewSegments($sourceChars, $translationChars),
        'capMultiplier' => $multiplier,
    ]);

    app(Engine::class)->review($call);

    expect(app(TranslationEngine::class)->reviewCap($call))->toBe($expected);
    ReviewAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->maxTokens === $expected);
})->with([
    'floor' => ['anthropic:claude-sonnet-5-5', 9000, 100, 1, 2048],
    'from the translations' => ['anthropic:claude-sonnet-5-5', 100, 9000, 1, 9000],
    'from the translations, doubled' => ['anthropic:claude-sonnet-5-5', 100, 9000, 2, 18000],
    'with headroom' => ['openai:gpt-6.1-sol', 100, 9000, 1, 13000],
    'catalog cap' => ['gemini:gemini-3.8-flash', 100, 40000, 2, 65536],
]);

it('reports a truncated, undecodable or refused review without throwing', function (string $provider, Closure $body, Finish $finish) {
    ProviderFixtures::fake($provider, $body($provider));

    $result = engineReview($provider);

    expect($result->finish)->toBe($finish)
        ->and($result->items)->toBe([]);
})->with(ProviderFixtures::PROVIDERS)->with([
    'truncated' => [fn (string $provider) => ProviderFixtures::truncated($provider), Finish::Length],
    'undecodable' => [fn (string $provider) => ProviderFixtures::withText($provider, 'Everything looks fine.'), Finish::Empty],
    'refused' => [fn (string $provider) => ProviderFixtures::refusal($provider), Finish::Refused],
]);

it('throws ProviderPaused without a request while the reviewer\'s provider is paused', function () {
    app(Throttle::class)->pause('gemini', 30);
    $this->fakeReview();

    $exception = reviewFailure('gemini');

    expect($exception)->toBeInstanceOf(ProviderPaused::class)
        ->and($exception->provider)->toBe('gemini')
        ->and($exception->seconds)->toBe(30);
    ReviewAgent::assertNeverPrompted();
    $this->assertNoModelCalls();

    expect(engineReview('anthropic')->finish)->toBe(Finish::Stop);
});

it('pauses the reviewer\'s provider on a 429 and throws ProviderPaused', function () {
    $this->freezeTime();
    ProviderFixtures::fake('openai', ProviderFixtures::error('openai', 429, 'rate_limit_exceeded', 'Rate limit reached for requests.'), 429, ['retry-after' => '12']);

    $exception = reviewFailure('openai');

    expect($exception)->toBeInstanceOf(ProviderPaused::class)
        ->and($exception->seconds)->toBe(12)
        ->and(app(Throttle::class)->pausedFor('openai'))->toBe(12);
});

it('maps provider failures as a translation does', function (string $provider, Closure $fake, string $class) {
    $fake($provider);

    $exception = reviewFailure($provider);

    expect($exception)->toBeInstanceOf($class)
        ->and(app(Throttle::class)->pausedFor($provider))->toBe(0);
})->with([
    'a timeout' => ['anthropic', fn (string $provider) => Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection('cURL error 28: Operation timed out')]), ProviderTimeout::class],
    'an invalid key' => ['gemini', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 401, 'UNAUTHENTICATED', 'No.'), 401), ProviderRejected::class],
    'a spend cap' => ['openai', fn (string $provider) => ProviderFixtures::fake($provider, ProviderFixtures::error($provider, 429, 'insufficient_quota', 'Quota.'), 429), ProviderRejected::class],
]);

it('throws ProviderRejected naming the reviewer\'s provider when it has no key, without a request', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
    Http::fake();

    $exception = reviewFailure('openai');

    expect($exception)->toBeInstanceOf(ProviderRejected::class)
        ->and($exception->provider)->toBe('openai');
    Http::assertNothingSent();
});

it('throws RequestRejected on a 400 and logs the shape of the review request without its content', function () {
    Log::spy();
    ProviderFixtures::fake('anthropic', ProviderFixtures::error('anthropic', 400, 'invalid_request_error', 'Invalid schema.'), 400);

    expect(reviewFailure('anthropic'))->toBeInstanceOf(RequestRejected::class);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['agent'] === 'ReviewAgent'
        && $context['segments'] === 2
        && $context['kinds'] === ['plain' => 1, 'inline' => 1]
        && $context['source_chars'] === 47
        && $context['max_tokens'] === app(TranslationEngine::class)->reviewCap(reviewCall())
        && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'Kyma')
        && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'θάλασσα'));
});
