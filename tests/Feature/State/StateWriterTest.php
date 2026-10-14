<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Listeners\MoveStateOfRenamedTerms;
use Reach\StatamicTolk\State\ConfirmReport;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\OverviewCache;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\State\TargetStatus;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\CountingStateRepository;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\TermSaved;
use Statamic\Facades\Git;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Statamic\Git\CommitJob;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [
            ['handle' => 'question', 'field' => ['type' => 'text']],
            ['handle' => 'answer', 'field' => ['type' => 'textarea']],
            ['handle' => 'more', 'field' => ['type' => 'text', 'default' => 'Read more']],
        ]],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
});

function writer(): StateWriter
{
    return app(StateWriter::class);
}

function writerTarget(EntryContract $origin, string $site = 'el'): EntryTarget
{
    return app(TargetFactory::class)->forEntry($origin, $site);
}

function writerStatus(EntryContract $origin, string $site = 'el'): TargetStatus
{
    return app(StatusResolver::class)->resolve(writerTarget($origin, $site));
}

function storedState(EntryContract $localization): ItemState
{
    return app(StateRepository::class)->get(StateFixtures::ref($localization));
}

function stateReferences(): array
{
    return array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false));
}

function switchGitOn(): void
{
    config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
}

describe('recordPending', function () {
    it('prepends a pending entry, keeps three and records the source site, and the entry\'s collection and site', function () {
        $localization = $this->localize($this->origin, 'el');
        $entries = array_map(fn (string $text) => StateFixtures::pending('Hello', $text), ['Ένα', 'Δύο', 'Τρία', 'Τέσσερα']);

        foreach ($entries as $entry) {
            writer()->recordPending(StateFixtures::ref($localization), writerTarget($this->origin), 'title', $entry);
        }

        $state = storedState($localization);

        expect($state->sourceSite)->toBe('en')
            ->and($state->collection)->toBe('articles')
            ->and($state->site)->toBe('el')
            ->and($state->version)->toBe(ItemState::VERSION)
            ->and($state->field('title')->pending)->toEqual([$entries[3], $entries[2], $entries[1]])
            ->and($state->field('title')->isMachine())->toBeFalse();
    });

    it('keeps the confirmed output and the other fields of the record', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια'), 'intro' => StateFixtures::machine('A short intro.', 'Εισαγωγή.')]);
        $pending = StateFixtures::pending('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);

        writer()->recordPending(StateFixtures::ref($localization), writerTarget($this->origin), 'title', $pending);
        $state = storedState($localization);

        expect($state->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']))
            ->and($state->field('title')->pending)->toEqual([$pending])
            ->and($state->field('intro'))->toEqual(StateFixtures::machine('A short intro.', 'Εισαγωγή.'));
    });
});

describe('confirm', function () {
    it('promotes a pending entry the target saved and moves the previous output to history', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χαίρετε']);
        $pending = StateFixtures::pending('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));
        $field = storedState($localization)->field('title');

        expect($report)->toEqual(new ConfirmReport(promoted: ['title']))
            ->and($field->targetHash)->toBe($pending->targetHash)
            ->and($field->sourceHash)->toBe($pending->sourceHash)
            ->and($field->translatedAt)->toEqual($pending->createdAt)
            ->and($field->translator)->toBe('openai:gpt-6.1-sol')
            ->and($field->pending)->toBe([])
            ->and($field->history)->toBe([['source_hash' => Fingerprint::of('Hello'), 'target_hash' => Fingerprint::ofLeaves(['Γεια'])]])
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Current);
    });

    it('writes a baseline for a delivery that was edited before it was saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια σου, κόσμε']);
        $pending = StateFixtures::pending('Hello', 'Γεια');
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));
        $field = storedState($localization)->field('title');
        $status = writerStatus($this->origin)->fields['title'];

        expect($report)->toEqual(new ConfirmReport(baselined: ['title']))
            ->and($field->isBaseline())->toBeTrue()
            ->and($field->sourceHash)->toBe(Fingerprint::of('Hello'))
            ->and($field->translator)->toBe('openai:gpt-6.1-sol')
            ->and($field->pending)->toEqual([$pending->withTargetBefore(Fingerprint::ofLeaves(['Γεια σου, κόσμε']))])
            ->and($status->status)->toBe(FieldStatus::Manual)
            ->and($status->sourceChanged)->toBeFalse();
    });

    it('promotes a delivery saved after a hand-typed text of the same field was baselined, as from a second tab', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια σου, κόσμε']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια'))]);
        writer()->confirm(writerTarget($this->origin));

        $localization->set('title', 'Γεια')->save();
        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(promoted: ['title']))
            ->and(storedState($localization)->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']))
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Current);
    });

    it('reports nothing when the same edited text is confirmed again', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια σου, κόσμε']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια'))]);
        writer()->confirm(writerTarget($this->origin));
        $baseline = storedState($localization)->toArray();

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title']))
            ->and($report->changed())->toBeFalse()
            ->and(storedState($localization)->toArray())->toBe($baseline);
    });

    it('keeps a baseline moved to the current source after an edited delivery, while that delivery is still pending', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια σου, κόσμε']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια'))]);
        writer()->confirm(writerTarget($this->origin));
        $this->origin->set('title', 'Hello, world')->save();

        writer()->baseline(writerTarget($this->origin));
        $report = writer()->confirm(writerTarget($this->origin));
        $status = writerStatus($this->origin)->fields['title'];

        expect($report->changed())->toBeFalse()
            ->and(storedState($localization)->field('title')->sourceHash)->toBe(Fingerprint::of('Hello, world'))
            ->and($status->status)->toBe(FieldStatus::Manual)
            ->and($status->sourceChanged)->toBeFalse();
    });

    it('reports what it would resolve and writes nothing in a dry run', function () {
        $saved = $this->localize($this->origin, 'el', ['title' => 'Χαίρετε', 'body' => 'Χειροποίητο σώμα.']);
        $stored = [
            'title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Χαίρετε')),
            'intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Μια εισαγωγή.', ['createdAt' => CarbonImmutable::now()->subDays(31)])),
            'body' => (new FieldState)->withPending(StateFixtures::pending('', 'Σώμα.')),
        ];
        StateFixtures::store($saved, $stored);
        $before = storedState($saved)->toArray();

        $dryRun = writer()->confirm(writerTarget($this->origin), dryRun: true);
        $unchanged = storedState($saved)->toArray();
        $report = writer()->confirm(writerTarget($this->origin));

        expect($dryRun)->toEqual(new ConfirmReport(promoted: ['title'], baselined: ['body'], expired: ['intro']))
            ->and($unchanged)->toBe($before)
            ->and($report)->toEqual($dryRun)
            ->and(storedState($saved)->toArray())->not->toBe($before);
    });

    it('keeps a delivery pending when the form saved a copy of the source\'s text instead', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Hello']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια'))]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title']))
            ->and(storedState($localization)->field('title')->pending)->toHaveCount(1)
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Untranslated);
    });

    it('keeps pending entries and the previous output while nothing was saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $title = StateFixtures::pending('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);
        $intro = StateFixtures::pending('A short intro.', 'Μια σύντομη εισαγωγή.');
        StateFixtures::store($localization, [
            'title' => StateFixtures::machine('Hello', 'Γεια')->withPending($title),
            'intro' => (new FieldState)->withPending($intro),
        ]);

        $report = writer()->confirm(writerTarget($this->origin));
        $state = storedState($localization);

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title', 'intro']))
            ->and($report->changed())->toBeFalse()
            ->and($state->field('title'))->toEqual(StateFixtures::machine('Hello', 'Γεια')->withPending($title))
            ->and($state->field('intro')->pending)->toEqual([$intro]);
    });

    it('keeps a delivery planned from hand-written text that was not saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        $pending = StateFixtures::pending('Hello', 'Γεια', ['targetBefore' => Fingerprint::ofLeaves(['Χειροποίητο'])]);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title']))
            ->and(storedState($localization)->field('title')->pending)->toEqual([$pending])
            ->and(writerStatus($this->origin)->fields['title']->isUntracked())->toBeTrue();
    });

    it('keeps pending entries while the target holds an earlier machine output', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Παλιά εισαγωγή.']);
        $pending = StateFixtures::pending('A short intro.', 'Εισαγωγή.');
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Νέα εισαγωγή.', ['history' => [
            ['source_hash' => Fingerprint::of('A short intro.'), 'target_hash' => Fingerprint::ofLeaves(['Παλιά εισαγωγή.'])],
        ]])->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['intro']))
            ->and(storedState($localization)->field('intro')->pending)->toEqual([$pending])
            ->and(writerStatus($this->origin)->fields['intro']->status)->toBe(FieldStatus::Outdated);
    });

    it('promotes the older of two pending entries when that one was saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $older = StateFixtures::pending('Hello', 'Γεια', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subHours(2)]);
        $newer = StateFixtures::pending('Hello', 'Χαίρετε', ['translator' => 'anthropic:claude-haiku-5-5']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($older)->withPending($newer)]);

        $report = writer()->confirm(writerTarget($this->origin));
        $field = storedState($localization)->field('title');

        expect($report->promoted)->toBe(['title'])
            ->and($field->targetHash)->toBe($older->targetHash)
            ->and($field->translator)->toBe('openai:gpt-6.1-sol')
            ->and($field->translatedAt)->toEqual($older->createdAt)
            ->and($field->pending)->toBe([]);
    });

    it('promotes an identical pending entry when the target inherits the field', function () {
        $localization = $this->localize($this->origin, 'el');
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Hello', ['identical' => true]))]);

        $report = writer()->confirm(writerTarget($this->origin));
        $field = storedState($localization)->field('title');

        expect($report->promoted)->toBe(['title'])
            ->and($field->identical)->toBeTrue()
            ->and($field->pending)->toBe([])
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Current);
    });

    it('promotes a delivery whose saved value gained ids, flags and set defaults but no text', function () {
        $source = [['question' => 'Check-in?', 'answer' => 'From 14:00.']];
        $this->origin->set('faq', $source)->save();
        $localization = $this->localize($this->origin, 'el', ['faq' => [
            ['id' => 'mf3k2a9x', 'question' => 'Άφιξη;', 'answer' => 'Από τις 14:00.', 'more' => 'Read more', 'enabled' => true],
        ]]);
        $pending = StateFixtures::pending('', '', [
            'sourceHash' => Fingerprint::of($source),
            'targetHash' => Fingerprint::ofLeaves(['Άφιξη;', 'Από τις 14:00.']),
            'segments' => [Fingerprint::ofText('Check-in?'), Fingerprint::ofText('From 14:00.')],
        ]);
        StateFixtures::store($localization, ['faq' => (new FieldState)->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report->promoted)->toBe(['faq'])
            ->and(storedState($localization)->field('faq')->targetHash)->toBe($pending->targetHash)
            ->and(writerStatus($this->origin)->fields['faq']->status)->toBe(FieldStatus::Current);
    });

    it('drops pending entries older than 30 days and keeps the recent ones', function () {
        $localization = $this->localize($this->origin, 'el');
        $recent = StateFixtures::pending('Hello', 'Γεια', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(29)]);
        $old = StateFixtures::pending('Hello', 'Χαίρετε', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(31)]);
        StateFixtures::store($localization, [
            'title' => (new FieldState)->withPending($old)->withPending($recent),
            'intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Εισαγωγή.', ['createdAt' => CarbonImmutable::now()->subDays(40)])),
        ]);
        $path = app(StateRepository::class)->path(StateFixtures::ref($localization));

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title'], expired: ['title', 'intro']))
            ->and($report->changed())->toBeTrue()
            ->and(storedState($localization)->field('title')->pending)->toEqual([$recent])
            ->and(storedState($localization)->fields)->not->toHaveKey('intro')
            ->and($path)->toBeFile();
    });

    it('leaves pending entries of fields that are out of scope alone', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $retired = StateFixtures::pending('Old', 'Παλιό');
        StateFixtures::store($localization, [
            'title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια')),
            'retired' => (new FieldState)->withPending($retired),
        ]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report->promoted)->toBe(['title'])
            ->and(storedState($localization)->field('retired')->pending)->toEqual([$retired]);
    });

    it('writes nothing for a target without pending entries or without a localization', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        $repository = new CountingStateRepository(app(StateRepository::class));
        $this->app->instance(StateRepository::class, $repository);

        $existing = writer()->confirm(writerTarget($this->origin));
        $missing = writer()->confirm(writerTarget($this->origin, 'de'));

        expect($existing)->toEqual(new ConfirmReport)
            ->and($missing)->toEqual(new ConfirmReport)
            ->and($repository->writes)->toBe(0);
    });
});

describe('baseline', function () {
    it('turns a manual, untracked field into a manual field with an unchanged source', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);

        $before = writerStatus($this->origin)->fields['title'];
        $count = writer()->baseline(writerTarget($this->origin));
        $after = writerStatus($this->origin)->fields['title'];
        $state = storedState($localization);

        $this->origin->set('title', 'Hello there')->save();
        $edited = writerStatus($this->origin);

        expect($before->isUntracked())->toBeTrue()
            ->and($count)->toBe(1)
            ->and($state->sourceSite)->toBe('en')
            ->and($state->field('title')->toArray())->toBe(['source_hash' => Fingerprint::of('Hello')])
            ->and($after->status)->toBe(FieldStatus::Manual)
            ->and($after->sourceChanged)->toBeFalse()
            ->and($after->isUntracked())->toBeFalse()
            ->and($edited->fields['title']->needsAttention())->toBeTrue()
            ->and($edited->counts['attention'])->toBe(1);
    });

    it('moves a baseline to the current source and counts nothing when there is nothing to change', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        StateFixtures::store($localization, ['title' => new FieldState(sourceHash: Fingerprint::of('Hi'), translator: 'openai:gpt-6.1-sol')]);

        $first = writer()->baseline(writerTarget($this->origin));
        $second = writer()->baseline(writerTarget($this->origin));

        expect($first)->toBe(1)
            ->and($second)->toBe(0)
            ->and(storedState($localization)->field('title'))->toEqual(new FieldState(sourceHash: Fingerprint::of('Hello'), translator: 'openai:gpt-6.1-sol'));
    });

    it('leaves machine output alone unless machine fields are included', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Χειροποίητη εισαγωγή.']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);

        $count = writer()->baseline(writerTarget($this->origin));
        $status = writerStatus($this->origin);

        expect($count)->toBe(1)
            ->and($status->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($status->fields['intro']->status)->toBe(FieldStatus::Manual)
            ->and($status->fields['intro']->sourceChanged)->toBeFalse();
    });

    it('turns machine output manual with $includeMachine', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Παλιά εισαγωγή.']);
        StateFixtures::store($localization, [
            'title' => StateFixtures::machine('Hello', 'Γεια'),
            'intro' => StateFixtures::machine('A short intro.', 'Νέα εισαγωγή.', ['history' => [
                ['source_hash' => Fingerprint::of('A short intro.'), 'target_hash' => Fingerprint::ofLeaves(['Παλιά εισαγωγή.'])],
            ]]),
        ]);

        $count = writer()->baseline(writerTarget($this->origin), includeMachine: true);
        $status = writerStatus($this->origin);
        $title = storedState($localization)->field('title');

        expect($count)->toBe(2)
            ->and($status->fields['title']->status)->toBe(FieldStatus::Manual)
            ->and($status->fields['title']->sourceChanged)->toBeFalse()
            ->and($status->fields['intro']->status)->toBe(FieldStatus::Manual)
            ->and($title->isBaseline())->toBeTrue()
            ->and($title->translator)->toBe('anthropic:claude-sonnet-5-5')
            ->and($title->history)->toBe([])
            ->and(storedState($localization)->field('intro')->history)->toBe([
                ['source_hash' => Fingerprint::of('A short intro.'), 'target_hash' => Fingerprint::ofLeaves(['Νέα εισαγωγή.'])],
            ]);
    });

    it('turns a copy of the source\'s text manual only with $includeMachine', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Hello']);

        $without = writer()->baseline(writerTarget($this->origin));
        $with = writer()->baseline(writerTarget($this->origin), includeMachine: true);
        $status = writerStatus($this->origin)->fields['title'];

        expect($without)->toBe(0)
            ->and($with)->toBe(1)
            ->and(storedState($localization)->field('title')->isBaseline())->toBeTrue()
            ->and($status->status)->toBe(FieldStatus::Manual)
            ->and($status->sourceChanged)->toBeFalse();
    });

    it('turns a copy of the source\'s text manual with $includeCopies, but not machine output', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Hello', 'intro' => 'Μια εισαγωγή.']);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Μια εισαγωγή.')]);

        $count = writer()->baseline(writerTarget($this->origin), includeCopies: true);
        $status = writerStatus($this->origin)->fields;

        expect($count)->toBe(1)
            ->and($status['title']->status)->toBe(FieldStatus::Manual)
            ->and($status['title']->sourceChanged)->toBeFalse()
            ->and($status['intro']->status)->toBe(FieldStatus::Current);
    });

    it('counts the fields it would change and writes nothing in a dry run', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο', 'intro' => 'Χειροποίητη εισαγωγή.', 'body' => 'Hello']);
        StateFixtures::store($localization, ['intro' => new FieldState(sourceHash: Fingerprint::of('A short intro.'))]);
        $before = storedState($localization)->toArray();
        Queue::fake();
        switchGitOn();

        $dryRun = writer()->baseline(writerTarget($this->origin), includeCopies: true, commit: true, dryRun: true);
        $unchanged = storedState($localization)->toArray();
        $count = writer()->baseline(writerTarget($this->origin), includeCopies: true);

        expect($dryRun)->toBe(2)
            ->and($count)->toBe(2)
            ->and($unchanged)->toBe($before);
        Queue::assertNotPushed(CommitJob::class);
    });

    it('confirms a saved delivery before it turns it manual', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια'))]);

        $count = writer()->baseline(writerTarget($this->origin), includeMachine: true);
        $confirmed = writer()->confirm(writerTarget($this->origin));

        expect($count)->toBe(1)
            ->and($confirmed)->toEqual(new ConfirmReport)
            ->and(storedState($localization)->field('title')->pending)->toBe([])
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Manual);
    });

    it('keeps unsaved deliveries of a manual field pending', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        $pending = StateFixtures::pending('Hello', 'Γεια', ['targetBefore' => Fingerprint::ofLeaves(['Χειροποίητο'])]);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        writer()->baseline(writerTarget($this->origin));

        expect(storedState($localization)->field('title'))->toEqual(new FieldState(sourceHash: Fingerprint::of('Hello'), pending: [$pending]));
    });

    it('skips a field whose state changed after its status was read', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $inner = app(StateRepository::class);
        $this->app->instance(StateRepository::class, new class($inner, StateFixtures::ref($localization)) implements StateRepository
        {
            private bool $raced = false;

            public function __construct(private StateRepository $inner, private TargetRef $ref) {}

            public function get(TargetRef $ref): ItemState
            {
                return $this->inner->get($ref);
            }

            public function update(TargetRef $ref, Closure $callback): ItemState
            {
                if (! $this->raced) {
                    $this->raced = true;
                    $this->inner->update($this->ref, fn (ItemState $state) => $state->withField('title', StateFixtures::machine('Hello', 'Γεια')));
                }

                return $this->inner->update($ref, $callback);
            }

            public function delete(TargetRef $ref): void
            {
                $this->inner->delete($ref);
            }

            public function references(): iterable
            {
                return $this->inner->references();
            }
        });

        $count = writer()->baseline(writerTarget($this->origin));

        expect($count)->toBe(0)
            ->and(storedState($localization)->field('title'))->toEqual(StateFixtures::machine('Hello', 'Γεια'));
    });

    it('baselines only the given fields and never a field the target inherits', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Χειροποίητη εισαγωγή.', 'body' => 'Χειροποίητο σώμα.']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Hello', ['identical' => true])]);

        $count = writer()->baseline(writerTarget($this->origin), ['title', 'intro'], includeMachine: true);
        $state = storedState($localization);

        expect($count)->toBe(1)
            ->and($state->field('intro')->isBaseline())->toBeTrue()
            ->and($state->field('title'))->toEqual(StateFixtures::machine('Hello', 'Hello', ['identical' => true]))
            ->and($state->fields)->not->toHaveKey('body')
            ->and(writer()->baseline(writerTarget($this->origin, 'de')))->toBe(0);
    });
});

describe('forget', function () {
    it('deletes the state of a target or of single fields', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Εισαγωγή.']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια'), 'intro' => StateFixtures::machine('A short intro.', 'Εισαγωγή.')]);
        $path = app(StateRepository::class)->path(StateFixtures::ref($localization));

        $field = writer()->forget(StateFixtures::ref($localization), ['title', 'body']);
        $absent = writer()->forget(StateFixtures::ref($localization), ['body']);
        $fields = array_keys(storedState($localization)->fields);
        $all = writer()->forget(StateFixtures::ref($localization));
        $again = writer()->forget(StateFixtures::ref($localization));

        expect([$field, $absent, $all, $again])->toBe([true, false, true, false])
            ->and($fields)->toBe(['intro'])
            ->and($path)->not->toBeFile();
    });
});

describe('recordRemoved', function () {
    it('keeps only the history of removed fields, leaves the others alone and stores nothing for a field without a record', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια'), 'intro' => StateFixtures::machine('A short intro.', 'Εισαγωγή.')]);

        writer()->recordRemoved(StateFixtures::ref($localization), ['intro', 'body']);

        expect(storedState($localization)->field('title')->toArray())->toBe(StateFixtures::machine('Hello', 'Γεια')->toArray())
            ->and(storedState($localization)->field('intro')->toArray())->toBe(['history' => [
                ['source_hash' => Fingerprint::of('A short intro.'), 'target_hash' => Fingerprint::ofLeaves(['Εισαγωγή.'])],
            ]])
            ->and(storedState($localization)->fields)->not->toHaveKey('body');
    });
});

describe('locate', function () {
    it('records the collection and site in an entry\'s state that lacks them, once, and creates no state', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        // As 1.0.0-beta.1 wrote an entry's state.
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        $ref = StateFixtures::ref($localization);
        $stored = storedState($localization)->toArray();

        $dryRun = writer()->locate($ref, 'articles', 'el', dryRun: true);
        $untouched = storedState($localization)->toArray();
        $located = writer()->locate($ref, 'articles', 'el');
        $again = writer()->locate($ref, 'articles', 'el', dryRun: true) || writer()->locate($ref, 'articles', 'el');
        $none = writer()->locate(TargetRef::parse('entry::without-state'), 'articles', 'el');

        expect([$dryRun, $located, $again, $none])->toBe([true, true, false, false])
            ->and($untouched)->toBe($stored)
            ->and(storedState($localization)->collection)->toBe('articles')
            ->and(storedState($localization)->site)->toBe('el')
            ->and(storedState($localization)->fields)->toEqual(ItemState::fromArray($stored)->fields)
            ->and(stateReferences())->toBe([(string) $ref]);
    });
});

describe('adopt', function () {
    it('moves the records of fields the target has a value for but no record of', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Εισαγωγή.']);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Δική της.')]);
        $from = TargetRef::parse('entry::other');
        app(StateRepository::class)->update($from, fn (ItemState $state) => $state
            ->withField('title', StateFixtures::machine('Hello', 'Γεια'))
            ->withField('intro', StateFixtures::machine('A short intro.', 'Εισαγωγή.'))
            ->withField('body', StateFixtures::machine('Body', 'Σώμα')));

        $adopted = writer()->adopt(writerTarget($this->origin), $from);

        expect($adopted)->toBe(['title'])
            ->and(storedState($localization)->field('title'))->toEqual(StateFixtures::machine('Hello', 'Γεια'))
            ->and(storedState($localization)->field('intro'))->toEqual(StateFixtures::machine('A short intro.', 'Δική της.'))
            ->and(array_keys(app(StateRepository::class)->get($from)->fields))->toBe(['intro', 'body'])
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Current)
            ->and(writer()->adopt(writerTarget($this->origin), $from))->toBe([])
            ->and(writer()->adopt(writerTarget($this->origin, 'de'), $from))->toBe([]);
    });
});

describe('git', function () {
    beforeEach(function () {
        Queue::fake();

        $this->localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο', 'intro' => 'Εισαγωγή.']);
        StateFixtures::store($this->localization, [
            'title' => new FieldState(sourceHash: Fingerprint::of('Hi')),
            'intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Εισαγωγή.')),
        ]);

        switchGitOn();
    });

    it('dispatches one commit with commit: true and none without it', function (Closure $write) {
        expect($write($this, false))->toBeTrue();
        Queue::assertNotPushed(CommitJob::class);

        expect($write($this, true))->toBeTrue();
        Queue::assertPushed(CommitJob::class, 1);
        Queue::assertPushed(CommitJob::class, fn (CommitJob $job) => $job->message === StateWriter::COMMIT_MESSAGE);
    })->with([
        'recordPending' => function ($test, bool $commit) {
            writer()->recordPending(StateFixtures::ref($test->localization), writerTarget($test->origin), 'body', StateFixtures::pending('', 'Σώμα.', ['plan' => 'plan-'.(int) $commit]), commit: $commit);

            return true;
        },
        'confirm' => function ($test, bool $commit) {
            if ($commit) {
                StateFixtures::store($test->localization, ['intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Εισαγωγή.'))]);
            }

            return writer()->confirm(writerTarget($test->origin), commit: $commit)->changed();
        },
        'baseline' => fn ($test, bool $commit) => writer()->baseline(writerTarget($test->origin), [$commit ? 'intro' : 'title'], includeMachine: $commit, commit: $commit) === 1,
        'forget' => fn ($test, bool $commit) => writer()->forget(StateFixtures::ref($test->localization), $commit ? null : ['intro'], commit: $commit),
    ]);

    it('dispatches no commit when nothing changed', function () {
        writer()->confirm(writerTarget($this->origin));
        writer()->confirm(writerTarget($this->origin), commit: true);
        writer()->recordPending(StateFixtures::ref($this->localization), writerTarget($this->origin), 'body', StateFixtures::pending('', 'Σώμα.'));
        $unresolved = writer()->confirm(writerTarget($this->origin), commit: true);
        writer()->baseline(writerTarget($this->origin), ['intro'], commit: true);
        writer()->baseline(writerTarget($this->origin), ['title']);
        $baselined = writer()->baseline(writerTarget($this->origin), ['title'], commit: true);
        writer()->forget(StateFixtures::ref($this->localization), ['faq'], commit: true);

        expect($unresolved)->toEqual(new ConfirmReport(unresolved: ['body']))
            ->and($baselined)->toBe(0);
        Queue::assertNotPushed(CommitJob::class);
    });

    it('dispatches nothing while automatic commits are off', function () {
        config(['statamic.git.automatic' => false]);

        $count = writer()->baseline(writerTarget($this->origin), commit: true);

        expect($count)->toBe(1);
        Queue::assertNotPushed(CommitJob::class);
    });
});

describe('deleted content', function () {
    it('forgets the state of a deleted localization and keeps that of its siblings', function () {
        $greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $german = $this->localize($this->origin, 'de', ['title' => 'Hallo']);
        StateFixtures::store($greek, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        StateFixtures::store($german, ['title' => StateFixtures::machine('Hello', 'Hallo')]);
        $path = app(StateRepository::class)->path(StateFixtures::ref($greek));

        $greek->delete();

        expect($path)->not->toBeFile()
            ->and(storedState($german)->isEmpty())->toBeFalse();
    });

    it('forgets the state of every localization deleted with its origin', function () {
        $greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $german = $this->localize($this->origin, 'de', ['title' => 'Hallo']);
        StateFixtures::store($greek, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        StateFixtures::store($german, ['title' => StateFixtures::machine('Hello', 'Hallo')]);

        $this->origin->deleteDescendants();
        $this->origin->delete();

        expect(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([]);
    });

    it('forgets the state of a deleted term in every site', function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $travel = $this->makeTerm('topics', 'travel', ['en' => ['title' => 'Travel'], 'el' => ['title' => 'Ταξίδια']]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
        $refs = array_map(fn (string $id) => TargetRef::parse($id), ['term::topics::travel::el', 'term::topics::travel::de', 'term::topics::food::el']);

        foreach ($refs as $ref) {
            app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withField('title', StateFixtures::machine('Travel', 'Ταξίδια')));
        }

        $travel->delete();

        expect(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toBe(['term::topics::food::el']);
    });

    it('forgets the state of the variables of a site removed from a global set, and of every site of a deleted set', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
        $this->makeGlobalSet('footer', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'Open all year.']]);
        $references = fn () => array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false));

        foreach (['globals::site_settings::el', 'globals::site_settings::de', 'globals::footer::el'] as $reference) {
            app(StateRepository::class)->update(TargetRef::parse($reference), fn (ItemState $state) => $state->withField('tagline', StateFixtures::machine('By the sea', 'Δίπλα στη θάλασσα')));
        }

        GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => 'en'])->save();

        expect($references())->toEqualCanonicalizing(['globals::site_settings::el', 'globals::footer::el']);

        GlobalSet::findByHandle('site_settings')->delete();

        expect($references())->toBe(['globals::footer::el']);
    });

    it('forgets the state of a deleted navigation tree, and of every site of a deleted navigation', function () {
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book']], 'el' => [['id' => 'book', 'title' => 'Κράτηση']], 'de' => [['id' => 'book', 'title' => 'Buchen']]]);
        $this->makeNav('footer', ['en' => [['id' => 'legal', 'title' => 'Legal']], 'el' => [['id' => 'legal', 'title' => 'Νομικά']]]);
        $references = fn () => array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false));

        foreach (['nav::main::el', 'nav::main::de', 'nav::footer::el'] as $reference) {
            app(StateRepository::class)->update(TargetRef::parse($reference), fn (ItemState $state) => $state->withField('tree', StateFixtures::machine('Book', 'Κράτηση')));
        }

        Nav::findByHandle('main')->in('de')->delete();

        expect($references())->toEqualCanonicalizing(['nav::main::el', 'nav::footer::el']);

        Nav::findByHandle('main')->delete();

        expect($references())->toBe(['nav::footer::el']);
    });

    it('commits the forgotten state once, and nothing for content without state', function () {
        $greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $german = $this->localize($this->origin, 'de', ['title' => 'Hallo']);
        StateFixtures::store($greek, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        switchGitOn();
        $messages = [];
        Git::shouldReceive('as')->andReturnSelf();
        Git::shouldReceive('dispatchCommit')->andReturnUsing(function (?string $message = null) use (&$messages) {
            $messages[] = $message;
        });

        $german->delete();
        $withoutState = $messages;
        $messages = [];
        $greek->delete();

        expect($withoutState)->toBe(['Entry deleted'])
            ->and($messages)->toBe(['Entry deleted', StateWriter::COMMIT_MESSAGE]);
    });
});

describe('renamed terms', function () {
    beforeEach(function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food'], 'el' => ['title' => 'Φαγητό']]);
        $this->termState = fn (string $reference) => app(StateRepository::class)->get(TargetRef::parse($reference));
        $this->storeTermState = fn (string $reference, string $text) => app(StateRepository::class)->update(
            TargetRef::parse($reference),
            fn (ItemState $state) => $state->withSourceSite('en')->withField('title', StateFixtures::machine('Food', $text)),
        );
    });

    it('moves the state of every site to the new default slug, with its source site and pending entries', function () {
        ($this->storeTermState)('term::topics::food::el', 'Φαγητό');
        ($this->storeTermState)('term::topics::food::de', 'Essen');
        app(StateRepository::class)->update(TargetRef::parse('term::topics::food::el'), fn (ItemState $state) => $state->withField('title', $state->field('title')->withPending(StateFixtures::pending('Food', 'Τρόφιμα'))));
        $greek = ($this->termState)('term::topics::food::el');
        $this->makeTerm('topics', 'drinks', ['en' => ['title' => 'Drinks']]);
        ($this->storeTermState)('term::topics::drinks::el', 'Ποτά');

        Term::find('topics::food')->slug('cuisine')->save();

        expect(stateReferences())->toEqualCanonicalizing(['term::topics::cuisine::el', 'term::topics::cuisine::de', 'term::topics::drinks::el'])
            ->and(($this->termState)('term::topics::cuisine::el')->toArray())->toBe([...$greek->toArray(), 'reference' => 'term::topics::cuisine::el'])
            ->and(($this->termState)('term::topics::cuisine::de')->field('title')->toArray())->toBe(StateFixtures::machine('Food', 'Essen')->toArray());
    });

    it('keeps the state stored under the new slug, and moves nothing for a slug of another site or a new term', function () {
        ($this->storeTermState)('term::topics::food::el', 'Φαγητό');
        ($this->storeTermState)('term::topics::food::de', 'Essen');
        ($this->storeTermState)('term::topics::cuisine::el', 'Κουζίνα');

        Term::find('topics::food')->slug('cuisine')->save();

        expect(stateReferences())->toEqualCanonicalizing(['term::topics::food::el', 'term::topics::cuisine::el', 'term::topics::cuisine::de'])
            ->and(($this->termState)('term::topics::cuisine::el')->field('title')->toArray())->toBe(StateFixtures::machine('Food', 'Κουζίνα')->toArray());

        Term::find('topics::cuisine')->in('el')->slug('kouzina')->save();
        $this->makeTerm('topics', 'drinks', ['en' => ['title' => 'Drinks']]);

        expect(stateReferences())->toEqualCanonicalizing(['term::topics::food::el', 'term::topics::cuisine::el', 'term::topics::cuisine::de']);
    });

    it('makes the Overview count the taxonomy again once the state moved, which can be after the term\'s save bumped it', function () {
        ($this->storeTermState)('term::topics::food::el', 'Φαγητό');
        $term = Term::find('topics::food')->term()->slug('cuisine');
        $slot = fn () => app(OverviewCache::class)->slot(ContentType::Term, 'topics', 'el');
        $before = $slot();

        app(MoveStateOfRenamedTerms::class)->handle(new TermSaved($term));
        $moved = $slot();
        app(MoveStateOfRenamedTerms::class)->handle(new TermSaved($term));

        expect($moved)->not->toBe($before)
            ->and($slot())->toBe($moved)
            ->and(stateReferences())->toBe(['term::topics::cuisine::el']);
    });

    it('commits the moved state once, and nothing for a renamed term without state', function () {
        $this->makeTerm('topics', 'drinks', ['en' => ['title' => 'Drinks']]);
        ($this->storeTermState)('term::topics::food::el', 'Φαγητό');
        ($this->storeTermState)('term::topics::food::de', 'Essen');
        switchGitOn();
        $messages = [];
        Git::shouldReceive('as')->andReturnSelf();
        Git::shouldReceive('dispatchCommit')->andReturnUsing(function (?string $message = null) use (&$messages) {
            $messages[] = $message;
        });

        Term::find('topics::drinks')->slug('beverages')->save();
        $withoutState = $messages;
        $messages = [];
        Term::find('topics::food')->slug('cuisine')->save();

        expect($withoutState)->not->toContain(StateWriter::COMMIT_MESSAGE)
            ->and(array_values(array_filter($messages, fn (?string $message) => $message === StateWriter::COMMIT_MESSAGE)))->toBe([StateWriter::COMMIT_MESSAGE]);
    });
});
