<?php

namespace Reach\StatamicTolk\Ai;

use Reach\StatamicTolk\Enums\Finish;

final readonly class EngineResult
{
    /**
     * @param  array<string, string|array{text: string, reason: string}>  $items  id → text for a translation; id → text and reason for a review
     * @param  ModelRef  $model  the model that was requested, which usage is keyed by
     */
    public function __construct(
        public array $items,
        public Finish $finish,
        public Usage $usage,
        public ModelRef $model,
    ) {}
}
