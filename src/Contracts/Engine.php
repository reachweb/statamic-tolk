<?php

namespace Reach\StatamicTolk\Contracts;

use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\EngineResult;

/**
 * One provider call per method. An engine never retries, never sleeps and never splits; the pipeline decides what follows
 * (§A10).
 */
interface Engine
{
    public function translate(EngineCall $call): EngineResult;

    public function review(EngineCall $call): EngineResult;
}
