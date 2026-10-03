<?php

namespace Reach\StatamicTolk\Targets;

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

final class TargetFactory
{
    public function __construct(
        private readonly TranslatableFields $translatableFields,
        private readonly SourceSummary $summary,
        private readonly Settings $settings,
        private readonly ContentWalker $walker,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
    ) {}

    /**
     * The reference of an existing target; an entry reference must name a localization.
     */
    public function fromReference(string|TargetRef $reference): Target
    {
        $ref = $reference instanceof TargetRef ? $reference : TargetRef::parse($reference);

        if ($ref->type !== ContentType::Entry) {
            throw NotSupportedYet::contentType($ref->type);
        }

        $localization = Entry::find($ref->id) ?? throw TargetNotFound::entry($ref->id);
        $origin = $localization->origin() ?? throw NotALocalization::for((string) $ref);

        return $this->forEntry($origin, $localization->locale());
    }

    /**
     * $source is the entry to translate from when the localization does not exist yet: the root of the
     * family, or another localization of it. An existing localization is always translated from its origin.
     */
    public function forEntry(EntryContract|string $source, string $site): EntryTarget
    {
        $entry = $source instanceof EntryContract ? $source : (Entry::find($source) ?? throw TargetNotFound::entry($source));

        if (Site::get($site) === null) {
            throw UnknownSite::for($site);
        }

        if ($entry->locale() === $site) {
            throw InvalidTarget::sameSite((string) $entry->id(), $site);
        }

        if (! $entry->collection()->sites()->contains($site)) {
            throw InvalidTarget::siteNotInCollection($entry->collectionHandle(), $site);
        }

        return new EntryTarget((string) $entry->id(), $site, $this->translatableFields, $this->summary, $this->settings, $this->walker, $this->resolver, $this->stateWriter);
    }

    public function fromDescription(array $describe): Target
    {
        $type = ContentType::tryFrom((string) ($describe['type'] ?? ''));
        $sourceId = $describe['source_id'] ?? null;
        $site = $describe['site'] ?? null;

        if ($type === null || ! (is_string($sourceId) || is_int($sourceId)) || (string) $sourceId === '' || ! is_string($site) || $site === '') {
            throw InvalidTarget::description($describe);
        }

        return match ($type) {
            ContentType::Entry => $this->forEntry((string) $sourceId, $site),
            default => throw NotSupportedYet::contentType($type),
        };
    }
}
