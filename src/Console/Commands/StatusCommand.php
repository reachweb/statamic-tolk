<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\ScanResult;
use Reach\StatamicTolk\State\StatusScanner;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Site;

/**
 * Counts, per container and site, the targets that are missing, untranslated, outdated, need attention or are current
 * (§A6), with StatusScanner, the engine of the Overview page.
 */
class StatusCommand extends Command
{
    use RunsInPlease;

    /**
     * The budget of each scan; a container and site that take longer are scanned in several calls.
     */
    public const int BUDGET_MS = 1000;

    private const array OPTIONS = ['collection' => ContentType::Entry, 'taxonomy' => ContentType::Term, 'globals' => ContentType::GlobalSet, 'nav' => ContentType::Nav];

    private const array TYPES = ['entry' => 'Collection', 'term' => 'Taxonomy', 'globals' => 'Global set', 'nav' => 'Navigation'];

    protected $signature = 'tolk:status
        {--site=* : Show only this site (default: every site)}
        {--collection=* : Show this collection}
        {--taxonomy=* : Show this taxonomy}
        {--globals=* : Show this global set}
        {--nav=* : Show this navigation}
        {--json : Print the counts as JSON}';

    protected $description = 'Show how much of each collection, taxonomy, global set and navigation is translated into each site';

    public function handle(StatusScanner $scanner): int
    {
        try {
            $cells = $this->cells($scanner, $this->sites());
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = array_map(fn (array $cell) => [
            'type' => $cell['type']->value,
            'container' => $cell['container'],
            'site' => $cell['site'],
            ...$this->scan($scanner, $cell['type'], $cell['container'], $cell['site']),
        ], $cells);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('Nothing to show: no content selected has a site to translate into.');

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'Handle', 'Site', 'Missing', 'Untranslated', 'Outdated', 'Needs attention', 'Current', 'Untracked fields'],
            array_map(fn (array $row) => [
                self::TYPES[$row['type']],
                $row['container'],
                $row['site'],
                ...array_map(number_format(...), array_values($row['counts'])),
                number_format($row['untracked']),
            ], $rows),
        );

        $untracked = array_sum(array_column($rows, 'untracked'));

        if ($untracked > 0) {
            $this->components->warn(sprintf(
                '%s translated by hand without a record of %s origin, so a change to the origin goes unnoticed. Run php please tolk:baseline to record it.',
                $untracked === 1 ? '1 field was' : number_format($untracked).' fields were',
                $untracked === 1 ? 'its' : 'their',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Every container and site to scan, each container checked before anything is scanned.
     *
     * @param  list<string>  $sites  empty for every site
     * @return list<array{type: ContentType, container: string, site: string}>
     */
    private function cells(StatusScanner $scanner, array $sites): array
    {
        $named = [];

        foreach (array_keys(self::OPTIONS) as $option) {
            $named[$option] = array_values(array_unique($this->option($option)));
        }

        $everything = array_merge(...array_values($named)) === [];
        $cells = [];

        foreach (self::OPTIONS as $option => $type) {
            $containers = $everything ? $scanner->containers($type) : $named[$option];

            foreach ($containers as $container) {
                foreach ($scanner->sites($type, $container) as $site) {
                    if ($sites === [] || in_array($site, $sites, true)) {
                        $cells[] = compact('type', 'container', 'site');
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * @return array{counts: array<string, int>, untracked: int, scanned: int}
     */
    private function scan(StatusScanner $scanner, ContentType $type, string $container, string $site): array
    {
        $result = ScanResult::empty();

        do {
            $result = $result->add($scanner->scan($type, $container, $site, $result->cursor, self::BUDGET_MS));
        } while (! $result->isFinished());

        return array_diff_key($result->toArray(), ['cursor' => true]);
    }

    /**
     * @return list<string>
     */
    private function sites(): array
    {
        $sites = array_values(array_unique($this->option('site')));

        foreach ($sites as $site) {
            if (Site::get($site) === null) {
                throw UnknownSite::for($site);
            }
        }

        return $sites;
    }
}
