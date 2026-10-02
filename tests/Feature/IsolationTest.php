<?php

use Illuminate\Support\Facades\File;
use Statamic\Facades\Addon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\YAML;

$written = [];

it('writes a blueprint, a role, addon settings, an entry and a state record', function () use (&$written) {
    $blueprint = $this->makeBlueprint('collections.isolation', 'isolation', ['title' => ['type' => 'text']]);

    Role::make('isolation_role')->title('Isolation')->addPermission('access cp')->save();

    $settings = Addon::get('reachweb/statamic-tolk')->settings()->set('isolation', true);
    $settings->save();

    $this->makeCollection('isolation_pages', ['en', 'el'], ['title' => ['type' => 'text']]);
    $entry = $this->makeEntry('isolation_pages', 'en', ['title' => 'Isolation']);

    $state = config('tolk.state.path').'/isolation.yaml';
    File::ensureDirectoryExists(dirname($state));
    File::put($state, YAML::dump(['reference' => 'entry::isolation']));

    $written = [
        'blueprint' => $blueprint->path(),
        'roles' => config('statamic.users.repositories.file.paths.roles'),
        'settings' => $settings->path(),
        'entry' => $entry->path(),
        'state' => $state,
    ];

    expect($written)->each->toBeFile();
});

it('starts the next test without any of them', function () use (&$written) {
    expect($written)->toHaveCount(5)->each->not->toBeFile();

    expect(Blueprint::find('collections.isolation.isolation'))->toBeNull()
        ->and(Role::find('isolation_role'))->toBeNull()
        ->and(Addon::get('reachweb/statamic-tolk')->settings()->raw())->toBe([])
        ->and(Collection::findByHandle('isolation_pages'))->toBeNull()
        ->and(Entry::query()->count())->toBe(0);
});
