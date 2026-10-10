<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
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
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Blueprint as BlueprintObject;
use Symfony\Component\Process\Process;
use Throwable;

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

    /**
     * A structured `pages` collection in `en`, `el` and `de` whose English tree has the given shape, such as
     * `['home', 'rooms' => ['suites']]`. The entries are created in tree order, or in `$creationOrder` when given.
     *
     * @return array<string, EntryContract> by slug
     */
    protected function makePages(array $shape, bool $root = true, ?array $creationOrder = null): array
    {
        $this->makeCollection('pages', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true)
            ->structureContents(['root' => $root, 'max_depth' => 3])
            ->save();

        $treeOrder = function (array $shape) use (&$treeOrder): array {
            return collect($shape)->flatMap(fn ($children, $slug) => is_int($slug) ? [$children] : [$slug, ...$treeOrder($children)])->all();
        };

        $entries = [];

        foreach ($creationOrder ?? $treeOrder($shape) as $slug) {
            $entries[$slug] = $this->makeEntry('pages', 'en', ['title' => ucfirst($slug)], $slug);
        }

        $branches = function (array $shape) use (&$branches, $entries): array {
            return collect($shape)->map(function ($children, $slug) use (&$branches, $entries) {
                [$slug, $children] = is_int($slug) ? [$children, []] : [$slug, $children];

                return array_filter(['entry' => $entries[$slug]->id(), 'children' => $branches($children) ?: null]);
            })->values()->all();
        };

        Collection::find('pages')->structure()->in('en')->tree($branches($shape))->save();

        return $entries;
    }

    /**
     * The `pages` tree of a site as nested slugs.
     */
    protected function treeOfSlugs(string $site): array
    {
        $slugs = function (array $branches) use (&$slugs): array {
            return collect($branches)->mapWithKeys(fn (array $branch) => [
                Entry::find($branch['entry'])->slug() => isset($branch['children']) ? $slugs($branch['children']) : [],
            ])->all();
        };

        return $slugs(Collection::find('pages')->structure()->in($site)->tree());
    }

    protected function localize(EntryContract $origin, string $site, array $data = []): EntryContract
    {
        $localization = $origin->makeLocalization($site)->merge($data);

        $localization->save();

        return $localization;
    }

    /**
     * Entry::save() in another PHP process, with events, the addon's default settings and the suite's base config, on
     * this test's content, Stache and file cache: the entry's file, its Stache item and indexes and its localizations,
     * which core saves after it, change, while this process keeps what it memoized, such as the origin a localization
     * keeps in Blink, until ContentWriter::refreshStache(). A save in this process afterwards needs that refresh first,
     * or it writes the memoized indexes back over the other process's. About 0.2 s.
     */
    protected function saveEntryElsewhere(string $id, array $changes): void
    {
        $this->elsewhere('save-entry', ['id' => $id, 'changes' => $changes]);
    }

    /**
     * Entry::delete() in another PHP process, as saveEntryElsewhere() saves, so the addon's listener forgets the
     * entry's state there. Core refuses to delete an entry that has localizations: $localizations `delete` or `detach`
     * first deletes them or detaches them, as the CP's Delete action offers for an origin (deleteDescendants(),
     * detachLocalizations()).
     *
     * @param  'delete'|'detach'|null  $localizations
     */
    protected function deleteEntryElsewhere(string $id, ?string $localizations = null): void
    {
        $this->elsewhere('delete-entry', ['id' => $id, 'localizations' => $localizations]);
    }

    /**
     * Entry::detachLocalizations() in another PHP process, as saveEntryElsewhere() saves: each localization of the
     * entry is saved without an origin, holding the values it inherited. This process still finds them through the
     * entry until ContentWriter::refreshStache().
     */
    protected function detachLocalizationsElsewhere(string $id): void
    {
        $this->elsewhere('detach-localizations', ['id' => $id]);
    }

    /**
     * Entry::makeLocalization() saved in another PHP process, as saveEntryElsewhere() saves. This process finds the new
     * localization through the entry only after ContentWriter::refreshStache().
     */
    protected function localizeEntryElsewhere(string $id, string $site, array $data = []): void
    {
        $this->elsewhere('localize-entry', ['id' => $id, 'site' => $site, 'data' => $data]);
    }

    /**
     * Deletes a term in another PHP process, as the CP does (LocalizedTerm::delete(), with events). Until
     * ContentWriter::refreshStache() this process still finds it, with no data, and caches that empty term again.
     */
    protected function deleteTermElsewhere(string $id): void
    {
        $this->elsewhere('delete-term', ['id' => $id]);
    }

    /**
     * Runs a job of OtherProcess, through Symfony's Process rather than Laravel's, which Process::fake() answers. A
     * failure (one the other process reports, a wrong acknowledgement, input JSON cannot carry, a start that fails or
     * the 30 s timeout) is thrown as a RuntimeException, and thrown again when the test ends, because the code under
     * test may catch it (RunProcessor reports an exception and fails the item), so a test cannot expect one. Only the
     * LogicException of a test without useFileCache() is thrown once.
     */
    private function elsewhere(string $job, array $arguments): void
    {
        if (config('cache.default') !== 'tolk-test-file' || config('statamic.stache.cache_store') !== null) {
            throw new LogicException('Another process shares the Stache of a test only through its file cache: call useFileCache() first.');
        }

        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', OtherProcess::SCRIPT], env: ['TEST_TOKEN' => false], timeout: 30);
        $failure = null;

        try {
            $process->setInput(json_encode([
                'job' => $job,
                'arguments' => $arguments,
                'directory' => $this->temporaryPath(),
                'cache' => config('cache.stores.tolk-test-file.path'),
                'sites' => Site::config(),
            ], JSON_THROW_ON_ERROR))->run();
        } catch (Throwable $failure) {
        }

        if ($failure === null && $process->isSuccessful() && json_decode($process->getOutput(), true) === ['done' => $job]) {
            return;
        }

        $reason = $failure?->getMessage() ?? "exit code {$process->getExitCode()}";
        $output = $process->isStarted() ? $process->getErrorOutput().$process->getOutput() : '';
        $exception = new RuntimeException("Another process failed the job [{$job}] ({$reason}):\n{$output}", previous: $failure);
        $this->beforeApplicationDestroyed(fn () => throw $exception);

        throw $exception;
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
     * without an origin, for example `['en' => null, 'el' => 'en']`. With
     * `$fields` null the set has no blueprint.
     */
    protected function makeGlobalSet(string $handle, array $siteOrigins, ?array $fields, array $valuesBySite): GlobalSetContract
    {
        if ($fields !== null) {
            $this->makeBlueprint('globals', $handle, $fields);
        }

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
