<?php

use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Support\Pruner;
use Reach\StatamicTolk\Support\PruneReport;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    Queue::fake();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello'], 'hello');
});

function prunerState(TargetRef $ref): void
{
    app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withField('title', new FieldState(sourceHash: 'a1b2c3d4e5f60718')));
}

function pruned(bool $dryRun = false): PruneReport
{
    return app(Pruner::class)->prune($dryRun);
}

it('keeps the state of content in a site its collection or taxonomy no longer has', function () {
    $localization = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
    StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
    $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food'], 'el' => ['title' => 'Φαγητό']]);
    prunerState(new TargetRef(ContentType::Term, 'topics::food', 'el'));

    Collection::find('articles')->sites(['en', 'de'])->save();
    Taxonomy::find('topics')->sites(['en'])->save();
    // A new request reads the taxonomy again; Term::taxonomy() keeps it in Blink, which Taxonomy::save() does not clear.
    Blink::forget('taxonomy-topics');

    expect(pruned()->isEmpty())->toBeTrue()
        ->and(app(StateRepository::class)->get(StateFixtures::ref($localization))->isEmpty())->toBeFalse()
        ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Term, 'topics::food', 'el'))->isEmpty())->toBeFalse();
});

it('deletes the state of a global set\'s site without an origin and of a navigation\'s tree that is gone, and reports each reason', function () {
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']]]);
    prunerState(new TargetRef(ContentType::GlobalSet, 'site_settings', 'el'));
    prunerState(new TargetRef(ContentType::Nav, 'main', 'de'));
    prunerState(new TargetRef(ContentType::Nav, 'footer', 'el'));

    GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => null])->save();

    $dryRun = pruned(dryRun: true);
    $report = pruned();

    expect($dryRun)->toEqual(new PruneReport(...[...get_object_vars($report), 'dryRun' => true]))
        ->and($report->orphaned(PruneReport::NOT_A_LOCALIZATION))->toBe(['globals::site_settings::el'])
        ->and($report->orphaned(PruneReport::DELETED))->toEqualCanonicalizing(['nav::main::de', 'nav::footer::el'])
        ->and(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([]);
});

it('stops a stalled run that is older than the retention instead of deleting it, and counts it once with a dry run', function () {
    $stalled = RunFixtures::started();
    $old = RunFixtures::pending();
    $this->travel(31)->days();

    $dryRun = pruned(dryRun: true);
    $report = pruned();

    expect($dryRun->stoppedRuns)->toBe([$stalled->id])
        ->and($dryRun->deletedRuns)->toBe(1)
        ->and($report->stoppedRuns)->toBe([$stalled->id])
        ->and($report->deletedRuns)->toBe(1)
        ->and(RunFixtures::stored($stalled)->status)->toBe(RunStatus::Aborted)
        ->and(app(RunRepository::class)->find($old->id))->toBeNull();
});

it('completes a running run that has nothing left, and leaves a run that made progress alone', function () {
    $this->fakeTranslations();
    $finished = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
    RunFixtures::finish($finished);
    $finished = RunFixtures::stored($finished);
    $finished->status = RunStatus::Running;
    $finished->finishedAt = $finished->finalizedAt = null;
    app(RunRepository::class)->save($finished);

    $this->travel(config('tolk.runs.claim_ttl') + 1)->seconds();
    $busy = RunFixtures::started();

    $report = pruned();

    expect($report->stoppedRuns)->toBe([$finished->id])
        ->and(RunFixtures::stored($finished)->status)->toBe(RunStatus::Completed)
        ->and(RunFixtures::stored($finished)->error)->toBeNull()
        ->and(RunFixtures::stored($busy)->status)->toBe(RunStatus::Running);
});
