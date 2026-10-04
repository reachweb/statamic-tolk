<?php

namespace Reach\StatamicTolk\Contracts;

use Illuminate\Support\Collection;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Contracts\Auth\User;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

interface Target
{
    /**
     * Null while the target does not exist yet.
     */
    public function ref(): ?TargetRef;

    public function type(): ContentType;

    public function exists(): bool;

    public function sourceSite(): string;

    public function targetSite(): string;

    /**
     * The handle of the collection, taxonomy, global set or navigation.
     */
    public function container(): string;

    /**
     * The source's title, which is known whether or not the target exists.
     */
    public function title(): string;

    public function editUrl(): ?string;

    /**
     * Enough to rebuild the target with TargetFactory::fromDescription().
     *
     * @return array{type: string, source_id: string, site: string}
     */
    public function describe(): array;

    /**
     * The in-scope top-level fields (§A2), keyed by handle in blueprint order.
     *
     * @return Collection<string, Field>
     */
    public function fields(): Collection;

    /**
     * TranslatableFields::explain() of the blueprint and context that fields() uses.
     *
     * @return list<array{handle: string, display: mixed, type: string, in_scope: bool, reason: string}>
     */
    public function explainFields(): array;

    /**
     * Whether the source holds the field in its own data, rather than inheriting it.
     */
    public function sourceHasValue(string $handle): bool;

    /**
     * The source's own raw value; null for a field it inherits.
     */
    public function sourceValue(string $handle): mixed;

    public function sourceIsLocalization(): bool;

    /**
     * Whether the target holds the field in its own data, explicit nulls included.
     */
    public function hasOverride(string $handle): bool;

    /**
     * The target's own raw value; null for a field it inherits.
     */
    public function targetValue(string $handle): mixed;

    /**
     * The raw value the publish form shows for a field the target does not override, read as the CP's edit page reads it:
     * the source's own value, else what the source inherits; null where the form shows the field's default.
     */
    public function inheritedValue(string $handle): mixed;

    /**
     * The fields of the target's blueprint holding the given raw values, as the CP's publish form builds them: with the
     * target as parent (its working copy when revisions are on), or with $source the source, whose values the fields the
     * target inherits show.
     */
    public function formFields(array $values, bool $source = false): Fields;

    /**
     * Context for prompts (§A8).
     *
     * @return array{type: string, title: string, container: string, url: ?string, summary: string}
     */
    public function document(): array;

    public function authorize(User $user): bool;

    /**
     * The same target, with everything resolved again from new queries.
     */
    public function fresh(): static;

    /**
     * Saves assembled fields into the target (§A11). Only inside ContentWriter::write().
     */
    public function apply(array $fields, ApplyOptions $options): ApplyResult;
}
