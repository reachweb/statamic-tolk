<?php

use Reach\StatamicTolk\Fieldtypes\SiteProfile;
use Statamic\CP\PublishForm;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\FieldtypeRepository;

function siteProfileField(): Field
{
    return new Field('site_profile', ['type' => 'tolk_site_profile', 'display' => 'Site profile']);
}

it('is registered as tolk_site_profile and kept out of the blueprint builder', function () {
    $fieldtype = app(FieldtypeRepository::class)->find('tolk_site_profile');

    expect($fieldtype)->toBeInstanceOf(SiteProfile::class)
        ->and($fieldtype->selectable())->toBeFalse()
        ->and(siteProfileField()->fieldtype())->toBeInstanceOf(SiteProfile::class);
});

it('gives the form a string', function (mixed $stored, string $value) {
    expect(siteProfileField()->fieldtype()->preProcess($stored))->toBe($value);
})->with([
    'a profile' => ["A walking tour company.\n\nWarm and practical.", "A walking tour company.\n\nWarm and practical."],
    'nothing' => [null, ''],
    'not a string' => [['A list'], ''],
]);

it('stores the text trimmed, and nothing for an empty field', function (mixed $value, ?string $stored) {
    $blueprint = Blueprint::makeFromFields(['site_profile' => ['type' => 'tolk_site_profile', 'display' => 'Site profile']]);

    expect(PublishForm::make($blueprint)->submit(['site_profile' => $value]))->toBe(['site_profile' => $stored]);
})->with([
    'a profile' => ["  A walking tour company.\n\nWarm and practical.\n", "A walking tour company.\n\nWarm and practical."],
    'blank' => [" \n ", null],
    'empty' => ['', null],
    'null' => [null, null],
]);
