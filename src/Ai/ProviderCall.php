<?php

namespace Reach\StatamicTolk\Ai;

use Closure;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Keeps one provider call one call that never sleeps (D24). From laravel/ai 1.2.1 the SDK repeats a call answered 429
 * or overloaded whose Retry-After is at most 10 seconds, after sleeping that long, and has no switch to turn this off
 * (07-spike-findings.md "SDK"). While Tolk's call runs, a response's Retry-After moves to RETRY_AFTER, which the SDK
 * does not read and ErrorClassifier does, so the call fails at once and Throttle pauses the provider for every process.
 * The middleware is added once to the HTTP client the SDK uses and passes every other request through unchanged.
 */
final class ProviderCall
{
    public const string RETRY_AFTER = 'Tolk-Retry-After';

    private int $depth = 0;

    private readonly Closure $middleware;

    public function __construct()
    {
        $this->middleware = Middleware::mapResponse(fn (ResponseInterface $response): ResponseInterface => $this->depth > 0 && $response->hasHeader('Retry-After')
            ? $response->withoutHeader('Retry-After')->withHeader(self::RETRY_AFTER, $response->getHeader('Retry-After'))
            : $response);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function run(Closure $call): mixed
    {
        $this->register();
        $this->depth++;

        try {
            return $call();
        } finally {
            $this->depth--;
        }
    }

    /**
     * On the client the SDK resolves now, which a new application instance replaces.
     */
    private function register(): void
    {
        $factory = Http::getFacadeRoot();

        if ($factory instanceof Factory && ! in_array($this->middleware, $factory->getGlobalMiddleware(), true)) {
            $factory->globalMiddleware($this->middleware);
        }
    }
}
