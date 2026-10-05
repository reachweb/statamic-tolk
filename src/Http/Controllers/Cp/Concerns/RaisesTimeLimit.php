<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp\Concerns;

trait RaisesTimeLimit
{
    /**
     * Gives the request that many seconds, without lowering a limit that is higher or off.
     */
    private function raiseTimeLimit(int $seconds): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && function_exists('set_time_limit')) {
            set_time_limit(max($limit, $seconds));
        }
    }
}
