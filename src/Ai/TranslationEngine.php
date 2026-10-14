<?php

namespace Reach\StatamicTolk\Ai;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Reach\StatamicTolk\Ai\Agents\ReviewAgent;
use Reach\StatamicTolk\Ai\Agents\StructuredAgent;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;
use Reach\StatamicTolk\Ai\Prompts\PromptBuilder;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderTimeout;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\RequestRejected;
use Throwable;

final readonly class TranslationEngine implements Engine
{
    public const int MIN_OUTPUT_TOKENS = 2048;

    /**
     * Output tokens reserved per estimated source token: translations into other scripts or more verbose languages take
     * more tokens than their source, and JSON adds its own.
     */
    private const int OUTPUT_FACTOR = 3;

    /**
     * Output tokens reserved per segment for the JSON around its translation, which took 15 to 26 per segment in the
     * T13.4 measurements: 80 short strings need more than twice what their characters suggest.
     */
    private const int SEGMENT_OUTPUT_TOKENS = 32;

    public function __construct(
        private ModelCatalog $catalog,
        private PromptBuilder $prompts,
        private ErrorClassifier $classifier,
        private RetryPolicy $retry,
        private Throttle $throttle,
    ) {}

    public function translate(EngineCall $call): EngineResult
    {
        $this->ensureNotPaused($call);

        $agent = new TranslationAgent(
            model: $call->model,
            instructions: $this->prompts->system($call->source, $call->target, $call->context),
            providerOptions: $this->catalog->options($call->model),
            timeout: $call->timeout,
            maxTokens: $this->outputCap($call),
        );

        return $this->send($agent, $call, $this->translations(...));
    }

    public function review(EngineCall $call): EngineResult
    {
        $this->ensureNotPaused($call);

        $agent = new ReviewAgent(
            model: $call->model,
            instructions: $this->prompts->reviewSystem($call->source, $call->target, $call->context),
            providerOptions: $this->catalog->options($call->model),
            timeout: $call->timeout,
            maxTokens: $this->reviewCap($call),
        );

        return $this->send($agent, $call, $this->corrections(...));
    }

    /**
     * Thinking tokens count against the cap on all three providers, so the model's headroom is added on top. The model's
     * maximum is not used as the cap, because OpenAI counts the cap against its rate limit (R6 §4b).
     */
    public function outputCap(EngineCall $call): int
    {
        return $this->cap($call, $call->sourceChars());
    }

    /**
     * Sized from the translations under review, because at most every one of them comes back corrected.
     */
    public function reviewCap(EngineCall $call): int
    {
        return $this->cap($call, $call->translationChars());
    }

    private function cap(EngineCall $call, int $chars): int
    {
        $charsPerToken = (float) config('tolk.tokens.chars_per_token', 3);
        $charsPerToken = $charsPerToken > 0 ? $charsPerToken : 3.0;

        $tokens = max(
            (int) ceil($chars / $charsPerToken * self::OUTPUT_FACTOR) + count($call->segments) * self::SEGMENT_OUTPUT_TOKENS,
            self::MIN_OUTPUT_TOKENS,
        );

        return $this->catalog->outputCap($call->model, $tokens * max(1, $call->capMultiplier));
    }

    private function ensureNotPaused(EngineCall $call): void
    {
        $provider = $call->model->provider;

        if (($seconds = $this->throttle->pausedFor($provider)) > 0) {
            throw ProviderPaused::for($provider, $seconds);
        }
    }

    /**
     * @param  Closure(array, EngineCall): array  $items
     */
    private function send(StructuredAgent $agent, EngineCall $call, Closure $items): EngineResult
    {
        try {
            $response = $agent->prompt($this->prompts->user($call->document, $call->segments));
        } catch (Throwable $exception) {
            throw $this->failure($exception, $call, $agent);
        }

        return $this->result($response, $call, $items);
    }

    private function result(AgentResponse $response, EngineCall $call, Closure $items): EngineResult
    {
        $structured = $response instanceof StructuredAgentResponse ? $response->structured : [];
        $reason = $response->steps->last()?->finishReason;

        $finish = match (true) {
            $reason === FinishReason::ContentFilter, $this->refusedByOpenAi($response, $call) => Finish::Refused,
            $reason === FinishReason::Length => Finish::Length,
            $structured === [] => Finish::Empty,
            default => Finish::Stop,
        };

        return new EngineResult(
            items: $finish === Finish::Stop ? $items($structured, $call) : [],
            finish: $finish,
            usage: Usage::fromResponse($response),
            model: $call->model,
        );
    }

    /**
     * @return array<string, string>
     */
    private function translations(array $structured, EngineCall $call): array
    {
        return array_map(fn (array $item) => $item['text'], $this->items($structured['translations'] ?? [], $call, ['text']));
    }

    /**
     * @return array<string, array{text: string, reason: string}>
     */
    private function corrections(array $structured, EngineCall $call): array
    {
        return $this->items($structured['corrections'] ?? [], $call, ['text', 'reason']);
    }

    /**
     * The SDK does not validate decoded data (T2.1), so items without a string id and string values for the keys, ids
     * that were not asked for and repeated ids are dropped.
     *
     * @param  list<string>  $keys
     * @return array<string, array<string, string>>
     */
    private function items(mixed $list, EngineCall $call, array $keys): array
    {
        $requested = array_flip(array_filter(array_column($call->segments, 'id'), 'is_string'));
        $items = [];

        foreach (Arr::wrap($list) as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! is_string($id) || ! isset($requested[$id]) || isset($items[$id])) {
                continue;
            }

            $values = [];

            foreach ($keys as $key) {
                if (! is_string($item[$key] ?? null)) {
                    continue 2;
                }

                $values[$key] = $item[$key];
            }

            $items[$id] = $values;
        }

        return $items;
    }

    /**
     * OpenAI reports a refusal as a normal stop with an empty result (T2.1), and laravel/ai before 1.2.1 reports an
     * answer the content filter stopped as `Length`; only the raw response shows either.
     */
    private function refusedByOpenAi(AgentResponse $response, EngineCall $call): bool
    {
        return $call->model->provider === 'openai'
            && ($response->raw?->json('incomplete_details.reason') === 'content_filter'
                || in_array('refusal', (array) $response->raw?->json('output.*.content.*.type'), true));
    }

    private function failure(Throwable $exception, EngineCall $call, StructuredAgent $agent): Throwable
    {
        $provider = $call->model->provider;
        $error = $this->classifier->classify($exception, $provider);

        return match ($error?->kind) {
            null => $exception,
            ProviderErrorKind::Paused => $this->pause($provider, $this->retry->delay($call->attempt, $error->retryAfter)),
            ProviderErrorKind::Timeout => ProviderTimeout::after($provider, $call->timeout, $this->retry->delay($call->attempt, null)),
            ProviderErrorKind::Unavailable => ProviderUnavailable::connection($provider, $this->retry->delay($call->attempt, null)),
            ProviderErrorKind::Rejected => ProviderRejected::from($call->model, $error),
            ProviderErrorKind::RequestRejected => $this->rejectedRequest($call, $agent, $error),
        };
    }

    private function pause(string $provider, int $seconds): ProviderPaused
    {
        $this->throttle->pause($provider, $seconds);

        return ProviderPaused::for($provider, max($seconds, $this->throttle->pausedFor($provider)));
    }

    /**
     * Logs the shape of the request, never its content (§A10).
     */
    private function rejectedRequest(EngineCall $call, StructuredAgent $agent, ProviderError $error): RequestRejected
    {
        Log::warning('Tolk: the AI provider rejected a request.', [
            'model' => (string) $call->model,
            'agent' => class_basename($agent),
            'status' => $error->status,
            'message' => $error->message,
            'segments' => count($call->segments),
            'kinds' => array_count_values(array_filter(array_column($call->segments, 'kind'), 'is_string')),
            'source_chars' => $call->sourceChars(),
            'max_tokens' => $agent->maxTokens,
            'timeout' => $call->timeout,
            'options' => array_keys($agent->providerOptions($call->model->provider)),
        ]);

        return RequestRejected::from($call->model->provider, $error);
    }
}
