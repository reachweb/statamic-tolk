<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Str;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Stringable;

final readonly class TargetRef implements Stringable
{
    private const MAX_SEGMENT_LENGTH = 100;

    public function __construct(
        public ContentType $type,
        public string $id,
        public ?string $site = null,
    ) {
        if (! $this->isValid()) {
            throw InvalidTargetRef::for($this->__toString());
        }
    }

    public static function parse(string $reference): self
    {
        $parts = explode('::', $reference, 2);
        $type = ContentType::tryFrom($parts[0]);

        if ($type === null || count($parts) < 2) {
            throw InvalidTargetRef::for($reference);
        }

        [$id, $site] = self::hasSite($type) && str_contains($parts[1], '::')
            ? [Str::beforeLast($parts[1], '::'), Str::afterLast($parts[1], '::')]
            : [$parts[1], null];

        return new self($type, $id, $site);
    }

    public function storageKey(): string
    {
        $directory = match ($this->type) {
            ContentType::Entry => 'entries',
            ContentType::Term => 'terms',
            ContentType::GlobalSet => 'globals',
            ContentType::Nav => 'navs',
            ContentType::Lang => 'lang',
            ContentType::Forms => 'forms',
        };

        return implode('/', [$directory, ...array_map(self::safeSegment(...), $this->components())]);
    }

    public function __toString(): string
    {
        return $this->type->value.'::'.$this->id.($this->site === null ? '' : '::'.$this->site);
    }

    /**
     * An entry reference names the localization by its id alone, and a forms reference names only the language
     * (`forms::{lang}`); every other type ends with the site or language.
     */
    private static function hasSite(ContentType $type): bool
    {
        return ! in_array($type, [ContentType::Entry, ContentType::Forms], true);
    }

    private function components(): array
    {
        return self::hasSite($this->type) ? [...explode('::', $this->id), $this->site] : [$this->id];
    }

    private function isValid(): bool
    {
        if ($this->type === ContentType::Entry) {
            return $this->id !== '' && $this->site === null;
        }

        if (! self::hasSite($this->type) && $this->site !== null) {
            return false;
        }

        $components = $this->components();

        if (count($components) !== match ($this->type) {
            ContentType::Term => 3,
            ContentType::Forms => 1,
            default => 2,
        }) {
            return false;
        }

        // A component that starts or ends with a colon would merge with its `::` separator and not parse back.
        foreach ($components as $component) {
            if ($component === null || $component === '' || preg_match('/^:|::|:$/', $component)) {
                return false;
            }
        }

        return true;
    }

    private static function safeSegment(string $segment): string
    {
        $safe = substr(preg_replace('/[^a-z0-9_-]+/', '_', strtolower($segment)), 0, self::MAX_SEGMENT_LENGTH);

        // The hash follows a dot, which no unchanged segment contains, so a changed segment can never equal an unchanged one.
        return $safe === $segment ? $safe : $safe.'.'.substr(hash('sha256', $segment), 0, 8);
    }
}
