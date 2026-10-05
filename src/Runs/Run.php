<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;
use Statamic\Contracts\Auth\User;

/**
 * A bulk job (§A12). Several processes change the same run, so the RunRepository saves only the values changed since
 * the run was read, and refreshes the object from what is stored whenever it writes.
 */
final class Run
{
    use ReadsStoredValues;

    /**
     * The run as it was last read or written; empty until it is stored.
     */
    private array $original = [];

    /**
     * @param  ?array{id: string, name: string}  $createdBy  null for the CLI
     * @param  ?CarbonImmutable  $progressedAt  the last time an item changed, a claim was extended or usage was added
     * @param  ?array  $estimate  null until the run has been estimated
     */
    public function __construct(
        public string $id,
        public RunDriver $driver,
        public RunStatus $status = RunStatus::Pending,
        public ?array $createdBy = null,
        public CarbonImmutable $createdAt = new CarbonImmutable,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $finishedAt = null,
        public ?CarbonImmutable $finalizedAt = null,
        public ?CarbonImmutable $progressedAt = null,
        public array $options = [],
        public array $scope = [],
        public UsageMap $usage = new UsageMap,
        public ?array $estimate = null,
        public int $generation = 0,
        public ?string $error = null,
        public array $warnings = [],
    ) {}

    /**
     * A new pending run. The creator is captured now, because a queue worker has no current user (R1 §5.2).
     */
    public static function make(RunDriver $driver, array $scope = [], array $options = [], ?User $user = null): self
    {
        return new self(
            id: (string) Str::ulid(),
            driver: $driver,
            createdBy: $user ? ['id' => (string) $user->id(), 'name' => (string) ($user->name() ?? $user->email())] : null,
            createdAt: CarbonImmutable::now(),
            options: $options,
            scope: $scope,
        );
    }

    public function lastActivityAt(): CarbonImmutable
    {
        return collect([$this->createdAt, $this->startedAt, $this->progressedAt, $this->finishedAt, $this->finalizedAt])
            ->filter()
            ->max();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'driver' => $this->driver->value,
            'created_by' => $this->createdBy,
            'created_at' => self::timeToString($this->createdAt),
            'started_at' => self::timeToString($this->startedAt),
            'finished_at' => self::timeToString($this->finishedAt),
            'finalized_at' => self::timeToString($this->finalizedAt),
            'progressed_at' => self::timeToString($this->progressedAt),
            'options' => $this->options,
            'scope' => $this->scope,
            'usage' => $this->usage->toArray(),
            'estimate' => $this->estimate,
            'generation' => $this->generation,
            'error' => $this->error,
            'warnings' => $this->warnings,
        ];
    }

    public static function fromArray(array $data): self
    {
        $createdBy = self::storedArray($data, 'created_by');

        $run = new self(
            id: (string) ($data['id'] ?? ''),
            driver: RunDriver::from((string) ($data['driver'] ?? '')),
            status: RunStatus::from((string) ($data['status'] ?? '')),
            createdBy: isset($createdBy['id']) ? ['id' => (string) $createdBy['id'], 'name' => (string) ($createdBy['name'] ?? '')] : null,
            createdAt: self::storedTime($data, 'created_at') ?? CarbonImmutable::now(),
            startedAt: self::storedTime($data, 'started_at'),
            finishedAt: self::storedTime($data, 'finished_at'),
            finalizedAt: self::storedTime($data, 'finalized_at'),
            progressedAt: self::storedTime($data, 'progressed_at'),
            options: self::storedArray($data, 'options'),
            scope: self::storedArray($data, 'scope'),
            usage: UsageMap::fromArray(self::storedArray($data, 'usage')),
            estimate: is_array($data['estimate'] ?? null) ? $data['estimate'] : null,
            generation: (int) ($data['generation'] ?? 0),
            error: self::storedString($data, 'error'),
            warnings: self::storedArray($data, 'warnings'),
        );

        $run->original = $run->toArray();

        return $run;
    }

    /**
     * @internal The values changed since the run was read or written, as in toArray(); every value of a new run.
     */
    public function changes(): array
    {
        return array_filter(
            $this->toArray(),
            fn (mixed $value, string $key) => ! array_key_exists($key, $this->original) || $this->original[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @internal Takes over the run as it is stored, keeping the changes that are not saved yet.
     */
    public function refresh(self $stored): void
    {
        $merged = self::fromArray([...$stored->original, ...$this->changes()]);

        foreach (get_object_vars($merged) as $property => $value) {
            $this->{$property} = $value;
        }

        $this->original = $stored->original;
    }
}
