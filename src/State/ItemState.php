<?php

namespace Reach\StatamicTolk\State;

use Reach\StatamicTolk\Targets\TargetRef;

final readonly class ItemState
{
    public const int VERSION = 1;

    /** @var array<string, FieldState> */
    public array $fields;

    /**
     * @param  array<string, FieldState>  $fields
     */
    public function __construct(
        public TargetRef $reference,
        public ?string $sourceSite = null,
        public int $version = self::VERSION,
        array $fields = [],
    ) {
        $this->fields = array_filter($fields, fn (FieldState $field) => ! $field->isEmpty());
    }

    public static function empty(TargetRef $reference): self
    {
        return new self($reference);
    }

    /**
     * A record without a `v` is not of the current version, so its translations are not reused.
     */
    public static function fromArray(array $data): self
    {
        $fields = [];

        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $handle => $field) {
            if (is_array($field)) {
                $fields[(string) $handle] = FieldState::fromArray($field);
            }
        }

        return new self(
            reference: TargetRef::parse(is_string($data['reference'] ?? null) ? $data['reference'] : ''),
            sourceSite: is_string($data['source_site'] ?? null) && $data['source_site'] !== '' ? $data['source_site'] : null,
            version: is_int($data['v'] ?? null) ? $data['v'] : 0,
            fields: $fields,
        );
    }

    public function field(string $handle): FieldState
    {
        return $this->fields[$handle] ?? new FieldState;
    }

    public function withField(string $handle, FieldState $field): self
    {
        return new self($this->reference, $this->sourceSite, $this->version, [...$this->fields, $handle => $field]);
    }

    public function withoutField(string $handle): self
    {
        $fields = $this->fields;
        unset($fields[$handle]);

        return new self($this->reference, $this->sourceSite, $this->version, $fields);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function toArray(): array
    {
        return array_filter([
            'reference' => (string) $this->reference,
            'source_site' => $this->sourceSite,
            'v' => $this->version,
            'fields' => array_map(fn (FieldState $field) => $field->toArray(), $this->fields),
        ], fn (mixed $value) => $value !== null);
    }
}
