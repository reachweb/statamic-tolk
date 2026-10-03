<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
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

final class TranslationEngine implements Engine
{
    public const int MIN_OUTPUT_TOKENS = 2048;

    /**
     * Output tokens reserved per estimated source token: translations into other scripts or more verbose languages take
     * more tokens than their source, and JSON adds its own.
     */
    private const int OUTPUT_FACTOR = 3;

    public function __construct(
        private readonly ModelCatalog $catalog,
        private readonly PromptBuilder $prompts,
        private readonly ErrorClassifier $classifier,
        private readonly RetryPolicy $retry,
        private readonly Throttle $throttle,
    ) {}

    public function translate(EngineCall $call): EngineResult
    {
        $provider = $call->model->provider;

        if (($seconds = $this->throttle->pausedFor($provider)) > 0) {
            throw ProviderPaused::for($provider, $seconds);
        }

        $agent = new TranslationAgent(
            model: $call->model,
            instructions: $this->prompts->system($call->source, $call->target, $call->context),
            providerOptions: $this->catalog->options($call->model),
            timeout: $call->timeout,
            maxTokens: $this->outputCap($call),
        );

        $prompt = $this->prompts->user($call->document, $call->segments);

        try {
            $response = $agent->prompt($prompt);
        } catch (Throwable $exception) {
            throw $this->failure($exception, $call, $agent);
        }

        return $this->result($response, $call);
    }

    public function review(EngineCall $call): EngineResult
    {
        throw new LogicException('Review is not built yet (T7.2).');
    }

    /**
     * Thinking tokens count against the cap on all three providers, so the model's headroom is added on top. The model's
     * maximum is not used as the cap, because OpenAI counts the cap against its rate limit (R6 §4b).
     */
    public function outputCap(EngineCall $call): int
    {
        $charsPerToken = (float) config('tolk.tokens.chars_per_token', 3);
        $charsPerToken = $charsPerToken > 0 ? $charsPerToken : 3.0;

        $tokens = max((int) ceil($call->sourceChars() / $charsPerToken * self::OUTPUT_FACTOR), self::MIN_OUTPUT_TOKENS);
        $cap = $tokens * max(1, $call->capMultiplier) + $this->catalog->reasoningHeadroom($call->model);
        $max = $this->catalog->maxOutputTokens($call->model);

        return $max === null ? $cap : min($cap, $max);
    }

    private function result(AgentResponse $response, EngineCall $call): EngineResult
    {
        $structured = $response instanceof StructuredAgentResponse ? $response->structured : [];
        $reason = $response->steps->last()?->finishReason;

        $finish = match (true) {
            $reason === FinishReason::Length => Finish::Length,
            $reason === FinishReason::ContentFilter, $this->refusedByOpenAi($response, $call) => Finish::Refused,
            $structured === [] => Finish::Empty,
            default => Finish::Stop,
        };

        return new EngineResult(
            items: $finish === Finish::Stop ? $this->translations($structured, $call) : [],
            finish: $finish,
            usage: Usage::fromResponse($response),
            model: $call->model,
        );
    }

    /**
     * The SDK does not validate decoded data (T2.1), so items without a string id and text, ids that were not asked
     * for and repeated ids are dropped.
     *
     * @return array<string, string>
     */
    private function translations(array $structured, EngineCall $call): array
    {
        $requested = array_flip(array_filter(array_column($call->segments, 'id'), 'is_string'));
        $items = [];

        foreach (Arr::wrap($structured['translations'] ?? []) as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $text = is_array($item) ? ($item['text'] ?? null) : null;

            if (is_string($id) && is_string($text) && isset($requested[$id]) && ! isset($items[$id])) {
                $items[$id] = $text;
            }
        }

        return $items;
    }

    /**
     * OpenAI reports a refusal as a normal stop with an empty result; only the raw response shows it (T2.1).
     */
    private function refusedByOpenAi(AgentResponse $response, EngineCall $call): bool
    {
        return $call->model->provider === 'openai'
            && in_array('refusal', (array) $response->raw?->json('output.*.content.*.type'), true);
    }

    private function failure(Throwable $exception, EngineCall $call, TranslationAgent $agent): Throwable
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
    private function rejectedRequest(EngineCall $call, TranslationAgent $agent, ProviderError $error): RequestRejected
    {
        Log::warning('Tolk: the AI provider rejected a request.', [
            'model' => (string) $call->model,
            'status' => $error->status,
            'message' => $error->message,
            'segments' => count($call->segments),
            'kinds' => array_count_values(array_filter(array_column($call->segments, 'kind'), 'is_string')),
            'source_chars' => $call->sourceChars(),
            'max_tokens' => $agent->maxTokens,
            'timeout' => $call->timeout,
            'options' => array_keys($agent->providerOptions),
        ]);

        return RequestRejected::from($call->model->provider, $error);
    }
}
