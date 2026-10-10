<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Reach\StatamicTolk\Tests\TestCase;
use RuntimeException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * Another PHP process on a test's content, started through `other-process.php` by CreatesContent's `…Elsewhere()`
 * helpers. It boots the suite's application on the test's directory (Stache stores, blueprints, fieldsets, state, runs)
 * and file cache, with the test's sites, the addon's default settings and the suite's base config, and runs one job
 * through Statamic, with its events. A job is a method here, named in main()'s `match`.
 */
final class OtherProcess extends TestCase
{
    public const string SCRIPT = __DIR__.'/other-process.php';

    /**
     * @var array{job: string, arguments: array<string, mixed>, directory: string, cache: string, sites: array}
     */
    private static array $job;

    /**
     * @return array{done: string} the acknowledgement CreatesContent expects on STDOUT
     */
    public static function main(array $job): array
    {
        self::$job = $job;

        $process = new self('other-process');
        $process->setUp();
        Site::setSites($job['sites']);

        // No tearDown(): Testbench's needs PHPUnit's runner, and the test deletes this process's files with its own.
        match ($job['job']) {
            'save-entry' => $process->saveEntry(...$job['arguments']),
            'delete-entry' => $process->deleteEntry(...$job['arguments']),
            'detach-localizations' => $process->detachLocalizations(...$job['arguments']),
            'localize-entry' => $process->localizeEntry(...$job['arguments']),
            'delete-term' => $process->deleteTerm(...$job['arguments']),
        };

        return ['done' => $job['job']];
    }

    /**
     * Own copy of Testbench's application, inside the test's directory: setUp() deletes the addon's settings file of its
     * application, and in a parallel run TestCase would take the test's own copy (the TEST_TOKEN the helper drops).
     */
    public static function applicationBasePath()
    {
        $path = self::$job['directory'].'/apps/other-process';

        if (! is_dir($path) && ! (new Filesystem)->copyDirectory(default_skeleton_path(), $path)) {
            throw new RuntimeException("Could not copy Testbench's application to [{$path}].");
        }

        return $path;
    }

    /**
     * The test's directory, so the Stache stores, blueprints, fieldsets, state and runs are the test's.
     */
    protected function temporaryPath(string $path = ''): string
    {
        return rtrim(self::$job['directory'].'/'.$path, '/');
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cache.stores.tolk-test-file' => ['driver' => 'file', 'path' => self::$job['cache']],
            'cache.default' => 'tolk-test-file',
        ]);
    }

    private function saveEntry(string $id, array $changes): void
    {
        $this->entry($id)->merge($changes)->save() ?: throw new RuntimeException("A listener stopped the save of the entry [{$id}].");
    }

    /**
     * As the CP's Delete action deletes an entry of a multi-site collection (DeleteMultisiteEntry): its localizations
     * deleted or detached first when $localizations says so.
     *
     * @param  'delete'|'detach'|null  $localizations
     */
    private function deleteEntry(string $id, ?string $localizations = null): void
    {
        $entry = $this->entry($id);

        match ($localizations) {
            'delete' => $entry->deleteDescendants(),
            'detach' => $entry->detachLocalizations(),
            null => null,
        };

        $entry->delete() ?: throw new RuntimeException("A listener stopped the deletion of the entry [{$id}].");
    }

    private function detachLocalizations(string $id): void
    {
        $this->entry($id)->detachLocalizations();
    }

    private function localizeEntry(string $id, string $site, array $data = []): void
    {
        $this->entry($id)->makeLocalization($site)->merge($data)->save() ?: throw new RuntimeException("A listener stopped the save of the localization of the entry [{$id}] in [{$site}].");
    }

    // As the CP deletes a term: the term of every site, through the localization its listing holds.
    private function deleteTerm(string $id): void
    {
        $term = Term::find($id) ?? throw new RuntimeException("The term [{$id}] does not exist.");

        $term->delete() ?: throw new RuntimeException("A listener stopped the deletion of the term [{$id}].");
    }

    private function entry(string $id): EntryContract
    {
        return Entry::find($id) ?? throw new RuntimeException("The entry [{$id}] does not exist.");
    }
}
