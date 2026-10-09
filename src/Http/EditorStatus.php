<?php

namespace Reach\StatamicTolk\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The status payload of the editor banner for the entry or term open in the publish form: what it holds as a
 * localization and, when it is the origin of other sites, what they hold.
 */
final readonly class EditorStatus
{
    public const string SINGLE_SITE = 'single_site';

    public const string UNKNOWN_REFERENCE = 'unknown_reference';

    public const string NO_ACCESS = 'no_access';

    public const string EXCLUDED = 'excluded';

    /**
     * The types of reference item() resolves: the content with a publish form to deliver into (§A13).
     */
    public const array TYPES = [ContentType::Entry, ContentType::Term];

    public function __construct(
        private TargetFactory $targets,
        private StatusResolver $resolver,
        private ModelCatalog $catalog,
        private Providers $providers,
        private Settings $settings,
        private EditorPush $push,
        private EntryFamilies $families,
        private AutoUpdater $updater,
    ) {}

    /**
     * @throws AuthorizationException when the user may not edit the item
     */
    public function for(string $reference, User $user): array
    {
        $item = $this->item($reference);

        if ($item === null) {
            return $this->payload(self::UNKNOWN_REFERENCE);
        }

        // A term in its taxonomy's default site is its own origin().
        $source = $item->hasOrigin() ? $item->origin() : null;
        $target = $source === null ? null : $this->target($source, $item->locale());
        $allowed = $target === null
            ? $this->mayAccess($user, $item->locale()) && $user->can('edit', $item)
            : $target->authorize($user);

        if (! $allowed) {
            throw new AuthorizationException;
        }

        if ($this->sitesOf($item)->count() < 2) {
            return $this->payload(self::SINGLE_SITE);
        }

        if ($this->excluded($item)) {
            return $this->payload(self::EXCLUDED);
        }

        $localization = $target === null ? null : $this->localization($target, $item, $source);
        $origin = $this->origin($item, $user);

        if ($localization === null && $origin === null) {
            return $this->payload(self::NO_ACCESS);
        }

        $active = $origin === null ? null : $this->push->active($item->reference(), $user);

        return $this->payload(null, $localization, $origin, $active === null ? null : $this->push->payload($active));
    }

    /**
     * The entry, or the term in the site the reference names; null for any other reference.
     */
    public function item(string $reference): EntryContract|TermContract|null
    {
        try {
            $ref = TargetRef::parse($reference);
        } catch (InvalidTargetRef) {
            return null;
        }

        return match ($ref->type) {
            ContentType::Entry => Entry::find($ref->id),
            ContentType::Term => $this->targets->findTerm($ref),
            default => null,
        };
    }

    private function target(EntryContract|TermContract $source, string $site): Target
    {
        return $source instanceof EntryContract ? $this->targets->forEntry($source, $site) : $this->targets->forTerm($source, $site);
    }

    /**
     * @return Collection<int, string>
     */
    private function sitesOf(EntryContract|TermContract $item): Collection
    {
        return $item instanceof EntryContract ? $item->collection()->sites() : $item->taxonomy()->sites();
    }

    private function excluded(EntryContract|TermContract $item): bool
    {
        return $item instanceof EntryContract
            ? $this->settings->excludes(ContentType::Entry, $item->collectionHandle())
            : $this->settings->excludes(ContentType::Term, $item->taxonomyHandle());
    }

    private function localization(Target $target, EntryContract|TermContract $item, EntryContract|TermContract $source): array
    {
        $status = $this->resolver->resolve($target);

        return [
            'source' => $this->site($target->sourceSite()),
            'target' => $this->site($target->targetSite()),
            'status' => $status->itemStatus->value,
            'fields' => array_map(fn (FieldStatusInfo $field) => [
                'status' => $field->status->value,
                'label' => $field->label,
                'source_changed' => $field->sourceChanged,
                'incomplete' => $field->incomplete,
            ], $status->fields),
            'counts' => $status->counts,
            'out_of_scope' => $this->outOfScope($target),
            'origin_slug' => $source->slug(),
            'translate_slug' => $source instanceof EntryContract && $this->settings->translatesSlugs($source->collectionHandle()),
            'auto_updated' => $this->autoUpdated($item, $source),
        ];
    }

    /**
     * When an automatic update from the origin last saved the localization, and whether that update waits in its
     * working copy (T11.1). Looked up only while the settings update the origin's collection automatically.
     *
     * @return ?array{at: string, unpublished: bool}
     */
    private function autoUpdated(EntryContract|TermContract $item, EntryContract|TermContract $source): ?array
    {
        if (! $item instanceof EntryContract || ! $source instanceof EntryContract || ! $this->updater->covers($source)) {
            return null;
        }

        $at = $this->updater->lastUpdate($source, $item->locale());

        return $at === null ? null : [
            'at' => $at->toIso8601ZuluString(),
            'unpublished' => $item->revisionsEnabled() && $item->published() && $item->hasWorkingCopy(),
        ];
    }

    /**
     * The sites the item is the origin of, among those the user may access: an entry's direct localizations and, for
     * the root of the family, the sites it has no localization in yet (§A11 "Entries"); every other site of a term's
     * taxonomy for the term in its default site (§A11 "Terms").
     */
    private function origin(EntryContract|TermContract $item, User $user): ?array
    {
        $localizations = [];

        foreach ($this->originOf($item) as $site) {
            if (! $this->mayAccess($user, $site)) {
                continue;
            }

            $target = $this->target($item, $site);
            $status = $this->resolver->resolve($target);

            $localizations[] = [
                ...$this->site($site),
                'exists' => $target->exists(),
                'status' => $status->itemStatus->value,
                'counts' => $status->counts,
                'edit_url' => $target->editUrl(),
            ];
        }

        if ($localizations === []) {
            return null;
        }

        $autoUpdate = $this->autoUpdate($item, $user);

        return [
            'localizations' => $localizations,
            'auto_update' => $autoUpdate,
            'auto_update_sites' => $autoUpdate === null ? [] : $this->updater->sites($item, $user),
        ];
    }

    /**
     * @return list<string>
     */
    private function originOf(EntryContract|TermContract $item): array
    {
        if ($item instanceof EntryContract) {
            return $this->families->originSites($item);
        }

        return $item->isRoot() ? $this->sitesOf($item)->reject(fn (string $site) => $site === $item->locale())->values()->all() : [];
    }

    /**
     * When the settings update the entry's translations automatically (T11.1) as the user saves it: `save` when each
     * save of the entry does, `publish` when only its publishing does (with revisions, or while it is a draft), null
     * when nothing does.
     */
    private function autoUpdate(EntryContract|TermContract $item, User $user): ?string
    {
        if (! $item instanceof EntryContract || ! $this->updater->covers($item) || $this->updater->sites($item, $user) === []) {
            return null;
        }

        return $item->published() && ! $item->revisionsEnabled() ? 'save' : 'publish';
    }

    /**
     * Text-bearing top-level fields that are out of scope, with the reason (§A2).
     */
    private function outOfScope(Target $target): array
    {
        return TranslatableFields::textOutOfScope($target->explainFields());
    }

    /**
     * @param  ?array  $activeRun  the user's push from this item that is still running (EditorPush::payload())
     */
    private function payload(?string $reason, ?array $localization = null, ?array $origin = null, ?array $activeRun = null): array
    {
        return [
            'translatable' => $reason === null,
            'reason' => $reason,
            'localization' => $localization,
            'origin' => $origin,
            'active_run' => $activeRun,
            'defaults' => [
                'translator' => $this->translator(),
                'providers_configured' => $this->providers->configured() !== [],
                'fake_engine' => FakeEngine::enabled(),
                'review' => $this->settings->reviewEnabled(),
                'new_status' => $this->settings->newLocalizationStatus(),
            ],
            'offer' => $this->settings->offerOnCreate(),
        ];
    }

    private function translator(): ?string
    {
        try {
            return (string) $this->catalog->resolveTranslator($this->settings);
        } catch (NoProviderConfigured|MissingDefaultModel) {
            return null;
        }
    }

    /**
     * @return array{site: string, name: string}
     */
    private function site(string $handle): array
    {
        return ['site' => $handle, 'name' => (string) (Site::get($handle)?->name() ?? $handle)];
    }

    private function mayAccess(User $user, string $site): bool
    {
        $site = Site::get($site);

        return $site !== null && $user->can('view', $site);
    }
}
