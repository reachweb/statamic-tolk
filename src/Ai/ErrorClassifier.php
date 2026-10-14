<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Support\Sanitizer;
use Throwable;

/**
 * The single place that knows how a failed provider call maps to the handling of §A10. Facts from T2.1
 * (07-spike-findings.md "SDK"): only Anthropic turns 529 into an overload, Anthropic reports credit and billing messages
 * of any status as InsufficientCreditsException, a timeout is the same ProviderConnectionException as a refused
 * connection, and a missing retry-after header reads as ''.
 */
class ErrorClassifier
{
    /**
     * Unverified: the body of a Gemini quota error has not been seen live (T13.4).
     */
    public const string GEMINI_QUOTA_CODE = 'quota_exceeded';

    /**
     * 429 codes that report a spend cap, quota or billing limit rather than a rate limit (R6 §1.5, §2.5, §3.5).
     */
    public const array REJECTED_429_CODES = [
        'anthropic' => ['enforced_spend_limit_reached'],
        'openai' => [
            'credit_balance_exhausted',
            'organization_spend_limit_exceeded',
            'project_spend_limit_exceeded',
            'organization_usage_limit_exceeded',
            'insufficient_quota',
        ],
        'gemini' => [self::GEMINI_QUOTA_CODE],
    ];

    /**
     * Google refuses a wrong, revoked, expired or blocked key with an ErrorInfo reason of this prefix, and a wrong or
     * revoked one with HTTP 400 (API_KEY_INVALID), not 401 or 403.
     */
    public const string GEMINI_KEY_REASON_PREFIX = 'API_KEY_';

    /**
     * The status of a Gemini 400 that refuses the project, not the request: billing, or a region the free tier does not
     * serve.
     */
    public const string GEMINI_PRECONDITION = 'FAILED_PRECONDITION';

    private const array CODE_PATHS = ['error.code', 'error.type', 'error.status', 'error.details.error_code', 'error.details.*.reason'];

    private const int CURL_TIMEOUT = 28;

    public function classify(Throwable $exception, string $provider): ?ProviderError
    {
        if ($exception instanceof ProviderNotConfigured) {
            return new ProviderError(ProviderErrorKind::Rejected, $exception->getMessage(), code: ProviderError::NOT_CONFIGURED);
        }

        $response = $this->response($exception);

        if ($exception instanceof InsufficientCreditsException) {
            return new ProviderError(
                ProviderErrorKind::Rejected,
                $this->message($exception, $response ? $this->body($response) : []),
                $response?->status(),
                ProviderError::INSUFFICIENT_CREDITS,
            );
        }

        if ($exception instanceof ProviderConnectionException) {
            $kind = $this->timedOut($exception->getPrevious()) ? ProviderErrorKind::Timeout : ProviderErrorKind::Unavailable;

            return new ProviderError($kind, $this->message($exception));
        }

        if ($response) {
            return $this->fromResponse($exception, $response, $provider);
        }

        if ($exception instanceof AiException) {
            return new ProviderError(ProviderErrorKind::Paused, $this->message($exception));
        }

        return null;
    }

    private function fromResponse(Throwable $exception, Response $response, string $provider): ProviderError
    {
        $status = $response->status();
        $body = $this->body($response);
        $message = $this->message($exception, $body);

        if ($code = $this->rejectedCode($body, $status, $provider)) {
            return new ProviderError(ProviderErrorKind::Rejected, $message, $status, $code);
        }

        $kind = match (true) {
            $status === 429, $status === 408, $status >= 500 => ProviderErrorKind::Paused,
            in_array($status, [401, 402, 403, 404], true) => ProviderErrorKind::Rejected,
            default => ProviderErrorKind::RequestRejected,
        };

        $retryAfter = $kind === ProviderErrorKind::Paused ? $this->retryAfter($response) : null;

        return new ProviderError($kind, $message, $status, retryAfter: $retryAfter);
    }

    /**
     * The HTTP response, from a plain RequestException or from the RequestException the SDK wrapped.
     */
    private function response(Throwable $exception): ?Response
    {
        $request = $exception instanceof RequestException ? $exception : $exception->getPrevious();

        return $request instanceof RequestException ? $request->response : null;
    }

    /**
     * The decoded error body. Gemini's Interactions endpoint sends it wrapped in a JSON list: [{"error": {…}}].
     */
    private function body(Response $response): array
    {
        $body = $response->json();

        if (is_array($body) && array_is_list($body) && is_array($body[0] ?? null)) {
            $body = $body[0];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * The code of a client error that refuses the account rather than the request: a spend-cap, quota or billing code
     * on a 429, and for Gemini a key Google refuses or a failed precondition, whatever their status.
     */
    private function rejectedCode(array $body, int $status, string $provider): ?string
    {
        if ($status === 429) {
            $codes = self::REJECTED_429_CODES[$provider] ?? [];

            foreach (self::CODE_PATHS as $path) {
                foreach ((array) data_get($body, $path) as $value) {
                    if (is_string($value) && in_array($value, $codes, true)) {
                        return $value;
                    }
                }
            }

            return null;
        }

        return $provider === 'gemini' && $status >= 400 && $status < 500 ? $this->geminiRejectedCode($body) : null;
    }

    private function geminiRejectedCode(array $body): ?string
    {
        foreach ((array) data_get($body, 'error.details.*.reason') as $reason) {
            if (is_string($reason) && str_starts_with($reason, self::GEMINI_KEY_REASON_PREFIX)) {
                return ProviderError::INVALID_KEY;
            }
        }

        return data_get($body, 'error.status') === self::GEMINI_PRECONDITION ? self::GEMINI_PRECONDITION : null;
    }

    private function retryAfter(Response $response): ?int
    {
        $value = trim($response->header('retry-after'));

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) ceil((float) $value));
        }

        $date = strtotime($value);

        return $date === false ? null : max(0, $date - Carbon::now()->getTimestamp());
    }

    private function timedOut(?Throwable $previous): bool
    {
        if (! $previous instanceof ConnectionException) {
            return false;
        }

        $transfer = $previous->getPrevious();

        if ($transfer && method_exists($transfer, 'getHandlerContext') && ($transfer->getHandlerContext()['errno'] ?? null) === self::CURL_TIMEOUT) {
            return true;
        }

        return str_starts_with($previous->getMessage(), 'cURL error '.self::CURL_TIMEOUT.':');
    }

    private function message(Throwable $exception, array $body = []): string
    {
        $message = data_get($body, 'error.message');

        if (! is_string($message) || trim($message) === '') {
            $message = $exception->getMessage();
        }

        return Sanitizer::message($message);
    }
}
