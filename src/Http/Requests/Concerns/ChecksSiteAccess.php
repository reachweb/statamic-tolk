<?php

namespace Reach\StatamicTolk\Http\Requests\Concerns;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Statamic\Facades\Site;

trait ChecksSiteAccess
{
    /**
     * Whether $user may access each of the sites. A value that names no site is left to validation, or to the code that
     * resolves it.
     */
    protected static function mayAccessSites(?Authorizable $user, array $handles): bool
    {
        foreach ($handles as $handle) {
            $site = is_string($handle) ? Site::get($handle) : null;

            if ($site !== null && ! $user?->can('view', $site)) {
                return false;
            }
        }

        return true;
    }
}
