<?php

namespace Reach\StatamicTolk\Ai;

/**
 * Usage per requested model, keyed by the ModelRef string. Never keyed by the model a response reports, which can be a
 * dated snapshot of the alias that was requested (T2.1).
 */
final readonly class UsageMap
{
    /**
     * @param  array<string, Usage>  $usages
     */
    public function __construct(public array $usages = []) {}

    public function add(ModelRef $model, Usage $usage): self
    {
        return $this->merge(new self([(string) $model => $usage]));
    }

    public function merge(self $map): self
    {
        $usages = $this->usages;

        foreach ($map->usages as $model => $usage) {
            $usages[$model] = ($usages[$model] ?? new Usage)->add($usage);
        }

        return new self($usages);
    }

    public function get(ModelRef $model): Usage
    {
        return $this->usages[(string) $model] ?? new Usage;
    }

    public function isEmpty(): bool
    {
        return $this->usages === [];
    }

    /**
     * @return array<string, array>
     */
    public function toArray(): array
    {
        return array_map(fn (Usage $usage) => $usage->toArray(), $this->usages);
    }

    public static function fromArray(array $data): self
    {
        $usages = [];

        foreach ($data as $model => $usage) {
            if (is_string($model) && is_array($usage)) {
                $usages[$model] = Usage::fromArray($usage);
            }
        }

        return new self($usages);
    }
}
