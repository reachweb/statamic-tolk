<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Facades\YAML;

/**
 * The CP pages `run bulk translations` opens, from the routes it guards: Overview, Audit and Runs.
 *
 * @return list<string>
 */
function bulkPermissionPages(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with((string) $route->getName(), 'statamic.cp.tolk.')
            && in_array('GET', $route->methods(), true)
            && in_array('can:run bulk translations', $route->gatherMiddleware(), true))
        ->map(fn (Route $route) => Str::of($route->getName())->after('statamic.cp.tolk.')->before('.')->ucfirst()->toString())
        ->unique()
        ->sort()
        ->values()
        ->all();
}

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

it('describes what each permission grants: the listing action comes with running bulk translations only', function () {
    $permissions = Permission::boot();

    expect($permissions->get('translate content')->description())->toContain('entry and term forms')->not->toContain('listing')
        ->and($permissions->get('run bulk translations')->description())->toContain('listings');
});

it('names every page and the menu that running bulk translations opens', function () {
    $description = Permission::boot()->get('run bulk translations')->description();

    expect(bulkPermissionPages())->toBe(['Audit', 'Overview', 'Runs']);

    foreach (bulkPermissionPages() as $page) {
        expect($description)->toMatch("/\\b{$page}\\b/");
    }

    expect($description)->toContain('Tools › Translations');
});

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

describe('in a queue worker', function () {
    beforeEach(function () {
        $this->asQueueWorker();
        $this->permissions = ['access cp', 'access en site'];
        Role::make('translator')->title('Translator')->addPermission([...$this->permissions, 'access de site'])->save();
        Role::make('english')->title('English')->addPermission($this->permissions)->save();
        UserGroup::make()->handle('translators')->title('Translators')->roles(['translator'])->save();
    });

    it('reads roles, groups and permissions again before each job, so a job sees what another process changed since the last', function (string $through, Closure $revoke) {
        $user = User::make()->email('translator@example.com');
        $through === 'role' ? $user->assignRole('translator') : $user->addToGroup('translators');
        $user->save();
        $german = fn () => User::find($user->id())->can('view', Site::get('de'));

        expect($german())->toBeTrue();

        $revoke($user, $this->permissions);

        expect($german())->toBeTrue();

        dispatch(static fn () => null)->onConnection('sync');

        expect($german())->toBeFalse();
    })->with([
        // Another process saved the role or the group: the YAML file changed, and this process's roles and groups did not.
        'a role loses the site' => ['role', function (UserContract $user, array $permissions) {
            file_put_contents(config('statamic.users.repositories.file.paths.roles'), YAML::dump([
                'translator' => ['title' => 'Translator', 'permissions' => $permissions],
                'english' => ['title' => 'English', 'permissions' => $permissions],
            ]));
        }],
        'a group loses the role' => ['group', function () {
            file_put_contents(config('statamic.users.repositories.file.paths.groups'), YAML::dump([
                'translators' => ['title' => 'Translators', 'roles' => ['english']],
            ]));
        }],
        // Quietly: a save runs Statamic's search listener as a job on the `sync` queue, which would read them again at once.
        'the user is given a role without the site' => ['role', fn (UserContract $user) => User::find($user->id())->explicitRoles(['english'])->saveQuietly()],
    ]);
});

it('leaves the roles and permissions of a request alone for the jobs it runs, since only a queue worker outlives its jobs', function () {
    $user = $this->signInWithPermissions(['access cp', 'translate content']);

    dispatch(static fn () => null)->onConnection('sync');

    expect(Role::find('tolk_test'))->not->toBeNull()
        ->and(User::find($user->id())->can('translate content'))->toBeTrue();
});
