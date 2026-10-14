<?php

namespace Reach\StatamicTolk\Content;

use ArrayObject;

final class WalkContext
{
    private array $path = [];

    private array $labels = [];

    // Shared by every context derived through with(), so a walk collects its warnings in one place.
    private ArrayObject $warnings;

    // Shared the same way: the leaves whose translation a handler gave back for the source's text.
    private ArrayObject $kept;

    public function __construct(
        public readonly ContentWalker $walker,
        public readonly bool $target = false,
    ) {
        $this->warnings = new ArrayObject;
        $this->kept = new ArrayObject;
    }

    public function with(string|int $segment, ?string $label = null): self
    {
        $context = clone $this;
        $context->path[] = $segment;

        if ($label !== null) {
            $context->labels[] = $label;
        }

        return $context;
    }

    public function path(): array
    {
        return $this->path;
    }

    public function labels(): array
    {
        return $this->labels;
    }

    public function label(): string
    {
        return implode(' › ', $this->labels);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function warnings(): array
    {
        return $this->warnings->getArrayCopy();
    }

    /**
     * Reports a leaf whose translation the handler left out of the value it returns, keeping the leaf's source text.
     */
    public function keep(Leaf $leaf): void
    {
        $this->kept[] = $leaf;
    }

    /**
     * @return list<Leaf>
     */
    public function kept(): array
    {
        return $this->kept->getArrayCopy();
    }
}
