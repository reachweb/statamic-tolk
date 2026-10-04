<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Ai\UsageMap;

/**
 * What one unit produced (§A8 step 2). Stored once under the unit's own cache key and never changed.
 */
final readonly class UnitOutcome
{
    public const string INVALID = 'invalid';

    public const string MISSING = 'missing';

    public const string TOO_LONG = 'too_long';

    public const string REFUSED = 'refused';

    public const string REQUEST_REJECTED = 'request_rejected';

    /**
     * Why a review was skipped after `retry.max_attempts` transient failures.
     */
    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param  string  $unit  the unit's id
     * @param  ?string  $model  the model that was called; null when the unit was split after a timeout, and when a review
     *                          was skipped
     * @param  array<string, string>  $translations  segment id → valid translation
     * @param  list<array{id: string, before: string, after: string, reason: string}>  $corrections  a review's accepted corrections
     * @param  array<string, string>  $failed  segment id → why it failed for good
     * @param  array<string, list<string>>  $warnings  segment id → warnings
     * @param  list<Unit>  $spawned
     */
    public function __construct(
        public string $unit,
        public ?string $model = null,
        public array $translations = [],
        public array $corrections = [],
        public array $failed = [],
        public array $warnings = [],
        public UsageMap $usage = new UsageMap,
        public array $spawned = [],
    ) {}

    public function toArray(): array
    {
        return [
            'unit' => $this->unit,
            'model' => $this->model,
            'translations' => $this->translations,
            'corrections' => $this->corrections,
            'failed' => $this->failed,
            'warnings' => $this->warnings,
            'usage' => $this->usage->toArray(),
            'spawned' => array_map(fn (Unit $unit) => $unit->toArray(), $this->spawned),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            unit: (string) $data['unit'],
            model: $data['model'] ?? null,
            translations: $data['translations'] ?? [],
            corrections: $data['corrections'] ?? [],
            failed: $data['failed'] ?? [],
            warnings: $data['warnings'] ?? [],
            usage: UsageMap::fromArray($data['usage'] ?? []),
            spawned: array_map(fn (array $unit) => Unit::fromArray($unit), $data['spawned'] ?? []),
        );
    }
}
