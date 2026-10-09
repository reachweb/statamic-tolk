<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Targets\TargetRef;

/**
 * What TargetTranslator::translate() or review() did to one target.
 */
final readonly class TargetResult
{
    public const string APPLIED = 'applied';

    public const string INCOMPLETE = 'incomplete';

    public const string NOTHING_TO_DO = 'nothing_to_do';

    /**
     * A reviewed field the reviewer accepted, and a review that saved nothing for that reason.
     */
    public const string NO_CORRECTIONS = 'no_corrections';

    public const string TARGET_CHANGED = 'target_changed';

    public const string ROOT_MISSING = 'root_missing';

    public const string SOURCE_CHANGED = 'source_changed';

    public const string PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const string PROVIDER_REJECTED = 'provider_rejected';

    public const string FIELDS_FAILED = 'fields_failed';

    public const string WRITER_BUSY = 'writer_busy';

    public const string APPLY_FAILED = 'apply_failed';

    public const string NOT_SUPPORTED = 'not_supported';

    /**
     * @param  ?TargetRef  $ref  null while the target does not exist
     * @param  array<string, string>  $fields  in-scope handle → `applied`, `incomplete`, `no_corrections`, or why it was
     *                                         skipped or failed
     * @param  array<string, list<array{id: string, before: string, after: string, reason: string}>>  $corrections  by handle
     * @param  list<string>  $warnings
     * @param  ?string  $reason  why the target was skipped or failed
     * @param  ?string  $error  the message of a failure
     */
    public function __construct(
        public TargetResultStatus $status,
        public ?TargetRef $ref = null,
        public bool $created = false,
        public array $fields = [],
        public array $corrections = [],
        public array $warnings = [],
        public UsageMap $usage = new UsageMap,
        public ?string $reason = null,
        public ?string $error = null,
    ) {}

    public static function failed(string $reason, string $error, ?TargetRef $ref = null): self
    {
        return new self(TargetResultStatus::Failed, $ref, reason: $reason, error: $error);
    }

    public function withUsage(UsageMap $usage): self
    {
        return new self($this->status, $this->ref, $this->created, $this->fields, $this->corrections, $this->warnings, $usage, $this->reason, $this->error);
    }

    /**
     * @return list<string> the fields that were saved, complete or not
     */
    public function appliedFields(): array
    {
        return array_keys(array_filter($this->fields, fn (string $result) => in_array($result, [self::APPLIED, self::INCOMPLETE], true)));
    }
}
