<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Reach\StatamicTolk\Tests\Support\Agents\ProbeAgent;
use RuntimeException;
use Throwable;

final class ProviderFixtures
{
    public const array PROVIDERS = ['anthropic', 'openai', 'gemini'];

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

    public const array MODELS = [
        'anthropic' => [
            'quality' => ['id' => 'claude-sonnet-5-5', 'options' => ['thinking' => ['type' => 'between_tools']]],
            'fast' => ['id' => 'claude-haiku-4-5', 'options' => []],
        ],
        'openai' => [
            'quality' => ['id' => 'gpt-6.1-sol', 'options' => ['reasoning' => ['effort' => 'low']]],
            'fast' => ['id' => 'gpt-6-luna', 'options' => ['reasoning' => ['effort' => 'none']]],
        ],
        'gemini' => [
            'quality' => ['id' => 'gemini-3.8-flash', 'options' => ['thinking_level' => 'low']],
            'fast' => ['id' => 'gemini-3.5-flash-lite', 'options' => []],
        ],
    ];

    public const array TRANSLATIONS = [
        ['id' => 's1', 'text' => 'Καλώς ήρθατε στο Kyma Bay'],
        ['id' => 's2', 'text' => 'Η <1>θάλασσα</1> είναι ζεστή.<x1/>'],
    ];

    public static function agent(string $provider, string $tier = 'quality', array $overrides = []): ProbeAgent
    {
        $model = self::MODELS[$provider][$tier];

        return new ProbeAgent(...[
            'providerName' => $provider,
            'modelId' => $model['id'],
            'options' => $model['options'],
            ...$overrides,
        ]);
    }

    public static function response(string $provider): array
    {
        $path = dirname(__DIR__)."/Fixtures/providers/{$provider}.json";

        if (! is_file($path)) {
            throw new RuntimeException("No response fixture for [{$provider}].");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function withText(string $provider, string $text): array
    {
        $data = self::response($provider);

        switch ($provider) {
            case 'anthropic':
                $data['content'][0]['text'] = $text;
                break;
            case 'openai':
                $data['output'][1]['content'][0]['text'] = $text;
                break;
            case 'gemini':
                $data['steps'][0]['content'][0]['text'] = $text;
                break;
        }

        return $data;
    }

    public static function truncated(string $provider): array
    {
        $data = self::withText($provider, '{"translations":[{"id":"s1","text":"Καλώς ήρθα');

        switch ($provider) {
            case 'anthropic':
                $data['stop_reason'] = 'max_tokens';
                break;
            case 'openai':
                $data['status'] = 'incomplete';
                $data['incomplete_details'] = ['reason' => 'max_output_tokens'];
                $data['output'][1]['status'] = 'incomplete';
                break;
            case 'gemini':
                $data['status'] = 'incomplete';
                break;
        }

        return $data;
    }

    public static function refusal(string $provider): array
    {
        $data = self::response($provider);

        switch ($provider) {
            case 'anthropic':
                $data['content'] = [];
                $data['stop_reason'] = 'refusal';
                break;
            case 'openai':
                $data['output'][1]['content'] = [['type' => 'refusal', 'refusal' => 'I can’t help with that.']];
                break;
            case 'gemini':
                $data['status'] = 'failed';
                $data['steps'] = [];
                $data['errors'] = [['code' => 'blocked_safety', 'message' => 'The response was blocked.']];
                break;
        }

        return $data;
    }

    public static function error(string $provider, int $status, string $type, string $message, array $extra = []): array
    {
        return match ($provider) {
            'anthropic' => ['type' => 'error', 'error' => ['type' => $type, 'message' => $message, ...$extra]],
            'openai' => ['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => $type, ...$extra]],
            'gemini' => ['error' => ['code' => $status, 'message' => $message, 'status' => $type, ...$extra]],
        };
    }

    public static function fake(string $provider, array $body, int $status = 200, array $headers = []): void
    {
        Http::fake([self::HOSTS[$provider] => Http::response($body, $status, $headers)]);
    }

    public static function sentRequest(): Request
    {
        $recorded = Http::recorded();

        if ($recorded->count() !== 1) {
            throw new RuntimeException("Expected exactly one request, {$recorded->count()} were sent.");
        }

        return $recorded->first()[0];
    }

    public static function prompt(string $provider, string $tier = 'quality', array $overrides = []): Request
    {
        self::fake($provider, self::response($provider));

        self::agent($provider, $tier, $overrides)->prompt('{"segments":[]}');

        return self::sentRequest();
    }

    public static function respond(string $provider, array $body, int $status = 200, array $headers = []): StructuredAgentResponse
    {
        self::fake($provider, $body, $status, $headers);

        return self::agent($provider)->prompt('{"segments":[]}');
    }

    public static function thrown(string $provider): Throwable
    {
        try {
            self::agent($provider)->prompt('{"segments":[]}');
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
}
