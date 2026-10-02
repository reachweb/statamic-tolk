<?php

use Statamic\Facades\Addon;
use Statamic\Facades\Site;

it('registers the addon', function () {
    $addon = Addon::get('reachweb/statamic-tolk');

    expect($addon)->not->toBeNull()
        ->and($addon->slug())->toBe('statamic-tolk');
});

it('merges the tolk config', function () {
    expect(config('tolk'))->toBeArray();
});

it('runs with three sites', function () {
    expect(Site::all()->keys()->all())->toBe(['en', 'el', 'de'])
        ->and(Site::hasMultiple())->toBeTrue();
});
