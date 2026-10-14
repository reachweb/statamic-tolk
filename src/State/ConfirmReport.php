<?php

namespace Reach\StatamicTolk\State;

final readonly class ConfirmReport
{
    /**
     * @param  list<string>  $promoted  handles whose saved machine output was confirmed (§A5 reconcile rule 1)
     * @param  list<string>  $baselined  handles whose delivered text was edited before it was saved (rule 2)
     * @param  list<string>  $unresolved  handles that still have pending entries (rule 3)
     * @param  list<string>  $expired  handles whose pending entries older than FieldState::PENDING_DAYS were dropped
     * @param  list<string>  $plans  the plans of the pending entries of the promoted and baselined handles
     */
    public function __construct(
        public array $promoted = [],
        public array $baselined = [],
        public array $unresolved = [],
        public array $expired = [],
        public array $plans = [],
    ) {}

    public function changed(): bool
    {
        return $this->promoted !== [] || $this->baselined !== [] || $this->expired !== [];
    }
}
