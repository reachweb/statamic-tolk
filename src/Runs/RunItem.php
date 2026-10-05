<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;

/**
 * One target of a run (§A12). The RunRepository keeps the claim and updates the object it is given.
 */
final class RunItem
{
    use ReadsStoredValues;

    public const string DEPENDENCY_FAILED = 'dependency_failed';

    public const string UNAUTHORIZED = 'unauthorized';

    /**
     * Attempts at an item before it fails: a takeover after its claim expired, or a retry after its work threw.
     */
    public const int MAX_ATTEMPTS = 3;

    /**
     * @param  list<int>  $dependsOn  indexes of the items that must be done or skipped first
     * @param  ?string  $reason  why the item was skipped or failed, such as `dependency_failed`; on a pending item
     *                           `source_changed` when its origin changed before it was applied
     * @param  array<string, string>  $fields  in-scope handle → `applied`, `incomplete`, `no_corrections`, or why it was
     *                                         skipped or failed, as TargetResult::$fields
     * @param  array<string, list<array{id: string, before: string, after: string, reason: string}>>  $corrections  by handle
     * @param  bool  $created  whether applying the item created the localization
     */
    public function __construct(
        public int $index,
        public ContentType $type,
        public string $sourceId,
        public string $targetSite,
        public string $title = '',
        public int $stage = 0,
        public array $dependsOn = [],
        public RunItemStatus $status = RunItemStatus::Pending,
        public ?string $reason = null,
        public int $attempts = 0,
        public int $waits = 0,
        public ?string $planId = null,
        public ?Claim $claim = null,
        public ?CarbonImmutable $availableAt = null,
        public array $fields = [],
        public array $warnings = [],
        public array $corrections = [],
        public ?string $error = null,
        public UsageMap $usage = new UsageMap,
        public ?string $reference = null,
        public ?string $editUrl = null,
        public bool $created = false,
    ) {}

    /**
     * @return array{type: string, source_id: string, site: string} TargetFactory::fromDescription() input
     */
    public function describe(): array
    {
        return ['type' => $this->type->value, 'source_id' => $this->sourceId, 'site' => $this->targetSite];
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'type' => $this->type->value,
            'source_id' => $this->sourceId,
            'target_site' => $this->targetSite,
            'title' => $this->title,
            'stage' => $this->stage,
            'depends_on' => $this->dependsOn,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'attempts' => $this->attempts,
            'waits' => $this->waits,
            'plan_id' => $this->planId,
            'claim' => $this->claim?->toArray(),
            'available_at' => self::timeToString($this->availableAt),
            'fields' => $this->fields,
            'warnings' => $this->warnings,
            'corrections' => $this->corrections,
            'error' => $this->error,
            'usage' => $this->usage->toArray(),
            'reference' => $this->reference,
            'edit_url' => $this->editUrl,
            'created' => $this->created,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            index: (int) ($data['index'] ?? 0),
            type: ContentType::from((string) ($data['type'] ?? '')),
            sourceId: (string) ($data['source_id'] ?? ''),
            targetSite: (string) ($data['target_site'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            stage: (int) ($data['stage'] ?? 0),
            dependsOn: array_values(array_map(intval(...), self::storedArray($data, 'depends_on'))),
            status: RunItemStatus::from((string) ($data['status'] ?? '')),
            reason: self::storedString($data, 'reason'),
            attempts: (int) ($data['attempts'] ?? 0),
            waits: (int) ($data['waits'] ?? 0),
            planId: self::storedString($data, 'plan_id'),
            claim: Claim::fromArray($data['claim'] ?? null),
            availableAt: self::storedTime($data, 'available_at'),
            fields: self::storedArray($data, 'fields'),
            warnings: self::storedArray($data, 'warnings'),
            corrections: self::storedArray($data, 'corrections'),
            error: self::storedString($data, 'error'),
            usage: UsageMap::fromArray(self::storedArray($data, 'usage')),
            reference: self::storedString($data, 'reference'),
            editUrl: self::storedString($data, 'edit_url'),
            created: (bool) ($data['created'] ?? false),
        );
    }
}
