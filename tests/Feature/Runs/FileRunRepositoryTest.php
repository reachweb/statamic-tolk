<?php

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\ClaimLost;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Runs\FileRunRepository;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();

    $this->runs = app(RunRepository::class);
    $this->root = config('tolk.runs.path');
});

function runRepoItem(int $index, array $attributes = []): RunItem
{
    return new RunItem(...[
        'index' => $index,
        'type' => ContentType::Entry,
        'sourceId' => "source-{$index}",
        'targetSite' => 'el',
        'title' => "Page {$index}",
        ...$attributes,
    ]);
}

/**
 * A running run with the given items, or six plain ones.
 */
function runRepoRun(?array $items = null, RunStatus $status = RunStatus::Running): Run
{
    $run = Run::make(RunDriver::Cli);
    $run->status = $status;

    return app(RunRepository::class)->create($run, $items ?? array_map(runRepoItem(...), range(0, 5)));
}

function runRepoCounts(array $counts): array
{
    return [...array_fill_keys(array_column(RunItemStatus::cases(), 'value'), 0), ...$counts];
}

function runRepoClaimAll(Run $run, string $worker): array
{
    $claimed = [];

    while ($item = app(RunRepository::class)->claimNext($run, $worker.count($claimed), 60)) {
        $claimed[] = $item->index;
    }

    return $claimed;
}

it('is bound to the file driver', function () {
    expect($this->runs)->toBeInstanceOf(FileRunRepository::class)
        ->and(app(RunRepository::class))->toBe($this->runs);
});

it('creates a run with six items in its own files', function () {
    $run = runRepoRun([
        runRepoItem(0),
        runRepoItem(1, ['dependsOn' => [0], 'targetSite' => 'de', 'title' => 'Ünïcode/slash']),
        ...array_map(runRepoItem(...), range(2, 5)),
    ]);
    $directory = "{$this->root}/{$run->id}";

    expect(File::files($directory))->toHaveCount(2)
        ->and("{$directory}/run.json")->toBeFile()
        ->and(File::files("{$directory}/items"))->toHaveCount(6)
        ->and(collect(File::allFiles($directory))->map->getExtension()->unique()->all())->toBe(['json'])
        ->and(json_decode(File::get("{$directory}/run.json"), true))->toBe($run->toArray())
        ->and(json_decode(File::get("{$directory}/items/1.json"), true))->toBe(runRepoItem(1, ['dependsOn' => [0], 'targetSite' => 'de', 'title' => 'Ünïcode/slash'])->toArray())
        ->and(File::get("{$directory}/items/1.json"))->toContain('"Ünïcode/slash"')
        ->and(json_decode(File::get("{$directory}/index.json"), true))->toBe([
            ['index' => 0, 'type' => 'entry', 'site' => 'el', 'stage' => 0, 'status' => 'pending', 'attempts' => 0],
            ['index' => 1, 'type' => 'entry', 'site' => 'de', 'stage' => 0, 'status' => 'pending', 'attempts' => 0, 'depends_on' => [0]],
            ['index' => 2, 'type' => 'entry', 'site' => 'el', 'stage' => 0, 'status' => 'pending', 'attempts' => 0],
            ['index' => 3, 'type' => 'entry', 'site' => 'el', 'stage' => 0, 'status' => 'pending', 'attempts' => 0],
            ['index' => 4, 'type' => 'entry', 'site' => 'el', 'stage' => 0, 'status' => 'pending', 'attempts' => 0],
            ['index' => 5, 'type' => 'entry', 'site' => 'el', 'stage' => 0, 'status' => 'pending', 'attempts' => 0],
        ])
        ->and($this->runs->find($run->id)->toArray())->toBe($run->toArray())
        ->and($this->runs->item($run, 3)->toArray())->toBe(runRepoItem(3)->toArray())
        ->and($this->runs->item($run, 6))->toBeNull()
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['pending' => 6]));
});

it('creates the items of a lazy iterable and refuses a run that exists or has two items with one index', function () {
    $run = Run::make(RunDriver::Queue);

    $this->runs->create($run, (function () {
        yield runRepoItem(1);
        yield runRepoItem(0);
    })());

    expect(array_map(fn (RunItem $item) => $item->index, $this->runs->items($run)))->toBe([0, 1])
        ->and(fn () => $this->runs->create($run, []))->toThrow(LogicException::class, 'already exists')
        ->and(fn () => $this->runs->create(Run::make(RunDriver::Queue), [runRepoItem(0), runRepoItem(0)]))->toThrow(LogicException::class, 'two items')
        ->and(fn () => $this->runs->create(new Run('../escape', RunDriver::Cli), []))->toThrow(InvalidArgumentException::class);
});

it('pages the items and filters them by status', function () {
    $run = runRepoRun(array_map(runRepoItem(...), range(0, 6)));
    $this->runs->claimNext($run, 'a', 60);
    $this->runs->claimNext($run, 'b', 60);

    $indexes = fn (array $items) => array_map(fn (RunItem $item) => $item->index, $items);

    expect($indexes($this->runs->items($run, null, 1, 3)))->toBe([0, 1, 2])
        ->and($indexes($this->runs->items($run, null, 3, 3)))->toBe([6])
        ->and($indexes($this->runs->items($run, null, 4, 3)))->toBe([])
        ->and($indexes($this->runs->items($run, RunItemStatus::Translating, 1, 10)))->toBe([0, 1])
        ->and($indexes($this->runs->items($run, RunItemStatus::Pending, 2, 3)))->toBe([5, 6])
        ->and($indexes($this->runs->items($run, RunItemStatus::Done)))->toBe([]);
});

it('counts the items per status for each content type and target site, in item order', function () {
    $run = runRepoRun([
        runRepoItem(0, ['targetSite' => 'de']),
        runRepoItem(1),
        runRepoItem(2, ['targetSite' => 'de', 'status' => RunItemStatus::Skipped]),
        runRepoItem(3, ['type' => ContentType::Term]),
    ]);
    $this->runs->claimNext($run, 'lane-1', 60);

    expect($this->runs->breakdown($run))->toBe([
        ['type' => 'entry', 'site' => 'de', 'counts' => runRepoCounts(['translating' => 1, 'skipped' => 1])],
        ['type' => 'entry', 'site' => 'el', 'counts' => runRepoCounts(['pending' => 1])],
        ['type' => 'term', 'site' => 'el', 'counts' => runRepoCounts(['pending' => 1])],
    ])
        ->and($this->runs->breakdown(Run::make(RunDriver::Cli)))->toBe([]);
});

it('gives a worker its own item back', function () {
    $run = runRepoRun();

    $first = $this->runs->claimNext($run, 'lane-1', 60);
    $other = $this->runs->claimNext($run, 'lane-2', 60);
    $again = $this->runs->claimNext($run, 'lane-1', 60);

    expect($first->index)->toBe(0)
        ->and($first->status)->toBe(RunItemStatus::Translating)
        ->and($first->claim->by)->toBe('lane-1')
        ->and($first->claim->expiresAt->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and($other->index)->toBe(1)
        ->and($again->index)->toBe(0)
        ->and($again->attempts)->toBe(0)
        ->and($again->claim->token)->not->toBe($first->claim->token)
        ->and($this->runs->item($run, 0)->claim)->toEqual($again->claim)
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['pending' => 4, 'translating' => 2]));
});

it('never gives two workers the same item', function () {
    $run = runRepoRun();

    $claimed = runRepoClaimAll($run, 'worker-');

    expect($claimed)->toBe([0, 1, 2, 3, 4, 5])
        ->and($this->runs->claimNext($run, 'late', 60))->toBeNull()
        ->and(collect($this->runs->items($run))->map(fn (RunItem $item) => $item->claim->by)->unique())->toHaveCount(6)
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['translating' => 6]));
});

it('hands nothing out while the run is pending, cancelled or aborted', function (RunStatus $status) {
    $run = runRepoRun(status: $status);

    expect($this->runs->claimNext($run, 'lane-1', 60))->toBeNull()
        ->and($this->runs->claimTranslated($run, 'apply', 60))->toBeNull()
        ->and($this->runs->nextAvailableIn($run))->toBeNull()
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['pending' => 6]))
        ->and($this->runs->find($run->id)->startedAt)->toBeNull();
})->with([RunStatus::Pending, RunStatus::Cancelled, RunStatus::Aborted]);

it('reads the run status from storage, records the start and progress, and refreshes the run it is given', function () {
    $stale = runRepoRun(status: RunStatus::Pending);
    $current = $this->runs->find($stale->id);
    $current->status = RunStatus::Running;
    $this->runs->save($current);

    $this->travel(5)->seconds();
    $this->runs->claimNext($stale, 'lane-1', 60);

    expect($stale->status)->toBe(RunStatus::Running)
        ->and($stale->startedAt->equalTo(now()))->toBeTrue()
        ->and($stale->progressedAt->equalTo(now()))->toBeTrue()
        ->and($stale->changes())->toBe([]);

    $this->travel(5)->seconds();
    $this->runs->claimNext($stale, 'lane-2', 60);

    $stored = $this->runs->find($stale->id);

    expect($stored->startedAt->equalTo(now()->subSeconds(5)))->toBeTrue()
        ->and($stored->progressedAt->equalTo(now()))->toBeTrue();
});

it('hands an expired claim over, counts an attempt and fails the item on the third takeover', function () {
    $run = runRepoRun([runRepoItem(0), runRepoItem(1, ['dependsOn' => [0]])]);

    $first = $this->runs->claimNext($run, 'lane-1', 60);
    $this->travel(59)->seconds();

    expect($this->runs->claimNext($run, 'lane-2', 60))->toBeNull()
        ->and($this->runs->nextAvailableIn($run))->toBeNull();

    $this->travel(1)->seconds();

    expect($this->runs->nextAvailableIn($run))->toBe(0);

    $second = $this->runs->claimNext($run, 'lane-2', 60);

    expect($second->index)->toBe(0)
        ->and($second->attempts)->toBe(1)
        ->and($second->claim->by)->toBe('lane-2')
        ->and(fn () => $this->runs->saveItem($run, $first))->toThrow(ClaimLost::class);

    $this->travel(60)->seconds();
    $third = $this->runs->claimNext($run, 'lane-3', 60);

    expect($third->index)->toBe(0)
        ->and($third->attempts)->toBe(2);

    $this->travel(60)->seconds();

    expect($this->runs->claimNext($run, 'lane-4', 60))->toBeNull();

    $failed = $this->runs->item($run, 0);

    expect($failed->status)->toBe(RunItemStatus::Failed)
        ->and($failed->attempts)->toBe(3)
        ->and($failed->claim)->toBeNull()
        ->and($failed->error)->toBe('No worker finished this item in 3 attempts.')
        ->and($this->runs->item($run, 1)->status)->toBe(RunItemStatus::Skipped)
        ->and($this->runs->item($run, 1)->reason)->toBe(RunItem::DEPENDENCY_FAILED)
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['skipped' => 1, 'failed' => 1]))
        ->and($this->runs->nextAvailableIn($run))->toBeNull();
});

it('rejects a caller whose claim token is no longer the stored one', function () {
    $run = runRepoRun([runRepoItem(0)]);
    $lost = $this->runs->claimNext($run, 'lane-1', 60);
    $this->travel(61)->seconds();
    $holder = $this->runs->claimNext($run, 'lane-2', 60);

    $lost->status = RunItemStatus::Translated;
    $unclaimed = runRepoItem(0);

    expect(fn () => $this->runs->saveItem($run, $lost))->toThrow(ClaimLost::class)
        ->and(fn () => $this->runs->extendClaim($run, $lost))->toThrow(ClaimLost::class)
        ->and(fn () => $this->runs->release($run, $lost))->toThrow(ClaimLost::class)
        ->and(fn () => $this->runs->saveItem($run, $unclaimed))->toThrow(ClaimLost::class)
        ->and($this->runs->item($run, 0)->status)->toBe(RunItemStatus::Translating)
        ->and($this->runs->item($run, 0)->claim->token)->toBe($holder->claim->token);

    $holder->status = RunItemStatus::Translated;
    $this->runs->saveItem($run, $holder);

    // The claim moved on with the item, so the old holder is refused even though nobody holds the item now.
    expect(fn () => $this->runs->saveItem($run, $lost))->toThrow(ClaimLost::class)
        ->and($this->runs->item($run, 0)->claim)->toBeNull();

    $unclaimed->status = RunItemStatus::Translated;
    $unclaimed->planId = 'plan-1';
    $this->runs->saveItem($run, $unclaimed);

    expect($this->runs->item($run, 0)->planId)->toBe('plan-1');
});

it('saves an item, keeps its claim while it is held and drops it afterwards', function () {
    $run = runRepoRun([runRepoItem(0)]);
    $item = $this->runs->claimNext($run, 'lane-1', 60);
    $token = $item->claim->token;

    $item->planId = 'plan-1';
    $item->fields = ['title' => ['status' => 'applied']];
    $item->usage = $item->usage->add(new ModelRef('openai', 'gpt-6-luna'), new Usage(input: 10, output: 4, calls: 1));
    $item->claim = null;
    $this->travel(30)->seconds();
    expect(fn () => $this->runs->saveItem($run, $item))->toThrow(ClaimLost::class);

    $item = $this->runs->item($run, 0);
    $extending = $this->runs->item($run, 0);
    $this->runs->extendClaim($run, $extending);
    $item->planId = 'plan-1';
    $this->runs->saveItem($run, $item);

    expect($item->claim->token)->toBe($token)
        ->and($item->claim->expiresAt->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and($this->runs->item($run, 0)->toArray())->toBe($item->toArray());

    $item->status = RunItemStatus::Translated;
    $this->runs->saveItem($run, $item);

    expect($item->claim)->toBeNull()
        ->and($this->runs->item($run, 0)->claim)->toBeNull()
        ->and($this->runs->item($run, 0)->planId)->toBe('plan-1');
});

it('extends a claim by its own lifetime', function () {
    $run = runRepoRun([runRepoItem(0)]);
    $item = $this->runs->claimNext($run, 'lane-1', 60);

    $this->travel(50)->seconds();
    $this->runs->extendClaim($run, $item);

    expect($item->claim->expiresAt->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and($this->runs->item($run, 0)->claim)->toEqual($item->claim);

    $this->travel(50)->seconds();

    expect($this->runs->claimNext($run, 'lane-2', 60))->toBeNull();

    $this->runs->saveItem($run, $item);
});

it('releases an item back to pending or translated, available from the given time', function () {
    $run = runRepoRun([runRepoItem(0), runRepoItem(1)]);
    $item = $this->runs->claimNext($run, 'lane-1', 60);
    $item->waits = 1;

    $this->runs->release($run, $item, now()->addSeconds(30));

    expect($item->status)->toBe(RunItemStatus::Pending)
        ->and($item->claim)->toBeNull()
        ->and($this->runs->item($run, 0)->toArray())->toBe($item->toArray())
        ->and($this->runs->item($run, 0)->waits)->toBe(1);

    $other = $this->runs->claimNext($run, 'lane-1', 60);
    $other->status = RunItemStatus::Translated;
    $this->runs->saveItem($run, $other);
    $applying = $this->runs->claimTranslated($run, 'apply', 60);

    expect($applying->index)->toBe(1)
        ->and($applying->status)->toBe(RunItemStatus::Applying);

    $this->runs->release($run, $applying);

    expect($this->runs->item($run, 1)->status)->toBe(RunItemStatus::Translated)
        ->and($this->runs->item($run, 1)->claim)->toBeNull()
        ->and($this->runs->item($run, 1)->availableAt)->toBeNull();
});

it('applies the lowest translated item, the worker\'s own first, and takes over an expired apply', function () {
    $run = runRepoRun(array_map(runRepoItem(...), range(0, 2)));

    foreach (range(1, 3) as $lane) {
        $item = $this->runs->claimNext($run, "lane-{$lane}", 60);
        $item->status = RunItemStatus::Translated;
        $this->runs->saveItem($run, $item);
    }

    $first = $this->runs->claimTranslated($run, 'apply-1', 60);
    $second = $this->runs->claimTranslated($run, 'apply-2', 60);

    expect($first->index)->toBe(0)
        ->and($second->index)->toBe(1)
        ->and($this->runs->claimTranslated($run, 'apply-1', 60)->index)->toBe(0)
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['translated' => 1, 'applying' => 2]));

    $this->travel(61)->seconds();
    $third = $this->runs->claimTranslated($run, 'apply-3', 60);
    $takeover = $this->runs->claimTranslated($run, 'apply-4', 60);

    expect($third->index)->toBe(2)
        ->and($third->attempts)->toBe(0)
        ->and($takeover->index)->toBe(0)
        ->and($takeover->attempts)->toBe(1)
        ->and($takeover->status)->toBe(RunItemStatus::Applying);
});

it('holds a child back until its parent is done', function () {
    $run = runRepoRun([
        runRepoItem(0),
        runRepoItem(1, ['dependsOn' => [0]]),
        runRepoItem(2, ['dependsOn' => [1]]),
        runRepoItem(3, ['dependsOn' => [9]]),
    ]);

    $parent = $this->runs->claimNext($run, 'lane-1', 60);

    expect($parent->index)->toBe(0)
        ->and($this->runs->claimNext($run, 'lane-2', 60)->index)->toBe(3)
        ->and($this->runs->claimNext($run, 'lane-3', 60))->toBeNull()
        ->and($this->runs->nextAvailableIn($run))->toBeNull();

    $parent->status = RunItemStatus::Translated;
    $this->runs->saveItem($run, $parent);

    expect($this->runs->claimNext($run, 'lane-3', 60))->toBeNull();

    $parent = $this->runs->claimTranslated($run, 'apply', 60);
    $parent->status = RunItemStatus::Done;
    $this->runs->saveItem($run, $parent);

    expect($this->runs->nextAvailableIn($run))->toBe(0)
        ->and($this->runs->claimNext($run, 'lane-3', 60)->index)->toBe(1)
        ->and($this->runs->claimNext($run, 'lane-4', 60))->toBeNull();
});

it('lets a skipped parent through and skips the descendants of a failed one', function () {
    $run = runRepoRun([
        runRepoItem(0),
        runRepoItem(1, ['dependsOn' => [0]]),
        runRepoItem(2, ['dependsOn' => [1]]),
        runRepoItem(3, ['dependsOn' => [2], 'targetSite' => 'de']),
        runRepoItem(4),
        runRepoItem(5, ['dependsOn' => [4]]),
    ]);

    $failing = $this->runs->claimNext($run, 'lane-1', 60);
    $skipping = $this->runs->claimNext($run, 'lane-2', 60);

    expect([$failing->index, $skipping->index])->toBe([0, 4]);

    $failing->status = RunItemStatus::Failed;
    $failing->error = 'The provider refused.';
    $this->runs->saveItem($run, $failing);

    expect($this->runs->counts($run))->toBe(runRepoCounts(['translating' => 1, 'pending' => 1, 'skipped' => 3, 'failed' => 1]))
        ->and(collect([1, 2, 3])->map(fn (int $index) => $this->runs->item($run, $index))->every(
            fn (RunItem $item) => $item->status === RunItemStatus::Skipped && $item->reason === RunItem::DEPENDENCY_FAILED,
        ))->toBeTrue()
        ->and($this->runs->claimNext($run, 'lane-3', 60))->toBeNull();

    $skipping->status = RunItemStatus::Skipped;
    $skipping->reason = 'nothing_to_do';
    $this->runs->saveItem($run, $skipping);

    expect($this->runs->claimNext($run, 'lane-3', 60)->index)->toBe(5);
});

it('marks a child skipped on the next claim when its parent failed', function () {
    $run = runRepoRun([
        runRepoItem(0, ['status' => RunItemStatus::Failed]),
        runRepoItem(1, ['dependsOn' => [0]]),
        runRepoItem(2, ['status' => RunItemStatus::Skipped, 'reason' => RunItem::DEPENDENCY_FAILED]),
        runRepoItem(3, ['dependsOn' => [2]]),
    ]);

    expect($this->runs->counts($run))->toBe(runRepoCounts(['pending' => 2, 'skipped' => 1, 'failed' => 1]))
        ->and($this->runs->nextAvailableIn($run))->toBeNull()
        ->and($this->runs->claimNext($run, 'lane-1', 60))->toBeNull()
        ->and($this->runs->item($run, 1)->status)->toBe(RunItemStatus::Skipped)
        ->and($this->runs->item($run, 1)->reason)->toBe(RunItem::DEPENDENCY_FAILED)
        ->and($this->runs->item($run, 3)->reason)->toBe(RunItem::DEPENDENCY_FAILED)
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['skipped' => 3, 'failed' => 1]));
});

it('puts failed items and the items their failure skipped back to pending, all at once', function () {
    $later = CarbonImmutable::now()->addMinute();
    // Item 1 waits for item 3, which is later in the run, so resetting one item at a time would skip it again.
    $run = runRepoRun([
        runRepoItem(0, ['status' => RunItemStatus::Done]),
        runRepoItem(1, ['dependsOn' => [3], 'status' => RunItemStatus::Skipped, 'reason' => RunItem::DEPENDENCY_FAILED]),
        runRepoItem(2, ['status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do']),
        runRepoItem(3, ['dependsOn' => [5], 'status' => RunItemStatus::Skipped, 'reason' => RunItem::DEPENDENCY_FAILED]),
        runRepoItem(4, ['status' => RunItemStatus::Failed, 'reason' => 'root_missing', 'error' => 'Translate Home into Greek first.', 'attempts' => 2, 'waits' => 4, 'planId' => 'plan-4', 'availableAt' => $later, 'warnings' => ['Kept.']]),
        runRepoItem(5, ['status' => RunItemStatus::Failed, 'error' => 'Boom', 'attempts' => 3]),
    ]);
    $this->travel(5)->seconds();

    expect($this->runs->retryFailed($run))->toBe(4)
        ->and($this->runs->counts($run))->toBe(runRepoCounts(['done' => 1, 'skipped' => 1, 'pending' => 4]))
        ->and($this->runs->item($run, 4))->toMatchObject([
            'status' => RunItemStatus::Pending, 'reason' => null, 'error' => null, 'attempts' => 0, 'waits' => 0,
            'availableAt' => null, 'claim' => null, 'planId' => 'plan-4', 'warnings' => ['Kept.'],
        ])
        ->and($this->runs->item($run, 1)->reason)->toBeNull()
        ->and($run->progressedAt->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and(runRepoClaimAll($run, 'lane-'))->toBe([4, 5])
        ->and($this->runs->retryFailed($run))->toBe(0);
});

it('holds a stage-1 item back until the stage-0 items of its site are finished', function () {
    $run = runRepoRun([
        runRepoItem(0, ['stage' => 1]),
        runRepoItem(1, ['stage' => 1, 'targetSite' => 'de']),
        runRepoItem(2),
        runRepoItem(3, ['targetSite' => 'de']),
        runRepoItem(4, ['targetSite' => 'de']),
    ]);

    $greek = $this->runs->claimNext($run, 'lane-1', 60);
    $german = $this->runs->claimNext($run, 'lane-2', 60);
    $failing = $this->runs->claimNext($run, 'lane-3', 60);

    expect([$greek->index, $german->index, $failing->index])->toBe([2, 3, 4])
        ->and($this->runs->claimNext($run, 'lane-4', 60))->toBeNull();

    $german->status = RunItemStatus::Translated;
    $this->runs->saveItem($run, $german);

    expect($this->runs->claimNext($run, 'lane-4', 60))->toBeNull();

    $german = $this->runs->claimTranslated($run, 'apply', 60);
    $german->status = RunItemStatus::Done;
    $this->runs->saveItem($run, $german);

    expect($this->runs->claimNext($run, 'lane-4', 60))->toBeNull();

    $failing->status = RunItemStatus::Failed;
    $this->runs->saveItem($run, $failing);

    expect($this->runs->claimNext($run, 'lane-4', 60)->index)->toBe(1)
        ->and($this->runs->claimNext($run, 'lane-5', 60))->toBeNull();

    $greek->status = RunItemStatus::Skipped;
    $this->runs->saveItem($run, $greek);

    expect($this->runs->claimNext($run, 'lane-5', 60)->index)->toBe(0);
});

it('skips an item that is not available yet and reports when it will be', function () {
    $run = runRepoRun([runRepoItem(0), runRepoItem(1)]);

    $paused = $this->runs->claimNext($run, 'lane-1', 60);
    $this->runs->release($run, $paused, now()->addSeconds(30));

    expect($this->runs->nextAvailableIn($run))->toBe(0)
        ->and($this->runs->claimNext($run, 'lane-1', 60)->index)->toBe(1)
        ->and($this->runs->claimNext($run, 'lane-2', 60))->toBeNull()
        ->and($this->runs->nextAvailableIn($run))->toBe(30);

    $this->travel(12)->seconds();

    expect($this->runs->nextAvailableIn($run))->toBe(18);

    $this->travel(18)->seconds();

    expect($this->runs->nextAvailableIn($run))->toBe(0)
        ->and($this->runs->claimNext($run, 'lane-2', 60)->index)->toBe(0)
        ->and($this->runs->item($run, 0)->availableAt)->toBeNull()
        ->and($this->runs->nextAvailableIn($run))->toBeNull();
});

it('counts the items after every transition', function () {
    $run = runRepoRun([runRepoItem(0), runRepoItem(1), runRepoItem(2)]);
    $counts = fn () => $this->runs->counts($run);

    expect($counts())->toBe(runRepoCounts(['pending' => 3]));

    $item = $this->runs->claimNext($run, 'lane-1', 60);
    expect($counts())->toBe(runRepoCounts(['pending' => 2, 'translating' => 1]));

    $item->status = RunItemStatus::Translated;
    $this->runs->saveItem($run, $item);
    expect($counts())->toBe(runRepoCounts(['pending' => 2, 'translated' => 1]));

    $item = $this->runs->claimTranslated($run, 'apply', 60);
    expect($counts())->toBe(runRepoCounts(['pending' => 2, 'applying' => 1]));

    $item->status = RunItemStatus::Done;
    $this->runs->saveItem($run, $item);
    expect($counts())->toBe(runRepoCounts(['pending' => 2, 'done' => 1]));

    $item = $this->runs->claimNext($run, 'lane-1', 60);
    $this->runs->release($run, $item);
    expect($counts())->toBe(runRepoCounts(['pending' => 2, 'done' => 1]));

    $item = $this->runs->claimNext($run, 'lane-1', 60);
    $item->status = RunItemStatus::Skipped;
    $this->runs->saveItem($run, $item);
    expect($counts())->toBe(runRepoCounts(['pending' => 1, 'done' => 1, 'skipped' => 1]));

    $item = $this->runs->claimNext($run, 'lane-1', 60);
    $item->status = RunItemStatus::Failed;
    $this->runs->saveItem($run, $item);
    expect($counts())->toBe(runRepoCounts(['done' => 1, 'skipped' => 1, 'failed' => 1]));
});

it('saves only what changed, so a stale copy does not undo another process\'s change', function () {
    $run = runRepoRun(status: RunStatus::Pending);
    $estimating = $this->runs->find($run->id);

    $run->status = RunStatus::Running;
    $run->generation = 1;
    $this->runs->save($run);

    $estimating->estimate = ['segments' => 12, 'cost' => 0.5];
    $this->runs->save($estimating);

    $stored = $this->runs->find($run->id);

    expect($stored->status)->toBe(RunStatus::Running)
        ->and($stored->generation)->toBe(1)
        ->and($stored->estimate)->toBe(['segments' => 12, 'cost' => 0.5])
        ->and($estimating->status)->toBe(RunStatus::Running)
        ->and($estimating->changes())->toBe([])
        ->and($estimating->toArray())->toBe($stored->toArray());

    $unchanged = $this->runs->find($run->id);
    $stored->error = 'Stopped.';
    $this->runs->save($stored);
    $this->runs->save($unchanged);

    expect($unchanged->error)->toBe('Stopped.')
        ->and(fn () => $this->runs->save(Run::make(RunDriver::Cli)))->toThrow(RunNotFound::class);
});

it('adds usage per model under the lock and keeps it when a stale copy is saved', function () {
    $run = runRepoRun();
    $stale = $this->runs->find($run->id);
    $translator = new ModelRef('anthropic', 'claude-sonnet-5-5');
    $reviewer = new ModelRef('gemini', 'gemini-3.8-flash');

    $this->travel(3)->seconds();
    $this->runs->addUsage($run, $translator, new Usage(input: 100, output: 40, calls: 1));
    $this->runs->addUsage($run, $translator, new Usage(input: 50, output: 20, cacheRead: 30, calls: 1));
    $this->runs->addUsage($stale, $reviewer, new Usage(input: 10, output: 2, calls: 1));

    $stale->status = RunStatus::Cancelled;
    $this->runs->save($stale);

    $stored = $this->runs->find($run->id);

    expect($stored->usage->toArray())->toBe([
        'anthropic:claude-sonnet-5-5' => (new Usage(input: 150, output: 60, cacheRead: 30, calls: 2))->toArray(),
        'gemini:gemini-3.8-flash' => (new Usage(input: 10, output: 2, calls: 1))->toArray(),
    ])
        ->and($stored->status)->toBe(RunStatus::Cancelled)
        ->and($stored->progressedAt->equalTo(now()))->toBeTrue()
        ->and($run->usage->get($translator)->input)->toBe(150)
        ->and($run->usage->get($reviewer)->calls)->toBe(0)
        ->and($stale->usage->toArray())->toBe($stored->usage->toArray());
});

it('keeps unsaved changes of the run it refreshes', function () {
    $run = runRepoRun();

    $run->status = RunStatus::Aborted;
    $run->error = 'The provider rejected the key.';
    $this->runs->claimNext($run, 'lane-1', 60);

    expect($run->status)->toBe(RunStatus::Aborted)
        ->and($run->startedAt)->not->toBeNull()
        ->and($this->runs->find($run->id)->status)->toBe(RunStatus::Running);

    $this->runs->save($run);

    expect($this->runs->find($run->id)->status)->toBe(RunStatus::Aborted)
        ->and($this->runs->find($run->id)->error)->toBe('The provider rejected the key.');
});

it('lists the recent runs, newest first', function () {
    $runs = [];

    foreach (range(1, 3) as $i) {
        $runs[] = runRepoRun([runRepoItem(0)]);
        $this->travel(1)->seconds();
    }

    File::ensureDirectoryExists("{$this->root}/01ZZZZZZZZZZZZZZZZZZZZZZZZ");

    expect(array_map(fn (Run $run) => $run->id, $this->runs->recent(2)))->toBe([$runs[2]->id, $runs[1]->id])
        ->and($this->runs->recent(10))->toHaveCount(3)
        ->and($this->runs->recent(0))->toBe([]);
});

it('finds running runs without progress for that long', function () {
    $idle = runRepoRun([runRepoItem(0)], RunStatus::Pending);
    $finished = runRepoRun([runRepoItem(0)], RunStatus::Completed);
    $stalled = runRepoRun([runRepoItem(0)]);
    $this->runs->claimNext($stalled, 'lane-1', 60);
    $busy = runRepoRun([runRepoItem(0)]);
    $item = $this->runs->claimNext($busy, 'lane-1', 60);

    $this->travel(200)->seconds();
    $this->runs->extendClaim($busy, $item);
    $this->travel(101)->seconds();

    expect(array_map(fn (Run $run) => $run->id, $this->runs->stale(300)))->toBe([$stalled->id])
        ->and($this->runs->stale(400))->toBe([]);
});

it('prunes the runs without activity for that many days and nothing else', function () {
    $old = runRepoRun([runRepoItem(0)], RunStatus::Completed);
    $oldPending = runRepoRun([runRepoItem(0)], RunStatus::Pending);
    $active = runRepoRun([runRepoItem(0)]);
    $item = $this->runs->claimNext($active, 'lane-1', 60);

    $this->travel(20)->days();
    $this->runs->saveItem($active, $item);
    $this->travel(11)->days();
    $recent = runRepoRun([runRepoItem(0)], RunStatus::Completed);

    expect($this->runs->prune(30))->toBe(2)
        ->and("{$this->root}/{$old->id}")->not->toBeDirectory()
        ->and("{$this->root}/{$oldPending->id}")->not->toBeDirectory()
        ->and($this->runs->find($old->id))->toBeNull()
        ->and(array_map(fn (Run $run) => $run->id, $this->runs->recent(10)))->toBe([$recent->id, $active->id])
        ->and($this->runs->prune(30))->toBe(0);
});

it('deletes a run with its files', function () {
    $run = runRepoRun();

    $this->runs->delete($run);

    expect("{$this->root}/{$run->id}")->not->toBeDirectory()
        ->and($this->runs->find($run->id))->toBeNull()
        ->and($this->runs->counts($run))->toBe(runRepoCounts([]))
        ->and($this->runs->claimNext($run, 'lane-1', 60))->toBeNull()
        ->and(fn () => $this->runs->addUsage($run, new ModelRef('openai', 'gpt-6-luna'), new Usage))->toThrow(RunNotFound::class)
        ->and(fn () => $this->runs->saveItem($run, runRepoItem(0)))->toThrow(RunNotFound::class);
});

it('reads an unknown, invalid or unreadable run as missing', function () {
    $run = runRepoRun();
    File::put("{$this->root}/{$run->id}/items/2.json", '{"index": 2, "type": "page"}');
    File::put("{$this->root}/{$run->id}/items/3.json", 'not json');

    expect($this->runs->find('01JUNKNOWNRUN0000000000000'))->toBeNull()
        ->and($this->runs->find('../../etc'))->toBeNull()
        ->and($this->runs->item($run, 2))->toBeNull()
        ->and($this->runs->item($run, 3))->toBeNull()
        ->and(array_map(fn (RunItem $item) => $item->index, $this->runs->items($run)))->toBe([0, 1, 4, 5])
        ->and(fn () => $this->runs->saveItem($run, runRepoItem(7)))->toThrow(RunNotFound::class);

    $moved = runRepoRun();
    File::copy("{$this->root}/{$moved->id}/run.json", "{$this->root}/{$run->id}/run.json");

    expect($this->runs->find($run->id))->toBeNull()
        ->and(array_map(fn (Run $run) => $run->id, $this->runs->recent(10)))->toBe([$moved->id]);

    File::put("{$this->root}/{$run->id}/run.json", '{"id": "'.$run->id.'", "status": "lost", "driver": "cli"}');
    File::put("{$this->root}/{$moved->id}/run.json", '[');

    expect($this->runs->find($run->id))->toBeNull()
        ->and($this->runs->recent(10))->toBe([]);
});

it('writes every file under the run\'s lock on the configured store, through a temporary file and a rename', function () {
    config([
        'cache.stores.tolk-run-locks' => ['driver' => 'file', 'path' => $this->temporaryPath('locks')],
        'tolk.cache_store' => 'tolk-run-locks',
    ]);

    $files = new class extends Filesystem
    {
        public ?string $runId = null;

        public array $writes = [];

        public function put($path, $contents, $lock = false)
        {
            $runLock = Cache::store('tolk-run-locks')->lock('tolk:run:'.$this->runId, 10);
            $defaultLock = Cache::lock('tolk:run:'.$this->runId, 10);
            $this->writes[] = [
                'path' => $path,
                'locked' => ! tap($runLock->get(), fn ($free) => $free && $runLock->release()),
                'locked_on_default' => ! tap($defaultLock->get(), fn ($free) => $free && $defaultLock->release()),
            ];

            return parent::put($path, $contents, $lock);
        }

        public function move($path, $target)
        {
            $this->writes[] = ['path' => $path, 'target' => basename($target)];

            return parent::move($path, $target);
        }
    };

    $runs = new FileRunRepository($files);
    $run = Run::make(RunDriver::Cli);
    $run->status = RunStatus::Running;
    $files->runId = $run->id;

    $runs->create($run, [runRepoItem(0), runRepoItem(1)]);
    $item = $runs->claimNext($run, 'lane-1', 60);
    $runs->extendClaim($run, $item);
    $item->status = RunItemStatus::Translated;
    $runs->saveItem($run, $item);
    $item = $runs->claimTranslated($run, 'apply', 60);
    $runs->release($run, $item);
    $runs->addUsage($run, new ModelRef('openai', 'gpt-6-luna'), new Usage(input: 1, calls: 1));
    $run->generation = 2;
    $runs->save($run);

    $puts = array_values(array_filter($files->writes, fn (array $write) => isset($write['locked'])));
    $moves = array_values(array_filter($files->writes, fn (array $write) => isset($write['target'])));

    expect($puts)->toHaveCount(21)
        ->and(collect($puts)->every(fn (array $write) => $write['locked'] && ! $write['locked_on_default'] && str_ends_with($write['path'], '.tmp')))->toBeTrue()
        ->and(array_column($moves, 'path'))->toBe(array_column($puts, 'path'))
        ->and(array_count_values(array_column($moves, 'target')))->toBe(['0.json' => 6, '1.json' => 1, 'index.json' => 6, 'run.json' => 8])
        ->and(collect(File::allFiles("{$this->root}/{$run->id}"))->map->getExtension()->unique()->all())->toBe(['json'])
        ->and(Cache::store('tolk-run-locks')->lock('tolk:run:'.$run->id, 10)->get())->toBeTrue();
});

it('leaves no temporary file behind when a write fails', function () {
    $files = new class extends Filesystem
    {
        public function move($path, $target)
        {
            return false;
        }
    };

    $run = Run::make(RunDriver::Cli);

    expect(fn () => (new FileRunRepository($files))->create($run, [runRepoItem(0)]))->toThrow(RuntimeException::class, 'Unable to write the run file')
        ->and(File::allFiles($this->root))->toBe([]);
});
