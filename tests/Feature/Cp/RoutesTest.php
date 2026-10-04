<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Every route of routes/cp.php: name, method, path below the CP route, and the permission group that guards it.
 */
function tolkCpRoutes(): array
{
    return [
        'editor status' => ['tolk.editor.status', 'GET', 'tolk/editor/status', 'editor'],
        'editor plan' => ['tolk.editor.plan', 'POST', 'tolk/editor/plan', 'editor'],
        'editor unit' => ['tolk.editor.unit', 'POST', 'tolk/editor/unit', 'editor'],
        'editor confirm' => ['tolk.editor.confirm', 'POST', 'tolk/editor/confirm', 'editor'],
        'editor baseline' => ['tolk.editor.baseline', 'POST', 'tolk/editor/baseline', 'editor'],
        'models' => ['tolk.models.index', 'GET', 'tolk/models', 'models'],
    ];
}

/**
 * Per group (§A14): permission sets, besides `access cp`, that may call it, and sets that may not.
 */
function tolkCpRouteGroups(): array
{
    return [
        'editor' => [
            'allowed' => [['translate content']],
            'denied' => [[], ['run bulk translations']],
        ],
        'models' => [
            'allowed' => [['translate content'], ['run bulk translations']],
            'denied' => [[]],
        ],
    ];
}

dataset('tolk routes', tolkCpRoutes());

function registeredTolkRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with($route->uri(), config('statamic.cp.route').'/tolk/'))
        ->values()
        ->all();
}

it('resolves every route with cp_route()', function (string $name, string $method, string $path) {
    $route = Router::getRoutes()->getByName('statamic.cp.'.$name);

    expect(cp_route($name))->toBe(url(config('statamic.cp.route').'/'.$path))
        ->and($route->methods())->toContain($method)
        ->and($route->getControllerClass())->toStartWith('Reach\\StatamicTolk\\Http\\Controllers\\Cp\\');
})->with('tolk routes');

it('names every registered route and has a case for each', function () {
    $registered = collect(registeredTolkRoutes())->map(fn (Route $route) => $route->getName());

    expect($registered->filter()->sort()->values()->all())
        ->toBe(collect(tolkCpRoutes())->map(fn (array $case) => 'statamic.cp.'.$case[0])->sort()->values()->all())
        ->and($registered->filter())->toHaveCount($registered->count());
});

it('registers every route inside the authenticated CP group', function () {
    foreach (registeredTolkRoutes() as $route) {
        expect($route->gatherMiddleware())->toContain('statamic.cp.authenticated');
    }
});

it('redirects an unauthenticated request to the login page', function (string $name, string $method) {
    $this->call($method, cp_route($name))->assertRedirect(cp_route('login'));
})->with('tolk routes');

it('forbids a user without the group\'s permission', function (string $name, string $method, string $path, string $group) {
    foreach (tolkCpRouteGroups()[$group]['denied'] as $permissions) {
        $this->signInWithPermissions(['access cp', ...$permissions]);

        $this->json($method, cp_route($name))->assertForbidden();
    }
})->with('tolk routes');

it('lets a user with the group\'s permission through', function (string $name, string $method, string $path, string $group) {
    foreach (tolkCpRouteGroups()[$group]['allowed'] as $permissions) {
        $this->signInWithPermissions(['access cp', ...$permissions]);

        expect($this->json($method, cp_route($name))->status())->not->toBe(403)->toBeLessThan(500);
    }
})->with('tolk routes');

it('lets a super user through', function (string $name, string $method) {
    $this->signInAdmin();

    expect($this->json($method, cp_route($name))->status())->not->toBe(403)->toBeLessThan(500);
})->with('tolk routes');
