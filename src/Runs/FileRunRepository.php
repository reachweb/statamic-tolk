<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\ClaimLost;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;
use Reach\StatamicTolk\Support\Concerns\StoresFiles;
use ValueError;

/**
 * Runs as JSON files under `runs.path`: `{id}/run.json`, `{id}/items/{index}.json`, and `{id}/index.json`, which holds
 * what claiming needs of every item, so counts() and the claim methods read that one file. Every write happens under
 * the run's cache lock and goes through a temporary file and rename().
 *
 * index.json is rewritten on every claim, claim extension and item save, so this driver suits runs of up to a few
 * thousand items. Beyond that, a database driver is the answer (T11.2).
 */
final class FileRunRepository implements RunRepository
{
    use ReadsStoredValues;
    use StoresFiles;

    private const array IDLE = [RunStatus::Pending, RunStatus::Cancelled, RunStatus::Aborted];

    private const array HOLDING = [RunItemStatus::Translating, RunItemStatus::Applying];

    private const array ENTRY = ['index' => 0, 'type' => 'entry', 'site' => '', 'stage' => 0, 'status' => 'pending', 'attempts' => 0, 'depends_on' => [], 'reason' => null, 'claim' => null, 'available_at' => null];

    public function __construct(private readonly Filesystem $files) {}

    public function create(Run $run, iterable $items): Run
    {
        if (! $this->isValidId($run->id)) {
            throw new InvalidArgumentException("[{$run->id}] is not a valid run id.");
        }

        return $this->locked($run->id, function () use ($run, $items) {
            if ($this->files->exists($this->directory($run->id))) {
                throw new LogicException("The run [{$run->id}] already exists.");
            }

            $index = [];

            foreach ($items as $item) {
                if (isset($index[$item->index])) {
                    throw new LogicException("The run [{$run->id}] has two items with the index [{$item->index}].");
                }

                $this->writeJson($this->itemPath($run->id, $item->index), $item->toArray(), 'the run file');
                $index[$item->index] = $this->entry($item);
            }

            ksort($index);
            $this->writeIndex($run->id, $index);

            // The run file comes last: until it exists, find() does not see a run whose items are still being written.
            $this->writeRun($run, $run);

            return $run;
        });
    }

    public function find(string $id): ?Run
    {
        return $this->readRun($id);
    }

    public function save(Run $run): void
    {
        $this->locked($run->id, function () use ($run) {
            $stored = $this->readRun($run->id) ?? throw RunNotFound::for($run->id);

            $run->changes() === []
                ? $run->refresh($stored)
                : $this->writeRun(Run::fromArray([...$stored->toArray(), ...$run->changes()]), $run);
        });
    }

    public function delete(Run $run): void
    {
        if ($this->isValidId($run->id)) {
            $this->locked($run->id, fn () => $this->files->deleteDirectory($this->directory($run->id)));
        }
    }

    public function recent(int $limit): array
    {
        return collect($this->ids())
            ->sortDesc()
            ->lazy()
            ->map(fn (string $id) => $this->readRun($id))
            ->filter()
            ->take(max(0, $limit))
            ->values()
            ->all();
    }

    public function stale(int $seconds): array
    {
        $cutoff = CarbonImmutable::now()->subSeconds($seconds);

        return collect($this->runs())
            ->filter(fn (Run $run) => $run->status === RunStatus::Running && $run->lastActivityAt()->lessThan($cutoff))
            ->values()
            ->all();
    }

    public function inactive(int $days): array
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        return collect($this->runs())
            ->filter(fn (Run $run) => $run->lastActivityAt()->lessThan($cutoff))
            ->values()
            ->all();
    }

    public function prune(int $olderThanDays): int
    {
        $runs = $this->inactive($olderThanDays);

        foreach ($runs as $run) {
            $this->delete($run);
        }

        return count($runs);
    }

    public function item(Run $run, int $index): ?RunItem
    {
        return $this->readItem($run->id, $index);
    }

    public function items(Run $run, ?RunItemStatus $status = null, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, $perPage);

        return collect($this->readIndex($run->id))
            ->filter(fn (array $entry) => $status === null || $entry['status'] === $status->value)
            ->keys()
            ->slice((max(1, $page) - 1) * $perPage, $perPage)
            ->map(fn (int $index) => $this->readItem($run->id, $index))
            ->filter()
            ->values()
            ->all();
    }

    public function dependents(Run $run, int $index): array
    {
        return collect($this->readIndex($run->id))
            ->filter(fn (array $entry) => in_array($index, $entry['depends_on'], true))
            ->keys()
            ->map(fn (int $dependent) => $this->readItem($run->id, $dependent))
            ->filter()
            ->values()
            ->all();
    }

    public function counts(Run $run): array
    {
        $counts = self::noCounts();

        foreach ($this->readIndex($run->id) as $entry) {
            $counts[$entry['status']]++;
        }

        return $counts;
    }

    public function breakdown(Run $run): array
    {
        $groups = [];

        foreach ($this->readIndex($run->id) as $entry) {
            $groups[$entry['type'].'|'.$entry['site']] ??= ['type' => $entry['type'], 'site' => $entry['site'], 'counts' => self::noCounts()];
            $groups[$entry['type'].'|'.$entry['site']]['counts'][$entry['status']]++;
        }

        return array_values($groups);
    }

    public function isReady(Run $run, int $index): bool
    {
        $entries = $this->readIndex($run->id);

        return isset($entries[$index]) && $this->ready($entries, $entries[$index], $this->openStages($entries));
    }

    public function claimNext(Run $run, string $workerId, int $ttl): ?RunItem
    {
        return $this->claim($run, $workerId, $ttl, RunItemStatus::Pending, RunItemStatus::Translating);
    }

    public function claimTranslated(Run $run, string $workerId, int $ttl): ?RunItem
    {
        return $this->claim($run, $workerId, $ttl, RunItemStatus::Translated, RunItemStatus::Applying);
    }

    public function extendClaim(Run $run, RunItem $item): void
    {
        $this->changeItem($run, $item, function (?Claim $claim) use ($run, $item) {
            if ($claim === null) {
                throw ClaimLost::for($run->id, $item->index);
            }

            $current = $this->readItem($run->id, $item->index) ?? throw RunNotFound::item($run->id, $item->index);
            $current->claim = $item->claim = $claim->extended();

            return $current;
        });
    }

    public function saveItem(Run $run, RunItem $item): void
    {
        $this->changeItem($run, $item, function (?Claim $claim) use ($item) {
            $item->claim = in_array($item->status, self::HOLDING, true) ? $claim : null;

            return $item;
        });
    }

    public function release(Run $run, RunItem $item, ?CarbonInterface $availableAt = null): void
    {
        $this->changeItem($run, $item, function () use ($item, $availableAt) {
            $item->status = match (true) {
                $item->status === RunItemStatus::Applying => RunItemStatus::Translated,
                $item->status->isFinal() => $item->status,
                default => RunItemStatus::Pending,
            };
            $item->claim = null;
            $item->availableAt = $availableAt?->toImmutable();

            return $item;
        });
    }

    public function retryFailed(Run $run): int
    {
        return $this->locked($run->id, function () use ($run) {
            $stored = $this->readRun($run->id) ?? throw RunNotFound::for($run->id);
            $index = $this->readIndex($run->id);
            $changed = [];

            // All at once, so commit() does not skip an item again for a dependency that is not reset yet.
            foreach ($index as $i => $entry) {
                $retried = $entry['status'] === RunItemStatus::Failed->value
                    || ($entry['status'] === RunItemStatus::Skipped->value && $entry['reason'] === RunItem::DEPENDENCY_FAILED);

                if (! $retried || ! $item = $this->readItem($run->id, $i)) {
                    continue;
                }

                $item->status = RunItemStatus::Pending;
                $item->reason = $item->error = $item->rootPage = null;
                $item->attempts = $item->waits = 0;
                $item->claim = $item->availableAt = null;
                $changed[$i] = $item;
            }

            $this->commit($run, $stored, $index, $changed);

            return count($changed);
        });
    }

    public function releaseTranslating(Run $run): int
    {
        return $this->locked($run->id, function () use ($run) {
            $stored = $this->readRun($run->id) ?? throw RunNotFound::for($run->id);
            $index = $this->readIndex($run->id);
            $changed = [];

            foreach ($index as $i => $entry) {
                if ($entry['status'] !== RunItemStatus::Translating->value || ! $item = $this->readItem($run->id, $i)) {
                    continue;
                }

                $item->status = RunItemStatus::Pending;
                $item->claim = null;
                $changed[$i] = $item;
            }

            $this->commit($run, $stored, $index, $changed);

            return count($changed);
        });
    }

    public function nextAvailableIn(Run $run): ?int
    {
        $status = $this->readRun($run->id)?->status;

        if ($status === null || in_array($status, self::IDLE, true)) {
            return null;
        }

        $index = $this->readIndex($run->id);
        $now = CarbonImmutable::now();
        $openStages = $this->openStages($index);
        $seconds = null;

        foreach ($index as $entry) {
            $wait = match (true) {
                $entry['status'] === RunItemStatus::Translating->value => $this->claimOf($entry)?->isExpired() === false ? null : 0,
                $entry['status'] === RunItemStatus::Pending->value && $this->ready($index, $entry, $openStages) => max(0, (self::storedTime($entry, 'available_at') ?? $now)->getTimestamp() - $now->getTimestamp()),
                default => null,
            };

            if ($wait !== null) {
                $seconds = min($seconds ?? $wait, $wait);
            }
        }

        return $seconds;
    }

    public function addUsage(Run $run, ModelRef $model, Usage $usage): void
    {
        $this->locked($run->id, function () use ($run, $model, $usage) {
            $stored = $this->readRun($run->id) ?? throw RunNotFound::for($run->id);
            $stored->usage = $stored->usage->add($model, $usage);
            $stored->progressedAt = CarbonImmutable::now();

            $this->writeRun($stored, $run);
        });
    }

    /**
     * Hands out the worker's own item in $holding, else the first available item in $from, else one in $holding whose
     * claim expired, and moves it to $holding.
     */
    private function claim(Run $run, string $workerId, int $ttl, RunItemStatus $from, RunItemStatus $holding): ?RunItem
    {
        return $this->locked($run->id, function () use ($run, $workerId, $ttl, $from, $holding) {
            $stored = $this->readRun($run->id);

            if ($stored === null) {
                return null;
            }

            $index = $this->readIndex($run->id);
            $changed = [];
            $claimed = in_array($stored->status, self::IDLE, true)
                ? null
                : $this->select($run->id, $index, $changed, $workerId, $ttl, $from, $holding);

            $this->commit($run, $stored, $index, $changed, started: $claimed !== null);

            return $claimed;
        });
    }

    /**
     * @param  array<int, RunItem>  $changed
     */
    private function select(string $id, array $index, array &$changed, string $workerId, int $ttl, RunItemStatus $from, RunItemStatus $holding): ?RunItem
    {
        $now = CarbonImmutable::now();
        // Dependencies and stages hold back only the items that are still to be translated.
        $openStages = $from === RunItemStatus::Pending ? $this->openStages($index) : null;
        $own = $fresh = $expired = [];

        foreach ($index as $i => $entry) {
            if ($entry['status'] === $from->value) {
                if ($this->isAvailable($entry, $now) && ($openStages === null || $this->ready($index, $entry, $openStages))) {
                    $fresh[] = $i;
                }

                continue;
            }

            if ($entry['status'] !== $holding->value) {
                continue;
            }

            $claim = $this->claimOf($entry);

            if ($claim?->by === $workerId) {
                $own[] = $i;
            } elseif ($claim === null || $claim->isExpired()) {
                $expired[] = $i;
            }
        }

        foreach ([...$own, ...$fresh] as $i) {
            if ($item = $this->readItem($id, $i)) {
                return $changed[$i] = $this->handOut($item, $workerId, $ttl, $holding);
            }
        }

        foreach ($expired as $i) {
            if (! $item = $this->readItem($id, $i)) {
                continue;
            }

            $item->attempts++;

            if ($item->attempts < RunItem::MAX_ATTEMPTS) {
                return $changed[$i] = $this->handOut($item, $workerId, $ttl, $holding);
            }

            $item->status = RunItemStatus::Failed;
            $item->claim = null;
            $item->error = __('No worker finished this item in :attempts attempts.', ['attempts' => RunItem::MAX_ATTEMPTS]);
            $changed[$i] = $item;
        }

        return null;
    }

    private function handOut(RunItem $item, string $workerId, int $ttl, RunItemStatus $holding): RunItem
    {
        $item->status = $holding;
        $item->claim = Claim::for($workerId, $ttl);
        $item->availableAt = null;

        return $item;
    }

    /**
     * Checks the caller's claim against the stored one and stores the item the callback returns.
     *
     * @param  Closure(?Claim $claim): RunItem  $callback
     */
    private function changeItem(Run $run, RunItem $item, Closure $callback): void
    {
        $this->locked($run->id, function () use ($run, $item, $callback) {
            $stored = $this->readRun($run->id) ?? throw RunNotFound::for($run->id);
            $index = $this->readIndex($run->id);
            $entry = $index[$item->index] ?? throw RunNotFound::item($run->id, $item->index);
            $claim = $this->claimOf($entry);

            if ($claim?->token !== $item->claim?->token) {
                throw ClaimLost::for($run->id, $item->index);
            }

            $this->commit($run, $stored, $index, [$item->index => $callback($claim)]);
        });
    }

    /**
     * Skips the items whose dependency failed, then writes the changed items, the index and the run's progress.
     *
     * @param  array<int, RunItem>  $changed
     */
    private function commit(Run $run, Run $stored, array $index, array $changed, bool $started = false): void
    {
        foreach ($changed as $i => $item) {
            $index[$i] = $this->entry($item);
        }

        $this->skipBlocked($run->id, $index, $changed);

        if ($changed === []) {
            $run->refresh($stored);

            return;
        }

        foreach ($changed as $i => $item) {
            $this->writeJson($this->itemPath($run->id, $i), $item->toArray(), 'the run file');
        }

        $this->writeIndex($run->id, $index);

        $now = CarbonImmutable::now();
        $stored->progressedAt = $now;

        if ($started) {
            $stored->startedAt ??= $now;
        }

        $this->writeRun($stored, $run);
    }

    /**
     * @param  array<int, array>  $index
     * @param  array<int, RunItem>  $changed
     */
    private function skipBlocked(string $id, array &$index, array &$changed): void
    {
        do {
            $skipped = false;

            foreach ($index as $i => $entry) {
                if ($entry['status'] !== RunItemStatus::Pending->value || ! $this->dependencyFailed($index, $entry)) {
                    continue;
                }

                if (! $item = $changed[$i] ?? $this->readItem($id, $i)) {
                    continue;
                }

                $item->status = RunItemStatus::Skipped;
                $item->reason = RunItem::DEPENDENCY_FAILED;
                $item->claim = null;
                $item->availableAt = null;
                $index[$i] = $this->entry($item);
                $changed[$i] = $item;
                $skipped = true;
            }
        } while ($skipped);
    }

    private function dependencyFailed(array $index, array $entry): bool
    {
        foreach ($entry['depends_on'] as $dependency) {
            $status = $index[$dependency]['status'] ?? null;

            if ($status === RunItemStatus::Failed->value
                || ($status === RunItemStatus::Skipped->value && $index[$dependency]['reason'] === RunItem::DEPENDENCY_FAILED)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every dependency is done or skipped and no item of an earlier stage is unfinished in the item's site.
     * A dependency that is not in the run does not hold the item back.
     */
    private function ready(array $index, array $entry, array $openStages): bool
    {
        foreach ($entry['depends_on'] as $dependency) {
            if (! isset($index[$dependency])) {
                continue;
            }

            $status = $index[$dependency]['status'];

            if ($status !== RunItemStatus::Done->value && ($status !== RunItemStatus::Skipped->value || $index[$dependency]['reason'] === RunItem::DEPENDENCY_FAILED)) {
                return false;
            }
        }

        return ($openStages[$entry['site']] ?? $entry['stage']) >= $entry['stage'];
    }

    /**
     * @return array<string, int> the lowest stage with an unfinished item, per site
     */
    private function openStages(array $index): array
    {
        $stages = [];

        foreach ($index as $entry) {
            if (! RunItemStatus::from($entry['status'])->isFinal()) {
                $stages[$entry['site']] = min($stages[$entry['site']] ?? PHP_INT_MAX, $entry['stage']);
            }
        }

        return $stages;
    }

    private function isAvailable(array $entry, CarbonImmutable $now): bool
    {
        return self::storedTime($entry, 'available_at')?->lessThanOrEqualTo($now) ?? true;
    }

    private function claimOf(array $entry): ?Claim
    {
        return Claim::fromArray($entry['claim']);
    }

    private function entry(RunItem $item): array
    {
        return [
            'index' => $item->index,
            'type' => $item->type->value,
            'site' => $item->targetSite,
            'stage' => $item->stage,
            'status' => $item->status->value,
            'attempts' => $item->attempts,
            'depends_on' => $item->dependsOn,
            'reason' => $item->reason,
            'claim' => $item->claim?->toArray(),
            'available_at' => self::timeToString($item->availableAt),
        ];
    }

    /**
     * @return array<int, array> every item's entry by index, in index order, with the omitted keys filled in
     */
    private function readIndex(string $id): array
    {
        $index = [];
        $entries = $this->isValidId($id) ? $this->readJson($this->indexPath($id)) : null;

        foreach ($entries ?? [] as $entry) {
            if (is_array($entry) && is_int($entry['index'] ?? null) && RunItemStatus::tryFrom($entry['status'] ?? '')) {
                $index[$entry['index']] = [...self::ENTRY, ...$entry];
            }
        }

        ksort($index);

        return $index;
    }

    // Keys with an empty value are left out: the index is rewritten on every claim.
    private function writeIndex(string $id, array $index): void
    {
        $this->writeJson($this->indexPath($id), array_values(array_map(fn (array $entry) => array_filter(
            $entry,
            fn (mixed $value) => $value !== null && $value !== [],
        ), $index)), 'the run file', pretty: false);
    }

    private function readRun(string $id): ?Run
    {
        $data = $this->isValidId($id) ? $this->readJson($this->runPath($id)) : null;

        try {
            $run = $data === null ? null : Run::fromArray($data);
        } catch (ValueError) {
            return null;
        }

        return $run?->id === $id ? $run : null;
    }

    /**
     * Writes $data as the stored run and refreshes $run from it.
     */
    private function writeRun(Run $data, Run $run): void
    {
        $stored = $data->toArray();

        $this->writeJson($this->runPath($run->id), $stored, 'the run file');

        $run->refresh(Run::fromArray($stored));
    }

    private function readItem(string $id, int $index): ?RunItem
    {
        $data = $this->isValidId($id) ? $this->readJson($this->itemPath($id, $index)) : null;

        try {
            return $data === null ? null : RunItem::fromArray($data);
        } catch (ValueError) {
            return null;
        }
    }

    /**
     * @return iterable<Run>
     */
    private function runs(): iterable
    {
        foreach ($this->ids() as $id) {
            if ($run = $this->readRun($id)) {
                yield $run;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        if (! $this->files->isDirectory($this->root())) {
            return [];
        }

        return array_values(array_filter(array_map(basename(...), $this->files->directories($this->root())), $this->isValidId(...)));
    }

    // Every claim and item save takes this lock for a few milliseconds, so a waiter tries again sooner than Laravel's 250.
    private function locked(string $id, Closure $callback): mixed
    {
        return Cache::store(config('tolk.cache_store'))->lock('tolk:run:'.$id, 10)->betweenBlockedAttemptsSleepFor(10)->block(5, $callback);
    }

    /**
     * @return array<string, int>
     */
    private static function noCounts(): array
    {
        return array_fill_keys(array_column(RunItemStatus::cases(), 'value'), 0);
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[0-9A-Za-z]{1,64}$/', $id) === 1;
    }

    private function root(): string
    {
        return rtrim(config('tolk.runs.path'), '/\\');
    }

    private function directory(string $id): string
    {
        return $this->root().'/'.$id;
    }

    private function runPath(string $id): string
    {
        return $this->directory($id).'/run.json';
    }

    private function indexPath(string $id): string
    {
        return $this->directory($id).'/index.json';
    }

    private function itemPath(string $id, int $index): string
    {
        return $this->directory($id).'/items/'.$index.'.json';
    }
}
