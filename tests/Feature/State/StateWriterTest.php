<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\State\ConfirmReport;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\State\TargetStatus;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\CountingStateRepository;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeGridHandler;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Git;
use Statamic\Git\CommitJob;

beforeEach(function () {
    $this->useFileCache();
    app(FieldtypeRegistry::class)->register('grid', FakeGridHandler::class);

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

function writerRef(EntryContract $localization): TargetRef
{
    return new TargetRef(ContentType::Entry, $localization->id());
}

function storedState(EntryContract $localization): ItemState
{
    return app(StateRepository::class)->get(writerRef($localization));
}

/**
 * @param  array<string, FieldState>  $fields
 */
function storeState(EntryContract $localization, array $fields): void
{
    app(StateRepository::class)->update(writerRef($localization), function (ItemState $state) use ($fields) {
        foreach ($fields as $handle => $field) {
            $state = $state->withField($handle, $field);
        }

        return $state;
    });
}

function machineField(mixed $source, string $translation, array $overrides = []): FieldState
{
    return new FieldState(...[
        'sourceHash' => Fingerprint::of($source),
        'targetHash' => Fingerprint::ofLeaves([$translation]),
        'segments' => [Fingerprint::ofText((string) $source)],
        'translatedAt' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        'translator' => 'anthropic:claude-sonnet-5-5',
        ...$overrides,
    ]);
}

function delivery(mixed $source, string $translation, array $overrides = []): PendingState
{
    return new PendingState(...[
        'plan' => 'plan-'.Fingerprint::ofText($translation),
        'sourceHash' => Fingerprint::of($source),
        'targetHash' => Fingerprint::ofLeaves([$translation]),
        'targetBefore' => null,
        'segments' => [Fingerprint::ofText((string) $source)],
        'identical' => false,
        'incomplete' => false,
        'translator' => 'openai:gpt-6.1-sol',
        'reviewer' => null,
        'createdAt' => CarbonImmutable::now()->startOfSecond()->subHour(),
        ...$overrides,
    ]);
}

function switchGitOn(): void
{
    config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
}

describe('recordPending', function () {
    it('prepends a pending entry, keeps three and records the source site', function () {
        $localization = $this->localize($this->origin, 'el');
        $entries = array_map(fn (string $text) => delivery('Hello', $text), ['Ένα', 'Δύο', 'Τρία', 'Τέσσερα']);

        foreach ($entries as $entry) {
            writer()->recordPending(writerRef($localization), 'en', 'title', $entry);
        }

        $state = storedState($localization);

        expect($state->sourceSite)->toBe('en')
            ->and($state->version)->toBe(ItemState::VERSION)
            ->and($state->field('title')->pending)->toEqual([$entries[3], $entries[2], $entries[1]])
            ->and($state->field('title')->isMachine())->toBeFalse();
    });

    it('keeps the confirmed output and the other fields of the record', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        storeState($localization, ['title' => machineField('Hello', 'Γεια'), 'intro' => machineField('A short intro.', 'Εισαγωγή.')]);
        $pending = delivery('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);

        writer()->recordPending(writerRef($localization), 'en', 'title', $pending);
        $state = storedState($localization);

        expect($state->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']))
            ->and($state->field('title')->pending)->toEqual([$pending])
            ->and($state->field('intro'))->toEqual(machineField('A short intro.', 'Εισαγωγή.'));
    });
});

describe('confirm', function () {
    it('promotes a pending entry the target saved and moves the previous output to history', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χαίρετε']);
        $pending = delivery('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);
        storeState($localization, ['title' => machineField('Hello', 'Γεια')->withPending($pending)]);

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
        storeState($localization, ['title' => (new FieldState)->withPending(delivery('Hello', 'Γεια'))]);

        $report = writer()->confirm(writerTarget($this->origin));
        $field = storedState($localization)->field('title');
        $status = writerStatus($this->origin)->fields['title'];

        expect($report)->toEqual(new ConfirmReport(baselined: ['title']))
            ->and($field->isBaseline())->toBeTrue()
            ->and($field->sourceHash)->toBe(Fingerprint::of('Hello'))
            ->and($field->translator)->toBe('openai:gpt-6.1-sol')
            ->and($field->pending)->toBe([])
            ->and($status->status)->toBe(FieldStatus::Manual)
            ->and($status->sourceChanged)->toBeFalse();
    });

    it('keeps pending entries and the previous output while nothing was saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $title = delivery('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);
        $intro = delivery('A short intro.', 'Μια σύντομη εισαγωγή.');
        storeState($localization, [
            'title' => machineField('Hello', 'Γεια')->withPending($title),
            'intro' => (new FieldState)->withPending($intro),
        ]);

        $report = writer()->confirm(writerTarget($this->origin));
        $state = storedState($localization);

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title', 'intro']))
            ->and($report->changed())->toBeFalse()
            ->and($state->field('title'))->toEqual(machineField('Hello', 'Γεια')->withPending($title))
            ->and($state->field('intro')->pending)->toEqual([$intro]);
    });

    it('keeps a delivery planned from hand-written text that was not saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        $pending = delivery('Hello', 'Γεια', ['targetBefore' => Fingerprint::ofLeaves(['Χειροποίητο'])]);
        storeState($localization, ['title' => (new FieldState)->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title']))
            ->and(storedState($localization)->field('title')->pending)->toEqual([$pending])
            ->and(writerStatus($this->origin)->fields['title']->isUntracked())->toBeTrue();
    });

    it('keeps pending entries while the target holds an earlier machine output', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Παλιά εισαγωγή.']);
        $pending = delivery('A short intro.', 'Εισαγωγή.');
        storeState($localization, ['intro' => machineField('A short intro.', 'Νέα εισαγωγή.', ['history' => [
            ['source_hash' => Fingerprint::of('A short intro.'), 'target_hash' => Fingerprint::ofLeaves(['Παλιά εισαγωγή.'])],
        ]])->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['intro']))
            ->and(storedState($localization)->field('intro')->pending)->toEqual([$pending])
            ->and(writerStatus($this->origin)->fields['intro']->status)->toBe(FieldStatus::Outdated);
    });

    it('promotes the older of two pending entries when that one was saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $older = delivery('Hello', 'Γεια', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subHours(2)]);
        $newer = delivery('Hello', 'Χαίρετε', ['translator' => 'anthropic:claude-haiku-4-5']);
        storeState($localization, ['title' => (new FieldState)->withPending($older)->withPending($newer)]);

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
        storeState($localization, ['title' => (new FieldState)->withPending(delivery('Hello', 'Hello', ['identical' => true]))]);

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
        $pending = delivery('', '', [
            'sourceHash' => Fingerprint::of($source),
            'targetHash' => Fingerprint::ofLeaves(['Άφιξη;', 'Από τις 14:00.']),
            'segments' => [Fingerprint::ofText('Check-in?'), Fingerprint::ofText('From 14:00.')],
        ]);
        storeState($localization, ['faq' => (new FieldState)->withPending($pending)]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report->promoted)->toBe(['faq'])
            ->and(storedState($localization)->field('faq')->targetHash)->toBe($pending->targetHash)
            ->and(writerStatus($this->origin)->fields['faq']->status)->toBe(FieldStatus::Current);
    });

    it('drops pending entries older than 30 days and keeps the recent ones', function () {
        $localization = $this->localize($this->origin, 'el');
        $recent = delivery('Hello', 'Γεια', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(29)]);
        $old = delivery('Hello', 'Χαίρετε', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(31)]);
        storeState($localization, [
            'title' => (new FieldState)->withPending($old)->withPending($recent),
            'intro' => (new FieldState)->withPending(delivery('A short intro.', 'Εισαγωγή.', ['createdAt' => CarbonImmutable::now()->subDays(40)])),
        ]);
        $path = app(StateRepository::class)->path(writerRef($localization));

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report)->toEqual(new ConfirmReport(unresolved: ['title'], expired: ['title', 'intro']))
            ->and($report->changed())->toBeTrue()
            ->and(storedState($localization)->field('title')->pending)->toEqual([$recent])
            ->and(storedState($localization)->fields)->not->toHaveKey('intro')
            ->and($path)->toBeFile();
    });

    it('leaves pending entries of fields that are out of scope alone', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $retired = delivery('Old', 'Παλιό');
        storeState($localization, [
            'title' => (new FieldState)->withPending(delivery('Hello', 'Γεια')),
            'retired' => (new FieldState)->withPending($retired),
        ]);

        $report = writer()->confirm(writerTarget($this->origin));

        expect($report->promoted)->toBe(['title'])
            ->and(storedState($localization)->field('retired')->pending)->toEqual([$retired]);
    });

    it('writes nothing for a target without pending entries or without a localization', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        storeState($localization, ['title' => machineField('Hello', 'Γεια')]);
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
        storeState($localization, ['title' => new FieldState(sourceHash: Fingerprint::of('Hi'), translator: 'openai:gpt-6.1-sol')]);

        $first = writer()->baseline(writerTarget($this->origin));
        $second = writer()->baseline(writerTarget($this->origin));

        expect($first)->toBe(1)
            ->and($second)->toBe(0)
            ->and(storedState($localization)->field('title'))->toEqual(new FieldState(sourceHash: Fingerprint::of('Hello'), translator: 'openai:gpt-6.1-sol'));
    });

    it('leaves machine output alone unless machine fields are included', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Χειροποίητη εισαγωγή.']);
        storeState($localization, ['title' => machineField('Hello', 'Γεια')]);

        $count = writer()->baseline(writerTarget($this->origin));
        $status = writerStatus($this->origin);

        expect($count)->toBe(1)
            ->and($status->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($status->fields['intro']->status)->toBe(FieldStatus::Manual)
            ->and($status->fields['intro']->sourceChanged)->toBeFalse();
    });

    it('turns machine output manual with $includeMachine', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Παλιά εισαγωγή.']);
        storeState($localization, [
            'title' => machineField('Hello', 'Γεια'),
            'intro' => machineField('A short intro.', 'Νέα εισαγωγή.', ['history' => [
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

    it('confirms a saved delivery before it turns it manual', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        storeState($localization, ['title' => (new FieldState)->withPending(delivery('Hello', 'Γεια'))]);

        $count = writer()->baseline(writerTarget($this->origin), includeMachine: true);
        $confirmed = writer()->confirm(writerTarget($this->origin));

        expect($count)->toBe(1)
            ->and($confirmed)->toEqual(new ConfirmReport)
            ->and(storedState($localization)->field('title')->pending)->toBe([])
            ->and(writerStatus($this->origin)->fields['title']->status)->toBe(FieldStatus::Manual);
    });

    it('keeps unsaved deliveries of a manual field pending', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        $pending = delivery('Hello', 'Γεια', ['targetBefore' => Fingerprint::ofLeaves(['Χειροποίητο'])]);
        storeState($localization, ['title' => (new FieldState)->withPending($pending)]);

        writer()->baseline(writerTarget($this->origin));

        expect(storedState($localization)->field('title'))->toEqual(new FieldState(sourceHash: Fingerprint::of('Hello'), pending: [$pending]));
    });

    it('skips a field whose state changed after its status was read', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $inner = app(StateRepository::class);
        $this->app->instance(StateRepository::class, new class($inner, writerRef($localization)) implements StateRepository
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
                    $this->inner->update($this->ref, fn (ItemState $state) => $state->withField('title', machineField('Hello', 'Γεια')));
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
            ->and(storedState($localization)->field('title'))->toEqual(machineField('Hello', 'Γεια'));
    });

    it('baselines only the given fields and never a field the target inherits', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Χειροποίητη εισαγωγή.', 'body' => 'Χειροποίητο σώμα.']);
        storeState($localization, ['title' => machineField('Hello', 'Hello', ['identical' => true])]);

        $count = writer()->baseline(writerTarget($this->origin), ['title', 'intro'], includeMachine: true);
        $state = storedState($localization);

        expect($count)->toBe(1)
            ->and($state->field('intro')->isBaseline())->toBeTrue()
            ->and($state->field('title'))->toEqual(machineField('Hello', 'Hello', ['identical' => true]))
            ->and($state->fields)->not->toHaveKey('body')
            ->and(writer()->baseline(writerTarget($this->origin, 'de')))->toBe(0);
    });
});

describe('forget', function () {
    it('deletes the state of a target or of single fields', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Εισαγωγή.']);
        storeState($localization, ['title' => machineField('Hello', 'Γεια'), 'intro' => machineField('A short intro.', 'Εισαγωγή.')]);
        $path = app(StateRepository::class)->path(writerRef($localization));

        $field = writer()->forget(writerRef($localization), ['title', 'body']);
        $absent = writer()->forget(writerRef($localization), ['body']);
        $fields = array_keys(storedState($localization)->fields);
        $all = writer()->forget(writerRef($localization));
        $again = writer()->forget(writerRef($localization));

        expect([$field, $absent, $all, $again])->toBe([true, false, true, false])
            ->and($fields)->toBe(['intro'])
            ->and($path)->not->toBeFile();
    });
});

describe('git', function () {
    beforeEach(function () {
        Queue::fake();

        $this->localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο', 'intro' => 'Εισαγωγή.']);
        storeState($this->localization, [
            'title' => new FieldState(sourceHash: Fingerprint::of('Hi')),
            'intro' => (new FieldState)->withPending(delivery('A short intro.', 'Εισαγωγή.')),
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
            writer()->recordPending(writerRef($test->localization), 'en', 'body', delivery('', 'Σώμα.', ['plan' => 'plan-'.(int) $commit]), commit: $commit);

            return true;
        },
        'confirm' => function ($test, bool $commit) {
            if ($commit) {
                storeState($test->localization, ['intro' => (new FieldState)->withPending(delivery('A short intro.', 'Εισαγωγή.'))]);
            }

            return writer()->confirm(writerTarget($test->origin), commit: $commit)->changed();
        },
        'baseline' => fn ($test, bool $commit) => writer()->baseline(writerTarget($test->origin), [$commit ? 'intro' : 'title'], includeMachine: $commit, commit: $commit) === 1,
        'forget' => fn ($test, bool $commit) => writer()->forget(writerRef($test->localization), $commit ? null : ['intro'], commit: $commit),
    ]);

    it('dispatches no commit when nothing changed', function () {
        writer()->confirm(writerTarget($this->origin));
        writer()->confirm(writerTarget($this->origin), commit: true);
        writer()->recordPending(writerRef($this->localization), 'en', 'body', delivery('', 'Σώμα.'));
        $unresolved = writer()->confirm(writerTarget($this->origin), commit: true);
        writer()->baseline(writerTarget($this->origin), ['intro'], commit: true);
        writer()->baseline(writerTarget($this->origin), ['title']);
        $baselined = writer()->baseline(writerTarget($this->origin), ['title'], commit: true);
        writer()->forget(writerRef($this->localization), ['faq'], commit: true);

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
        storeState($greek, ['title' => machineField('Hello', 'Γεια')]);
        storeState($german, ['title' => machineField('Hello', 'Hallo')]);
        $path = app(StateRepository::class)->path(writerRef($greek));

        $greek->delete();

        expect($path)->not->toBeFile()
            ->and(storedState($german)->isEmpty())->toBeFalse();
    });

    it('forgets the state of every localization deleted with its origin', function () {
        $greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $german = $this->localize($this->origin, 'de', ['title' => 'Hallo']);
        storeState($greek, ['title' => machineField('Hello', 'Γεια')]);
        storeState($german, ['title' => machineField('Hello', 'Hallo')]);

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
            app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withField('title', machineField('Travel', 'Ταξίδια')));
        }

        $travel->delete();

        expect(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toBe(['term::topics::food::el']);
    });

    it('commits the forgotten state once, and nothing for content without state', function () {
        $greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $german = $this->localize($this->origin, 'de', ['title' => 'Hallo']);
        storeState($greek, ['title' => machineField('Hello', 'Γεια')]);
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
