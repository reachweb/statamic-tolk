<?php

namespace Reach\StatamicTolk\Tests\Support;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Runs\WorkReport;

/**
 * Stored runs of the `articles` collection, for tests of the run processor.
 */
final class RunFixtures
{
    /**
     * A stored and started browser run into Greek; without entries or collections, of the whole `articles` collection.
     *
     * @param  array<string, mixed>  $request  RunRequest arguments by name
     */
    public static function started(array $request = []): Run
    {
        $run = self::pending($request);

        app(RunProcessor::class)->start($run);

        return $run;
    }

    /**
     * The same run, stored and not started.
     *
     * @param  array<string, mixed>  $request  RunRequest arguments by name
     */
    public static function pending(array $request = []): Run
    {
        $scope = isset($request['entryIds']) || isset($request['collections']) ? [] : ['collections' => ['articles']];
        $planned = app(RunPlanner::class)->build(new RunRequest(...['sites' => ['el'], 'driver' => RunDriver::Browser, ...$scope, ...$request]));

        return app(RunRepository::class)->create($planned->run, $planned->items);
    }

    public static function translate(Run $run, string $worker = 'lane-1', int $seconds = 30, int $ttl = 300): WorkReport
    {
        return app(RunProcessor::class)->translate($run, $worker, CarbonImmutable::now()->addSeconds($seconds), $ttl);
    }

    /**
     * @return list<RunItem>
     */
    public static function items(Run $run): array
    {
        return app(RunRepository::class)->items($run, perPage: 1000);
    }

    /**
     * @return list<string>
     */
    public static function statuses(Run $run): array
    {
        return array_map(fn (RunItem $item) => $item->status->value, self::items($run));
    }

    public static function stored(Run $run): Run
    {
        return app(RunRepository::class)->find($run->id);
    }
}
