<?php

namespace Reach\StatamicTolk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Targets\TargetRef;

/**
 * Translations were saved into a target server-side (TargetTranslator, RunProcessor::applyReady()).
 */
final readonly class TargetTranslated
{
    use Dispatchable;

    /**
     * @param  list<string>  $handles  the fields that were saved
     * @param  list<string>  $models  the models that were called for the target, as ModelRef strings
     */
    public function __construct(
        public TargetRef $ref,
        public array $handles,
        public array $models,
        public UsageMap $usage,
    ) {}
}
