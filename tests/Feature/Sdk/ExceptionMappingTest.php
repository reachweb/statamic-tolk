<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

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
    'gemini' => ['gemini', 'RESOURCE_EXHAUSTED', ['details' => [['reason' => 'quota_exceeded']]], 'error.details.0.reason', 'quota_exceeded'],
]);

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
