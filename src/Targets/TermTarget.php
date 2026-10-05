<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use LogicException;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\Concerns\AppliesFields;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Support\Arr;

/**
 * A taxonomy term in one site other than its taxonomy's default site, translated from the term in that default site
 * (§A11 "Terms"). Build it with TargetFactory, which validates the term and the site.
 */
final class TermTarget implements Target
{
    use AppliesFields;

    private ?TermContract $term = null;

    private ?TermContract $localized = null;

    private ?Collection $fields = null;

    /**
     * @param  string  $termId  `{taxonomy}::{default slug}`
     */
    public function __construct(
        private readonly string $termId,
        private readonly string $site,
        private readonly TranslatableFields $translatableFields,
        private readonly SourceSummary $summary,
        private readonly Settings $settings,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
    ) {}

    public function ref(): TargetRef
    {
        return new TargetRef(ContentType::Term, $this->termId, $this->site);
    }

    public function type(): ContentType
    {
        return ContentType::Term;
    }

    // Every site of a taxonomy has its localization of every term (R4 §3.1).
    public function exists(): bool
    {
        return true;
    }

    public function sourceSite(): string
    {
        return $this->source()->locale();
    }

    public function targetSite(): string
    {
        return $this->site;
    }

    public function container(): string
    {
        return $this->source()->taxonomyHandle();
    }

    public function title(): string
    {
        $source = $this->source();
        $title = $source->value('title');

        return is_string($title) && trim($title) !== '' ? $title : (string) $source->slug();
    }

    public function editUrl(): ?string
    {
        return $this->localized()->editUrl();
    }

    public function describe(): array
    {
        return ['type' => ContentType::Term->value, 'source_id' => $this->termId, 'site' => $this->site];
    }

    public function fields(): Collection
    {
        return $this->fields ??= $this->translatableFields->for($this->blueprint(), $this->fieldContext());
    }

    public function explainFields(): array
    {
        return $this->translatableFields->explain($this->blueprint(), $this->fieldContext());
    }

    // LocalizedTerm::has() is a "not null" check (R4 §3.2), so the data is asked directly, explicit nulls included.
    public function sourceHasValue(string $handle): bool
    {
        return $this->source()->data()->has($handle);
    }

    public function sourceValue(string $handle): mixed
    {
        return $this->source()->data()->get($handle);
    }

    public function sourceIsLocalization(): bool
    {
        return false;
    }

    public function hasOverride(string $handle): bool
    {
        return $this->localized()->data()->has($handle);
    }

    public function targetValue(string $handle): mixed
    {
        return $this->localized()->data()->get($handle);
    }

    // The default site's data without its null, '' and [] values, as core's term edit page reads it.
    public function inheritedValue(string $handle): mixed
    {
        return Arr::removeNullValues([$handle => $this->source()->data()->get($handle)])[$handle] ?? null;
    }

    public function formFields(array $values, bool $source = false): Fields
    {
        // Every site of a term shares one blueprint object, so the parent is set right before fields().
        return $this->localized()->blueprint()
            ->setParent($source ? $this->source() : $this->localized())
            ->fields()
            ->addValues($values);
    }

    public function document(): array
    {
        $source = $this->source();

        return [
            'type' => ContentType::Term->value,
            'title' => $this->title(),
            'container' => (string) $source->taxonomy()->title(),
            'url' => $source->url(),
            'summary' => $this->summary->of($this),
        ];
    }

    // Core's LocalizedTermPolicy checks the site too; the check here keeps §A14's rule whatever policy applies.
    public function authorize(User $user): bool
    {
        $site = Site::get($this->site);

        return $site !== null
            && $user->can('view', $site)
            && $user->can('edit', $this->localized());
    }

    public function fresh(): static
    {
        return new self($this->termId, $this->site, $this->translatableFields, $this->summary, $this->settings, $this->resolver, $this->stateWriter);
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    public function apply(array $fields, ApplyOptions $options): ApplyResult
    {
        if (! ContentWriter::active()) {
            throw new LogicException('Translations are applied only inside ContentWriter::write().');
        }

        return $this->fresh()->applyFresh($fields, $options);
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        $this->stateWriter->confirm($this);
        [$writes, $skipped] = $this->writable($this->keyByHandle($fields), $options);
        $localized = $this->localized();
        $ref = $this->ref();
        $values = $this->prepareWrites($localized, $ref, $writes);

        if ($writes !== []) {
            $removed = $this->removedHandles($writes);

            // A LocalizedTerm has no remove(): the site's data is set again without those keys, everything else kept.
            if ($removed !== []) {
                $localized->data($localized->data()->except($removed));
            }

            $localized->merge($values);
            $this->save($localized, $options);
            $this->confirmWrites($ref, $writes);
        }

        return new ApplyResult(ref: $ref, applied: array_keys($writes), skipped: $skipped);
    }

    private function save(TermContract $localized, ApplyOptions $options): void
    {
        // Without a user, updateLastModified() throws on a LocalizedTerm (R4 §16.2 item 19).
        if ($options->user !== null) {
            $localized->updateLastModified($options->user);
        }

        if ($localized->save() === false) {
            throw ApplyFailed::vetoed((string) $this->ref());
        }
    }

    // Term::find() returns the term in its taxonomy's default site.
    private function source(): TermContract
    {
        return $this->term ??= Term::find($this->termId) ?? throw TargetNotFound::term($this->termId);
    }

    private function localized(): TermContract
    {
        return $this->localized ??= $this->source()->in($this->site);
    }

    private function blueprint(): Blueprint
    {
        return $this->localized()->blueprint()->setParent($this->localized());
    }

    // Terms have no title format, so a term title is never generated.
    private function fieldContext(): array
    {
        return [
            'auto_title' => false,
            'excluded_fields' => $this->settings->excludedFields(),
        ];
    }
}
