<?php

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\Formality;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Enums\UsageSource;

it('pins every case and its value', function (string $enum, array $cases) {
    expect(array_column($enum::cases(), 'value', 'name'))->toBe($cases);
})->with([
    'ContentType' => [ContentType::class, ['Entry' => 'entry', 'Term' => 'term', 'GlobalSet' => 'globals', 'Nav' => 'nav', 'Lang' => 'lang', 'Forms' => 'forms']],
    'FieldStatus' => [FieldStatus::class, [
        'NotApplicable' => 'not_applicable',
        'Untranslated' => 'untranslated',
        'Current' => 'current',
        'Outdated' => 'outdated',
        'Manual' => 'manual',
    ]],
    'ItemStatus' => [ItemStatus::class, [
        'Missing' => 'missing',
        'Untranslated' => 'untranslated',
        'Outdated' => 'outdated',
        'Attention' => 'attention',
        'Current' => 'current',
    ]],
    'Mode' => [Mode::class, ['Missing' => 'missing', 'Update' => 'update', 'All' => 'all', 'Review' => 'review']],
    'SegmentKind' => [SegmentKind::class, ['Plain' => 'plain', 'Inline' => 'inline', 'Markdown' => 'markdown', 'Html' => 'html']],
    'UnitKind' => [UnitKind::class, ['Translate' => 'translate', 'Review' => 'review']],
    'Tier' => [Tier::class, ['Quality' => 'quality', 'Fast' => 'fast']],
    'Formality' => [Formality::class, ['Default' => 'default', 'Formal' => 'formal', 'Informal' => 'informal']],
    'RunStatus' => [RunStatus::class, [
        'Pending' => 'pending',
        'Running' => 'running',
        'Completed' => 'completed',
        'CompletedWithErrors' => 'completed_with_errors',
        'Cancelled' => 'cancelled',
        'Aborted' => 'aborted',
    ]],
    'RunItemStatus' => [RunItemStatus::class, [
        'Pending' => 'pending',
        'Translating' => 'translating',
        'Translated' => 'translated',
        'Applying' => 'applying',
        'Done' => 'done',
        'Skipped' => 'skipped',
        'Failed' => 'failed',
    ]],
    'TargetResultStatus' => [TargetResultStatus::class, ['Done' => 'done', 'Skipped' => 'skipped', 'Failed' => 'failed']],
    'RunDriver' => [RunDriver::class, ['Queue' => 'queue', 'Browser' => 'browser', 'Cli' => 'cli']],
    'UsageSource' => [UsageSource::class, ['Editor' => 'editor', 'Run' => 'run', 'Cli' => 'cli']],
    'Finish' => [Finish::class, ['Stop' => 'stop', 'Length' => 'length', 'Refused' => 'refused', 'Empty' => 'empty']],
    'ProviderErrorKind' => [ProviderErrorKind::class, [
        'Paused' => 'paused',
        'Timeout' => 'timeout',
        'Unavailable' => 'unavailable',
        'Rejected' => 'rejected',
        'RequestRejected' => 'request_rejected',
    ]],
]);

it('picks the worst item status in the order of §A6', function () {
    $order = [ItemStatus::Missing, ItemStatus::Untranslated, ItemStatus::Outdated, ItemStatus::Attention, ItemStatus::Current];

    foreach ($order as $i => $worse) {
        foreach (array_slice($order, $i) as $better) {
            expect(ItemStatus::worst($better, $worse))->toBe($worse)
                ->and(ItemStatus::worst($worse, $better))->toBe($worse);
        }
    }

    expect(ItemStatus::worst(ItemStatus::Current, ItemStatus::Attention, ItemStatus::Current, ItemStatus::Outdated))->toBe(ItemStatus::Outdated)
        ->and(ItemStatus::worst(...array_reverse($order)))->toBe(ItemStatus::Missing);
});

it('treats an item without statuses as current', function () {
    expect(ItemStatus::worst())->toBe(ItemStatus::Current);
});

it('knows which run item statuses are final', function (RunItemStatus $status, bool $final) {
    expect($status->isFinal())->toBe($final);
})->with([
    'pending' => [RunItemStatus::Pending, false],
    'translating' => [RunItemStatus::Translating, false],
    'translated' => [RunItemStatus::Translated, false],
    'applying' => [RunItemStatus::Applying, false],
    'done' => [RunItemStatus::Done, true],
    'skipped' => [RunItemStatus::Skipped, true],
    'failed' => [RunItemStatus::Failed, true],
]);

it('escapes literal <, > and & in plain and inline segments only', function (SegmentKind $kind, bool $escapes) {
    expect($kind->escapes())->toBe($escapes);
})->with([
    'plain' => [SegmentKind::Plain, true],
    'inline' => [SegmentKind::Inline, true],
    'markdown' => [SegmentKind::Markdown, false],
    'html' => [SegmentKind::Html, false],
]);
