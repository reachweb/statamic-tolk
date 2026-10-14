<?php

use Composer\InstalledVersions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Reach\StatamicTolk\Ai\ProviderCall;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

beforeEach(function () {
    // From 1.2.1 the SDK sleeps before it repeats a call answered with a short retry-after.
    Sleep::fake();
});

/**
 * laravel/ai 1.2.1 wraps each provider call in retry(2): a 429, or a status the gateway counts as overloaded, whose
 * retry-after is a whole number of seconds up to 10, is called once more after sleeping that long. 1.2.0 and earlier
 * call once. Tolk's calls run inside ProviderCall, which hides the header from the SDK (D24).
 */
function sdkRepeatsShortWaits(): bool
{
    return version_compare((string) InstalledVersions::getVersion('laravel/ai'), '1.2.1', '>=');
}

it('throws RateLimitedException on 429, with retry-after and the error body on the previous exception', function (string $provider, string $type) {
    $exception = ProviderFixtures::failure($provider, 429, $type, 'Rate limit reached for requests.', ['retry-after' => '17']);

    expect($exception)->toBeInstanceOf(RateLimitedException::class)
        ->and($exception)->toBeInstanceOf(FailoverableException::class)
        ->and($exception->getMessage())->toBe("Application rate limited by AI provider [tolk_{$provider}].")
        ->and($exception->getPrevious())->toBeInstanceOf(RequestException::class)
        ->and($exception->getPrevious()->response->status())->toBe(429)
        ->and($exception->getPrevious()->response->header('retry-after'))->toBe('17')
        ->and($exception->getPrevious()->response->json('error.message'))->toBe('Rate limit reached for requests.');
})->with([
    ['anthropic', 'rate_limit_error'],
    ['openai', 'rate_limit_exceeded'],
    ['gemini', 'RESOURCE_EXHAUSTED'],
]);

it('throws the same RateLimitedException for a 429 that reports a spend cap or quota, with the body reachable', function (string $provider, string $type, array $extra, string $path, string $code) {
    $exception = ProviderFixtures::failure($provider, 429, $type, 'You have reached your spend limit.', extra: $extra);

    expect($exception)->toBeInstanceOf(RateLimitedException::class)
        ->and($exception->getPrevious()->response->json($path))->toBe($code)
        ->and($exception->getPrevious()->response->header('retry-after'))->toBe('');
})->with([
    'anthropic' => ['anthropic', 'rate_limit_error', ['details' => ['error_code' => 'enforced_spend_limit_reached']], 'error.details.error_code', 'enforced_spend_limit_reached'],
    'openai' => ['openai', 'organization_spend_limit_exceeded', [], 'error.code', 'organization_spend_limit_exceeded'],
]);

it('throws the same RateLimitedException for Gemini\'s quota 429, its list-shaped body left as sent', function () {
    ProviderFixtures::fake('gemini', ProviderFixtures::geminiQuota(), 429);

    $exception = ProviderFixtures::thrown('gemini');
    $response = $exception->getPrevious()->response;

    expect($exception)->toBeInstanceOf(RateLimitedException::class)
        ->and($response->json('0.error.status'))->toBe('RESOURCE_EXHAUSTED')
        ->and($response->json('0.error.details.0.violations.0.quotaId'))->toBe('GenerateRequestsPerDayPerProjectPerModel-FreeTier')
        ->and($response->json('0.error.details.2.retryDelay'))->toBe('1s')
        ->and($response->header('retry-after'))->toBe('');
});

it('throws ProviderOverloadedException on 503 and on transient gateway errors', function (string $provider, int $status) {
    $exception = ProviderFixtures::failure($provider, $status, 'overloaded_error', 'The service is temporarily overloaded.', ['retry-after' => '3']);

    expect($exception)->toBeInstanceOf(ProviderOverloadedException::class)
        ->and($exception)->toBeInstanceOf(FailoverableException::class)
        ->and($exception->getPrevious()->response->status())->toBe($status)
        ->and($exception->getPrevious()->response->header('retry-after'))->toBe('3');
})->with(ProviderFixtures::PROVIDERS)->with([502, 503, 504]);

it('throws ProviderOverloadedException on 529 for anthropic only', function (string $provider, string $class) {
    expect(ProviderFixtures::failure($provider, 529, 'overloaded_error', 'Overloaded.'))->toBeInstanceOf($class);
})->with([
    ['anthropic', ProviderOverloadedException::class],
    ['openai', RequestException::class],
    ['gemini', RequestException::class],
]);

it('throws ProviderConnectionException on a connection failure, whose previous exception has no response', function (string $provider) {
    Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection()]);

    $exception = ProviderFixtures::thrown($provider);

    expect($exception)->toBeInstanceOf(ProviderConnectionException::class)
        ->and($exception)->toBeInstanceOf(FailoverableException::class)
        ->and($exception->getMessage())->toBe("Could not connect to AI provider [tolk_{$provider}].")
        ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and($exception->getPrevious())->not->toBeInstanceOf(RequestException::class)
        ->and(property_exists($exception->getPrevious(), 'response'))->toBeFalse();
})->with(ProviderFixtures::PROVIDERS);

it('throws the same ProviderConnectionException on a timeout, which only the message of the previous exception identifies', function (string $provider) {
    Http::fake([ProviderFixtures::HOSTS[$provider] => Http::failedConnection('cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received')]);

    $exception = ProviderFixtures::thrown($provider);

    expect($exception)->toBeInstanceOf(ProviderConnectionException::class)
        ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and($exception->getPrevious()->getMessage())->toStartWith('cURL error 28: ');
})->with(ProviderFixtures::PROVIDERS);

it('throws a plain RequestException on other error statuses', function (string $provider, int $status) {
    $exception = ProviderFixtures::failure($provider, $status, 'api_error', 'Something about the request.');

    expect($exception)->toBeInstanceOf(RequestException::class)
        ->and($exception)->not->toBeInstanceOf(FailoverableException::class)
        ->and($exception->response->status())->toBe($status)
        ->and($exception->response->json('error.message'))->toBe('Something about the request.');
})->with(ProviderFixtures::PROVIDERS)->with([400, 401, 403, 404, 408, 413, 500]);

it('throws a plain RequestException on Gemini\'s 400 for an invalid key, its list-shaped body left as sent', function () {
    ProviderFixtures::fake('gemini', ProviderFixtures::geminiInvalidKey(), 400);

    $exception = ProviderFixtures::thrown('gemini');

    expect($exception)->toBeInstanceOf(RequestException::class)
        ->and($exception)->not->toBeInstanceOf(FailoverableException::class)
        ->and($exception->response->json('error.message'))->toBeNull()
        ->and($exception->response->json('0.error.details.0.reason'))->toBe('API_KEY_INVALID');
});

it('throws InsufficientCreditsException on 402', function (string $provider) {
    $exception = ProviderFixtures::failure($provider, 402, 'payment_required', 'Payment required.');

    expect($exception)->toBeInstanceOf(InsufficientCreditsException::class)
        ->and($exception)->toBeInstanceOf(FailoverableException::class)
        ->and($exception->getPrevious()->response->status())->toBe(402);
})->with(ProviderFixtures::PROVIDERS);

it('throws InsufficientCreditsException for an anthropic error whose message mentions credit or billing', function (string $message) {
    expect(ProviderFixtures::failure('anthropic', 400, 'invalid_request_error', $message))->toBeInstanceOf(InsufficientCreditsException::class);
})->with([
    'Your credit balance is too low to access the Anthropic API.',
    'This request would exceed your organization’s usage limit.',
]);

it('throws AiException for an error inside a 200 response', function (string $provider, string $message) {
    $exception = ProviderFixtures::failure($provider, 200, 'api_error', 'Internal error.');

    expect($exception)->toBeInstanceOf(AiException::class)
        ->and($exception)->not->toBeInstanceOf(FailoverableException::class)
        ->and($exception->getMessage())->toBe($message);
})->with([
    ['anthropic', 'Anthropic Error: [api_error] Internal error.'],
    ['openai', 'OpenAI Error: [api_error] Internal error.'],
    ['gemini', 'Gemini Error: [200] Internal error.'],
]);

it('calls again after sleeping a retry-after of at most 10 seconds on a 429 or an overload, from 1.2.1 on', function (string $provider, int $status, string $retryAfter, bool $repeats) {
    ProviderFixtures::failure($provider, $status, 'rate_limit_error', 'Slow down.', ['retry-after' => $retryAfter]);
    $repeats = $repeats && sdkRepeatsShortWaits();

    expect(Http::recorded())->toHaveCount($repeats ? 2 : 1);
    $repeats ? Sleep::assertSequence([Sleep::for((int) $retryAfter)->seconds()]) : Sleep::assertNeverSlept();
})->with([
    'openai 429, 10 s' => ['openai', 429, '10', true],
    'gemini 429, 1 s' => ['gemini', 429, '1', true],
    'anthropic 529, 3 s' => ['anthropic', 529, '3', true],
    'gemini 503, 3 s' => ['gemini', 503, '3', true],
    'openai 429, 11 s' => ['openai', 429, '11', false],
    'openai 429, 1.5 s' => ['openai', 429, '1.5', false],
    'openai 429, a date' => ['openai', 429, 'Wed, 21 Oct 2026 07:28:00 GMT', false],
    'openai 529, 3 s' => ['openai', 529, '3', false],
    'anthropic 500, 3 s' => ['anthropic', 500, '3', false],
    'anthropic 408, 3 s' => ['anthropic', 408, '3', false],
]);

it('does not call again without a retry-after', function (string $provider, int $status) {
    ProviderFixtures::failure($provider, $status, 'rate_limit_error', 'Slow down.');

    expect(Http::recorded())->toHaveCount(1);
    Sleep::assertNeverSlept();
})->with(ProviderFixtures::PROVIDERS)->with([429, 503]);

it('calls once inside ProviderCall, which hands the retry-after on under its own header', function (string $provider, int $status) {
    $exception = app(ProviderCall::class)->run(fn () => ProviderFixtures::failure($provider, $status, 'rate_limit_error', 'Slow down.', ['retry-after' => '5']));
    $response = $exception->getPrevious()->response;

    expect(Http::recorded())->toHaveCount(1)
        ->and($response->header('retry-after'))->toBe('')
        ->and($response->header(ProviderCall::RETRY_AFTER))->toBe('5');
    Sleep::assertNeverSlept();
})->with(ProviderFixtures::PROVIDERS)->with([429, 503]);
