<?php

namespace Reach\StatamicTolk\State;

use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;
use Reach\StatamicTolk\Targets\TargetRef;

final readonly class ItemState
{
    use ReadsStoredValues;

    public const int VERSION = 1;

    /** @var array<string, FieldState> */
    public array $fields;

    /**
     * @param  array<string, FieldState>  $fields
     * @param  ?string  $collection  an entry's collection, and $site its site, which its reference does not name (§A5)
     */
    public function __construct(
        public TargetRef $reference,
        public ?string $sourceSite = null,
        public int $version = self::VERSION,
        array $fields = [],
        public ?string $collection = null,
        public ?string $site = null,
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

        foreach (self::storedArray($data, 'fields') as $handle => $field) {
            if (is_array($field)) {
                $fields[(string) $handle] = FieldState::fromArray($field);
            }
        }

        return new self(
            reference: TargetRef::parse(self::storedString($data, 'reference') ?? ''),
            sourceSite: self::storedString($data, 'source_site'),
            version: is_int($data['v'] ?? null) ? $data['v'] : 0,
            fields: $fields,
            collection: self::storedString($data, 'collection'),
            site: self::storedString($data, 'site'),
        );
    }

    public function field(string $handle): FieldState
    {
        return $this->fields[$handle] ?? new FieldState;
    }

    public function withField(string $handle, FieldState $field): self
    {
        // Not a spread, which would renumber a handle that reads as an integer, such as a translation file's key `404`.
        $fields = $this->fields;
        $fields[$handle] = $field;

        return new self($this->reference, $this->sourceSite, $this->version, $fields, $this->collection, $this->site);
    }

    public function withoutField(string $handle): self
    {
        $fields = $this->fields;
        unset($fields[$handle]);

        return new self($this->reference, $this->sourceSite, $this->version, $fields, $this->collection, $this->site);
    }

    public function withReference(TargetRef $reference): self
    {
        return new self($reference, $this->sourceSite, $this->version, $this->fields, $this->collection, $this->site);
    }

    public function withSourceSite(string $sourceSite): self
    {
        return new self($this->reference, $sourceSite, $this->version, $this->fields, $this->collection, $this->site);
    }

    public function withCollection(string $collection, string $site): self
    {
        return new self($this->reference, $this->sourceSite, $this->version, $this->fields, $collection, $site);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function hasPending(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->pending !== []) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return array_filter([
            'reference' => (string) $this->reference,
            'source_site' => $this->sourceSite,
            'collection' => $this->collection,
            'site' => $this->site,
            'v' => $this->version,
            'fields' => array_map(fn (FieldState $field) => $field->toArray(), $this->fields),
        ], fn (mixed $value) => $value !== null);
    }
}
