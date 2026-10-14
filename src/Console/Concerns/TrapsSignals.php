<?php

namespace Reach\StatamicTolk\Console\Concerns;

use Symfony\Component\Console\SignalRegistry\SignalRegistry;

/**
 * Laravel's trap() asks only that pcntl is loaded, and Symfony then throws when php.ini disables pcntl_signal()
 * (disable_functions). A command with this trait traps nothing there, as without pcntl, instead of failing.
 */
trait TrapsSignals
{
    /**
     * @param  (\Closure(): (iterable<array-key, int>|int))|iterable<array-key, int>|int  $signals
     * @param  callable(int $signal): void  $callback
     * @return void
     */
    public function trap($signals, $callback)
    {
        if (SignalRegistry::isSupported()) {
            parent::trap($signals, $callback);
        }
    }
}
