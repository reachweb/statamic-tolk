<?php

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\ConcurrencyWorker;
use Reach\StatamicTolk\Tests\Support\RunFixtures;

function concurrencyRun(int $items, RunItemStatus $status = RunItemStatus::Pending): Run
{
    $run = Run::make(RunDriver::Cli);
    $run->status = RunStatus::Running;

    return app(RunRepository::class)->create($run, array_map(fn (int $index) => new RunItem(
        index: $index,
        type: ContentType::Entry,
        sourceId: "source-{$index}",
        targetSite: 'el',
        status: $status,
    ), range(0, $items - 1)));
}

// Real processes, so only the cache locks keep them apart; in one process the calls never overlap, lock or no lock.
it('keeps parallel processes from claiming an item twice, losing state or finishing a run twice', function () {
    $this->useFileCache();
    $workers = 4;
    $claims = 50;
    $run = concurrencyRun($workers * $claims);
    // A second finalize() sees the first one's end unless it reads the run within a few milliseconds of it, so the
    // workers also finalize ten runs that are already done, each at the same moment.
    $runs = [$run, ...array_map(fn () => concurrencyRun(1, RunItemStatus::Done), range(1, 10))];
    $directory = $this->temporaryPath();

    File::ensureDirectoryExists("{$directory}/reports");

    $results = Process::pool(function (Pool $pool) use ($directory, $runs, $workers, $claims) {
        foreach (range(1, $workers) as $lane) {
            $pool->as("worker {$lane}")->timeout(120)->command([
                PHP_BINARY, ConcurrencyWorker::SCRIPT, $directory, $lane, $workers, $claims, ...array_column($runs, 'id'),
            ]);
        }
    })->start()->wait();

    foreach ($results as $name => $result) {
        expect($result->successful())->toBeTrue("{$name} failed:\n{$result->output()}{$result->errorOutput()}");
    }

    $reports = array_map(fn (int $lane) => File::json("{$directory}/reports/{$lane}.json"), range(1, $workers));
    $claimed = collect($reports)->flatMap(fn (array $report) => $report['claims']);

    expect(array_merge(...array_column($reports, 'errors')))->toBe([])
        ->and($claimed)->toHaveCount($workers * $claims)
        ->and($claimed->filter())->toHaveCount($workers * $claims)
        ->and($claimed->pluck('index')->sort()->values()->all())->toBe(range(0, $workers * $claims - 1))
        ->and($claimed->pluck('token')->unique())->toHaveCount($workers * $claims)
        ->and(RunFixtures::statuses($run))->each->toBe(RunItemStatus::Done->value);

    $expected = [];

    foreach (range(1, $workers) as $lane) {
        foreach (range(0, $claims - 1) as $n) {
            [$reference, $field] = ConcurrencyWorker::update($lane, $n, $claims);
            $expected[$reference][] = $field;
        }
    }

    expect($expected)->toHaveCount(ConcurrencyWorker::REFERENCES);

    foreach ($expected as $reference => $fields) {
        expect(array_keys(app(StateRepository::class)->get(TargetRef::parse($reference))->fields))
            ->toEqualCanonicalizing($fields, "{$reference} lost fields");
    }

    $finished = [];

    foreach (array_column($reports, 'finished') as $events) {
        foreach ($events as $id => $count) {
            $finished[$id] = ($finished[$id] ?? 0) + $count;
        }
    }

    $once = array_fill_keys(array_column($runs, 'id'), 1);
    ksort($finished);
    ksort($once);

    expect($finished)->toBe($once);

    foreach ($runs as $finalized) {
        expect(RunFixtures::stored($finalized))
            ->status->toBe(RunStatus::Completed)
            ->finalizedAt->not->toBeNull();
    }
})->group('slow');
