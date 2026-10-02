<?php

use Statamic\Facades\GlobalSet;
use Statamic\Facades\YAML;
use Statamic\Globals\Variables;

beforeEach(function () {
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], [
        'tagline' => ['type' => 'text', 'localizable' => true],
        'phone' => ['type' => 'text'],
    ], [
        'en' => ['tagline' => 'By the sea', 'phone' => '+30 1234'],
    ]);
});

it('returns variables with their origin for an enabled site and null for a site that is not enabled', function () {
    $set = GlobalSet::findByHandle('site_settings');

    expect($set->in('el'))->toBeInstanceOf(Variables::class)
        ->and($set->in('el')->hasOrigin())->toBeTrue()
        ->and($set->in('el')->origin()->locale())->toBe('en')
        ->and($set->in('en')->hasOrigin())->toBeFalse()
        ->and($set->in('de'))->toBeNull()
        ->and($set->origins()->all())->toBe(['en' => null, 'el' => 'en']);
});

it('creates the variables of every site on save, and makes unsaved ones for a site enabled since', function () {
    $set = GlobalSet::findByHandle('site_settings');

    expect($set->localizations()->keys()->all())->toBe(['en', 'el']);

    $set->sites(['en' => null, 'el' => 'en', 'de' => 'en']);

    expect($set->in('de'))->toBeInstanceOf(Variables::class)
        ->and($set->in('de')->id())->toBe('site_settings::de')
        ->and($set->in('de')->origin()->locale())->toBe('en')
        ->and($set->localizations()->has('de'))->toBeFalse();
});

it('persists merge() and save() and leaves inherited keys out of has()', function () {
    GlobalSet::findByHandle('site_settings')->in('el')->merge(['tagline' => 'Δίπλα στη θάλασσα'])->save();

    $variables = GlobalSet::findByHandle('site_settings')->in('el');

    expect($variables->data()->all())->toBe(['tagline' => 'Δίπλα στη θάλασσα'])
        ->and($variables->has('tagline'))->toBeTrue()
        ->and($variables->has('phone'))->toBeFalse()
        ->and($variables->get('phone'))->toBeNull()
        ->and($variables->value('phone'))->toBe('+30 1234');
});

it('keeps an explicit null in localized variables and drops it from origin variables', function () {
    $set = GlobalSet::findByHandle('site_settings');
    $set->in('el')->merge(['tagline' => null])->save();
    $set->in('en')->merge(['phone' => null])->save();

    $set = GlobalSet::findByHandle('site_settings');

    expect($set->in('el')->has('tagline'))->toBeTrue()
        ->and($set->in('el')->value('tagline'))->toBeNull()
        ->and(YAML::parse($set->in('el')->fileContents()))->toBe(['tagline' => null])
        ->and(YAML::parse($set->in('en')->fileContents()))->toBe(['tagline' => 'By the sea']);
});
