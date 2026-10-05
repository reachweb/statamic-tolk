<?php

namespace Reach\StatamicTolk\Runs;

use Closure;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\UsageSource;
use RuntimeException;

/**
 * Provider usage per month, in `{usage.path}/{YYYY-MM}.json`: per requested model and source, the tokens, the calls and
 * the cost at the prices of the day of each call. Every write happens under the month's cache lock and goes through a
 * temporary file and rename().
 */
final readonly class UsageLog
{
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

            $this->put($this->path($month), $data);
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
        $month ??= now()->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("[{$month}] is not a month. Use the format YYYY-MM.");
        }

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
        try {
            $data = json_decode($this->files->get($this->path($month)), true, 512, JSON_THROW_ON_ERROR);
        } catch (FileNotFoundException|JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function put(string $path, array $data): void
    {
        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $temporary = $path.'.'.Str::random(16).'.tmp';

        $this->files->ensureDirectoryExists(dirname($path));

        try {
            if ($this->files->put($temporary, $contents) === false || ! $this->files->move($temporary, $path)) {
                throw new RuntimeException("Unable to write the usage log [{$path}].");
            }
        } finally {
            $this->files->delete($temporary);
        }
    }

    private function locked(string $month, Closure $callback): mixed
    {
        return Cache::store(config('tolk.cache_store'))->lock('tolk:usage:'.$month, 10)->block(5, $callback);
    }

    private function path(string $month): string
    {
        return rtrim(config('tolk.usage.path'), '/\\').'/'.$month.'.json';
    }
}
