<?php

use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicTolk\ServiceProvider;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\CP\Navigation\NavBuilder;
use Statamic\Facades\CP\Nav;

beforeEach(function () {
    $this->useFileCache();
    $this->makeCollection('articles', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);

    // AddonTestCase replaces the nav with a mock that builds nothing; build the real one with the addon's items.
    Nav::swap(new Navigation);
    (fn () => $this->registerNavigation())->call(app()->getProvider(ServiceProvider::class));
});

/**
 * The children of the Translations item as the CP receives them for a page, and whether each is active: the sidebar
 * marks it, and the breadcrumb names the first one.
 *
 * @return array<string, bool>
 */
function translationsChildren(string $url): array
{
    $children = null;

    test()->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$children) {
        $item = collect($page->toArray()['props']['_statamic']['nav'])
            ->flatMap(fn (array $section) => $section['items'])
            ->firstWhere('display', 'Translations');

        $children = collect($item['children'])->pluck('active', 'display')->all();
    });

    return $children;
}

it('marks only the page shown as the active child of Translations', function (Closure $url, string $active) {
    $this->signInAdmin();

    expect(translationsChildren($url()))->toBe(['Overview' => $active === 'Overview', 'Runs' => $active === 'Runs', 'Audit' => $active === 'Audit', 'Settings' => $active === 'Settings']);
})->with([
    'overview' => [fn () => cp_route('tolk.overview'), 'Overview'],
    'runs' => [fn () => cp_route('tolk.runs.index'), 'Runs'],
    'a run' => [fn () => cp_route('tolk.runs.show', RunFixtures::pending()->id), 'Runs'],
    'audit' => [fn () => cp_route('tolk.audit'), 'Audit'],
    'settings' => [fn () => cp_route('addons.settings.edit', 'statamic-tolk'), 'Settings'],
]);

it('lists Settings for a user who may edit the settings', function () {
    $this->signInWithPermissions(['access cp', 'run bulk translations', 'edit reachweb/statamic-tolk settings']);

    expect(array_keys(translationsChildren(cp_route('tolk.overview'))))->toBe(['Overview', 'Runs', 'Audit', 'Settings']);
});

it('leaves Settings out for a user who may not edit the settings', function () {
    $this->signInWithPermissions(['access cp', 'run bulk translations']);

    expect(array_keys(translationsChildren(cp_route('tolk.overview'))))->toBe(['Overview', 'Runs', 'Audit']);
});

// Statamic caches the nav's URLs until its nav preferences change; a host whose cache was built before Tolk was added
// matches Tolk's pages by their URLs' prefixes.
it('marks the right child when the cached nav URLs predate the addon', function () {
    $this->signInAdmin();
    $this->get(cp_route('tolk.overview'))->assertOk();
    Cache::put(NavBuilder::ALL_URLS_CACHE_KEY, Cache::get(NavBuilder::ALL_URLS_CACHE_KEY)->reject(fn (string $url) => str_contains($url, '/tolk')));

    expect(translationsChildren(cp_route('tolk.runs.index')))->toBe(['Overview' => false, 'Runs' => true, 'Audit' => false, 'Settings' => false])
        ->and(translationsChildren(cp_route('tolk.audit')))->toBe(['Overview' => false, 'Runs' => false, 'Audit' => true, 'Settings' => false]);
});
