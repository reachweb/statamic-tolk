<?php

use Illuminate\Validation\ValidationException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Fieldtypes\ModelSelect;
use Statamic\CP\PublishForm;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\FieldtypeRepository;

function modelField(array $config = []): Field
{
    return new Field('translator_model', ['type' => 'tolk_model', 'display' => 'Translator model', ...$config]);
}

function submitModel(mixed $value): array
{
    $blueprint = Blueprint::makeFromFields(['translator_model' => ['type' => 'tolk_model', 'display' => 'Translator model']]);

    return PublishForm::make($blueprint)->submit(['translator_model' => $value]);
}

it('is registered as tolk_model and kept out of the blueprint builder', function () {
    $fieldtype = app(FieldtypeRepository::class)->find('tolk_model');

    expect($fieldtype)->toBeInstanceOf(ModelSelect::class)
        ->and($fieldtype->selectable())->toBeFalse()
        ->and(modelField()->fieldtype())->toBeInstanceOf(ModelSelect::class);
});

it('preloads the catalog of the three providers and marks those without a key', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'sdk-key']);

    $meta = modelField()->meta();

    expect(array_keys($meta['catalog']))->toBe(['anthropic', 'openai', 'gemini'])
        ->and($meta['catalog'])->toBe(app(ModelCatalog::class)->grouped())
        ->and($meta['catalog']['anthropic'][0])->toBe([
            'value' => 'anthropic:claude-sonnet-5-5',
            'label' => 'Claude Sonnet 5.5',
            'tier' => 'quality',
            'configured' => true,
        ])
        ->and(collect($meta['catalog']['openai'])->pluck('configured')->unique()->all())->toBe([false])
        ->and(collect($meta['catalog']['gemini'])->pluck('configured')->unique()->all())->toBe([false])
        ->and($meta['fake_engine'])->toBeFalse();
});

it('labels the automatic translator with the first configured provider\'s quality model', function () {
    expect(modelField()->meta()['automatic'])->toBe('Automatic (Claude Sonnet 5.5)');

    $this->withProviderKeys(['ai.providers.gemini.key' => 'sdk-key']);

    expect(modelField()->meta()['automatic'])->toBe('Automatic (Gemini 3.8 Flash)');
});

it('labels the automatic choice by what an empty field resolves to, not by the saved model', function () {
    $this->withSettings(['translator_model' => 'gemini:gemini-3.8-flash']);

    expect(modelField()->meta()['automatic'])->toBe('Automatic (Claude Sonnet 5.5)')
        ->and(modelField(['role' => 'reviewer'])->meta()['automatic'])->toBe('Automatic (Claude Sonnet 5.5)');
});

it('labels the automatic reviewer with another provider\'s quality model, else the translator', function () {
    expect(modelField(['role' => 'reviewer'])->meta()['automatic'])->toBe('Automatic (GPT-6.1 Sol)');

    $this->withProviderKeys(['ai.providers.anthropic.key' => 'sdk-key']);

    expect(modelField(['role' => 'reviewer'])->meta()['automatic'])->toBe('Automatic (Claude Sonnet 5.5)');
});

it('labels an empty fallback as no fallback', function () {
    expect(modelField(['role' => 'fallback'])->meta()['automatic'])->toBe('No fallback');
});

it('labels the automatic choice without a model when no provider is configured', function () {
    $this->withProviderKeys([]);

    $meta = modelField()->meta();

    expect($meta['automatic'])->toBe('Automatic')
        ->and(modelField(['role' => 'reviewer'])->meta()['automatic'])->toBe('Automatic')
        ->and(collect($meta['catalog'])->flatten(1)->pluck('configured')->unique()->all())->toBe([false]);
});

it('tells the select when the fake engine answers every call', function () {
    $this->withProviderKeys([]);
    config(['tolk.fake_engine' => true]);

    expect(modelField()->meta())
        ->fake_engine->toBeTrue()
        ->automatic->toBe('Automatic (Claude Sonnet 5.5)');
});

it('accepts an empty value or any provider:model reference', function (mixed $value, ?string $stored) {
    expect(submitModel($value))->toBe(['translator_model' => $stored]);
})->with([
    'null' => [null, null],
    'empty' => ['', null],
    'blank' => ['  ', null],
    'catalog model' => ['anthropic:claude-haiku-4-5', 'anthropic:claude-haiku-4-5'],
    'model outside the catalog' => ['openai:gpt-7', 'openai:gpt-7'],
    'padded' => [' gemini:gemini-3.8-flash ', 'gemini:gemini-3.8-flash'],
]);

it('refuses a value that is not a model reference', function (mixed $value) {
    try {
        submitModel($value);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'translator_model' => ['The Translator model must name a model as provider:model.'],
        ]);

        return;
    }

    $this->fail('The value passed validation.');
})->with([
    'no provider' => ['claude-sonnet-5-5'],
    'unknown provider' => ['mistral:large'],
    'no model' => ['anthropic:'],
    'space in the model' => ['anthropic:claude sonnet'],
    'array' => [['anthropic:claude-sonnet-5-5']],
]);

it('reads a stored reference into the form and nothing else', function () {
    $fieldtype = modelField()->fieldtype();

    expect($fieldtype->preProcess('openai:gpt-6-luna'))->toBe('openai:gpt-6-luna')
        ->and($fieldtype->preProcess(null))->toBeNull()
        ->and($fieldtype->preProcess(''))->toBeNull()
        ->and($fieldtype->preProcess(['openai:gpt-6-luna']))->toBeNull();
});
