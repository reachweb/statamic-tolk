<?php

use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Translation\PlanOptions;

function optionsFromSettings(array $overrides = [], ?Settings $settings = null): PlanOptions
{
    return PlanOptions::fromSettings($settings ?? app(Settings::class), app(ModelCatalog::class), $overrides);
}

it('defaults to the quality model of the first configured provider', function () {
    $options = optionsFromSettings();

    expect($options->translator)->toEqual(ModelRef::fromString('anthropic:claude-sonnet-5-5'))
        ->and($options->mode)->toBe(Mode::Missing)
        ->and($options->overwriteManual)->toBeFalse()
        ->and($options->fields)->toBeNull()
        ->and($options->skip)->toBe([])
        ->and($options->review)->toBeFalse()
        ->and($options->reviewer)->toBeNull()
        ->and($options->fallback)->toBeNull()
        ->and($options->profile)->toBe('web')
        ->and($options->strict)->toBeFalse()
        ->and($options->userId)->toBeNull();
});

it('fills the models and strict from the settings', function () {
    $settings = $this->withSettings([
        'translator_model' => 'openai:gpt-6-luna',
        'fallback_model' => 'gemini:gemini-3.8-flash',
        'strict_fields' => true,
    ]);

    $options = optionsFromSettings(settings: $settings);

    expect((string) $options->translator)->toBe('openai:gpt-6-luna')
        ->and((string) $options->fallback)->toBe('gemini:gemini-3.8-flash')
        ->and($options->strict)->toBeTrue();
});

it('drops a fallback that equals the translator or has no key', function () {
    $same = $this->withSettings(['translator_model' => 'openai:gpt-6-luna', 'fallback_model' => 'openai:gpt-6-luna']);

    expect(optionsFromSettings(settings: $same)->fallback)->toBeNull();

    $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
    $withoutKey = $this->withSettings(['fallback_model' => 'gemini:gemini-3.8-flash']);

    expect(optionsFromSettings(settings: $withoutKey)->fallback)->toBeNull();
});

it('lets overrides win over the settings, with model references and the mode as strings', function () {
    $settings = $this->withSettings(['translator_model' => 'openai:gpt-6-luna', 'fallback_model' => 'gemini:gemini-3.8-flash', 'strict_fields' => true]);

    $options = optionsFromSettings([
        'mode' => 'update',
        'overwriteManual' => true,
        'fields' => ['title'],
        'skip' => ['intro' => 'edited_in_form'],
        'translator' => 'gemini:gemini-3.5-flash-lite',
        'fallback' => ModelRef::fromString('anthropic:claude-haiku-4-5'),
        'profile' => 'background',
        'strict' => false,
        'userId' => 'user-1',
    ], $settings);

    expect($options->mode)->toBe(Mode::Update)
        ->and($options->overwriteManual)->toBeTrue()
        ->and($options->fields)->toBe(['title'])
        ->and($options->skip)->toBe(['intro' => 'edited_in_form'])
        ->and((string) $options->translator)->toBe('gemini:gemini-3.5-flash-lite')
        ->and((string) $options->fallback)->toBe('anthropic:claude-haiku-4-5')
        ->and($options->profile)->toBe('background')
        ->and($options->strict)->toBeFalse()
        ->and($options->userId)->toBe('user-1');
});

it('reads a null override as the default', function () {
    $settings = $this->withSettings(['translator_model' => 'openai:gpt-6-luna', 'strict_fields' => true]);

    $options = optionsFromSettings(['mode' => null, 'translator' => null, 'strict' => null, 'profile' => null], $settings);

    expect((string) $options->translator)->toBe('openai:gpt-6-luna')
        ->and($options->mode)->toBe(Mode::Missing)
        ->and($options->strict)->toBeTrue()
        ->and($options->profile)->toBe('web');
});

it('resolves a reviewer only when review is on', function () {
    expect(optionsFromSettings(['reviewer' => 'gemini:gemini-3.8-flash'])->reviewer)->toBeNull()
        ->and((string) optionsFromSettings(['review' => true])->reviewer)->toBe('openai:gpt-6.1-sol')
        ->and((string) optionsFromSettings(['review' => true, 'reviewer' => 'gemini:gemini-3.8-flash'])->reviewer)->toBe('gemini:gemini-3.8-flash');
});

it('always has a reviewer in mode review, which runs only the reviewer', function () {
    expect(optionsFromSettings(['mode' => 'review'])->review)->toBeTrue()
        ->and((string) optionsFromSettings(['mode' => Mode::Review])->reviewer)->toBe('openai:gpt-6.1-sol')
        ->and((string) optionsFromSettings(['mode' => 'review', 'review' => false, 'reviewer' => 'gemini:gemini-3.8-flash'])->reviewer)->toBe('gemini:gemini-3.8-flash');
});

it('reviews when the settings say so, unless overridden', function () {
    $settings = $this->withSettings(['review_enabled' => true]);

    expect(optionsFromSettings(settings: $settings)->review)->toBeTrue()
        ->and((string) optionsFromSettings(settings: $settings)->reviewer)->toBe('openai:gpt-6.1-sol')
        ->and(optionsFromSettings(['review' => false], $settings)->review)->toBeFalse()
        ->and(optionsFromSettings(['review' => false], $settings)->reviewer)->toBeNull()
        ->and(optionsFromSettings(['review' => null], $settings)->review)->toBeTrue();
});

it('refuses invalid options', function (array $overrides, string $exception) {
    expect(fn () => optionsFromSettings($overrides))->toThrow($exception);
})->with([
    'unknown profile' => [['profile' => 'cli'], InvalidArgumentException::class],
    'fields that are not a list' => [['fields' => ['title' => true]], InvalidArgumentException::class],
    'a field that is not a handle' => [['fields' => ['title', 3]], InvalidArgumentException::class],
    'a skip without a reason' => [['skip' => ['title']], InvalidArgumentException::class],
    'an empty skip reason' => [['skip' => ['title' => '']], InvalidArgumentException::class],
    'unknown mode' => [['mode' => 'everything'], ValueError::class],
    'invalid model reference' => [['translator' => 'claude'], InvalidModelRef::class],
    'unknown option' => [['overwrite_manual' => true], Error::class],
]);

it('needs a translator', function () {
    $this->withProviderKeys();

    expect(fn () => optionsFromSettings())->toThrow(NoProviderConfigured::class);
});

it('survives toArray() and fromArray()', function () {
    $options = optionsFromSettings([
        'mode' => Mode::All,
        'overwriteManual' => true,
        'fields' => ['title', 'intro'],
        'skip' => ['body' => 'edited_in_form'],
        'review' => true,
        'fallback' => 'gemini:gemini-3.8-flash',
        'profile' => 'background',
        'strict' => true,
        'userId' => 'user-1',
    ]);

    expect(PlanOptions::fromArray($options->toArray()))->toEqual($options)
        ->and(PlanOptions::fromArray(optionsFromSettings()->toArray()))->toEqual(optionsFromSettings())
        ->and($options->toArray())->toBe([
            'mode' => 'all',
            'overwrite_manual' => true,
            'fields' => ['title', 'intro'],
            'skip' => ['body' => 'edited_in_form'],
            'translator' => 'anthropic:claude-sonnet-5-5',
            'review' => true,
            'reviewer' => 'openai:gpt-6.1-sol',
            'fallback' => 'gemini:gemini-3.8-flash',
            'profile' => 'background',
            'strict' => true,
            'user_id' => 'user-1',
        ]);
});
