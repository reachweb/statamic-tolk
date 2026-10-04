<?php

namespace Reach\StatamicTolk\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The status payload of the editor banner for the item open in the publish form: what it holds as a localization and,
 * when it is the origin of other sites, what they hold.
 */
final readonly class EditorStatus
{
    public const string SINGLE_SITE = 'single_site';

    public const string UNKNOWN_REFERENCE = 'unknown_reference';

    public const string NO_ACCESS = 'no_access';

    // Out-of-scope reasons that say nothing about text: a fieldtype without a handler, and the slug, never translated (D16).
    private const array NOT_TEXT = ['unsupported_fieldtype', 'slug'];

    public function __construct(
        private TargetFactory $targets,
        private StatusResolver $resolver,
        private ModelCatalog $catalog,
        private Providers $providers,
        private Settings $settings,
    ) {}

    /**
     * @throws AuthorizationException when the user may not edit the item
     */
    public function for(string $reference, User $user): array
    {
        $entry = $this->entry($reference);

        if ($entry === null) {
            return $this->payload(self::UNKNOWN_REFERENCE);
        }

        $target = $entry->origin() === null ? null : $this->targets->forEntry($entry->origin(), $entry->locale());

        if (! ($target?->authorize($user) ?? ($this->mayAccess($user, $entry->locale()) && $user->can('edit', $entry)))) {
            throw new AuthorizationException;
        }

        if ($entry->collection()->sites()->count() < 2) {
            return $this->payload(self::SINGLE_SITE);
        }

        $localization = $target === null ? null : $this->localization($target, $entry->origin());
        $origin = $this->origin($entry, $user);

        return $localization === null && $origin === null
            ? $this->payload(self::NO_ACCESS)
            : $this->payload(null, $localization, $origin);
    }

    private function entry(string $reference): ?EntryContract
    {
        try {
            $ref = TargetRef::parse($reference);
        } catch (InvalidTargetRef) {
            return null;
        }

        return $ref->type === ContentType::Entry ? Entry::find($ref->id) : null;
    }

    private function localization(Target $target, EntryContract $source): array
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
        ];
    }

    /**
     * The sites the item is the origin of, among those the user may access: its direct localizations and, for the root of
     * the family, the sites it has no localization in yet (§A11 "Entries").
     */
    private function origin(EntryContract $entry, User $user): ?array
    {
        $children = $entry->directDescendants();
        $family = $entry->isRoot() ? $entry->descendants() : null;
        $localizations = [];

        foreach ($entry->collection()->sites() as $site) {
            if ($site === $entry->locale() || ! $this->mayAccess($user, $site)) {
                continue;
            }

            if (! $children->has($site) && ($family === null || $family->has($site))) {
                continue;
            }

            $target = $this->targets->forEntry($entry, $site);
            $status = $this->resolver->resolve($target);

            $localizations[] = [
                ...$this->site($site),
                'exists' => $target->exists(),
                'status' => $status->itemStatus->value,
                'counts' => $status->counts,
                'edit_url' => $target->editUrl(),
            ];
        }

        return $localizations === [] ? null : ['localizations' => $localizations];
    }

    /**
     * Text-bearing top-level fields that are out of scope, with the reason (§A2).
     */
    private function outOfScope(Target $target): array
    {
        $fields = array_filter($target->explainFields(), fn (array $field) => ! $field['in_scope'] && ! in_array($field['reason'], self::NOT_TEXT, true));

        return array_values(array_map(fn (array $field) => [
            'handle' => $field['handle'],
            'label' => (string) $field['display'],
            'type' => $field['type'],
            'reason' => $field['reason'],
        ], $fields));
    }

    private function payload(?string $reason, ?array $localization = null, ?array $origin = null): array
    {
        return [
            'translatable' => $reason === null,
            'reason' => $reason,
            'localization' => $localization,
            'origin' => $origin,
            'defaults' => [
                'translator' => $this->translator(),
                'providers_configured' => $this->providers->configured() !== [],
                'fake_engine' => FakeEngine::enabled(),
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
