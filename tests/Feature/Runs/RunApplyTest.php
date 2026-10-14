<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Events\RunFinished;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Runs\ApplyReport;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Git\Subscriber;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    Queue::fake();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
    $this->again = $this->makeEntry('articles', 'en', ['title' => 'Again', 'intro' => 'A third intro.'], 'again');

    $this->runs = app(RunRepository::class);
});

function applyRun(Run $run, string $worker = 'apply-1', int $seconds = 30): ApplyReport
{
    return app(RunProcessor::class)->applyReady($run, $worker, CarbonImmutable::now()->addSeconds($seconds));
}

function greekStatus(Statamic\Contracts\Entries\Entry $origin): ItemStatus
{
    return app(StatusResolver::class)->resolve(app(TargetFactory::class)->forEntry((string) $origin->id(), 'el'))->itemStatus;
}

describe('applyReady', function () {
    it('applies every translated item and leaves each target current', function () {
        Event::fake([TargetTranslated::class, RunFinished::class]);
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);
        $plans = array_map(fn (RunItem $item) => $item->planId, RunFixtures::items($run));

        $report = applyRun($run);
        $items = RunFixtures::items($run);
        $greek = $this->hello->fresh()->in('el');

        expect($report->toArray())->toBe(['items' => 3, 'returned' => 0, 'remains' => false, 'wait' => null])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done', 'done'])
            ->and($items[0]->fields)->toBe(['title' => 'applied', 'intro' => 'applied'])
            ->and($items[0]->reason)->toBeNull()
            ->and($items[0]->planId)->toBeNull()
            ->and($items[0]->claim)->toBeNull()
            ->and($items[0]->reference)->toBe('entry::'.$greek->id())
            ->and($items[0]->editUrl)->toBe($greek->editUrl())
            ->and($greek->get('title'))->toBe('[T] Hello')
            ->and($greek->published())->toBeFalse()
            ->and(array_map(greekStatus(...), [$this->hello, $this->world, $this->again]))->toBe(array_fill(0, 3, ItemStatus::Current))
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::stored($run)->saves)->toBe(3);

        foreach ($plans as $plan) {
            expect(app(PlanStore::class)->get($plan))->toBeNull();
        }

        Event::assertDispatchedTimes(TargetTranslated::class, 3);
        Event::assertDispatched(TargetTranslated::class, fn (TargetTranslated $event) => (string) $event->ref === 'entry::'.$greek->id()
            && $event->handles === ['title', 'intro']
            && $event->models === array_keys($items[0]->usage->usages));
        Event::assertDispatchedTimes(RunFinished::class, 1);
    });

    it('takes the write lock before claiming and applies nothing while another process holds it', function () {
        Sleep::fake(syncWithCarbon: true);
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        RunFixtures::translate($run);
        $lock = Cache::lock(ContentWriter::LOCK, 60);
        $lock->get();
        $start = CarbonImmutable::now();

        $report = applyRun($run, seconds: 2);

        expect($start->diffInMilliseconds(CarbonImmutable::now()))->toBeLessThanOrEqual(2000)
            ->and($report->toArray())->toBe(['items' => 0, 'returned' => 0, 'remains' => true, 'wait' => RunProcessor::BUSY_SECONDS])
            ->and(RunFixtures::statuses($run))->toBe(['translated'])
            ->and(RunFixtures::items($run)[0]->claim)->toBeNull();

        $lock->release();

        expect(applyRun($run)->items)->toBe(1)
            ->and(RunFixtures::statuses($run))->toBe(['done']);
    });

    it('applies nothing once the deadline has passed and nothing of a run that is not running', function () {
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);

        $late = applyRun($run, seconds: 0);
        $run->status = RunStatus::Cancelled;
        $this->runs->save($run);
        $cancelled = applyRun($run);

        expect($late->toArray())->toBe(['items' => 0, 'returned' => 0, 'remains' => true, 'wait' => 0])
            ->and($cancelled->toArray())->toBe(['items' => 0, 'returned' => 0, 'remains' => false, 'wait' => null])
            ->and(RunFixtures::statuses($run))->toBe(['translated', 'translated', 'translated'])
            ->and(Entry::query()->where('site', 'el')->count())->toBe(0);
    });

    it('applies a child under its localized parent', function () {
        $this->fakeTranslations();
        $this->makePages(['home', 'rooms' => ['suites'], 'dining']);
        $run = RunFixtures::started(['collections' => ['pages']]);

        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'done', 'done', 'done'])
            ->and($this->treeOfSlugs('el'))->toBe($this->treeOfSlugs('en'))
            ->and($this->treeOfSlugs('el'))->toBe(['home' => [], 'rooms' => ['suites' => []], 'dining' => []])
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed);
    });

    it('gives the target the origin\'s order when it applies the pages out of order, as parallel lanes finish them', function () {
        $this->fakeTranslations();
        $this->makePages(['home', 'about', 'rooms', 'dining', 'spa']);
        $run = RunFixtures::started(['collections' => ['pages']]);
        RunFixtures::translate($run);
        applyRun($run);
        RunFixtures::translate($run);

        // Another lane still holds About and Rooms while Dining and Spa are applied.
        $held = [$this->runs->claimTranslated($run, 'lane-2', 300), $this->runs->claimTranslated($run, 'lane-3', 300)];
        applyRun($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'applying', 'applying', 'done', 'done']);

        foreach ($held as $item) {
            $this->runs->release($run, $item);
        }

        applyRun($run);

        expect(RunFixtures::statuses($run))->toBe(array_fill(0, 5, 'done'))
            ->and($this->treeOfSlugs('el'))->toBe($this->treeOfSlugs('en'));
    });

    it('keeps the planner\'s warnings and adds those of the apply', function () {
        $this->fakeTranslations();
        $pages = $this->makePages(['home', 'rooms' => ['suites']], root: false);
        $this->makeEntry('pages', 'el', ['title' => 'Άλλη'], 'suites');
        $run = RunFixtures::started(['entryIds' => [$pages['suites']->id()]]);

        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done'])
            ->and(RunFixtures::items($run)[0]->warnings)->toBe([
                'Its parent page, Rooms, has no localization in Greek, so this page will be placed at the top level.',
                'The slug "suites" is already used in Greek, so the new localization got "suites-2".',
            ]);
    });

    it('sends an item whose origin changed after translating back to pending, and applies it on the next round', function () {
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);
        $plan = RunFixtures::items($run)[0]->planId;
        $this->hello->set('title', 'Hello there')->save();

        $report = applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect($report->toArray())->toBe(['items' => 2, 'returned' => 1, 'remains' => false, 'wait' => null])
            ->and(RunFixtures::statuses($run))->toBe(['pending', 'done', 'done'])
            ->and($item->reason)->toBe('source_changed')
            ->and($item->planId)->toBeNull()
            ->and($item->claim)->toBeNull()
            ->and(app(PlanStore::class)->get($plan))->toBeNull()
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse()
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Running);

        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'done', 'done'])
            ->and(RunFixtures::items($run)[0]->reason)->toBeNull()
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello there')
            ->and(greekStatus($this->hello))->toBe(ItemStatus::Current)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed);
    });

    it('fails an item whose origin changed again before its second apply', function () {
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);

        foreach (['Hello there', 'Hello again'] as $title) {
            RunFixtures::translate($run);
            $this->hello->set('title', $title)->save();
            applyRun($run);
        }

        $item = RunFixtures::items($run)[0];

        expect($item->status)->toBe(RunItemStatus::Failed)
            ->and($item->reason)->toBe('source_changed')
            ->and($item->error)->toBe('The source of the field [title] changed after its translation was planned.')
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse()
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
    });

    it('sends an item whose plan expired back to pending', function () {
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        RunFixtures::translate($run);
        app(PlanStore::class)->forget(app(PlanStore::class)->get(RunFixtures::items($run)[0]->planId));

        $report = applyRun($run);

        expect($report->returned)->toBe(1)
            ->and(RunFixtures::statuses($run))->toBe(['pending'])
            ->and(RunFixtures::items($run)[0]->reason)->toBeNull()
            ->and($this->modelCalls())->toBe(1);

        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done'])
            ->and($this->modelCalls())->toBe(2)
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello');
    });

    it('keeps a field a human edited meanwhile and reports target_changed', function () {
        $this->fakeTranslations();
        RunFixtures::finish(RunFixtures::started(['entryIds' => [$this->hello->id()]]));
        $this->hello->merge(['title' => 'Hello there', 'intro' => 'A longer intro.'])->save();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update']]);
        RunFixtures::translate($run);
        $this->hello->fresh()->in('el')->set('title', 'Γεια σας')->save();

        applyRun($run);
        $item = RunFixtures::items($run)[0];
        $greek = $this->hello->fresh()->in('el');

        expect($item->status)->toBe(RunItemStatus::Done)
            ->and($item->fields)->toBe(['title' => 'target_changed', 'intro' => 'applied'])
            ->and($greek->get('title'))->toBe('Γεια σας')
            ->and($greek->get('intro'))->toBe('[T] A longer intro.');
    });

    it('overwrites a field a human edited meanwhile when the run overwrites manual edits', function () {
        $this->fakeTranslations();
        RunFixtures::finish(RunFixtures::started(['entryIds' => [$this->hello->id()]]));
        $this->hello->set('title', 'Hello there')->save();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update', 'overwrite_manual' => true]]);
        RunFixtures::translate($run);
        $this->hello->fresh()->in('el')->set('title', 'Γεια σας')->save();

        applyRun($run);

        expect(RunFixtures::items($run)[0]->fields)->toBe(['title' => 'applied', 'intro' => 'current'])
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello there');
    });

    it('keeps a field a human edited meanwhile in a review run that overwrites manual edits', function () {
        $this->fakeTranslations();
        RunFixtures::finish(RunFixtures::started(['entryIds' => [$this->hello->id()]]));
        $this->fakeReview(fn (string $source) => $source === 'Hello' ? 'Γεια' : 'Μια σύντομη εισαγωγή.');
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()], 'options' => ['mode' => 'review', 'overwrite_manual' => true]]);
        RunFixtures::translate($run);
        $this->hello->fresh()->in('el')->set('title', 'Γεια σας')->save();

        applyRun($run);
        $item = RunFixtures::items($run)[0];
        $greek = $this->hello->fresh()->in('el');

        expect($item->status)->toBe(RunItemStatus::Done)
            ->and($item->fields)->toBe(['title' => 'target_changed', 'intro' => 'applied'])
            ->and($greek->get('title'))->toBe('Γεια σας')
            ->and($greek->get('intro'))->toBe('Μια σύντομη εισαγωγή.')
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Manual, 'intro' => FieldStatus::Current]);
    });

    it('skips an item whose every field a human edited meanwhile', function () {
        $this->fakeTranslations();
        RunFixtures::finish(RunFixtures::started(['entryIds' => [$this->hello->id()]]));
        $this->hello->set('title', 'Hello there')->save();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update']]);
        RunFixtures::translate($run);
        $this->hello->fresh()->in('el')->set('title', 'Γεια σας')->save();

        applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect($item->status)->toBe(RunItemStatus::Skipped)
            ->and($item->reason)->toBe('target_changed')
            ->and($item->fields)->toBe(['title' => 'target_changed', 'intro' => 'current'])
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('Γεια σας')
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::stored($run)->saves)->toBe(0);
    });

    it('skips the item at apply time when its creator lost access', function () {
        $this->fakeTranslations();
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit articles entries']);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id(), $this->world->id()], 'user' => $user]);
        RunFixtures::translate($run);
        $plans = array_map(fn (RunItem $item) => $item->planId, RunFixtures::items($run));
        $this->setTestRoles(['tolk_test' => ['access en site', 'access el site']]);

        applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect(RunFixtures::statuses($run))->toBe(['skipped', 'skipped'])
            ->and($item->reason)->toBe(RunItem::UNAUTHORIZED)
            ->and($item->planId)->toBeNull()
            ->and(app(PlanStore::class)->get($plans[0]))->toBeNull()
            ->and(Entry::query()->where('site', 'el')->count())->toBe(0)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed);
    });

    it('applies with the creator as the user and the run\'s publish option', function () {
        $this->fakeTranslations();
        $this->hello->published(true)->save();
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit articles entries', 'publish articles entries']);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()], 'user' => $user, 'options' => ['new_status' => 'mirror']]);

        RunFixtures::finish($run);
        $greek = $this->hello->fresh()->in('el');

        expect(RunFixtures::statuses($run))->toBe(['done'])
            ->and($greek->published())->toBeTrue()
            ->and($greek->get('updated_by'))->toBe($user->id());
    });

    it('lists a field that failed in strict mode and applies the others', function () {
        $this->withSettings(['strict_fields' => true]);
        $this->fakeTranslations(fn (string $text) => $text === 'Hello' ? null : '[T] '.$text);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);

        RunFixtures::finish($run);
        $item = RunFixtures::items($run)[0];
        $greek = $this->hello->fresh()->in('el');

        expect($item->status)->toBe(RunItemStatus::Done)
            ->and($item->fields)->toBe(['title' => 'missing', 'intro' => 'applied'])
            ->and($item->warnings[0])->toStartWith('The field [title] could not be translated completely')
            ->and($greek->has('title'))->toBeFalse()
            ->and($greek->get('intro'))->toBe('[T] A short intro.');
    });

    it('fails an item none of whose fields could be translated completely in strict mode', function () {
        $this->withSettings(['strict_fields' => true]);
        $this->fakeTranslations(fn () => null);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);

        RunFixtures::finish($run);
        $item = RunFixtures::items($run)[0];

        expect($item->status)->toBe(RunItemStatus::Failed)
            ->and($item->reason)->toBe('fields_failed')
            ->and($item->error)->toBe('No field could be translated completely.')
            ->and($item->fields)->toBe(['title' => 'missing', 'intro' => 'missing'])
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse()
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
    });

    it('saves only the corrected fields of a review and keeps the corrections on the item', function () {
        $this->fakeTranslations();
        RunFixtures::finish(RunFixtures::started(['entryIds' => [$this->hello->id(), $this->world->id()]]));
        $this->fakeReview(fn (string $source) => $source === 'Hello' ? 'Γεια' : null);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id(), $this->world->id()], 'options' => ['mode' => 'review']]);

        RunFixtures::finish($run);
        [$corrected, $accepted] = RunFixtures::items($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and($corrected->reason)->toBeNull()
            ->and($corrected->fields)->toBe(['title' => 'applied', 'intro' => 'no_corrections'])
            ->and($corrected->corrections['title'][0])->toMatchArray(['id' => 's1', 'before' => '[T] Hello', 'after' => 'Γεια'])
            ->and($accepted->reason)->toBe('no_corrections')
            ->and($accepted->fields)->toBe(['title' => 'no_corrections', 'intro' => 'no_corrections'])
            ->and($accepted->corrections)->toBe([])
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('Γεια')
            ->and($this->world->fresh()->in('el')->get('title'))->toBe('[T] World')
            ->and(RunFixtures::stored($run)->saves)->toBe(1);
    });

    it('fails an item whose source was deleted after translating', function () {
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        RunFixtures::translate($run);
        $this->hello->delete();

        applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect($item->status)->toBe(RunItemStatus::Failed)
            ->and($item->error)->toBe("Entry [{$this->hello->id()}] not found.")
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
    });

    it('fails an item whose save a listener vetoed and still commits the localization it created', function () {
        $commits = RunFixtures::commits();
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        RunFixtures::translate($run);
        Event::listen(EntrySaving::class, fn (EntrySaving $event) => $event->entry->has('title') && $event->entry->locale() === 'el' ? false : null);

        applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect($item->status)->toBe(RunItemStatus::Failed)
            ->and($item->reason)->toBe('apply_failed')
            ->and($item->error)->toStartWith('Saving [entry::')
            ->and($this->hello->fresh()->existsIn('el'))->toBeTrue()
            ->and(RunFixtures::stored($run)->saves)->toBe(1)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
        expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });

    it('retries an item whose apply threw after a growing delay and fails it at the third attempt', function () {
        Exceptions::fake();
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id(), $this->world->id()]]);
        RunFixtures::translate($run);
        Event::listen(EntrySaving::class, function (EntrySaving $event) {
            if ($event->entry->locale() === 'el' && $event->entry->origin()?->id() === $this->hello->id()) {
                throw new RuntimeException('Disk full.');
            }
        });

        $first = applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect($first->toArray())->toBe(['items' => 1, 'returned' => 0, 'remains' => true, 'wait' => RunProcessor::BUSY_SECONDS])
            ->and(RunFixtures::statuses($run))->toBe(['translated', 'done'])
            ->and($item->attempts)->toBe(1)
            ->and($item->availableAt)->toEqual(CarbonImmutable::now()->addSeconds(5))
            ->and(applyRun($run)->items)->toBe(0);

        $this->travel(5)->seconds();
        applyRun($run);

        expect(RunFixtures::items($run)[0]->attempts)->toBe(2)
            ->and(RunFixtures::items($run)[0]->availableAt)->toEqual(CarbonImmutable::now()->addSeconds(10));

        $this->travel(10)->seconds();
        $last = applyRun($run);
        $item = RunFixtures::items($run)[0];

        expect($last->toArray())->toBe(['items' => 1, 'returned' => 0, 'remains' => false, 'wait' => null])
            ->and($item->status)->toBe(RunItemStatus::Failed)
            ->and($item->error)->toBe('Disk full.')
            ->and($item->planId)->toBeNull()
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
        Exceptions::assertReportedCount(3);
    });

    it('applies the item of a worker that holds it again, and leaves an item another request works on', function () {
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        RunFixtures::translate($run);
        $claimed = $this->runs->claimTranslated($run, 'apply-1', 120);
        $lock = Cache::lock("tolk:item:{$run->id}:0", 120);
        $lock->get();

        $busy = applyRun($run);

        expect($busy->toArray())->toBe(['items' => 0, 'returned' => 0, 'remains' => true, 'wait' => RunProcessor::BUSY_SECONDS])
            ->and(RunFixtures::statuses($run))->toBe(['applying'])
            ->and(RunFixtures::items($run)[0]->claim->token)->not->toBe($claimed->claim->token);

        $lock->release();

        expect(applyRun($run)->items)->toBe(1)
            ->and(RunFixtures::statuses($run))->toBe(['done']);
    });
});

describe('finalize', function () {
    it('finalizes a run whose last item fails when claiming it, after its claims expired too often', function () {
        $this->fakeTranslations();
        Event::fake([RunFinished::class]);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);

        foreach (['gone-1', 'gone-2', 'gone-3'] as $worker) {
            $this->runs->claimNext($run, $worker, 1);
            $this->travel(2)->seconds();
        }

        expect(RunFixtures::translate($run)->remains)->toBeFalse()
            ->and(RunFixtures::statuses($run))->toBe(['failed'])
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
        Event::assertDispatchedTimes(RunFinished::class, 1);
        $this->assertNoModelCalls();
    });

    it('finalizes a run whose last item fails when claiming it to apply, after its claims expired too often', function () {
        $this->fakeTranslations();
        Event::fake([RunFinished::class]);
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        RunFixtures::translate($run);

        foreach (['gone-1', 'gone-2', 'gone-3'] as $worker) {
            $this->runs->claimTranslated($run, $worker, 1);
            $this->travel(2)->seconds();
        }

        expect(applyRun($run)->remains)->toBeFalse()
            ->and(RunFixtures::statuses($run))->toBe(['failed'])
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);
        Event::assertDispatchedTimes(RunFinished::class, 1);
    });

    it('fires RunFinished once and dispatches one commit when called twice', function () {
        $commits = RunFixtures::commits();
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $run = RunFixtures::started();

        RunFixtures::finish($run);
        app(RunProcessor::class)->finalize($run);
        app(RunProcessor::class)->finalize(RunFixtures::stored($run));

        $stored = RunFixtures::stored($run);

        expect($stored->status)->toBe(RunStatus::Completed)
            ->and($stored->finishedAt)->toEqual(CarbonImmutable::now())
            ->and($stored->finalizedAt)->toEqual(CarbonImmutable::now())
            ->and([$stored->saves, $stored->committedSaves])->toBe([3, 3]);
        Event::assertDispatchedTimes(RunFinished::class, 1);
        Event::assertDispatched(RunFinished::class, fn (RunFinished $event) => $event->runId === $run->id
            && $event->status === RunStatus::Completed
            && $event->counts['done'] === 3
            && $event->usage->toArray() === $stored->usage->toArray());
        expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });

    it('commits the content a run saved before it was cancelled', function () {
        $commits = RunFixtures::commits();
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);
        Cache::lock("tolk:item:{$run->id}:1", 120)->get();
        Cache::lock("tolk:item:{$run->id}:2", 120)->get();

        applyRun($run);
        applyRun($run, 'apply-2');

        expect(RunFixtures::statuses($run))->toBe(['done', 'applying', 'applying']);
        expect($commits)->toHaveCount(0);
        Event::assertNotDispatched(RunFinished::class);

        $run->status = RunStatus::Cancelled;
        $this->runs->save($run);
        app(RunProcessor::class)->finalize($run);

        expect(RunFixtures::stored($run)->finishedAt)->toEqual(CarbonImmutable::now());
        expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
        Event::assertDispatched(RunFinished::class, fn (RunFinished $event) => $event->status === RunStatus::Cancelled
            && $event->counts['done'] === 1);
    });

    it('commits the content an apply saved after the run was cancelled', function () {
        $commits = RunFixtures::commits();
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id(), $this->world->id()]]);
        RunFixtures::translate($run);
        Event::listen(EntrySaved::class, function () use ($run) {
            $stored = app(RunRepository::class)->find($run->id);

            if ($stored->status === RunStatus::Running) {
                $stored->status = RunStatus::Cancelled;
                app(RunRepository::class)->save($stored);
                app(RunProcessor::class)->finalize($stored);
            }
        });

        $report = applyRun($run);

        expect($report->toArray())->toBe(['items' => 1, 'returned' => 0, 'remains' => false, 'wait' => null])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'translated']);
        expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });

    it('commits once for a stalled run without ending it, and nothing when nothing was saved since', function () {
        $commits = RunFixtures::commits();
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);
        Cache::lock("tolk:item:{$run->id}:1", 120)->get();
        applyRun($run);

        app(RunProcessor::class)->finalize($run);
        app(RunProcessor::class)->finalize($run);

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Running)
            ->and(RunFixtures::stored($run)->finishedAt)->toBeNull()
            ->and(RunFixtures::stored($run)->finalizedAt)->toBeNull();
        expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
        Event::assertNotDispatched(RunFinished::class);
    });

    it('fires RunFinished again when a resumed run ends again', function () {
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
        $run->status = RunStatus::Cancelled;
        $this->runs->save($run);
        app(RunProcessor::class)->finalize($run);

        $run->status = RunStatus::Running;
        $run->finishedAt = $run->finalizedAt = null;
        $this->runs->save($run);
        RunFixtures::finish($run);

        Event::assertDispatchedTimes(RunFinished::class, 2);
        Event::assertDispatched(RunFinished::class, fn (RunFinished $event) => $event->status === RunStatus::Completed);
    });

    it('saves without Statamic\'s git listener and dispatches no commit per entry', function () {
        $commits = RunFixtures::commits();
        $listening = [];
        Event::listen(EntrySaved::class, function () use (&$listening) {
            $listening[] = in_array(Subscriber::class.'@commit', Event::getRawListeners()[EntrySaved::class] ?? [], true);
        });
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);

        applyRun($run);

        expect($listening)->not->toBeEmpty()
            ->and(array_unique($listening))->toBe([false])
            ->and(in_array(Subscriber::class.'@commit', Event::getRawListeners()[EntrySaved::class] ?? [], true))->toBeTrue();
        expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });
});
