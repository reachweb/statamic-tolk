<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Reach\StatamicTolk\Ai\Agents\SiteProfileAgent;

// Generating the site profile is the one route that calls a model without a body.
beforeEach(fn () => SiteProfileAgent::fake(fn () => ['profile' => 'A website.', 'terms' => []]));

/**
 * Every route of routes/cp.php: name, method, path below the CP route, the permission group that guards it, and the
 * route's parameters. Run routes name a run that does not exist.
 */
function tolkCpRoutes(): array
{
    $run = ['run' => '01MISSINGRUN'];

    return [
        'editor status' => ['tolk.editor.status', 'GET', 'tolk/editor/status', 'editor'],
        'editor plan' => ['tolk.editor.plan', 'POST', 'tolk/editor/plan', 'editor'],
        'editor review' => ['tolk.editor.review', 'POST', 'tolk/editor/review', 'editor'],
        'editor unit' => ['tolk.editor.unit', 'POST', 'tolk/editor/unit', 'editor'],
        'editor confirm' => ['tolk.editor.confirm', 'POST', 'tolk/editor/confirm', 'editor'],
        'editor baseline' => ['tolk.editor.baseline', 'POST', 'tolk/editor/baseline', 'editor'],
        'editor push' => ['tolk.editor.push', 'POST', 'tolk/editor/push', 'editor'],
        'overview' => ['tolk.overview', 'GET', 'tolk/overview', 'runs'],
        'overview scan' => ['tolk.overview.scan', 'POST', 'tolk/overview/scan', 'runs'],
        'audit' => ['tolk.audit', 'GET', 'tolk/audit', 'runs'],
        'audit localizable' => ['tolk.audit.localizable', 'POST', 'tolk/audit/localizable', 'blueprints'],
        'runs index' => ['tolk.runs.index', 'GET', 'tolk/runs', 'runs'],
        'runs store' => ['tolk.runs.store', 'POST', 'tolk/runs', 'runs'],
        'runs show' => ['tolk.runs.show', 'GET', 'tolk/runs/01MISSINGRUN', 'runs', $run],
        'runs state' => ['tolk.runs.state', 'GET', 'tolk/runs/01MISSINGRUN/state', 'runs', $run],
        'runs estimate' => ['tolk.runs.estimate', 'POST', 'tolk/runs/01MISSINGRUN/estimate', 'runs', $run],
        'runs start' => ['tolk.runs.start', 'POST', 'tolk/runs/01MISSINGRUN/start', 'runs', $run],
        'runs tick' => ['tolk.runs.tick', 'POST', 'tolk/runs/01MISSINGRUN/tick', 'runs', $run],
        'runs apply' => ['tolk.runs.apply', 'POST', 'tolk/runs/01MISSINGRUN/apply', 'runs', $run],
        'runs cancel' => ['tolk.runs.cancel', 'POST', 'tolk/runs/01MISSINGRUN/cancel', 'runs', $run],
        'runs resume' => ['tolk.runs.resume', 'POST', 'tolk/runs/01MISSINGRUN/resume', 'runs', $run],
        'runs retry' => ['tolk.runs.retry', 'POST', 'tolk/runs/01MISSINGRUN/retry', 'runs', $run],
        'runs take over' => ['tolk.runs.take-over', 'POST', 'tolk/runs/01MISSINGRUN/take-over', 'runs', $run],
        'models' => ['tolk.models.index', 'GET', 'tolk/models', 'models'],
        'providers test' => ['tolk.providers.test', 'POST', 'tolk/providers/test', 'settings'],
        'site profile generate' => ['tolk.site-profile.generate', 'POST', 'tolk/site-profile/generate', 'settings'],
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
        // `state`, `tick`, `apply` and `cancel` also let the run's creator through with `translate content`
        // (RunsControllerTest); these routes name a run nobody created.
        'runs' => [
            'allowed' => [['run bulk translations']],
            'denied' => [[], ['translate content']],
        ],
        'models' => [
            'allowed' => [['translate content'], ['run bulk translations']],
            'denied' => [[]],
        ],
        // The addon's settings ability, `editSettings` on the addon (core's AddonPolicy).
        'settings' => [
            'allowed' => [['edit reachweb/statamic-tolk settings'], ['configure addons']],
            'denied' => [[], ['translate content', 'run bulk translations']],
        ],
        // The settings ability and Statamic's permission to edit blueprints, both (§A14).
        'blueprints' => [
            'allowed' => [['edit reachweb/statamic-tolk settings', 'configure fields'], ['configure addons', 'configure fields']],
            'denied' => [[], ['edit reachweb/statamic-tolk settings'], ['configure addons', 'run bulk translations'], ['configure fields', 'run bulk translations']],
        ],
    ];
}

dataset('tolk routes', tolkCpRoutes());

function registeredTolkRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => $route->uri() === config('statamic.cp.route').'/tolk' || str_starts_with($route->uri(), config('statamic.cp.route').'/tolk/'))
        ->values()
        ->all();
}

it('resolves every route with cp_route()', function (string $name, string $method, string $path, string $group, array $parameters = []) {
    $route = Router::getRoutes()->getByName('statamic.cp.'.$name);

    expect(cp_route($name, $parameters))->toBe(url(config('statamic.cp.route').'/'.$path))
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

it('redirects an unauthenticated request to the login page', function (string $name, string $method, string $path, string $group, array $parameters = []) {
    $this->call($method, cp_route($name, $parameters))->assertRedirect(cp_route('login'));
})->with('tolk routes');

it('forbids a user without the group\'s permission', function (string $name, string $method, string $path, string $group, array $parameters = []) {
    foreach (tolkCpRouteGroups()[$group]['denied'] as $permissions) {
        $this->signInWithPermissions(['access cp', ...$permissions]);

        $this->json($method, cp_route($name, $parameters))->assertForbidden();
    }
})->with('tolk routes');

it('lets a user with the group\'s permission through', function (string $name, string $method, string $path, string $group, array $parameters = []) {
    foreach (tolkCpRouteGroups()[$group]['allowed'] as $permissions) {
        $this->signInWithPermissions(['access cp', ...$permissions]);

        expect($this->json($method, cp_route($name, $parameters))->status())->not->toBe(403)->toBeLessThan(500);
    }
})->with('tolk routes');

it('lets a super user through', function (string $name, string $method, string $path, string $group, array $parameters = []) {
    $this->signInAdmin();

    expect($this->json($method, cp_route($name, $parameters))->status())->not->toBe(403)->toBeLessThan(500);
})->with('tolk routes');
