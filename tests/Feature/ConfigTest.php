<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Tier;

/**
 * Reads config/tolk.php with only the given Tolk variables in the environment.
 */
function loadTolkConfig(array $env = []): array
{
    $put = function (string $name, ?string $server, ?string $environment, string|false $process): void {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        if ($server !== null) {
            $_SERVER[$name] = $server;
        }

        if ($environment !== null) {
            $_ENV[$name] = $environment;
        }

        if ($process !== false) {
            putenv("{$name}={$process}");
        }
    };

    $saved = [];

    foreach (['TOLK_ANTHROPIC_API_KEY', 'TOLK_OPENAI_API_KEY', 'TOLK_GEMINI_API_KEY', 'TOLK_FAKE_ENGINE'] as $name) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        $value = $env[$name] ?? null;

        $put($name, $value, $value, $value ?? false);
    }

    try {
        return require dirname(__DIR__, 2).'/config/tolk.php';
    } finally {
        foreach ($saved as $name => $values) {
            $put($name, ...$values);
        }
    }
}

/**
 * Reads Tolk's own model catalog, resources/models.php.
 */
function loadTolkModels(): array
{
    return require dirname(__DIR__, 2).'/resources/models.php';
}

it('pins every default', function () {
    expect(loadTolkConfig())->toBe([
        'providers' => [
            'anthropic' => ['key' => null, 'url' => null],
            'openai' => ['key' => null, 'url' => null],
            'gemini' => ['key' => null, 'url' => null],
        ],
        'models' => ['anthropic' => [], 'openai' => [], 'gemini' => []],
        'http' => [
            'timeout' => ['web' => 45, 'background' => 180],
        ],
        'batch' => [
            'max_chars' => ['web' => 4000, 'background' => 10000],
            'min_chars' => 1200,
            'max_segments' => 80,
        ],
        'retry' => [
            'max_attempts' => 3,
            'base_delay_ms' => 1000,
            'max_delay_ms' => 20000,
        ],
        'concurrency' => [
            'editor' => 4,
            'browser' => 2,
            'queue' => 4,
            'cli' => 4,
        ],
        'state' => [
            'driver' => 'file',
            'path' => base_path('content/tolk'),
        ],
        'runs' => [
            'driver' => 'file',
            'path' => storage_path('statamic/tolk/runs'),
            'retention_days' => 30,
            'tick_seconds' => 5,
            'claim_ttl' => 300,
            'execution' => 'auto',
            'queue' => ['connection' => null, 'name' => null],
        ],
        'usage' => [
            'path' => storage_path('statamic/tolk/usage'),
        ],
        'write' => [
            'lock_seconds' => 120,
            'wait_seconds' => 60,
        ],
        'cache_store' => null,
        'plan_ttl' => 7200,
        'fieldtypes' => [],
        'tokens' => [
            'chars_per_token' => 3,
        ],
        'fake_engine' => false,
        'fake_delay_ms' => 300,
    ]);
});

it('pins Tolk\'s model catalog', function () {
    expect(loadTolkModels())->toBe([
        'anthropic' => [
            [
                'id' => 'claude-sonnet-5-5',
                'label' => 'Claude Sonnet 5.5',
                'tier' => 'quality',
                'options' => ['thinking' => ['type' => 'between_tools']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 0,
                'price' => ['input' => 2.0, 'output' => 10.0],
            ],
            [
                'id' => 'claude-haiku-5-5',
                'label' => 'Claude Haiku 5.5',
                'tier' => 'fast',
                'options' => ['thinking' => ['type' => 'disabled']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 0,
                'price' => ['input' => 0.1, 'output' => 0.5],
            ],
        ],
        'openai' => [
            [
                'id' => 'gpt-6.1-sol',
                'label' => 'GPT-6.1 Sol',
                'tier' => 'quality',
                'options' => ['reasoning' => ['effort' => 'low']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 4000,
                'price' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.1],
            ],
            [
                'id' => 'gpt-6-luna',
                'label' => 'GPT-6 Luna',
                'tier' => 'fast',
                'options' => ['reasoning' => ['effort' => 'none']],
                'max_output_tokens' => 128000,
                'reasoning_headroom' => 0,
                'price' => ['input' => 0.1, 'output' => 0.5],
            ],
        ],
        'gemini' => [
            [
                'id' => 'gemini-3.8-flash',
                'label' => 'Gemini 3.8 Flash',
                'tier' => 'quality',
                'options' => ['thinking_level' => 'low'],
                'max_output_tokens' => 65536,
                'reasoning_headroom' => 0,
                'price' => ['input' => 1.5, 'output' => 7.5],
            ],
            [
                'id' => 'gemini-3.5-flash-lite',
                'label' => 'Gemini 3.5 Flash-Lite',
                'tier' => 'fast',
                'options' => [],
                'max_output_tokens' => 65536,
                'reasoning_headroom' => 0,
                'price' => ['input' => 0.3, 'output' => 2.5],
            ],
        ],
    ]);
});

it('reads the provider keys and the fake engine switch from the environment', function () {
    $config = loadTolkConfig([
        'TOLK_ANTHROPIC_API_KEY' => 'anthropic-key',
        'TOLK_OPENAI_API_KEY' => 'openai-key',
        'TOLK_GEMINI_API_KEY' => 'gemini-key',
        'TOLK_FAKE_ENGINE' => 'true',
    ]);

    expect(Arr::pluck($config['providers'], 'key'))->toBe(['anthropic-key', 'openai-key', 'gemini-key'])
        ->and($config['fake_engine'])->toBeTrue()
        ->and(loadTolkConfig()['fake_engine'])->toBeFalse();
});

it('merges the file into the tolk config', function () {
    $paths = ['state.path', 'runs.path', 'usage.path'];

    expect(Arr::except(Arr::dot(config('tolk')), $paths))->toBe(Arr::except(Arr::dot(loadTolkConfig()), $paths));
});

it('has a catalog entry with every key for each model', function () {
    $catalog = loadTolkModels();

    expect(array_keys($catalog))->toBe(ModelRef::PROVIDERS);

    foreach ($catalog as $provider => $models) {
        expect($models)->toBeList()
            ->and(array_unique(array_column($models, 'id')))->toHaveCount(count($models))
            ->and(array_column($models, 'tier'))->toContain('quality', 'fast');

        foreach ($models as $model) {
            expect(array_keys($model))->toBe(['id', 'label', 'tier', 'options', 'max_output_tokens', 'reasoning_headroom', 'price'])
                ->and((string) ModelRef::fromString("{$provider}:{$model['id']}"))->toBe("{$provider}:{$model['id']}")
                ->and($model['label'])->toBeString()->not->toBeEmpty()
                ->and(Tier::tryFrom($model['tier']))->not->toBeNull()
                ->and($model['options'])->toBeArray()
                ->and($model['max_output_tokens'])->toBeInt()->toBeGreaterThan(0)
                ->and($model['reasoning_headroom'])->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThan($model['max_output_tokens'])
                ->and(array_keys($model['price']))->toContain('input', 'output')
                ->and(array_diff(array_keys($model['price']), ['input', 'output', 'cache_read', 'cache_write']))->toBe([])
                ->and($model['price'])->each->toBeFloat()->toBeGreaterThan(0);
        }
    }
});

it('keeps format and sampling keys out of the catalog options', function () {
    foreach (loadTolkModels() as $models) {
        foreach ($models as $model) {
            $keys = array_map(fn (string $key) => Str::afterLast($key, '.'), array_keys(Arr::dot($model['options'])));

            expect(array_intersect(array_keys($model['options']), ['output_config', 'text', 'response_format']))->toBe([])
                ->and(array_intersect($keys, ['temperature', 'top_p', 'top_k']))->toBe([]);
        }
    }
});
