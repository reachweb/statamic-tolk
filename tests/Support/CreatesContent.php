<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Taxonomies\Taxonomy as TaxonomyContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Blueprint as BlueprintObject;

trait CreatesContent
{
    /**
     * Fields are `handle => field config`; an entry with a numeric key is
     * passed through as-is (for example `['import' => 'seo']`).
     */
    protected function makeBlueprint(string $namespace, string $handle, array $fields): BlueprintObject
    {
        $definitions = collect($fields)
            ->map(fn (array $field, int|string $key) => is_int($key) ? $field : ['handle' => $key, 'field' => $field])
            ->values()
            ->all();

        $blueprint = Blueprint::make($handle)
            ->setNamespace($namespace)
            ->setContents(['tabs' => ['main' => ['sections' => [['fields' => $definitions]]]]]);

        $blueprint->save();

        return $blueprint;
    }

    protected function makeCollection(string $handle, array $sites, array $fields, bool $structured = false): CollectionContract
    {
        $collection = Collection::make($handle)
            ->sites($sites)
            ->routes($structured ? '{parent_uri}/{slug}' : '/'.$handle.'/{slug}');

        if ($structured) {
            $collection->structureContents(['root' => false]);
        }

        $collection->save();

        $this->makeBlueprint("collections.{$handle}", Str::singular($handle), $fields);

        return $collection;
    }

    protected function makeEntry(string $collection, string $site, array $data, ?string $slug = null): EntryContract
    {
        $entry = Entry::make()
            ->collection($collection)
            ->locale($site)
            ->slug($slug ?? Str::slug($data['title'] ?? Str::random(8)))
            ->data($data);

        $entry->save();

        return $entry;
    }

    protected function localize(EntryContract $origin, string $site, array $data = []): EntryContract
    {
        $localization = $origin->makeLocalization($site)->merge($data);

        $localization->save();

        return $localization;
    }

    protected function makeTaxonomy(string $handle, array $sites, array $fields = []): TaxonomyContract
    {
        $taxonomy = Taxonomy::make($handle)->sites($sites);

        $taxonomy->save();

        $this->makeBlueprint("taxonomies.{$handle}", Str::singular($handle), $fields);

        return $taxonomy;
    }

    /**
     * `$dataBySite` holds the data of the taxonomy's first site (the origin)
     * and the overrides of every other site, keyed by site handle.
     */
    protected function makeTerm(string $taxonomy, string $slug, array $dataBySite): TermContract
    {
        $term = Term::make($slug)->taxonomy($taxonomy);

        foreach ($dataBySite as $site => $data) {
            $term->dataForLocale($site, $data);
        }

        $term->save();

        return $term;
    }

    /**
     * `$siteOrigins` maps each site to its origin site, or to null for a site
     * without an origin, for example `['en' => null, 'el' => 'en']`.
     */
    protected function makeGlobalSet(string $handle, array $siteOrigins, array $fields, array $valuesBySite): GlobalSetContract
    {
        $this->makeBlueprint('globals', $handle, $fields);

        $set = GlobalSet::make($handle)
            ->title(Str::headline($handle))
            ->sites($siteOrigins);

        $set->save();

        foreach ($valuesBySite as $site => $values) {
            $set->in($site)->merge($values)->save();
        }

        return $set;
    }

    /**
     * `$treesBySite` holds one branch array per site, in the stored shape:
     * `[['id' => …, 'entry' => …], ['id' => …, 'title' => …, 'url' => …, 'children' => […]]]`.
     */
    protected function makeNav(string $handle, array $treesBySite): NavContract
    {
        $nav = Nav::make($handle)->title(Str::headline($handle));

        $nav->save();

        foreach ($treesBySite as $site => $tree) {
            $nav->makeTree($site, $tree)->save();
        }

        return $nav;
    }
}
