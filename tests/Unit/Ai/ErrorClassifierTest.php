<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Reach\StatamicTolk\Ai\ErrorClassifier;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

function failedRequest(int $status, array $body = ['error' => ['message' => 'Provider message.']], array $headers = []): RequestException
{
    return new RequestException(new Response(new PsrResponse($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body))));
}

function classifyError(Throwable $exception, string $provider = 'openai'): ?ProviderError
{
    return (new ErrorClassifier)->classify($exception, $provider);
}

it('rejects a provider without a key', function () {
    $error = classifyError(ProviderNotConfigured::for('anthropic'), 'anthropic');

    expect($error->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($error->code)->toBe(ProviderError::NOT_CONFIGURED)
        ->and($error->status)->toBeNull()
        ->and($error->message)->toBe(ProviderNotConfigured::for('anthropic')->getMessage());
});

it('rejects insufficient credits, whatever the status', function (int $status) {
    $error = classifyError(InsufficientCreditsException::forProvider('tolk_anthropic', 0, failedRequest($status)), 'anthropic');

    expect($error->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($error->code)->toBe(ProviderError::INSUFFICIENT_CREDITS)
        ->and($error->status)->toBe($status)
        ->and($error->describe())->toBe("HTTP {$status}, insufficient_credits");
})->with([402, 400]);

it('pauses on a rate limit with the retry-after the provider sent', function (string $header, ?int $expected) {
    $error = classifyError(RateLimitedException::forProvider('tolk_openai', 0, failedRequest(429, headers: ['retry-after' => $header])));

    expect($error->kind)->toBe(ProviderErrorKind::Paused)
        ->and($error->status)->toBe(429)
        ->and($error->retryAfter)->toBe($expected);
})->with([
    'seconds' => ['17', 17],
    'fraction' => ['2.5', 3],
    'empty, as the SDK reads a missing header' => ['', null],
    'garbage' => ['soon', null],
]);

it('reads a retry-after given as an HTTP date', function () {
    $this->freezeTime();
    $header = Carbon::now()->addSeconds(30)->toRfc7231String();

    expect(classifyError(RateLimitedException::forProvider('tolk_openai', 0, failedRequest(429, headers: ['retry-after' => $header])))->retryAfter)->toBe(30);
});

it('rejects a 429 that carries a spend-cap or quota code of its provider', function (string $provider, array $error, string $code) {
    $result = classifyError(RateLimitedException::forProvider("tolk_{$provider}", 0, failedRequest(429, ['error' => $error])), $provider);

    expect($result->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($result->code)->toBe($code)
        ->and($result->status)->toBe(429);
})->with([
    'anthropic' => ['anthropic', ['type' => 'rate_limit_error', 'message' => 'Spend limit.', 'details' => ['error_code' => 'enforced_spend_limit_reached']], 'enforced_spend_limit_reached'],
    'openai credit balance' => ['openai', ['code' => 'credit_balance_exhausted', 'message' => 'No credit.'], 'credit_balance_exhausted'],
    'openai organization spend' => ['openai', ['code' => 'organization_spend_limit_exceeded', 'message' => 'Spend.'], 'organization_spend_limit_exceeded'],
    'openai project spend' => ['openai', ['code' => 'project_spend_limit_exceeded', 'message' => 'Spend.'], 'project_spend_limit_exceeded'],
    'openai usage limit' => ['openai', ['code' => 'organization_usage_limit_exceeded', 'message' => 'Usage.'], 'organization_usage_limit_exceeded'],
    'openai quota' => ['openai', ['type' => 'insufficient_quota', 'code' => 'insufficient_quota', 'message' => 'Quota.'], 'insufficient_quota'],
    'gemini' => ['gemini', ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota.', 'details' => [['reason' => ErrorClassifier::GEMINI_QUOTA_CODE]]], 'quota_exceeded'],
]);

it('treats a code of another provider as a rate limit', function () {
    $error = classifyError(RateLimitedException::forProvider('tolk_anthropic', 0, failedRequest(429, ['error' => ['code' => 'organization_spend_limit_exceeded']])), 'anthropic');

    expect($error->kind)->toBe(ProviderErrorKind::Paused);
});

it('pauses on an overload, a server error and a request timeout', function (Throwable $exception, int $status, ?int $retryAfter) {
    $error = classifyError($exception);

    expect($error->kind)->toBe(ProviderErrorKind::Paused)
        ->and($error->status)->toBe($status)
        ->and($error->retryAfter)->toBe($retryAfter);
})->with([
    'overloaded 503' => fn () => [ProviderOverloadedException::forProvider('tolk_openai', 0, failedRequest(503, headers: ['retry-after' => '3'])), 503, 3],
    'plain 500' => fn () => [failedRequest(500), 500, null],
    'plain 529' => fn () => [failedRequest(529), 529, null],
    'plain 408' => fn () => [failedRequest(408, headers: ['retry-after' => '2']), 408, 2],
]);

it('pauses on an error inside a 200 response', function () {
    $error = classifyError(new AiException('OpenAI Error: [server_error] The server had an error.'));

    expect($error->kind)->toBe(ProviderErrorKind::Paused)
        ->and($error->status)->toBeNull()
        ->and($error->retryAfter)->toBeNull()
        ->and($error->message)->toBe('OpenAI Error: [server_error] The server had an error.');
});

it('rejects 401, 403 and 404 without a retry-after', function (int $status) {
    $error = classifyError(failedRequest($status, headers: ['retry-after' => '5']));

    expect($error->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($error->status)->toBe($status)
        ->and($error->code)->toBeNull()
        ->and($error->retryAfter)->toBeNull();
})->with([401, 403, 404]);

it('rejects a Gemini key that Google refuses with a 400, also in a list-shaped body', function (array $body) {
    $error = classifyError(failedRequest(400, $body), 'gemini');

    expect($error->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($error->code)->toBe(ProviderError::INVALID_KEY)
        ->and($error->status)->toBe(400)
        ->and($error->retryAfter)->toBeNull()
        ->and($error->message)->toBe('API key not valid. Please pass a valid API key.')
        ->and($error->describe())->toBe('HTTP 400, invalid_key');
})->with([
    'list, as the Interactions endpoint sends it' => [ProviderFixtures::geminiInvalidKey()],
    'object' => [ProviderFixtures::geminiInvalidKey()[0]],
    'expired' => [ProviderFixtures::geminiInvalidKey(reason: 'API_KEY_EXPIRED')],
]);

it('rejects a Gemini key that Google blocks for the service, as the key it is', function () {
    $error = classifyError(failedRequest(403, ProviderFixtures::geminiError(403, 'PERMISSION_DENIED', 'Requests to this API are blocked.', 'API_KEY_SERVICE_BLOCKED')), 'gemini');

    expect($error->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($error->code)->toBe(ProviderError::INVALID_KEY)
        ->and($error->message)->toBe('Requests to this API are blocked.');
});

it('rejects a Gemini project that Google refuses for its billing or region', function () {
    $message = 'Gemini API free tier is not available in your country. Please enable billing on your project in Google AI Studio.';
    $error = classifyError(failedRequest(400, ProviderFixtures::geminiError(400, 'FAILED_PRECONDITION', $message)), 'gemini');

    expect($error->kind)->toBe(ProviderErrorKind::Rejected)
        ->and($error->code)->toBe('FAILED_PRECONDITION')
        ->and($error->message)->toBe($message)
        ->and($error->describe())->toBe('HTTP 400, FAILED_PRECONDITION');
});

it('rejects only the request on a Gemini 400 about the request, and reads its list-shaped message', function () {
    $error = classifyError(failedRequest(400, ProviderFixtures::geminiError(400, 'INVALID_ARGUMENT', 'Invalid JSON payload received.', 'BAD_REQUEST')), 'gemini');

    expect($error->kind)->toBe(ProviderErrorKind::RequestRejected)
        ->and($error->code)->toBeNull()
        ->and($error->message)->toBe('Invalid JSON payload received.');
});

it('reads a key reason or a precondition only for Gemini', function (string $provider) {
    expect(classifyError(failedRequest(400, ProviderFixtures::geminiInvalidKey()), $provider)->kind)->toBe(ProviderErrorKind::RequestRejected)
        ->and(classifyError(failedRequest(400, ProviderFixtures::geminiError(400, 'FAILED_PRECONDITION', 'No.')), $provider)->kind)->toBe(ProviderErrorKind::RequestRejected);
})->with(['anthropic', 'openai']);

it('rejects the request on other client errors', function (int $status) {
    $error = classifyError(failedRequest($status));

    expect($error->kind)->toBe(ProviderErrorKind::RequestRejected)
        ->and($error->status)->toBe($status)
        ->and($error->describe())->toBe("HTTP {$status}");
})->with([400, 409, 413, 422]);

it('tells a timeout from a connection failure', function (ConnectionException $previous, ProviderErrorKind $kind) {
    expect(classifyError(ProviderConnectionException::forProvider('tolk_openai', 0, $previous))->kind)->toBe($kind);
})->with([
    'message' => fn () => [new ConnectionException('cURL error 28: Operation timed out after 45001 milliseconds'), ProviderErrorKind::Timeout],
    'handler context' => fn () => [
        new ConnectionException('Connection reset', 0, new ConnectException('Connection reset', new PsrRequest('POST', 'https://api.openai.com'), null, ['errno' => 28])),
        ProviderErrorKind::Timeout,
    ],
    'refused' => fn () => [new ConnectionException('cURL error 7: Failed to connect to api.openai.com'), ProviderErrorKind::Unavailable],
    'other errno' => fn () => [
        new ConnectionException('Could not resolve host', 0, new ConnectException('Could not resolve host', new PsrRequest('POST', 'https://api.openai.com'), null, ['errno' => 6])),
        ProviderErrorKind::Unavailable,
    ],
]);

it('takes the message from the error body, sanitised, and falls back to the exception', function () {
    expect(classifyError(failedRequest(401, ['error' => ['message' => 'Bad key sk-proj-Ab12Cd34Ef56Gh78Ij90.']]))->message)->toBe('Bad key [redacted].')
        ->and(classifyError(failedRequest(500, ['oops' => true]))->message)->toStartWith('HTTP request returned status code 500');
});

it('does not classify an exception that is not a provider failure', function (Throwable $exception) {
    expect(classifyError($exception))->toBeNull();
})->with([
    fn () => new RuntimeException('Something else.'),
    fn () => new JsonException('Malformed UTF-8 characters'),
]);
