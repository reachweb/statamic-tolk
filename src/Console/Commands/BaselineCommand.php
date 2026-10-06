<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Reach\StatamicTolk\Console\Concerns\SelectsContainers;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusScanner;
use Reach\StatamicTolk\Support\GitListeners;
use Statamic\Console\RunsInPlease;

/**
 * Records the origin of the fields translated by hand in existing localizations (StateWriter::baseline()), so a later
 * change to the origin shows as needing attention: the adoption path for a site translated before Tolk was installed.
 * It walks the targets tolk:status counts.
 */
class BaselineCommand extends Command
{
    use RunsInPlease;
    use SelectsContainers;

    protected $signature = 'tolk:baseline
        {--site=* : Record only in this site (default: every site)}
        {--collection=* : Record in this collection}
        {--taxonomy=* : Record in this taxonomy}
        {--globals=* : Record in this global set}
        {--nav=* : Record in this navigation}
        {--include-copies : Also record the fields whose text is a copy of their origin\'s, so translating does not replace them (they read untranslated otherwise)}
        {--dry-run : Count the fields without recording anything}';

    protected $description = 'Record the origin of fields translated by hand, so later changes to the origin are noticed';

    public function handle(StatusScanner $scanner, StateWriter $writer): int
    {
        try {
            $cells = $this->cells($scanner);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeCopies = (bool) $this->option('include-copies');
        $rows = [];
        $total = 0;

        foreach ($cells as $cell) {
            $count = 0;

            foreach ($scanner->targets($cell['type'], $cell['container'], $cell['site']) as $target) {
                $count += $writer->baseline($target(), includeCopies: $includeCopies, dryRun: $dryRun);
            }

            $total += $count;

            if ($count > 0) {
                $rows[] = [self::CONTAINER_TYPES[$cell['type']->value], $cell['container'], $cell['site'], number_format($count)];
            }
        }

        if ($total === 0) {
            $this->components->info('Nothing to record: every field translated by hand already has a record of its origin.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'Handle', 'Site', $dryRun ? 'Fields to record' : 'Fields recorded'], $rows);

        $fields = number_format($total).' '.Str::plural('field', $total);

        if ($dryRun) {
            $this->components->info("Would record the origin of {$fields}. Nothing was written (--dry-run).");

            return self::SUCCESS;
        }

        GitListeners::commit(StateWriter::COMMIT_MESSAGE);

        $this->components->info("Recorded the origin of {$fields}. A change to the origin now shows as needing attention.");

        return self::SUCCESS;
    }
}
