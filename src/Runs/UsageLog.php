<?php

namespace Reach\StatamicTolk\Runs;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Support\Concerns\StoresFiles;

/**
 * Provider usage per month, in `{usage.path}/{YYYY-MM}.json`: per requested model and source, the tokens, the calls and
 * the cost at the prices of the day of each call. Every write happens under the month's cache lock.
 */
final readonly class UsageLog
{
    use StoresFiles;

    public function __construct(
        private Filesystem $files,
        private Pricing $pricing,
    ) {}

    public function add(ModelRef $model, Usage $usage, UsageSource $source): void
    {
        $month = now()->format('Y-m');
        $cost = $this->pricing->cost($model, $usage);

        $this->locked($month, function () use ($month, $model, $usage, $source, $cost) {
            $data = $this->read($month);
            $sources = is_array($data[(string) $model] ?? null) ? $data[(string) $model] : [];
            $entry = is_array($sources[$source->value] ?? null) ? $sources[$source->value] : [];

            $sources[$source->value] = [
                ...Usage::fromArray($entry)->add($usage)->toArray(),
                'cost' => $this->sum($this->cost($entry), $cost),
            ];
            $data[(string) $model] = $sources;

            $this->writeJson($this->path($month), $data, 'the usage log');
        });
    }

    /**
     * The month's usage per model, keyed by its reference, with the totals and the usage of each source. A `cost` is null
     * when a call was made with a model that had no price.
     *
     * @param  ?string  $month  `YYYY-MM`; null for the current month
     * @return array<string, array{input: int, output: int, cache_read: int, cache_write: int, reasoning: int, calls: int, cost: ?float, sources: array<string, array>}>
     */
    public function month(?string $month = null): array
    {
        $month = $this->validMonth($month);
        $models = [];

        foreach ($this->read($month) as $model => $sources) {
            if (! is_string($model) || ! is_array($sources)) {
                continue;
            }

            $total = new Usage;
            $cost = 0.0;
            $bySource = [];

            foreach (UsageSource::cases() as $source) {
                if (! is_array($entry = $sources[$source->value] ?? null)) {
                    continue;
                }

                $usage = Usage::fromArray($entry);
                $bySource[$source->value] = [...$usage->toArray(), 'cost' => $this->cost($entry)];
                $total = $total->add($usage);
                $cost = $this->sum($cost, $this->cost($entry));
            }

            if ($bySource !== []) {
                $models[$model] = [...$total->toArray(), 'cost' => $cost, 'sources' => $bySource];
            }
        }

        ksort($models);

        return $models;
    }

    /**
     * Deletes the month's usage. A call recorded meanwhile waits for the lock and starts the month again.
     *
     * @param  ?string  $month  `YYYY-MM`; null for the current month
     */
    public function reset(?string $month = null): void
    {
        $month = $this->validMonth($month);

        $this->locked($month, fn () => $this->files->delete($this->path($month)));
    }

    private function validMonth(?string $month): string
    {
        $month ??= now()->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("[{$month}] is not a month. Use the format YYYY-MM.");
        }

        return $month;
    }

    private function cost(array $entry): ?float
    {
        if (! array_key_exists('cost', $entry)) {
            return 0.0;
        }

        return is_numeric($entry['cost']) ? max(0.0, (float) $entry['cost']) : null;
    }

    private function sum(?float $a, ?float $b): ?float
    {
        return $a === null || $b === null ? null : $a + $b;
    }

    private function read(string $month): array
    {
        return $this->readJson($this->path($month)) ?? [];
    }

    // Every provider call takes this lock, each for a few milliseconds, so a waiter retries sooner than Laravel's 250.
    private function locked(string $month, Closure $callback): mixed
    {
        return Cache::store(config('tolk.cache_store'))->lock('tolk:usage:'.$month, 10)->betweenBlockedAttemptsSleepFor(10)->block(5, $callback);
    }

    private function path(string $month): string
    {
        return rtrim(config('tolk.usage.path'), '/\\').'/'.$month.'.json';
    }
}
