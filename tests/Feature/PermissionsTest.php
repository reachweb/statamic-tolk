<?php

use Statamic\Facades\Permission;

it('registers both permissions exactly once in the Tolk group', function () {
    $values = Permission::boot()->flattened()->map->value();

    expect($values->filter(fn ($value) => $value === 'translate content'))->toHaveCount(1)
        ->and($values->filter(fn ($value) => $value === 'run bulk translations'))->toHaveCount(1);

    $group = Permission::tree()->firstWhere('handle', 'tolk');

    expect($group['label'])->toBe('Tolk')
        ->and(collect($group['permissions'])->pluck('value')->all())->toBe(['translate content', 'run bulk translations']);
});

it('gives each permission a label and a description', function (string $value, string $label) {
    $permission = Permission::boot()->get($value);

    expect($permission->group())->toBe('tolk')
        ->and($permission->label())->toBe($label)
        ->and($permission->description())->toBeString()->not->toBeEmpty();
})->with([
    ['translate content', 'Translate content'],
    ['run bulk translations', 'Run bulk translations'],
]);

it('grants the permissions through a role', function () {
    $translator = $this->signInWithPermissions(['access cp', 'translate content']);

    expect($translator->can('translate content'))->toBeTrue()
        ->and($translator->can('run bulk translations'))->toBeFalse();

    $runner = $this->signInWithPermissions(['access cp', 'translate content', 'run bulk translations']);

    expect($runner->can('translate content'))->toBeTrue()
        ->and($runner->can('run bulk translations'))->toBeTrue();
});

it('denies the permissions to a user without them and grants them to a super user', function () {
    $user = $this->signInUserWithoutPermissions();

    expect($user->can('translate content'))->toBeFalse()
        ->and($user->can('run bulk translations'))->toBeFalse();

    $admin = $this->signInAdmin();

    expect($admin->can('translate content'))->toBeTrue()
        ->and($admin->can('run bulk translations'))->toBeTrue();
});
