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
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

final class TargetFactory
{
    public function __construct(
        private readonly TranslatableFields $translatableFields,
        private readonly SourceSummary $summary,
        private readonly Settings $settings,
        private readonly ContentWalker $walker,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
        private readonly LangFiles $langFiles,
    ) {}

    /**
     * The reference of an existing target; an entry, term or globals reference must name a localization, a nav
     * reference a site other than the default site, whose tree is the source, and a lang reference a language other
     * than the default site's, whose file is the source.
     */
    public function fromReference(string|TargetRef $reference): Target
    {
        $ref = $reference instanceof TargetRef ? $reference : TargetRef::parse($reference);

        return match ($ref->type) {
            ContentType::Entry => $this->entryFromReference($ref),
            ContentType::Term => $this->termFromReference($ref),
            ContentType::GlobalSet => $this->globalsFromReference($ref),
            ContentType::Nav => $this->navFromReference($ref),
            ContentType::Lang => $this->langFromReference($ref),
            default => throw NotSupportedYet::contentType($ref->type),
        };
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

    /**
     * $term is any site of the term, or its id; the source is always the term in its taxonomy's default site.
     */
    public function forTerm(TermContract|string $term, string $site): TermTarget
    {
        $source = $term instanceof TermContract ? $term->inDefaultLocale() : (Term::find($term) ?? throw TargetNotFound::term($term));

        if (Site::get($site) === null) {
            throw UnknownSite::for($site);
        }

        if ($source->locale() === $site) {
            throw InvalidTarget::defaultSite((string) $source->id(), $site);
        }

        if (! $source->taxonomy()->sites()->contains($site)) {
            throw InvalidTarget::siteNotInTaxonomy($source->taxonomyHandle(), $site);
        }

        return new TermTarget((string) $source->id(), $site, $this->translatableFields, $this->summary, $this->settings, $this->resolver, $this->stateWriter, $source);
    }

    /**
     * $set is the set or its handle. A site the set does not have or has no origin for gives a target that is not
     * translatable (GlobalsTarget::untranslatableReason()), so callers can report it.
     */
    public function forGlobalSet(GlobalSetContract|string $set, string $site): GlobalsTarget
    {
        $set = $set instanceof GlobalSetContract ? $set : (GlobalSet::findByHandle($set) ?? throw TargetNotFound::globalSet($set));

        if (Site::get($site) === null) {
            throw UnknownSite::for($site);
        }

        return new GlobalsTarget($set->handle(), $site, $this->translatableFields, $this->summary, $this->settings, $this->resolver, $this->stateWriter, $set);
    }

    /**
     * $nav is the navigation or its handle. Its tree in $sourceSite, by default the default site's, is the source; a
     * source site without a tree gives a target with nothing to translate.
     */
    public function forNav(NavContract|string $nav, string $site, ?string $sourceSite = null): NavTarget
    {
        $nav = $nav instanceof NavContract ? $nav : (Nav::findByHandle($nav) ?? throw TargetNotFound::nav($nav));
        $sourceSite ??= Site::default()->handle();

        foreach ([$site, $sourceSite] as $handle) {
            if (Site::get($handle) === null) {
                throw UnknownSite::for($handle);
            }
        }

        if ($site === $sourceSite) {
            throw InvalidTarget::navSource($nav->handle(), $site);
        }

        return new NavTarget($nav->handle(), $site, $sourceSite, $this->translatableFields, $this->summary, $this->resolver, $this->stateWriter, $nav);
    }

    /**
     * The translation file $group in $lang, translated from the same group in $sourceLang. Prompts use the language of
     * the first site with each `lang()`, the default site first for the source (§A16).
     *
     * @throws InvalidTarget when the languages are the same or no site uses one of them
     * @throws TargetNotFound when the source file does not exist
     */
    public function forLang(string $group, string $lang, string $sourceLang): LangTarget
    {
        if ($lang === $sourceLang) {
            throw InvalidTarget::langSource($lang);
        }

        $site = $this->siteWithLang($lang) ?? throw InvalidTarget::langWithoutSite($lang);
        $sourceSite = $this->siteWithLang($sourceLang) ?? throw InvalidTarget::langWithoutSite($sourceLang);

        if (! $this->langFiles->exists($sourceLang, $group)) {
            throw TargetNotFound::lang(LangFiles::name($sourceLang, $group));
        }

        return new LangTarget($group, $lang, $sourceLang, $site, $sourceSite, $this->langFiles, $this->resolver, $this->stateWriter);
    }

    /**
     * The term of a term reference in the site it names; null when there is no such term or its taxonomy lacks the site.
     */
    public function findTerm(TargetRef $ref): ?TermContract
    {
        $term = Term::find($ref->id);

        return $term !== null && $term->taxonomy()->sites()->contains($ref->site) ? $term->in($ref->site) : null;
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
            ContentType::Term => $this->forTerm((string) $sourceId, $site),
            ContentType::GlobalSet => $this->forGlobalSet((string) $sourceId, $site),
            ContentType::Nav => $this->navFromDescription((string) $sourceId, $site),
            ContentType::Lang => $this->langFromDescription((string) $sourceId, $site),
            default => throw NotSupportedYet::contentType($type),
        };
    }

    private function entryFromReference(TargetRef $ref): EntryTarget
    {
        $localization = Entry::find($ref->id) ?? throw TargetNotFound::entry($ref->id);
        $origin = $localization->origin() ?? throw NotALocalization::for((string) $ref);

        return $this->forEntry($origin, $localization->locale());
    }

    // A term reference names the site; the default site's term is the origin of the others.
    private function termFromReference(TargetRef $ref): TermTarget
    {
        $term = $this->findTerm($ref) ?? throw TargetNotFound::term($ref->id, $ref->site);

        if (! $term->hasOrigin()) {
            throw NotALocalization::for((string) $ref);
        }

        return $this->forTerm($term, $ref->site);
    }

    // As an entry or term reference names a localization, a globals reference names the variables of a site with an origin.
    private function globalsFromReference(TargetRef $ref): GlobalsTarget
    {
        $set = GlobalSet::findByHandle($ref->id);

        if ($set === null || ! $set->sites()->contains($ref->site)) {
            throw TargetNotFound::globalSet($ref->id, $ref->site);
        }

        if (GlobalsTarget::untranslatableReasonFor($set, $ref->site) === GlobalsTarget::NO_ORIGIN) {
            throw NotALocalization::for((string) $ref);
        }

        return $this->forGlobalSet($set, $ref->site);
    }

    // A nav reference names a site with a tree, translated from the default site's tree.
    private function navFromReference(TargetRef $ref): NavTarget
    {
        $nav = Nav::findByHandle($ref->id) ?? throw TargetNotFound::nav($ref->id);

        if (! $nav->existsIn($ref->site)) {
            throw TargetNotFound::nav($ref->id, $ref->site);
        }

        if ($ref->site === Site::default()->handle()) {
            throw NotALocalization::for((string) $ref);
        }

        return $this->forNav($nav, $ref->site);
    }

    // A nav's source id is `{handle}::{source site}` (NavTarget::describe()); a handle alone names the default site's tree.
    private function navFromDescription(string $sourceId, string $site): NavTarget
    {
        [$handle, $sourceSite] = str_contains($sourceId, '::') ? explode('::', $sourceId, 2) : [$sourceId, null];

        return $this->forNav($handle, $site, $sourceSite);
    }

    // A lang reference names the target language; the source is the default site's language.
    private function langFromReference(TargetRef $ref): LangTarget
    {
        $sourceLang = (string) Site::default()->lang();

        if ($ref->site === $sourceLang) {
            throw NotALocalization::for((string) $ref);
        }

        return $this->forLang($ref->id, $ref->site, $sourceLang);
    }

    // A translation file's source id is `{group}::{source language}`, and its site the target language (LangTarget::describe()).
    private function langFromDescription(string $sourceId, string $lang): LangTarget
    {
        [$group, $sourceLang] = str_contains($sourceId, '::') ? explode('::', $sourceId, 2) : [$sourceId, (string) Site::default()->lang()];

        return $this->forLang($group, $lang, $sourceLang);
    }

    private function siteWithLang(string $lang): ?string
    {
        $default = Site::default();

        if ($default->lang() === $lang) {
            return $default->handle();
        }

        return Site::all()->first(fn ($site) => $site->lang() === $lang)?->handle();
    }
}
