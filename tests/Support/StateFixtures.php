<?php

namespace Reach\StatamicTolk\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * State records of a plain text field, for tests of status and state writes.
 */
final class StateFixtures
{
    /**
     * @return array<string, FieldStatus> the status of each in-scope field of the entry's target in the site
     */
    public static function statuses(EntryContract|string $source, string $site = 'el'): array
    {
        return array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve(app(TargetFactory::class)->forEntry($source, $site))->fields);
    }

    public static function ref(EntryContract $localization): TargetRef
    {
        return new TargetRef(ContentType::Entry, $localization->id());
    }

    public static function stored(EntryContract $localization): ItemState
    {
        return app(StateRepository::class)->get(self::ref($localization));
    }

    /**
     * @param  array<string, FieldState>  $fields
     */
    public static function store(EntryContract $localization, array $fields): void
    {
        app(StateRepository::class)->update(self::ref($localization), function (ItemState $state) use ($fields) {
            foreach ($fields as $handle => $field) {
                $state = $state->withField($handle, $field);
            }

            return $state;
        });
    }

    /**
     * Leaves the markers of a git merge conflict in the state file of the localization or reference, which must exist,
     * and returns its path.
     */
    public static function conflict(EntryContract|TargetRef $target): string
    {
        $path = app(StateRepository::class)->path($target instanceof TargetRef ? $target : self::ref($target));
        File::put($path, "<<<<<<< HEAD\n".File::get($path)."=======\n>>>>>>> translations\n");

        return $path;
    }

    public static function machine(mixed $source, string $translation, array $overrides = []): FieldState
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

    public static function pending(mixed $source, string $translation, array $overrides = []): PendingState
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
}
