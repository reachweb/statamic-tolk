<?php

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;

it('round-trips a model reference', function (string $reference, string $provider, string $model) {
    $ref = ModelRef::fromString($reference);

    expect($ref->provider)->toBe($provider)
        ->and($ref->model)->toBe($model)
        ->and((string) $ref)->toBe($reference)
        ->and($ref)->toEqual(new ModelRef($provider, $model));
})->with([
    ['anthropic:claude-sonnet-5-5', 'anthropic', 'claude-sonnet-5-5'],
    ['anthropic:claude-haiku-5-5', 'anthropic', 'claude-haiku-5-5'],
    ['openai:gpt-6.1-sol', 'openai', 'gpt-6.1-sol'],
    ['openai:gpt-6-luna', 'openai', 'gpt-6-luna'],
    ['gemini:gemini-3.8-flash', 'gemini', 'gemini-3.8-flash'],
    ['gemini:gemini-3.5-flash-lite', 'gemini', 'gemini-3.5-flash-lite'],
]);

it('splits on the first colon only', function () {
    $ref = ModelRef::fromString('openai:ft:gpt-6-luna:reach::abc123');

    expect($ref->provider)->toBe('openai')
        ->and($ref->model)->toBe('ft:gpt-6-luna:reach::abc123')
        ->and((string) $ref)->toBe('openai:ft:gpt-6-luna:reach::abc123');
});

it('rejects invalid references', function (string $reference) {
    expect(fn () => ModelRef::fromString($reference))
        ->toThrow(InvalidModelRef::class, "Invalid model reference [{$reference}]");
})->with([
    'empty' => [''],
    'no colon' => ['anthropic'],
    'no model' => ['anthropic:'],
    'no provider' => [':claude-sonnet-5-5'],
    'unknown provider' => ['mistral:mistral-large'],
    'provider in another case' => ['Anthropic:claude-sonnet-5-5'],
    'leading space' => [' anthropic:claude-sonnet-5-5'],
    'space in the model' => ['anthropic: claude-sonnet-5-5'],
    'other separator' => ['anthropic/claude-sonnet-5-5'],
]);

it('validates when constructed directly', function (string $provider, string $model) {
    expect(fn () => new ModelRef($provider, $model))
        ->toThrow(InvalidModelRef::class, "Invalid model reference [{$provider}:{$model}]");
})->with([
    ['cohere', 'command-a'],
    ['openai', ''],
    ['gemini', "gemini-3.8-flash\n"],
]);
