<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Tests\Support\Agents\ProbeAgent;
use RuntimeException;
use Throwable;

final class ProviderFixtures
{
    public const array PROVIDERS = ModelRef::PROVIDERS;

    public const array HOSTS = [
        'anthropic' => 'api.anthropic.com/*',
        'openai' => 'api.openai.com/*',
        'gemini' => 'generativelanguage.googleapis.com/*',
    ];

    public const array ENDPOINTS = [
        'anthropic' => 'https://api.anthropic.com/v1/messages',
        'openai' => 'https://api.openai.com/v1/responses',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/interactions',
    ];

    private const array TEXT_PATHS = [
        'anthropic' => 'content.0.text',
        'openai' => 'output.1.content.0.text',
        'gemini' => 'steps.0.content.0.text',
    ];

    public const array TRANSLATIONS = [
        ['id' => 's1', 'text' => 'Καλώς ήρθατε στο Kyma Bay'],
        ['id' => 's2', 'text' => 'Η <1>θάλασσα</1> είναι ζεστή.<x1/>'],
    ];

    /**
     * The first catalog model of the tier.
     */
    public static function model(string $provider, string $tier = 'quality'): array
    {
        $catalog = app(ModelCatalog::class);

        return $catalog->find($catalog->default($provider, Tier::from($tier)));
    }

    public static function agent(string $provider, string $tier = 'quality', array $overrides = []): ProbeAgent
    {
        $model = self::model($provider, $tier);

        return new ProbeAgent(...[
            'providerName' => $provider,
            'modelId' => $model['id'],
            'options' => $model['options'],
            ...$overrides,
        ]);
    }

    public static function response(string $provider): array
    {
        return File::json(dirname(__DIR__)."/Fixtures/providers/{$provider}.json", JSON_THROW_ON_ERROR);
    }

    public static function withText(string $provider, string $text): array
    {
        return self::patch(self::response($provider), [self::TEXT_PATHS[$provider] => $text]);
    }

    public static function truncated(string $provider): array
    {
        return self::patch(self::withText($provider, '{"translations":[{"id":"s1","text":"Καλώς ήρθα'), match ($provider) {
            'anthropic' => ['stop_reason' => 'max_tokens'],
            'openai' => [
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output.1.status' => 'incomplete',
            ],
            'gemini' => ['status' => 'incomplete'],
        });
    }

    public static function refusal(string $provider): array
    {
        return self::patch(self::response($provider), match ($provider) {
            'anthropic' => ['content' => [], 'stop_reason' => 'refusal'],
            'openai' => ['output.1.content' => [['type' => 'refusal', 'refusal' => 'I can’t help with that.']]],
            'gemini' => [
                'status' => 'failed',
                'steps' => [],
                'errors' => [['code' => 'blocked_safety', 'message' => 'The response was blocked.']],
            ],
        });
    }

    public static function error(string $provider, int $status, string $type, string $message, array $extra = []): array
    {
        return match ($provider) {
            'anthropic' => ['type' => 'error', 'error' => ['type' => $type, 'message' => $message, ...$extra]],
            'openai' => ['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => $type, ...$extra]],
            'gemini' => ['error' => ['code' => $status, 'message' => $message, 'status' => $type, ...$extra]],
        };
    }

    /**
     * A Gemini error as Google sends it: a status, an ErrorInfo reason in `details`, and the whole body in a JSON list,
     * which is what the Interactions endpoint answered a made-up key with (HTTP 400, INVALID_ARGUMENT, API_KEY_INVALID).
     */
    public static function geminiError(int $status, string $type, string $message, ?string $reason = null): array
    {
        $details = $reason === null ? [] : [[
            '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
            'reason' => $reason,
            'domain' => 'googleapis.com',
            'metadata' => ['service' => 'generativelanguage.googleapis.com'],
        ]];

        return [['error' => [
            'code' => $status,
            'message' => $message,
            'status' => $type,
            'details' => [...$details, ['@type' => 'type.googleapis.com/google.rpc.LocalizedMessage', 'locale' => 'en-US', 'message' => $message]],
        ]]];
    }

    /**
     * Google's answer to a wrong or revoked key.
     */
    public static function geminiInvalidKey(string $message = 'API key not valid. Please pass a valid API key.', string $reason = 'API_KEY_INVALID'): array
    {
        return self::geminiError(400, 'INVALID_ARGUMENT', $message, $reason);
    }

    /**
     * Google's answer to a used-up Gemini quota (HTTP 429, RESOURCE_EXHAUSTED, no retry-after header), as developers
     * posted it in 2025 and 2026, in the list the Interactions endpoint wraps Google's errors in: the violated quota in a
     * QuotaFailure, whose id names its window (PerMinute or PerDay), a Help link, and a RetryInfo delay. The defaults
     * are a free tier's daily request quota, which still comes with a delay of one second.
     */
    public static function geminiQuota(
        string $quotaId = 'GenerateRequestsPerDayPerProjectPerModel-FreeTier',
        string $quotaValue = '250',
        string $retryDelay = '1s',
        string $metric = 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
    ): array {
        $message = 'You exceeded your current quota, please check your plan and billing details. For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits.';

        return [['error' => [
            'code' => 429,
            'message' => "{$message}\n* Quota exceeded for metric: {$metric}, limit: {$quotaValue}, model: gemini-3.8-flash\nPlease retry in {$retryDelay}.",
            'status' => 'RESOURCE_EXHAUSTED',
            'details' => [
                [
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaMetric' => $metric,
                        'quotaId' => $quotaId,
                        'quotaDimensions' => ['location' => 'global', 'model' => 'gemini-3.8-flash'],
                        'quotaValue' => $quotaValue,
                    ]],
                ],
                [
                    '@type' => 'type.googleapis.com/google.rpc.Help',
                    'links' => [['description' => 'Learn more about Gemini API quotas', 'url' => 'https://ai.google.dev/gemini-api/docs/rate-limits']],
                ],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay],
            ],
        ]]];
    }

    /**
     * A per-minute Gemini quota on a paid tier: the delay is when the window has room again.
     */
    public static function geminiRateLimit(string $retryDelay = '40s'): array
    {
        return self::geminiQuota('GenerateContentPaidTierInputTokensPerModelPerMinute', '1000000', $retryDelay, 'generativelanguage.googleapis.com/generate_content_paid_tier_input_token_count');
    }

    /**
     * An error in the format Google documents for the Interactions API: a snake_case code and a message, such as
     * `quota_exceeded` ("You have exceeded your daily quota.") or `rate_limit_exceeded` for a 429.
     */
    public static function geminiInteractionsError(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];
    }

    public static function fake(string $provider, array $body, int $status = 200, array $headers = []): void
    {
        Http::fake([self::HOSTS[$provider] => Http::response($body, $status, $headers)]);
    }

    public static function sentRequest(): Request
    {
        return Http::recorded()->sole()[0];
    }

    public static function prompt(string $provider, string $tier = 'quality', array $overrides = []): Request
    {
        self::fake($provider, self::response($provider));

        self::send($provider, $tier, $overrides);

        return self::sentRequest();
    }

    public static function respond(string $provider, ?array $body = null): StructuredAgentResponse
    {
        self::fake($provider, $body ?? self::response($provider));

        return self::send($provider);
    }

    public static function thrown(string $provider): Throwable
    {
        try {
            self::send($provider);
        } catch (Throwable $exception) {
            return $exception;
        }

        throw new RuntimeException('The prompt did not throw.');
    }

    public static function failure(string $provider, int $status, string $type, string $message, array $headers = [], array $extra = []): Throwable
    {
        self::fake($provider, self::error($provider, $status, $type, $message, $extra), $status, $headers);

        return self::thrown($provider);
    }

    public static function anthropicFormat(): array
    {
        $schema = self::agent('anthropic')->schema(new JsonSchemaTypeFactory);

        return [
            'type' => 'json_schema',
            'schema' => AnthropicSchemaSanitizer::sanitize((new ObjectSchema($schema))->toSchema()),
        ];
    }

    private static function send(string $provider, string $tier = 'quality', array $overrides = []): StructuredAgentResponse
    {
        return self::agent($provider, $tier, $overrides)->prompt('{"segments":[]}');
    }

    private static function patch(array $data, array $changes): array
    {
        foreach ($changes as $path => $value) {
            data_set($data, $path, $value);
        }

        return $data;
    }
}
