<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\RateLimitedException;
use Reach\StatamicTolk\Ai\ProviderCall;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function () {
    Sleep::fake();
    Http::fake([
        'example.test/*' => Http::response('Slow down.', 429, ['Retry-After' => '5']),
        ProviderFixtures::HOSTS['openai'] => Http::response(ProviderFixtures::error('openai', 429, 'rate_limit_exceeded', 'Slow down.'), 429, ['retry-after' => '5']),
    ]);
});

function hostRequest(): Response
{
    return Http::get('https://example.test/feed');
}

it('makes a provider call answered 429 with a short retry-after one request that does not sleep', function () {
    $exception = app(ProviderCall::class)->run(fn () => ProviderFixtures::thrown('openai'));

    expect($exception)->toBeInstanceOf(RateLimitedException::class)
        ->and($exception->getPrevious()->response->header(ProviderCall::RETRY_AFTER))->toBe('5')
        ->and(Http::recorded())->toHaveCount(1);
    Sleep::assertNeverSlept();
});

it('leaves the app\'s own requests alone before, after and between provider calls', function () {
    $before = hostRequest();
    app(ProviderCall::class)->run(fn () => ProviderFixtures::thrown('openai'));
    $after = hostRequest();

    try {
        app(ProviderCall::class)->run(fn () => throw new RuntimeException('The call failed.'));
    } catch (RuntimeException) {
    }

    expect($before->header('Retry-After'))->toBe('5')
        ->and($after->header('Retry-After'))->toBe('5')
        ->and($after->header(ProviderCall::RETRY_AFTER))->toBe('')
        ->and(hostRequest()->header('Retry-After'))->toBe('5');
});

it('adds its middleware to the HTTP client once', function () {
    $middleware = count(Http::getFacadeRoot()->getGlobalMiddleware());

    foreach (range(1, 3) as $call) {
        app(ProviderCall::class)->run(fn () => ProviderFixtures::thrown('openai'));
    }

    expect(Http::getFacadeRoot()->getGlobalMiddleware())->toHaveCount($middleware + 1);
});

it('keeps hiding the header until the outer call ends', function () {
    $call = app(ProviderCall::class);

    $inner = $call->run(function () use ($call) {
        $call->run(fn () => null);

        return hostRequest();
    });

    expect($inner->header('Retry-After'))->toBe('')
        ->and($inner->header(ProviderCall::RETRY_AFTER))->toBe('5')
        ->and(hostRequest()->header('Retry-After'))->toBe('5');
});

it('is the only way Tolk prompts an agent', function () {
    $callers = collect(File::allFiles(dirname(__DIR__, 3).'/src'))
        ->filter(fn (SplFileInfo $file) => preg_match('/->(prompt|stream|queue|broadcast\w*)\(/', $file->getContents()))
        ->map(fn (SplFileInfo $file) => str_replace('\\', '/', $file->getRelativePathname()))
        ->values()
        ->all();

    expect($callers)->toBe(['Ai/Agents/StructuredAgent.php']);
});
