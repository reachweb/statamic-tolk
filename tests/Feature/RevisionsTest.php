<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\EditorDelivery;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaving;
use Statamic\Events\RevisionSaving;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->useFileCache();
    $this->fakeTranslations();
    config(['statamic.revisions.enabled' => true]);

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);
    Collection::find('articles')->revisionsEnabled(true)->save();

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
});

function revisedTranslate(EntryContract $source, ApplyOptions $apply = new ApplyOptions, array $options = []): TargetResult
{
    return app(TargetTranslator::class)->translate(
        app(TargetFactory::class)->forEntry($source, 'el'),
        PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background', ...$options]),
        $apply,
    );
}

function greekOf(EntryContract $origin): EntryContract
{
    return Entry::find($origin->id())->in('el');
}

/**
 * @return list<string> the actions of the localization's revisions, oldest first; the working copy is not one of them
 */
function revisionActions(EntryContract $entry): array
{
    return $entry->revisions()->map(fn ($revision) => $revision->action())->values()->all();
}

/**
 * A published Greek localization whose title is the machine output of an earlier translation and whose intro is
 * untranslated; it has no working copy.
 */
function publishedGreek(EntryContract $origin): EntryContract
{
    test()->localize($origin, 'el', ['title' => '[T] Hello'])->published(true)->save();
    StateFixtures::store(greekOf($origin), ['title' => StateFixtures::machine('Hello', '[T] Hello')]);

    return greekOf($origin);
}

/**
 * Records the saves of entries, with their published state, and of revisions, with their action, in order.
 */
function recordSaves(): ArrayObject
{
    $saves = new ArrayObject;
    Event::listen(EntrySaving::class, fn (EntrySaving $event) => $saves[] = 'entry: '.($event->entry->published() ? 'published' : 'draft'));
    Event::listen(RevisionSaving::class, fn (RevisionSaving $event) => $saves[] = 'revision: '.$event->revision->action());

    return $saves;
}

describe('a new localization', function () {
    it('is created as a draft with a revision', function () {
        $saves = recordSaves();

        $result = revisedTranslate($this->origin);
        $greek = greekOf($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->created)->toBeTrue()
            ->and($greek->published())->toBeFalse()
            ->and($greek->data()->all())->toBe(['title' => '[T] Hello', 'intro' => '[T] A short intro.'])
            ->and(revisionActions($greek))->toBe(['revision'])
            ->and($greek->hasWorkingCopy())->toBeFalse()
            ->and($saves->getArrayCopy())->toBe(['entry: draft', 'revision: revision', 'entry: draft'])
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
    });

    it('is published through a revision with mirror from a published origin, never saved directly as published', function () {
        $this->freezeSecond();
        $saves = recordSaves();

        $result = revisedTranslate($this->origin, new ApplyOptions(newStatus: 'mirror'));
        $greek = greekOf($this->origin);
        $published = $greek->revisions()->last();

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($greek->published())->toBeTrue()
            ->and($greek->get('title'))->toBe('[T] Hello')
            // A revision is stored per second, so the publish revision replaced the one made with the localization.
            ->and(revisionActions($greek))->toBe(['publish'])
            ->and($published->attribute('data'))->toMatchArray(['title' => '[T] Hello', 'intro' => '[T] A short intro.'])
            ->and($published->message())->toBe('Translated from English with Tolk')
            ->and($greek->hasWorkingCopy())->toBeFalse()
            ->and($saves->getArrayCopy())->toBe(['entry: draft', 'revision: revision', 'entry: published', 'revision: publish'])
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
    });

    it('stays a draft with mirror from a draft origin', function () {
        $this->origin->published(false)->save();

        revisedTranslate($this->origin, new ApplyOptions(newStatus: 'mirror'));
        $greek = greekOf($this->origin);

        expect($greek->published())->toBeFalse()
            ->and($greek->get('title'))->toBe('[T] Hello')
            ->and(revisionActions($greek))->toBe(['revision']);
    });

    it('records the user on its revision', function () {
        $user = $this->signInAdmin();

        revisedTranslate($this->origin, new ApplyOptions(user: $user));
        $revisions = greekOf($this->origin)->revisions()->values();

        expect($revisions->map(fn ($revision) => $revision->user()?->id())->all())->toBe([$user->id()]);
    });

    it('fails when a listener cancels its revision', function () {
        Event::listen(RevisionSaving::class, fn () => false);

        $result = revisedTranslate($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::APPLY_FAILED)
            ->and(greekOf($this->origin)->get('title'))->toBeNull();
    });
});

describe('a published localization', function () {
    it('is updated by a run in its working copy only, which reads current, so a second run does nothing', function () {
        Queue::fake();
        $greek = publishedGreek($this->origin);
        $this->origin->set('title', 'Hello there')->save();
        $saves = recordSaves();
        $commits = RunFixtures::commits();

        $run = RunFixtures::started(['entryIds' => [$this->origin->id()], 'options' => ['mode' => 'update']]);
        RunFixtures::finish($run);
        $live = greekOf($this->origin);
        $working = $live->fromWorkingCopy();

        expect(RunFixtures::statuses($run))->toBe(['done'])
            ->and(RunFixtures::items($run)[0]->warnings)->toBe([])
            ->and($live->data()->all())->toBe($greek->data()->all())
            ->and($live->published())->toBeTrue()
            ->and($live->hasWorkingCopy())->toBeTrue()
            ->and($working->get('title'))->toBe('[T] Hello there')
            ->and($working->get('intro'))->toBe('[T] A short intro.')
            ->and($live->workingCopy()->message())->toBe('Translated from English with Tolk')
            ->and(revisionActions($live))->toBe([])
            ->and($saves->getArrayCopy())->toBe(['revision: working'])
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE])
            ->and(StateFixtures::stored($live)->hasPending())->toBeFalse()
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
            ->and(app(StatusResolver::class)->resolve(app(TargetFactory::class)->forEntry($this->origin, 'el'))->itemStatus)->toBe(ItemStatus::Current);

        $calls = $this->modelCalls();
        $second = RunFixtures::started(['entryIds' => [$this->origin->id()], 'options' => ['mode' => 'update']]);
        RunFixtures::finish($second);

        expect(RunFixtures::statuses($second))->toBe(['skipped'])
            ->and(RunFixtures::items($second)[0]->reason)->toBe(TargetResult::NOTHING_TO_DO)
            ->and(RunFixtures::stored($second)->status)->toBe(RunStatus::Completed)
            ->and($this->modelCalls())->toBe($calls)
            ->and($saves->getArrayCopy())->toBe(['revision: working']);
    });

    it('stays live when an editor restored a revision saved while it was unpublished, and is updated in that working copy', function () {
        Queue::fake();
        $this->signInAdmin();
        $greek = publishedGreek($this->origin);
        $greek->unpublish();
        $unpublished = (string) greekOf($this->origin)->revisions()->keys()->last();
        $this->travel(1)->seconds();
        greekOf($this->origin)->publish();
        $this->travel(1)->seconds();
        $this->postJson(cp_route('collections.entries.restore-revision', ['articles', $greek->id()]), ['revision' => $unpublished])->assertOk();
        $restored = greekOf($this->origin);
        $this->origin->set('title', 'Hello there')->save();
        $saves = recordSaves();

        $run = RunFixtures::started(['entryIds' => [$this->origin->id()], 'options' => ['mode' => 'update']]);
        RunFixtures::finish($run);
        $live = greekOf($this->origin);
        $working = $live->fromWorkingCopy();

        expect($restored->published())->toBeTrue()
            ->and($restored->fromWorkingCopy()->published())->toBeFalse()
            ->and(RunFixtures::statuses($run))->toBe(['done'])
            ->and($live->published())->toBeTrue()
            ->and($live->data()->all())->toBe($restored->data()->all())
            ->and(revisionActions($live))->toBe(['unpublish', 'publish'])
            ->and($working->published())->toBeFalse()
            ->and($working->get('title'))->toBe('[T] Hello there')
            ->and($working->get('intro'))->toBe('[T] A short intro.')
            ->and($saves->getArrayCopy())->toBe(['revision: working'])
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);

        $calls = $this->modelCalls();
        $second = RunFixtures::started(['entryIds' => [$this->origin->id()], 'options' => ['mode' => 'update']]);
        RunFixtures::finish($second);

        expect(RunFixtures::statuses($second))->toBe(['skipped'])
            ->and($this->modelCalls())->toBe($calls)
            ->and(greekOf($this->origin)->published())->toBeTrue();
    });

    it('is published with publish_changes when it had no working copy', function () {
        publishedGreek($this->origin);
        $saves = recordSaves();

        $result = revisedTranslate($this->origin, new ApplyOptions(publishChanges: true), ['mode' => 'update']);
        $greek = greekOf($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->warnings)->toBe([])
            ->and($greek->published())->toBeTrue()
            ->and($greek->get('intro'))->toBe('[T] A short intro.')
            ->and($greek->hasWorkingCopy())->toBeFalse()
            ->and(revisionActions($greek))->toBe(['publish'])
            ->and($greek->revisions()->last()->message())->toBe('Translated from English with Tolk')
            ->and($saves->getArrayCopy())->toBe(['revision: working', 'entry: published', 'revision: publish'])
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
    });

    it('keeps a foreign change unpublished with publish_changes and warns', function () {
        $greek = publishedGreek($this->origin);
        $greek->fromWorkingCopy()->set('title', 'Γεια σου')->makeWorkingCopy()->save();

        $result = revisedTranslate($this->origin, new ApplyOptions(publishChanges: true), ['mode' => 'update']);
        $live = greekOf($this->origin);
        $working = $live->fromWorkingCopy();

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->warnings)->toBe(['The Greek localization already had unpublished changes, so the translation was added to them and not published.'])
            ->and($result->fields)->toBe(['title' => 'manual', 'intro' => 'applied'])
            ->and($live->data()->all())->toBe($greek->data()->all())
            ->and($working->get('title'))->toBe('Γεια σου')
            ->and($working->get('intro'))->toBe('[T] A short intro.')
            ->and(revisionActions($live))->toBe([]);
    });

    it('keeps the changes unpublished with publish_changes for a user who may not publish, and warns', function () {
        publishedGreek($this->origin);
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'view articles entries', 'edit articles entries']);

        $result = revisedTranslate($this->origin, new ApplyOptions(user: $user, publishChanges: true), ['mode' => 'update']);
        $live = greekOf($this->origin);

        expect($result->warnings)->toBe(['The translation was saved as unpublished changes: the user who started it may not publish this entry.'])
            ->and($live->get('intro'))->toBeNull()
            ->and($live->fromWorkingCopy()->get('intro'))->toBe('[T] A short intro.')
            ->and($live->workingCopy()->user()?->id())->toBe($user->id());
    });

    it('fails when a listener cancels the publish of publish_changes, and keeps the changes in the working copy', function () {
        publishedGreek($this->origin);
        Event::listen(EntrySaving::class, fn () => false);

        $result = revisedTranslate($this->origin, new ApplyOptions(publishChanges: true), ['mode' => 'update']);
        $live = greekOf($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::APPLY_FAILED)
            ->and($live->get('intro'))->toBeNull()
            ->and($live->fromWorkingCopy()->get('intro'))->toBe('[T] A short intro.');
    });

    it('fails when a listener cancels its working copy', function () {
        publishedGreek($this->origin);
        Event::listen(RevisionSaving::class, fn () => false);

        $result = revisedTranslate($this->origin, options: ['mode' => 'update']);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::APPLY_FAILED)
            ->and(greekOf($this->origin)->hasWorkingCopy())->toBeFalse();
    });
});

it('saves an unpublished localization directly', function () {
    $this->localize($this->origin, 'el', ['title' => '[T] Hello'])->published(false)->save();
    $saves = recordSaves();

    revisedTranslate($this->origin, new ApplyOptions(publishChanges: true), ['mode' => 'update']);
    $greek = greekOf($this->origin);

    expect($greek->get('intro'))->toBe('[T] A short intro.')
        ->and($greek->published())->toBeFalse()
        ->and($greek->hasWorkingCopy())->toBeFalse()
        ->and(revisionActions($greek))->toBe([])
        ->and($saves->getArrayCopy())->toBe(['entry: draft']);
});

it('updates the working copy of an unpublished localization that has one, which the CP shows, so a second run does nothing', function () {
    // Left by a save that unpublished the live entry and kept the working copy of a restored draft revision.
    $greek = publishedGreek($this->origin);
    $greek->fromWorkingCopy()->published(false)->makeWorkingCopy()->save();
    greekOf($this->origin)->published(false)->save();
    $greek = greekOf($this->origin);
    $saves = recordSaves();

    $result = revisedTranslate($this->origin, options: ['mode' => 'update']);
    $live = greekOf($this->origin);

    expect($result->status)->toBe(TargetResultStatus::Done)
        ->and($live->published())->toBeFalse()
        ->and($live->data()->all())->toBe($greek->data()->all())
        ->and($live->fromWorkingCopy()->get('intro'))->toBe('[T] A short intro.')
        ->and($saves->getArrayCopy())->toBe(['revision: working'])
        ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);

    $calls = $this->modelCalls();

    expect(revisedTranslate($this->origin, options: ['mode' => 'update'])->status)->toBe(TargetResultStatus::Skipped)
        ->and($this->modelCalls())->toBe($calls);
});

it('promotes the pending entries of the editor flow once the CP saved the form into the working copy', function () {
    $greek = publishedGreek($this->origin);
    $this->signInAdmin();
    $target = app(TargetFactory::class)->forEntry($this->origin, 'el');
    $plan = app(Planner::class)->plan($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['mode' => 'update']));
    app(PlanStore::class)->put($plan);

    while (($runnable = app(Pipeline::class)->runnable($plan)) !== []) {
        app(Pipeline::class)->runUnit($plan, array_key_first($runnable));
    }

    $delivered = app(EditorDelivery::class)->deliver($plan, $target->fresh(), ['intro']);

    $this->patchJson(cp_route('collections.entries.update', ['articles', $greek->id()]), [
        'title' => '[T] Hello',
        'intro' => $delivered['intro']['value'],
        'slug' => 'hello',
        'published' => true,
        '_localized' => ['title', 'intro'],
    ])->assertOk();

    expect(greekOf($this->origin)->get('intro'))->toBeNull()
        ->and(greekOf($this->origin)->fromWorkingCopy()->get('intro'))->toBe('[T] A short intro.')
        ->and(StateFixtures::stored($greek)->field('intro')->pending)->toHaveCount(1);

    $this->postJson(cp_route('tolk.editor.confirm'), ['reference' => $greek->reference(), 'delivered' => ['intro']])
        ->assertOk()
        ->assertJsonPath('not_saved', [])
        ->assertJsonPath('localization.status', 'current');

    expect(StateFixtures::stored($greek)->field('intro')->pending)->toBe([])
        ->and(StateFixtures::stored($greek)->field('intro')->targetHash)->not->toBeNull()
        ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
});
