<?php

use Reach\StatamicTolk\Support\SiteSetup;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;

beforeEach(function () {
    $this->setup = app(SiteSetup::class);
});

describe('collections and taxonomies', function () {
    it('lists those that are not enabled for the site, except the ones excluded in the settings', function () {
        $this->makeCollection('articles', ['en', 'el', 'de'], []);
        $this->makeCollection('news', ['en', 'el'], []);
        $this->makeCollection('notes', ['en'], []);
        $this->makeTaxonomy('topics', ['en', 'el'], []);
        $this->makeTaxonomy('colours', ['en'], []);
        $this->withSettings(['excluded_collections' => ['notes'], 'excluded_taxonomies' => ['colours']]);

        expect($this->setup->containersWithout('de'))->toBe([
            ['type' => 'collection', 'handle' => 'news', 'name' => 'Collection News'],
            ['type' => 'taxonomy', 'handle' => 'topics', 'name' => 'Taxonomy Topics'],
        ]);
    });

    it('enables the site after the sites a collection or taxonomy has, once', function () {
        $this->makeCollection('news', ['en', 'el'], []);
        $this->makeTaxonomy('topics', ['el', 'en'], []);

        $this->setup->enable('collection', 'news', 'de');
        $this->setup->enable('collection', 'news', 'de');
        $this->setup->enable('taxonomy', 'topics', 'de');

        expect(Collection::find('news')->sites()->all())->toBe(['en', 'el', 'de'])
            ->and(Taxonomy::find('topics')->sites()->all())->toBe(['el', 'en', 'de'])
            ->and($this->setup->containersWithout('de'))->toBe([]);
    });
});

describe('global sets', function () {
    it('lists a set that is not enabled for the site, translated from the default site', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], [], []);

        expect($this->setup->globalSetsWithout('de', null))->toBe([
            ['handle' => 'site_settings', 'name' => 'Global set Site Settings', 'enabled' => false, 'origin' => 'en'],
        ]);
    });

    it('lists a set that names no origin for the site, translated from the --from site', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => null], [], []);

        expect($this->setup->globalSetsWithout('de', 'el'))->toBe([
            ['handle' => 'site_settings', 'name' => 'Global set Site Settings', 'enabled' => true, 'origin' => 'el'],
        ]);
    });

    it('leaves out a set that translates the site, and one whose site is the origin of another', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'de' => 'en'], [], []);
        $this->makeGlobalSet('footer', ['de' => null, 'en' => 'de'], [], []);

        expect($this->setup->globalSetsWithout('de', null))->toBe([]);
    });

    it('lists a site whose origin the set does not have', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'de' => 'el'], [], []);

        expect($this->setup->globalSetsWithout('de', null)[0])->toMatchArray(['enabled' => true, 'origin' => 'en']);
    });

    it('lists a site whose origin the set does not have though another is translated from it, and never offers a site translated from it', function () {
        $this->makeGlobalSet('site_settings', ['de' => 'fr', 'el' => 'de'], [], []);
        $this->makeGlobalSet('footer', ['en' => 'de', 'de' => 'fr', 'el' => 'en'], [], []);

        expect($this->setup->globalSetsWithout('de', 'el'))->toBe([
            ['handle' => 'site_settings', 'name' => 'Global set Site Settings', 'enabled' => true, 'origin' => null],
            ['handle' => 'footer', 'name' => 'Global set Footer', 'enabled' => true, 'origin' => null],
        ]);
    });

    it('translates from the set\'s first other site when it does not have the preferred one, and from none when it has no other', function () {
        $this->makeGlobalSet('greek', ['el' => null], [], []);
        $this->makeGlobalSet('german', ['de' => null], [], []);

        expect($this->setup->globalSetsWithout('de', 'en'))->toBe([
            ['handle' => 'greek', 'name' => 'Global set Greek', 'enabled' => false, 'origin' => 'el'],
            ['handle' => 'german', 'name' => 'Global set German', 'enabled' => true, 'origin' => null],
        ]);
    });

    it('enables the site with its origin, or names the origin of a site the set has', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], [], []);
        $this->makeGlobalSet('footer', ['en' => null, 'de' => null], [], []);

        $this->setup->enableGlobalSet('site_settings', 'de', 'el');
        $this->setup->enableGlobalSet('footer', 'de', 'en');

        expect(GlobalSet::find('site_settings')->origins()->all())->toBe(['en' => null, 'el' => 'en', 'de' => 'el'])
            ->and(GlobalSet::find('footer')->origins()->all())->toBe(['en' => null, 'de' => 'en'])
            ->and(GlobalSet::find('site_settings')->in('de'))->not->toBeNull()
            ->and($this->setup->globalSetsWithout('de', null))->toBe([]);
    });
});

it('lists the collections enabled for the site whose route is set per site but not for it', function () {
    Collection::make('articles')->sites(['en', 'el', 'de'])->routes(['en' => '/articles/{slug}', 'el' => '/arthra/{slug}'])->save();
    Collection::make('pages')->sites(['en', 'el', 'de'])->routes('/{slug}')->save();
    Collection::make('snippets')->sites(['en', 'el', 'de'])->save();
    Collection::make('news')->sites(['en', 'el'])->routes(['en' => '/news/{slug}'])->save();
    Collection::make('notes')->sites(['en', 'de'])->routes(['en' => '/notes/{slug}'])->save();
    $this->withSettings(['excluded_collections' => ['notes']]);

    expect($this->setup->collectionsWithoutRoute('de'))->toBe([['handle' => 'articles', 'name' => 'Collection Articles']]);
});
