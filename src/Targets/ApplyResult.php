<?php

namespace Reach\StatamicTolk\Targets;

final readonly class ApplyResult
{
    /**
     * @param  list<string>  $applied
     * @param  array<string, string>  $skipped  handle → reason
     * @param  list<string>  $warnings
     */
    public function __construct(
        public TargetRef $ref,
        public bool $created = false,
        public ?string $slug = null,
        public array $applied = [],
        public array $skipped = [],
        public array $warnings = [],
    ) {}
}
