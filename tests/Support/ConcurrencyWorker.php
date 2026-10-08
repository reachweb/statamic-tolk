<?php

namespace Reach\StatamicTolk\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Events\RunFinished;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\TestCase;
use RuntimeException;
use Throwable;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * One process of tests/Feature/ConcurrencyTest.php, started through `concurrency-worker.php`. It boots the application
 * of the test suite on the test's runs, state and cache, and works in three phases, each started by all workers at the
 * same moment: claims, state updates, finalizes. What it did goes to `reports/{lane}.json`.
 */
final class ConcurrencyWorker extends TestCase
{
    public const string SCRIPT = __DIR__.'/concurrency-worker.php';

    public const int REFERENCES = 20;

    private static string $directory;

    private static int $lane;

    /**
     * @param  string  $directory  the test's temporary directory
     * @param  list<string>  $runIds  the run whose items are claimed, then the runs that are only finalized
     */
    public static function main(string $directory, int $lane, int $workers, int $claims, array $runIds): void
    {
        self::$directory = $directory;
        self::$lane = $lane;

        $worker = new self('work');
        $worker->setUp();

        // No tearDown(): Testbench's needs PHPUnit's runner, and the test deletes the worker's files with its own.
        $report = $worker->work($workers, $claims, $runIds);

        (new Filesystem)->put("{$directory}/reports/{$lane}.json", json_encode($report, JSON_THROW_ON_ERROR));
    }

    /**
     * The reference that update $n of a lane changes, and the field it adds. Every lane walks the references in the same
     * order, a few updates each, so the workers change the same reference at about the same time.
     *
     * @return array{0: string, 1: string}
     */
    public static function update(int $lane, int $n, int $updates): array
    {
        return ['entry::concurrency-'.intdiv($n * self::REFERENCES, $updates), "lane{$lane}_{$n}"];
    }

    /**
     * Own copy of Testbench's application, inside the test's directory: a worker must not share the base path of the
     * process that started it (tests/TestCase.php).
     */
    public static function applicationBasePath()
    {
        $path = self::$directory.'/apps/'.self::$lane;

        if (! is_dir($path)) {
            (new Filesystem)->copyDirectory(default_skeleton_path(), $path);
        }

        return $path;
    }

    protected function temporaryPath(string $path = ''): string
    {
        return rtrim(self::$directory.'/workers/'.self::$lane.'/'.$path, '/');
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cache.stores.tolk-test-file' => ['driver' => 'file', 'path' => self::$directory.'/cache'],
            'cache.default' => 'tolk-test-file',
            'tolk.state.path' => self::$directory.'/state',
            'tolk.runs.path' => self::$directory.'/runs',
            'tolk.usage.path' => self::$directory.'/usage',
        ]);
    }

    /**
     * $claims claimNext() calls on the first run, each item saved as done; then as many state updates; then a finalize()
     * of every run. An exception ends a phase early and is reported, but the worker still takes part in the next phases,
     * so the others do not wait for it.
     *
     * @param  list<string>  $runIds
     * @return array{claims: list<?array{index: int, token: string}>, finished: array<string, int>, errors: list<string>}
     */
    public function work(int $workers, int $claims, array $runIds): array
    {
        $repository = app(RunRepository::class);
        $runs = array_map(fn (string $id) => $repository->find($id) ?? throw new RuntimeException("The run [{$id}] does not exist."), $runIds);
        $report = ['claims' => [], 'finished' => [], 'errors' => []];

        Event::listen(RunFinished::class, function (RunFinished $event) use (&$report) {
            $report['finished'][$event->runId] = ($report['finished'][$event->runId] ?? 0) + 1;
        });

        $this->phase('claims', $workers, $report, function () use ($repository, $runs, $claims, &$report) {
            for ($n = 0; $n < $claims; $n++) {
                $item = $repository->claimNext($runs[0], 'worker-'.self::$lane, 60);
                $report['claims'][] = $item === null ? null : ['index' => $item->index, 'token' => $item->claim->token];

                if ($item !== null) {
                    $item->status = RunItemStatus::Done;
                    $repository->saveItem($runs[0], $item);
                }
            }
        });

        $this->phase('state', $workers, $report, function () use ($claims) {
            for ($n = 0; $n < $claims; $n++) {
                [$reference, $field] = self::update(self::$lane, $n, $claims);

                app(StateRepository::class)->update(TargetRef::parse($reference), fn (ItemState $state) => $state->withField($field, new FieldState(
                    sourceHash: hash('xxh64', "{$field}-source"),
                    targetHash: hash('xxh64', "{$field}-target"),
                    translatedAt: CarbonImmutable::now(),
                )));
            }
        });

        foreach ($runs as $k => $run) {
            $this->phase("finalize-{$k}", $workers, $report, fn () => app(RunProcessor::class)->finalize($run));
        }

        return $report;
    }

    private function phase(string $name, int $workers, array &$report, callable $callback): void
    {
        $directory = self::$directory.'/barriers/'.$name;
        $files = new Filesystem;

        // Forced: the workers create it at the same moment, and ensureDirectoryExists() fails for all but one of them.
        $files->makeDirectory($directory, 0755, true, true);
        $files->put($directory.'/'.self::$lane, '');

        for ($deadline = microtime(true) + 60; count($files->files($directory)) < $workers; usleep(1_000)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("The other workers did not reach [{$name}] within 60 seconds.");
            }
        }

        try {
            $callback();
        } catch (Throwable $exception) {
            $report['errors'][] = "{$name}: ".$exception::class.": {$exception->getMessage()}";
        }
    }
}
