<?php

namespace Reach\StatamicTolk\Translation;

use Illuminate\Support\Arr;
use Reach\StatamicTolk\Enums\UnitKind;

/**
 * What one target needs (§A8). Built by the Planner and never modified afterwards.
 */
final readonly class Plan
{
    /**
     * @param  UnitKind  $kind  the kind of the plan's initial units
     * @param  ?string  $reference  the target's reference; null while the target does not exist
     * @param  array{type: string, source_id: string, site: string}  $target
     * @param  array{type: string, title: string, container: string, url: ?string, summary: string}  $document
     * @param  array<string, array{source_hash: string, target_before: ?string, leaf_count: int, status_before: string, segment_ids: list<string>, reused: int, clear: bool}>  $fields
     * @param  array<string, array{field: string, index: int, kind: string, text: string, label: string, hint: ?string, max_length: ?int, before: ?string, after: ?string, translation: ?string}>  $segments
     * @param  list<array{id: string, segment_ids: list<string>, chars: int}>  $batches
     * @param  array<string, string>  $skipped  in-scope fields that are not planned, with the reason
     */
    public function __construct(
        public string $id,
        public UnitKind $kind,
        public ?string $reference,
        public array $target,
        public string $sourceSite,
        public string $targetSite,
        public PlanOptions $options,
        public array $document,
        public array $fields,
        public array $segments,
        public array $batches,
        public array $skipped,
    ) {}

    /**
     * Whether applying the plan would change nothing: no batch to run, no reused translation to assemble, no field to clear.
     */
    public function isEmpty(): bool
    {
        return $this->batches === []
            && ! collect($this->fields)->contains(fn (array $field) => $field['reused'] > 0 || $field['clear']);
    }

    /**
     * @return list<string>
     */
    public function fieldHandles(): array
    {
        return array_keys($this->fields);
    }

    /**
     * @return array<string, array> the field's segments by id, in the order of its walk
     */
    public function segmentsOfField(string $handle): array
    {
        return collect($this->fields[$handle]['segment_ids'] ?? [])
            ->mapWithKeys(fn (string $id) => [$id => $this->segments[$id]])
            ->all();
    }

    /**
     * @return ?array{id: string, segment_ids: list<string>, chars: int}
     */
    public function batch(string $id): ?array
    {
        return Arr::first($this->batches, fn (array $batch) => $batch['id'] === $id);
    }

    public function toArray(): array
    {
        $options = $this->options->toArray();
        $userId = Arr::pull($options, 'user_id');

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'reference' => $this->reference,
            'target' => $this->target,
            'user_id' => $userId,
            'source_site' => $this->sourceSite,
            'target_site' => $this->targetSite,
            'options' => $options,
            'document' => $this->document,
            'fields' => $this->fields,
            'segments' => $this->segments,
            'batches' => $this->batches,
            'skipped' => $this->skipped,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            kind: UnitKind::from($data['kind']),
            reference: $data['reference'] ?? null,
            target: $data['target'],
            sourceSite: $data['source_site'],
            targetSite: $data['target_site'],
            options: PlanOptions::fromArray([...$data['options'], 'user_id' => $data['user_id'] ?? null]),
            document: $data['document'],
            fields: $data['fields'] ?? [],
            segments: $data['segments'] ?? [],
            batches: $data['batches'] ?? [],
            skipped: $data['skipped'] ?? [],
        );
    }
}
