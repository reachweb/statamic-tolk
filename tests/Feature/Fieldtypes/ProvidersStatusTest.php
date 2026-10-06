<?php

use Reach\StatamicTolk\Fieldtypes\ProvidersStatus;
use Statamic\CP\PublishForm;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\FieldtypeRepository;

const PROVIDERS_STATUS_KEY = 'sk-ant-api03-Status-Key-0123456789abcdef';

function providersField(): Field
{
    return new Field('providers', ['type' => 'tolk_providers', 'display' => 'Providers']);
}

it('is registered as tolk_providers and kept out of the blueprint builder', function () {
    $fieldtype = app(FieldtypeRepository::class)->find('tolk_providers');

    expect($fieldtype)->toBeInstanceOf(ProvidersStatus::class)
        ->and($fieldtype->selectable())->toBeFalse()
        ->and(providersField()->fieldtype())->toBeInstanceOf(ProvidersStatus::class);
});

it('preloads each provider\'s status, where its key comes from and the variables to set', function () {
    $this->withProviderKeys([
        'ai.providers.anthropic.key' => 'sdk-key-0123456789',
        'tolk.providers.openai.key' => 'addon-key-0123456789',
        'ai.providers.openai.key' => 'sdk-key-0123456789',
        'ai.providers.gemini.key' => '   ',
    ]);

    expect(providersField()->meta())->toBe([
        'providers' => [
            [
                'provider' => 'anthropic',
                'label' => 'Anthropic',
                'configured' => true,
                'source' => 'ai',
                'env' => ['ai' => 'ANTHROPIC_API_KEY', 'addon' => 'TOLK_ANTHROPIC_API_KEY'],
            ],
            [
                'provider' => 'openai',
                'label' => 'OpenAI',
                'configured' => true,
                'source' => 'addon',
                'env' => ['ai' => 'OPENAI_API_KEY', 'addon' => 'TOLK_OPENAI_API_KEY'],
            ],
            [
                'provider' => 'gemini',
                'label' => 'Google Gemini',
                'configured' => false,
                'source' => null,
                'env' => ['ai' => 'GEMINI_API_KEY', 'addon' => 'TOLK_GEMINI_API_KEY'],
            ],
        ],
        'fake_engine' => false,
    ]);
});

it('tells the field when the fake engine answers every call', function () {
    config(['tolk.fake_engine' => true]);

    expect(providersField()->meta()['fake_engine'])->toBeTrue();
});

it('never sends any part of a key to the form', function () {
    $this->withProviderKeys([
        'ai.providers.anthropic.key' => PROVIDERS_STATUS_KEY,
        'tolk.providers.gemini.key' => PROVIDERS_STATUS_KEY,
    ]);

    $meta = json_encode(providersField()->meta(), JSON_THROW_ON_ERROR);

    expect($meta)->not->toContain(PROVIDERS_STATUS_KEY)
        ->not->toContain(substr(PROVIDERS_STATUS_KEY, 0, 12))
        ->not->toContain(substr(PROVIDERS_STATUS_KEY, -8));
});

it('stores nothing', function (mixed $value) {
    $blueprint = Blueprint::makeFromFields(['providers' => ['type' => 'tolk_providers', 'display' => 'Providers']]);

    expect(PublishForm::make($blueprint)->submit(['providers' => $value]))->toBe(['providers' => null])
        ->and(providersField()->fieldtype()->preProcess($value))->toBeNull();
})->with([null, 'anything', [['anthropic' => 'key']]]);
