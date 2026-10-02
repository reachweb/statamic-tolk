<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Reach\StatamicTolk\Tests\Support\Agents\ProbeAgent;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

it('sends the translations schema as native structured output', function (string $provider, string $path) {
    $schema = data_get(ProviderFixtures::prompt($provider)->data(), $path);

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['translations'])
        ->and($schema['properties']['translations']['type'])->toBe('array')
        ->and($schema['properties']['translations']['items']['type'])->toBe('object')
        ->and($schema['properties']['translations']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['translations']['items']['required'])->toBe(['id', 'text'])
        ->and($schema['properties']['translations']['items']['properties'])->toBe([
            'id' => ['type' => 'string'],
            'text' => ['type' => 'string'],
        ]);
})->with([
    ['anthropic', 'output_config.format.schema'],
    ['openai', 'text.format.schema'],
    ['gemini', 'response_format.schema'],
]);

it('sends no sampling parameters', function (string $provider, string $tier) {
    $body = ProviderFixtures::prompt($provider, $tier, ['outputCap' => 4096])->data();

    foreach (['temperature', 'top_p', 'top_k'] as $key) {
        expect($body)->not->toHaveKey($key)
            ->and($body['generation_config'] ?? [])->not->toHaveKey($key);
    }
})->with(ProviderFixtures::PROVIDERS)->with(['quality', 'fast']);

it('sends every catalog model with its options and keeps the structured output format', function (string $provider, string $formatPath, ?string $optionsPath) {
    $models = config("tolk.models.{$provider}");

    ProviderFixtures::fake($provider, ProviderFixtures::response($provider));

    foreach ($models as $model) {
        (new ProbeAgent($provider, $model['id'], options: $model['options']))->prompt('{"segments":[]}');
    }

    $bodies = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();

    expect($bodies)->toHaveCount(count($models));

    foreach ($models as $index => $model) {
        $body = $bodies[$index];
        $sent = data_get($body, $optionsPath, []);

        expect($body['model'])->toBe($model['id'])
            ->and(data_get($body, "{$formatPath}.required"))->toBe(['translations'])
            ->and(Arr::only($sent, array_keys($model['options'])))->toBe($model['options']);
    }
})->with([
    ['anthropic', 'output_config.format.schema', null],
    ['openai', 'text.format.schema', null],
    ['gemini', 'response_format.schema', 'generation_config'],
]);

it('passes the agent timeout to the HTTP client and not in the body', function (string $provider) {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use ($provider, &$options) {
        $options = $requestOptions;

        return Http::response(ProviderFixtures::response($provider));
    });

    ProviderFixtures::agent($provider, 'quality', ['timeoutSeconds' => 45])->prompt('{"segments":[]}');

    expect($options['timeout'])->toBe(45)
        ->and($options['connect_timeout'])->toBe(10)
        ->and(Arr::dot(ProviderFixtures::sentRequest()->data()))->not->toHaveKey('timeout');
})->with(ProviderFixtures::PROVIDERS);

describe('anthropic', function () {
    it('sends native structured output, the output cap and the catalog options to the messages endpoint', function () {
        $request = ProviderFixtures::prompt('anthropic', 'quality', ['outputCap' => 8192]);
        $body = $request->data();

        expect($request->url())->toBe(ProviderFixtures::ENDPOINTS['anthropic'])
            ->and($request->hasHeader('x-api-key', 'test-key'))->toBeTrue()
            ->and($body['model'])->toBe('claude-sonnet-5-5')
            ->and($body['output_config'])->toBe(['format' => ProviderFixtures::anthropicFormat()])
            ->and($body['output_config']['format']['type'])->toBe('json_schema')
            ->and($body['max_tokens'])->toBe(8192)
            ->and($body['thinking'])->toBe(['type' => 'between_tools'])
            ->and($body)->not->toHaveKeys(['tools', 'tool_choice']);
    });

    it('sends the SDK default output cap of 64,000 when the agent sets none', function () {
        expect(ProviderFixtures::prompt('anthropic')->data()['max_tokens'])->toBe(64000);
    });

    it('sends no thinking and no effort for the fast model', function () {
        $body = ProviderFixtures::prompt('anthropic', 'fast')->data();

        expect($body['model'])->toBe('claude-haiku-4-5')
            ->and($body)->not->toHaveKey('thinking')
            ->and($body['output_config'])->toBe(['format' => ProviderFixtures::anthropicFormat()]);
    });

    it('loses the structured output format when a provider option sets output_config.effort alone', function () {
        $body = ProviderFixtures::prompt('anthropic', 'quality', [
            'options' => ['thinking' => ['type' => 'between_tools'], 'output_config' => ['effort' => 'low']],
        ])->data();

        expect($body['output_config'])->toBe(['effort' => 'low']);
    });

    it('sends both output_config.effort and output_config.format when the options carry both keys', function () {
        $body = ProviderFixtures::prompt('anthropic', 'quality', [
            'options' => [
                'thinking' => ['type' => 'between_tools'],
                'output_config' => ['effort' => 'low', 'format' => ProviderFixtures::anthropicFormat()],
            ],
        ])->data();

        expect($body['output_config']['effort'])->toBe('low')
            ->and($body['output_config']['format'])->toBe(ProviderFixtures::anthropicFormat())
            ->and($body['thinking'])->toBe(['type' => 'between_tools']);
    });
});

describe('openai', function () {
    it('sends a strict json schema, store false, the output cap and the reasoning effort to the responses endpoint', function () {
        $request = ProviderFixtures::prompt('openai', 'quality', ['outputCap' => 8192]);
        $body = $request->data();

        expect($request->url())->toBe(ProviderFixtures::ENDPOINTS['openai'])
            ->and($request->hasHeader('Authorization', 'Bearer test-key'))->toBeTrue()
            ->and($body['model'])->toBe('gpt-6.1-sol')
            ->and($body['text']['format']['type'])->toBe('json_schema')
            ->and($body['text']['format']['strict'])->toBeTrue()
            ->and($body['store'])->toBeFalse()
            ->and($body['reasoning'])->toBe(['effort' => 'low'])
            ->and($body['max_output_tokens'])->toBe(8192)
            ->and($body['include'])->toBe(['reasoning.encrypted_content'])
            ->and($body)->not->toHaveKeys(['tools', 'tool_choice', 'previous_response_id']);
    });

    it('sends the instructions as a system message in the input', function () {
        $body = ProviderFixtures::prompt('openai', 'quality', ['systemPrompt' => 'Translate into Greek.'])->data();

        expect($body['input'][0]['role'])->toBe('system')
            ->and($body['input'][0]['content'])->toBe('Translate into Greek.')
            ->and(collect($body['input'])->last()['role'])->toBe('user');
    });

    it('sends reasoning effort none for the fast model and no output cap when the agent sets none', function () {
        $body = ProviderFixtures::prompt('openai', 'fast')->data();

        expect($body['model'])->toBe('gpt-6-luna')
            ->and($body['reasoning'])->toBe(['effort' => 'none'])
            ->and($body)->not->toHaveKey('max_output_tokens');
    });

    it('loses the structured output format when a provider option sets another text key', function () {
        $body = ProviderFixtures::prompt('openai', 'quality', [
            'options' => ['reasoning' => ['effort' => 'low'], 'text' => ['verbosity' => 'low']],
        ])->data();

        expect($body['text'])->toBe(['verbosity' => 'low']);
    });
});

describe('gemini', function () {
    it('sends the response schema, store false, the output cap and the thinking level to the interactions endpoint', function () {
        $request = ProviderFixtures::prompt('gemini', 'quality', ['outputCap' => 8192, 'systemPrompt' => 'Translate into Greek.']);
        $body = $request->data();

        expect($request->url())->toBe(ProviderFixtures::ENDPOINTS['gemini'])
            ->and($request->hasHeader('x-goog-api-key', 'test-key'))->toBeTrue()
            ->and($body['model'])->toBe('gemini-3.8-flash')
            ->and($body['system_instruction'])->toBe('Translate into Greek.')
            ->and($body['store'])->toBeFalse()
            ->and($body['response_format']['type'])->toBe('text')
            ->and($body['response_format']['mime_type'])->toBe('application/json')
            ->and($body['generation_config'])->toBe(['max_output_tokens' => 8192, 'thinking_level' => 'low'])
            ->and($body)->not->toHaveKeys(['thinking_level', 'tools']);
    });

    it('sends no generation config for the fast model when the agent sets no output cap', function () {
        $body = ProviderFixtures::prompt('gemini', 'fast')->data();

        expect($body['model'])->toBe('gemini-3.5-flash-lite')
            ->and($body)->not->toHaveKey('generation_config');
    });

    it('flattens a nested generation_config option into the generation config', function () {
        $body = ProviderFixtures::prompt('gemini', 'quality', [
            'options' => ['generation_config' => ['thinking_level' => 'low']],
        ])->data();

        expect($body['generation_config'])->toBe(['thinking_level' => 'low']);
    });
});
