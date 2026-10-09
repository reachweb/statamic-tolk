<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\State\TargetStatus;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\CountingStateRepository;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Contracts\Entries\Entry as EntryContract;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [
            ['handle' => 'question', 'field' => ['type' => 'text']],
            ['handle' => 'answer', 'field' => ['type' => 'textarea']],
            ['handle' => 'more', 'field' => ['type' => 'text', 'default' => 'Read more']],
        ]],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
});

function statusOf(EntryContract $origin, string $site = 'el'): TargetStatus
{
    return app(StatusResolver::class)->resolve(app(TargetFactory::class)->forEntry($origin, $site));
}

function editOrigin(EntryContract $origin, array $values): void
{
    foreach ($values as $handle => $value) {
        $value === null ? $origin->remove($handle) : $origin->set($handle, $value);
    }

    $origin->save();
}

describe('field status', function () {
    it('reads a field the source holds no text for as not applicable and leaves it out of the counts', function () {
        $this->localize($this->origin, 'el');

        $status = statusOf($this->origin);

        expect($status->fields['body']->status)->toBe(FieldStatus::NotApplicable)
            ->and($status->fields['faq']->status)->toBe(FieldStatus::NotApplicable)
            ->and($status->counts)->toBe(['untranslated' => 2, 'current' => 0, 'outdated' => 0, 'manual' => 0, 'attention' => 0, 'untracked' => 0]);
    });

    it('reads an inherited field without state as untranslated', function () {
        $this->localize($this->origin, 'el');

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Untranslated)
            ->and($field->sourceChanged)->toBeNull()
            ->and($field->targetHash)->toBeNull()
            ->and($field->sourceHash)->toBe(Fingerprint::of('Hello'))
            ->and($field->state)->toEqual(new FieldState);
    });

    it('reads untouched machine output of the current source as current', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια', ['reviewer' => 'gemini:gemini-3.8-flash'])]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Current)
            ->and($field->sourceChanged)->toBeFalse()
            ->and($field->incomplete)->toBeFalse()
            ->and($field->translatedAt)->toEqual(CarbonImmutable::parse('2026-10-01T12:00:00Z'))
            ->and($field->translator)->toBe('anthropic:claude-sonnet-5-5')
            ->and($field->reviewer)->toBe('gemini:gemini-3.8-flash')
            ->and($field->label)->toBe('Title')
            ->and($field->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']));
    });

    it('reads machine output as outdated once the origin changes', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);

        editOrigin($this->origin, ['title' => 'Hello there']);
        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Outdated)
            ->and($field->sourceChanged)->toBeTrue();
    });

    it('reads incomplete machine output as outdated', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'A short intro.']);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'A short intro.', [
            'segments' => [null],
            'identical' => true,
            'incomplete' => true,
        ])]);

        $field = statusOf($this->origin)->fields['intro'];

        expect($field->status)->toBe(FieldStatus::Outdated)
            ->and($field->sourceChanged)->toBeFalse()
            ->and($field->incomplete)->toBeTrue()
            ->and($field->label)->toBe('Introduction');
    });

    it('reads a hand-written override without state as manual and untracked', function () {
        $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);

        $status = statusOf($this->origin);
        $field = $status->fields['title'];

        expect($field->status)->toBe(FieldStatus::Manual)
            ->and($field->sourceChanged)->toBeNull()
            ->and($field->isUntracked())->toBeTrue()
            ->and($field->needsAttention())->toBeFalse()
            ->and($status->counts['manual'])->toBe(1)
            ->and($status->counts['untracked'])->toBe(1)
            ->and($status->counts['attention'])->toBe(0);
    });

    it('reads an override without state that holds the source\'s own text as untranslated', function () {
        editOrigin($this->origin, ['faq' => [['question' => 'Check-in?', 'answer' => 'From 14:00.']]]);
        // As a CP save stores a field that desynced itself: a row id, a flag and a set default added, no text changed.
        $this->localize($this->origin, 'el', [
            'title' => 'Hello',
            'faq' => [['id' => 'mf3k2a9x', 'question' => 'Check-in?', 'answer' => 'From 14:00.', 'more' => 'Read more', 'enabled' => true]],
        ]);

        $status = statusOf($this->origin);

        expect($status->fields['title']->status)->toBe(FieldStatus::Untranslated)
            ->and($status->fields['title']->sourceChanged)->toBeNull()
            ->and($status->fields['title']->targetHash)->toBe(Fingerprint::ofLeaves(['Hello']))
            ->and($status->fields['faq']->status)->toBe(FieldStatus::Untranslated)
            ->and($status->counts)->toBe(['untranslated' => 3, 'current' => 0, 'outdated' => 0, 'manual' => 0, 'attention' => 0, 'untracked' => 0]);
    });

    it('reads the origin\'s Bard value as the CP stores it after the field desynced itself as untranslated', function () {
        $this->makeCollection('pages', ['en', 'de'], ['content' => ['type' => 'bard', 'localizable' => true, 'buttons' => ['h2', 'bold', 'anchor', 'image'], 'sets' => ['main' => ['sets' => [
            'quote' => ['display' => 'Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
        ]]]]]);
        $link = ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms']];
        $origin = $this->makeEntry('pages', 'en', ['content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Welcome']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'See our '], ['type' => 'text', 'marks' => [$link], 'text' => 'rooms'], ['type' => 'text', 'text' => '.']]],
            ['type' => 'image', 'attrs' => ['src' => 'asset::assets::pool.jpg', 'alt' => 'The pool']],
            ['type' => 'set', 'attrs' => ['id' => 'q1w2e3r4', 'values' => ['type' => 'quote', 'text' => 'Simply wonderful.']]],
        ]], 'home');
        // Bard's own JSON: null attributes added, the block image wrapped in a paragraph.
        $this->localize($origin, 'de', ['content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => null], 'content' => [['type' => 'text', 'text' => 'Welcome']]],
            ['type' => 'paragraph', 'attrs' => ['textAlign' => null], 'content' => [
                ['type' => 'text', 'text' => 'See our '],
                ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms', 'rel' => null, 'target' => null, 'title' => null]]], 'text' => 'rooms'],
                ['type' => 'text', 'text' => '.'],
            ]],
            ['type' => 'paragraph', 'attrs' => ['textAlign' => null], 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::assets::pool.jpg', 'alt' => 'The pool']]]],
            ['type' => 'set', 'attrs' => ['id' => 'q1w2e3r4', 'enabled' => true, 'values' => ['type' => 'quote', 'text' => 'Simply wonderful.']]],
        ]]);

        $field = statusOf($origin, 'de')->fields['content'];

        expect($field->status)->toBe(FieldStatus::Untranslated)
            ->and($field->targetHash)->toBe($field->sourceTextHash);
    });

    it('reads a copy of the source\'s text as manual once a human vouched for it, and a copy of a source without text as manual', function () {
        editOrigin($this->origin, ['body' => '2026']);
        $localization = $this->localize($this->origin, 'el', ['title' => 'Hello', 'body' => '2026']);
        StateFixtures::store($localization, ['title' => new FieldState(sourceHash: Fingerprint::of('Hello'))]);

        $status = statusOf($this->origin);

        expect($status->fields['title']->status)->toBe(FieldStatus::Manual)
            ->and($status->fields['title']->sourceChanged)->toBeFalse()
            ->and($status->fields['body']->status)->toBe(FieldStatus::Manual)
            ->and($status->fields['body']->isUntracked())->toBeTrue();
    });

    it('reads a baseline as manual with an unchanged source, then as changed after an origin edit', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        StateFixtures::store($localization, ['title' => new FieldState(sourceHash: Fingerprint::of('Hello'))]);

        $before = statusOf($this->origin)->fields['title'];
        editOrigin($this->origin, ['title' => 'Hello there']);
        $after = statusOf($this->origin);

        expect($before->status)->toBe(FieldStatus::Manual)
            ->and($before->sourceChanged)->toBeFalse()
            ->and($before->isUntracked())->toBeFalse()
            ->and($after->fields['title']->status)->toBe(FieldStatus::Manual)
            ->and($after->fields['title']->sourceChanged)->toBeTrue()
            ->and($after->fields['title']->needsAttention())->toBeTrue()
            ->and($after->counts['attention'])->toBe(1)
            ->and($after->counts['untracked'])->toBe(0);
    });
});

describe('pending entries', function () {
    it('promotes a pending entry in memory when the saved text matches it', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $pending = StateFixtures::pending('Hello', 'Γεια');
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Current)
            ->and($field->state->pending)->toBe([])
            ->and($field->state->targetHash)->toBe($pending->targetHash)
            ->and($field->translatedAt)->toEqual($pending->createdAt)
            ->and($field->translator)->toBe('openai:gpt-6.1-sol')
            ->and(app(StateRepository::class)->get(StateFixtures::ref($localization))->field('title')->pending)->toHaveCount(1);
    });

    it('reads a pending entry that was edited before saving as manual with an unchanged source', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια σου, κόσμε']);
        $pending = StateFixtures::pending('Hello', 'Γεια');
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Manual)
            ->and($field->sourceChanged)->toBeFalse()
            ->and($field->state->isBaseline())->toBeTrue()
            ->and($field->state->pending)->toEqual([$pending->withTargetBefore(Fingerprint::ofLeaves(['Γεια σου, κόσμε']))])
            ->and($field->translator)->toBe('openai:gpt-6.1-sol');
    });

    it('keeps a delivery pending when the form saved a copy of the source\'s text instead', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Hello']);
        $pending = StateFixtures::pending('Hello', 'Γεια');
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Untranslated)
            ->and($field->state->isBaseline())->toBeFalse()
            ->and($field->state->pending)->toEqual([$pending]);
    });

    it('keeps the previous status while a pending entry has not been saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $pending = StateFixtures::pending('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);
        StateFixtures::store($localization, [
            'title' => StateFixtures::machine('Hello', 'Γεια')->withPending($pending),
            'intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Μια σύντομη εισαγωγή.')),
        ]);

        $status = statusOf($this->origin);

        expect($status->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($status->fields['title']->state->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']))
            ->and($status->fields['title']->state->pending)->toEqual([$pending])
            ->and($status->fields['intro']->status)->toBe(FieldStatus::Untranslated)
            ->and($status->fields['intro']->state->pending)->toHaveCount(1);
    });

    it('reads given values in place of the target\'s own, as if they were saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $pending = StateFixtures::pending('Hello', 'Χαίρετε', ['targetBefore' => Fingerprint::ofLeaves(['Γεια'])]);
        StateFixtures::store($localization, [
            'title' => StateFixtures::machine('Hello', 'Γεια')->withPending($pending),
            'intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Μια σύντομη εισαγωγή.')),
        ]);
        $target = app(TargetFactory::class)->forEntry($this->origin, 'el');

        $delivered = app(StatusResolver::class)->resolve($target, ['title' => 'Χαίρετε', 'intro' => 'Μια σύντομη εισαγωγή.'])->fields;
        $edited = app(StatusResolver::class)->resolve($target, ['intro' => 'Μια εισαγωγή.'])->fields;

        expect($delivered['title']->status)->toBe(FieldStatus::Current)
            ->and($delivered['title']->targetHash)->toBe(Fingerprint::ofLeaves(['Χαίρετε']))
            ->and($delivered['title']->state->targetHash)->toBe($pending->targetHash)
            ->and($delivered['intro']->status)->toBe(FieldStatus::Current)
            ->and($edited['intro']->status)->toBe(FieldStatus::Manual)
            ->and($edited['title']->status)->toBe(FieldStatus::Current)
            ->and($edited['title']->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']))
            ->and(app(StatusResolver::class)->resolve($target, ['body' => null])->fields['body']->status)->toBe(FieldStatus::Manual);
    });

    it('keeps a hand-written field manual while a delivery planned from its text is not saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Χειροποίητο']);
        $pending = StateFixtures::pending('Hello', 'Γεια', ['targetBefore' => Fingerprint::ofLeaves(['Χειροποίητο'])]);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($pending)]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Manual)
            ->and($field->isUntracked())->toBeTrue()
            ->and($field->state->pending)->toEqual([$pending]);
    });

    it('keeps pending entries while the target holds earlier machine output', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Παλιά εισαγωγή.']);
        $titlePending = StateFixtures::pending('Hello', 'Χαίρετε');
        $introPending = StateFixtures::pending('A short intro.', 'Εισαγωγή.');
        StateFixtures::store($localization, [
            'title' => StateFixtures::machine('Hello', 'Γεια')->withPending($titlePending),
            'intro' => StateFixtures::machine('A short intro.', 'Νέα εισαγωγή.', ['history' => [
                ['source_hash' => Fingerprint::of('A short intro.'), 'target_hash' => Fingerprint::ofLeaves(['Παλιά εισαγωγή.'])],
            ]])->withPending($introPending),
        ]);

        $status = statusOf($this->origin);

        expect($status->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($status->fields['title']->state->pending)->toEqual([$titlePending])
            ->and($status->fields['intro']->status)->toBe(FieldStatus::Outdated)
            ->and($status->fields['intro']->state->pending)->toEqual([$introPending]);
    });

    it('promotes the older of two pending entries when that one was saved', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $older = StateFixtures::pending('Hello', 'Γεια', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subHours(2)]);
        $newer = StateFixtures::pending('Hello', 'Χαίρετε', ['translator' => 'anthropic:claude-haiku-5-5']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($older)->withPending($newer)]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Current)
            ->and($field->state->targetHash)->toBe($older->targetHash)
            ->and($field->translator)->toBe('openai:gpt-6.1-sol')
            ->and($field->translatedAt)->toEqual($older->createdAt)
            ->and($field->state->pending)->toBe([]);
    });

    it('promotes an identical pending entry when the target inherits the field', function () {
        $localization = $this->localize($this->origin, 'el');
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Hello', ['identical' => true]))]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Current)
            ->and($field->state->identical)->toBeTrue()
            ->and($field->state->pending)->toBe([]);
    });

    it('drops pending entries older than 30 days that were never saved', function () {
        $localization = $this->localize($this->origin, 'el');
        $recent = StateFixtures::pending('Hello', 'Γεια', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(29)]);
        $old = StateFixtures::pending('Hello', 'Χαίρετε', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(31)]);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending($old)->withPending($recent)]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Untranslated)
            ->and($field->state->pending)->toEqual([$recent]);
    });
});

describe('machine output', function () {
    it('reads an older machine output that was put back as outdated', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Χαίρετε', ['history' => [
            ['source_hash' => Fingerprint::of('Hello'), 'target_hash' => Fingerprint::ofLeaves(['Γεια'])],
        ]])]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Outdated)
            ->and($field->incomplete)->toBeFalse();
    });

    it('reads a translation identical to its source without an override as current, then outdated', function () {
        $localization = $this->localize($this->origin, 'el');
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Hello', ['identical' => true])]);

        $before = statusOf($this->origin)->fields['title'];
        editOrigin($this->origin, ['title' => 'Hello there']);
        $after = statusOf($this->origin)->fields['title'];

        expect($before->status)->toBe(FieldStatus::Current)
            ->and($before->targetHash)->toBeNull()
            ->and($after->status)->toBe(FieldStatus::Outdated);
    });

    it('reads an incomplete translation identical to its source without an override as outdated', function () {
        $localization = $this->localize($this->origin, 'el');
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Hello', ['identical' => true, 'incomplete' => true, 'segments' => [null]])]);

        $field = statusOf($this->origin)->fields['title'];

        expect($field->status)->toBe(FieldStatus::Outdated)
            ->and($field->incomplete)->toBeTrue();
    });

    it('reads machine output as outdated once its source was emptied', function () {
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Μια σύντομη εισαγωγή.']);
        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Μια σύντομη εισαγωγή.')]);

        editOrigin($this->origin, ['intro' => null]);
        $field = statusOf($this->origin)->fields['intro'];

        expect($field->status)->toBe(FieldStatus::Outdated)
            ->and($field->sourceChanged)->toBeTrue();
    });

    it('reads machine output of a source without text as current until the source changes', function () {
        editOrigin($this->origin, ['intro' => 'https://example.com/intro']);
        $localization = $this->localize($this->origin, 'el', ['intro' => 'https://example.com/intro']);
        StateFixtures::store($localization, ['intro' => new FieldState(
            sourceHash: Fingerprint::of('https://example.com/intro'),
            targetHash: Fingerprint::ofLeaves([]),
        )]);

        $before = statusOf($this->origin)->fields['intro'];
        editOrigin($this->origin, ['intro' => 'https://example.com/other']);
        $after = statusOf($this->origin)->fields['intro'];

        expect($before->status)->toBe(FieldStatus::Current)
            ->and($after->status)->toBe(FieldStatus::Outdated);
    });

    it('reads a manual target as manual once its source was emptied', function () {
        $this->localize($this->origin, 'el', ['intro' => 'Χειροποίητη εισαγωγή.']);

        editOrigin($this->origin, ['intro' => null]);
        $field = statusOf($this->origin)->fields['intro'];

        expect($field->status)->toBe(FieldStatus::Manual)
            ->and($field->isUntracked())->toBeTrue();
    });

    it('reads machine output rewritten without a text change as machine output', function () {
        $source = [['question' => 'Check-in?', 'answer' => 'From 14:00.']];
        editOrigin($this->origin, ['faq' => $source]);
        $localization = $this->localize($this->origin, 'el', ['faq' => [['question' => 'Άφιξη;', 'answer' => 'Από τις 14:00.']]]);
        StateFixtures::store($localization, ['faq' => new FieldState(
            sourceHash: Fingerprint::of($source),
            targetHash: Fingerprint::ofLeaves(['Άφιξη;', 'Από τις 14:00.']),
        )]);

        $before = statusOf($this->origin)->fields['faq'];
        $localization->set('faq', [['id' => 'mf3k2a9x', 'question' => 'Άφιξη;', 'answer' => 'Από τις 14:00.', 'more' => 'Read more', 'enabled' => true]])->save();
        $after = statusOf($this->origin)->fields['faq'];

        expect($before->status)->toBe(FieldStatus::Current)
            ->and($after->status)->toBe(FieldStatus::Current)
            ->and($after->targetHash)->toBe($before->targetHash);
    });
});

describe('item status', function () {
    it('reads a missing localization as missing, with every field the source has text for untranslated', function () {
        $status = statusOf($this->origin, 'de');

        expect($status->exists)->toBeFalse()
            ->and($status->itemStatus)->toBe(ItemStatus::Missing)
            ->and(array_keys($status->fields))->toBe(['title', 'intro', 'body', 'faq'])
            ->and($status->fields['title']->status)->toBe(FieldStatus::Untranslated)
            ->and($status->fields['intro']->status)->toBe(FieldStatus::Untranslated)
            ->and($status->fields['body']->status)->toBe(FieldStatus::NotApplicable)
            ->and($status->counts['untranslated'])->toBe(2);
    });

    it('orders item statuses worst first', function () {
        editOrigin($this->origin, ['body' => 'The body.']);
        $localization = $this->localize($this->origin, 'el', ['intro' => 'Εισαγωγή.', 'body' => 'Χειροποίητο σώμα.']);
        StateFixtures::store($localization, [
            'intro' => StateFixtures::machine('An older intro.', 'Εισαγωγή.'),
            'body' => new FieldState(sourceHash: Fingerprint::of('An older body.')),
        ]);

        $untranslated = statusOf($this->origin);

        $localization->set('title', 'Γεια')->save();
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        $outdated = statusOf($this->origin);

        StateFixtures::store($localization, ['intro' => StateFixtures::machine('A short intro.', 'Εισαγωγή.')]);
        $attention = statusOf($this->origin);

        StateFixtures::store($localization, ['body' => new FieldState(sourceHash: Fingerprint::of('The body.'))]);
        $current = statusOf($this->origin);

        $localization->set('body', 'Άλλο σώμα.')->save();
        StateFixtures::store($localization, ['body' => new FieldState]);
        $untracked = statusOf($this->origin);

        expect($untranslated->itemStatus)->toBe(ItemStatus::Untranslated)
            ->and($untranslated->exists)->toBeTrue()
            ->and($untranslated->counts)->toBe(['untranslated' => 1, 'current' => 0, 'outdated' => 1, 'manual' => 1, 'attention' => 1, 'untracked' => 0])
            ->and($outdated->itemStatus)->toBe(ItemStatus::Outdated)
            ->and($attention->itemStatus)->toBe(ItemStatus::Attention)
            ->and($current->itemStatus)->toBe(ItemStatus::Current)
            ->and($current->counts)->toBe(['untranslated' => 0, 'current' => 2, 'outdated' => 0, 'manual' => 1, 'attention' => 0, 'untracked' => 0])
            ->and($untracked->itemStatus)->toBe(ItemStatus::Current)
            ->and($untracked->counts['untracked'])->toBe(1);
    });

    it('never writes state', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => 'Χειροποίητη εισαγωγή.']);
        StateFixtures::store($localization, [
            'title' => (new FieldState)->withPending(StateFixtures::pending('Hello', 'Γεια')),
            'intro' => (new FieldState)->withPending(StateFixtures::pending('A short intro.', 'Εισαγωγή.')),
            'body' => (new FieldState)->withPending(StateFixtures::pending('The body.', 'Σώμα.', ['createdAt' => CarbonImmutable::now()->startOfSecond()->subDays(40)])),
        ]);
        $path = app(StateRepository::class)->path(StateFixtures::ref($localization));
        $stored = File::get($path);

        $repository = new CountingStateRepository(app(StateRepository::class));
        $this->app->instance(StateRepository::class, $repository);

        $status = statusOf($this->origin);

        expect($status->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($status->fields['intro']->state->isBaseline())->toBeTrue()
            ->and($status->fields['body']->state->pending)->toBe([])
            ->and($repository->writes)->toBe(0)
            ->and(File::get($path))->toBe($stored);
    });
});
