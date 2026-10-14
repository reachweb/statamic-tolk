<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Exceptions\UnreadableState;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\Support\Pruner;
use Reach\StatamicTolk\Support\PruneReport;
use Statamic\Console\RunsInPlease;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Cleans up translation state and runs with Support\Pruner.
 */
class PruneCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'tolk:prune
        {--dry-run : Show what would be cleaned up, without changing anything}';

    protected $description = 'Delete the translation state of deleted content, stop runs that stopped moving and delete old runs';

    public function handle(Pruner $pruner): int
    {
        $report = $pruner->prune((bool) $this->option('dry-run'));

        if ($report->isEmpty()) {
            $this->components->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        $days = Pruner::retentionDays();
        $listed = $report->dryRun || $this->output->isVerbose();

        $this->newLine();
        $this->detail('State of content that no longer exists', count($report->orphaned(PruneReport::DELETED)), 'record');
        $this->lines($listed ? $report->orphaned(PruneReport::DELETED) : []);
        $this->detail('State of content that is no longer a localization', count($report->orphaned(PruneReport::NOT_A_LOCALIZATION)), 'record');
        $this->lines($listed ? $report->orphaned(PruneReport::NOT_A_LOCALIZATION) : []);
        $this->detail('Pending translations confirmed', $report->resolved, 'field');
        $this->detail('Pending translations older than '.FieldState::PENDING_DAYS.' days dropped', $report->expired, 'field');

        // Only state written by 1.0.0-beta.1 lacks them, so the line would read 0 on every later run.
        if ($report->located > 0) {
            $this->detail('Entry state given its collection and site', $report->located, 'record');
        }

        $this->detail('Runs stopped for making no progress', count($report->stoppedRuns), 'run');
        $this->lines(array_map(fn (string $id) => cp_route('tolk.runs.show', $id), $report->stoppedRuns));
        $this->detail("Runs deleted after {$days} days without activity", $report->deletedRuns, 'run');

        if ($report->unreadable !== []) {
            $this->detail('State files that cannot be read', count($report->unreadable), 'file');
            $this->lines(array_map(UnreadableState::relativePath(...), $report->unreadable));
            $this->newLine();
            $this->components->warn('Fix each of these files, for example by resolving a git merge conflict in it, or delete it. Until then, the status of its item is unknown and translating the item fails.');
        }

        $this->newLine();

        if ($report->dryRun) {
            $this->components->info('Nothing was changed (--dry-run).');
        } elseif ($report->stoppedRuns !== []) {
            $this->components->info('A stopped run can be resumed on its page in the Control Panel.');
        }

        return self::SUCCESS;
    }

    private function detail(string $label, int $count, string $noun): void
    {
        $this->components->twoColumnDetail($label, number_format($count).' '.Str::plural($noun, $count));
    }

    /**
     * @param  list<string>  $lines
     */
    private function lines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->line('  <fg=gray>'.OutputFormatter::escape($line).'</>');
        }
    }
}
