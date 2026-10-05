<?php

namespace Reach\StatamicTolk\Http;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;

/**
 * The state of a run as the run page and the editor's push follow it (T8.7): the run with its usage, cost and estimate,
 * its counts per item status, overall and for each content type and target site, one page of its items, and whether a
 * queue run looks stalled.
 */
final readonly class RunState
{
    public const int PER_PAGE = 50;

    /**
     * Seconds after a start within which a queue worker should have claimed the first item.
     */
    public const int FIRST_CLAIM_SECONDS = 30;

    public function __construct(
        private RunRepository $runs,
        private Pricing $pricing,
        private ModelCatalog $catalog,
    ) {}

    /**
     * `run.estimate` is null until the run was estimated, and its `next` is null once the estimate is finished.
     */
    public function for(Run $run, int $page = 1, int $perPage = self::PER_PAGE, ?RunItemStatus $status = null): array
    {
        $counts = $this->runs->counts($run);
        $total = $status === null ? array_sum($counts) : $counts[$status->value];

        return [
            'run' => $this->run($run),
            'counts' => $counts,
            'breakdown' => $this->runs->breakdown($run),
            'items' => array_map($this->item(...), $this->runs->items($run, $status, $page, $perPage)),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'stalled' => $this->stalled($run),
        ];
    }

    /**
     * A run in the list of runs: the run, its counts and the URL of its page.
     */
    public function summary(Run $run): array
    {
        return [...$this->run($run), 'counts' => $this->runs->counts($run), 'url' => cp_route('tolk.runs.show', $run->id)];
    }

    /**
     * A running queue run that no worker took up within FIRST_CLAIM_SECONDS of its start, or that made no progress for
     * longer than a worker holds an item. Browser and CLI runs are driven by whoever follows them.
     */
    public function stalled(Run $run): bool
    {
        if ($run->status !== RunStatus::Running || $run->driver !== RunDriver::Queue) {
            return false;
        }

        $now = CarbonImmutable::now();

        if ($run->startedAt === null && $run->progressedAt?->lessThan($now->subSeconds(self::FIRST_CLAIM_SECONDS))) {
            return true;
        }

        return $run->lastActivityAt()->lessThan($now->subSeconds(max(1, (int) config('tolk.runs.claim_ttl'))));
    }

    /**
     * @return array<string, string> the label of each model the run names or has called, by reference
     */
    public function models(Run $run): array
    {
        $labels = [];

        foreach ([$run->options['translator'] ?? null, $run->options['reviewer'] ?? null, ...array_keys($run->usage->usages)] as $reference) {
            if (! is_string($reference) || isset($labels[$reference])) {
                continue;
            }

            try {
                $labels[$reference] = $this->catalog->label(ModelRef::fromString($reference));
            } catch (InvalidModelRef) {
                $labels[$reference] = $reference;
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function urls(Run $run): array
    {
        $urls = ['index' => cp_route('tolk.runs.index'), 'show' => cp_route('tolk.runs.show', $run->id)];

        foreach (['state', 'estimate', 'start', 'tick', 'apply', 'cancel', 'resume', 'retry', 'take-over'] as $action) {
            $urls[str_replace('-', '_', $action)] = cp_route('tolk.runs.'.$action, $run->id);
        }

        return $urls;
    }

    private function run(Run $run): array
    {
        return [
            ...Arr::except($run->toArray(), ['generation', 'saves', 'committed_saves']),
            'cost' => $this->pricing->total($run->usage),
        ];
    }

    // The claim and the plan are the workers' business.
    private function item(RunItem $item): array
    {
        return Arr::except($item->toArray(), ['claim', 'plan_id']);
    }
}
