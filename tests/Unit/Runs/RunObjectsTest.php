<?php

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Runs\Claim;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Statamic\Facades\User;

beforeEach(function () {
    $this->freezeSecond();
});

it('makes a pending run and captures its creator', function () {
    $named = Run::make(RunDriver::Browser, ['collections' => ['pages']], ['mode' => 'missing'], User::make()->id('u-1')->email('jo@example.com')->set('name', 'Jo Editor'));
    $unnamed = Run::make(RunDriver::Queue, user: User::make()->id('u-2')->email('kim@example.com'));
    $cli = Run::make(RunDriver::Cli);

    expect($named->id)->toMatch('/^[0-9A-Z]{26}$/')
        ->and($named->id)->not->toBe($cli->id)
        ->and($named->status)->toBe(RunStatus::Pending)
        ->and($named->driver)->toBe(RunDriver::Browser)
        ->and($named->createdBy)->toBe(['id' => 'u-1', 'name' => 'Jo Editor'])
        ->and($named->createdAt->equalTo(now()))->toBeTrue()
        ->and($named->scope)->toBe(['collections' => ['pages']])
        ->and($named->options)->toBe(['mode' => 'missing'])
        ->and($named->startedAt)->toBeNull()
        ->and($named->estimate)->toBeNull()
        ->and($named->generation)->toBe(0)
        ->and($unnamed->createdBy)->toBe(['id' => 'u-2', 'name' => 'kim@example.com'])
        ->and($cli->createdBy)->toBeNull();
});

it('round-trips a run through its array', function () {
    $run = new Run(
        id: '01K6ZZ4QJ1R4M3W6F2VXDB9CTA',
        driver: RunDriver::Queue,
        status: RunStatus::CompletedWithErrors,
        createdBy: ['id' => 'u-1', 'name' => 'Jo'],
        createdAt: CarbonImmutable::parse('2026-10-05T08:00:00Z'),
        startedAt: CarbonImmutable::parse('2026-10-05T08:00:10Z'),
        finishedAt: CarbonImmutable::parse('2026-10-05T08:05:00Z'),
        finalizedAt: CarbonImmutable::parse('2026-10-05T08:05:01Z'),
        progressedAt: CarbonImmutable::parse('2026-10-05T08:04:59Z'),
        options: ['mode' => 'update', 'review' => true],
        scope: ['sites' => ['el', 'de'], 'collections' => ['*']],
        usage: (new UsageMap)->add(new ModelRef('anthropic', 'claude-sonnet-5-5'), new Usage(input: 10, output: 5, calls: 1)),
        estimate: ['segments' => 4, 'cost' => 2.0],
        generation: 3,
        error: 'One item failed.',
        warnings: [['item' => 2, 'code' => 'parent_not_localized']],
        saves: 4,
        committedSaves: 3,
    );

    $data = $run->toArray();

    expect($data)->toBe([
        'id' => '01K6ZZ4QJ1R4M3W6F2VXDB9CTA',
        'status' => 'completed_with_errors',
        'driver' => 'queue',
        'created_by' => ['id' => 'u-1', 'name' => 'Jo'],
        'created_at' => '2026-10-05T08:00:00Z',
        'started_at' => '2026-10-05T08:00:10Z',
        'finished_at' => '2026-10-05T08:05:00Z',
        'finalized_at' => '2026-10-05T08:05:01Z',
        'progressed_at' => '2026-10-05T08:04:59Z',
        'options' => ['mode' => 'update', 'review' => true],
        'scope' => ['sites' => ['el', 'de'], 'collections' => ['*']],
        'usage' => ['anthropic:claude-sonnet-5-5' => (new Usage(input: 10, output: 5, calls: 1))->toArray()],
        'estimate' => ['segments' => 4, 'cost' => 2.0],
        'generation' => 3,
        'error' => 'One item failed.',
        'warnings' => [['item' => 2, 'code' => 'parent_not_localized']],
        'saves' => 4,
        'committed_saves' => 3,
    ])
        ->and(Run::fromArray(json_decode(json_encode($data, JSON_PRESERVE_ZERO_FRACTION), true))->toArray())->toBe($data)
        ->and($run->lastActivityAt()->toIso8601ZuluString())->toBe('2026-10-05T08:05:01Z')
        ->and(Run::fromArray(['id' => 'x', 'driver' => 'cli', 'status' => 'pending', 'created_by' => ['name' => 'nobody'], 'estimate' => 'none'])->toArray())
        ->toMatchArray(['created_by' => null, 'estimate' => null, 'usage' => [], 'generation' => 0, 'saves' => 0, 'committed_saves' => 0])
        ->and(fn () => Run::fromArray(['id' => 'x', 'driver' => 'cli']))->toThrow(ValueError::class);
});

it('tracks the changes since a run was read and refreshes it from storage without losing them', function () {
    $new = Run::make(RunDriver::Cli);

    expect(array_keys($new->changes()))->toBe(array_keys($new->toArray()));

    $read = Run::fromArray($new->toArray());

    expect($read->changes())->toBe([]);

    $read->status = RunStatus::Cancelled;
    $read->warnings = ['stopped by Jo'];

    expect($read->changes())->toBe(['status' => 'cancelled', 'warnings' => ['stopped by Jo']]);

    $stored = $new->toArray();
    $stored['generation'] = 4;
    $stored['status'] = 'running';
    $read->refresh(Run::fromArray($stored));

    expect($read->generation)->toBe(4)
        ->and($read->status)->toBe(RunStatus::Cancelled)
        ->and($read->changes())->toBe(['status' => 'cancelled', 'warnings' => ['stopped by Jo']]);
});

it('takes the latest of a run\'s times as its last activity', function () {
    $run = Run::make(RunDriver::Cli);

    expect($run->lastActivityAt()->equalTo(now()))->toBeTrue();

    $run->progressedAt = now()->addMinutes(5)->toImmutable();
    $run->startedAt = now()->addMinute()->toImmutable();

    expect($run->lastActivityAt()->equalTo(now()->addMinutes(5)))->toBeTrue();
});

it('round-trips a run item through its array', function () {
    $item = new RunItem(
        index: 4,
        type: ContentType::Entry,
        sourceId: 'home',
        targetSite: 'de',
        title: 'Startseite',
        stage: 1,
        dependsOn: [0, 2],
        status: RunItemStatus::Skipped,
        reason: RunItem::DEPENDENCY_FAILED,
        attempts: 2,
        waits: 1,
        planId: 'plan-9',
        claim: new Claim('lane-1', 'token', CarbonImmutable::parse('2026-10-05T08:00:00Z'), 60),
        availableAt: CarbonImmutable::parse('2026-10-05T08:01:00Z'),
        fields: ['title' => ['status' => 'applied']],
        warnings: ['parent_not_localized'],
        corrections: ['title' => [['id' => 's1', 'before' => 'Startseit', 'after' => 'Startseite', 'reason' => 'Typo.']]],
        error: 'Failed once.',
        usage: (new UsageMap)->add(new ModelRef('openai', 'gpt-6-luna'), new Usage(input: 3, calls: 1)),
        reference: 'entry::home-de',
        editUrl: 'http://localhost/cp/collections/pages/entries/home-de',
        created: true,
    );

    $data = $item->toArray();

    expect($data)->toMatchArray([
        'index' => 4,
        'type' => 'entry',
        'source_id' => 'home',
        'target_site' => 'de',
        'depends_on' => [0, 2],
        'status' => 'skipped',
        'reason' => 'dependency_failed',
        'claim' => ['by' => 'lane-1', 'token' => 'token', 'expires_at' => '2026-10-05T08:00:00Z', 'ttl' => 60],
        'available_at' => '2026-10-05T08:01:00Z',
        'plan_id' => 'plan-9',
        'corrections' => ['title' => [['id' => 's1', 'before' => 'Startseit', 'after' => 'Startseite', 'reason' => 'Typo.']]],
        'edit_url' => 'http://localhost/cp/collections/pages/entries/home-de',
        'created' => true,
    ])
        ->and(RunItem::fromArray(json_decode(json_encode($data), true))->toArray())->toBe($data)
        ->and(RunItem::fromArray(['index' => 1, 'type' => 'entry', 'source_id' => 'a', 'target_site' => 'el', 'status' => 'pending', 'claim' => ['by' => 'x']])->claim)->toBeNull()
        ->and(fn () => RunItem::fromArray(['index' => 1, 'type' => 'page', 'status' => 'pending']))->toThrow(ValueError::class);
});

it('hands out a claim with a random token and extends it by its lifetime', function () {
    $claim = Claim::for('lane-1', 60);
    $other = Claim::for('lane-1', 0);

    expect($claim->by)->toBe('lane-1')
        ->and(strlen($claim->token))->toBe(32)
        ->and($claim->token)->not->toBe($other->token)
        ->and($claim->expiresAt->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and($other->ttl)->toBe(1)
        ->and($claim->isExpired())->toBeFalse();

    $this->travel(60)->seconds();

    expect($claim->isExpired())->toBeTrue();

    $extended = $claim->extended();

    expect($extended->token)->toBe($claim->token)
        ->and($extended->expiresAt->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and(Claim::fromArray($extended->toArray()))->toEqual($extended)
        ->and(Claim::fromArray(null))->toBeNull()
        ->and(Claim::fromArray(['by' => 'lane-1', 'token' => 't']))->toBeNull();
});
