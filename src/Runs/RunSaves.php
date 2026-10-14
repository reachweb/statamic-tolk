<?php

namespace Reach\StatamicTolk\Runs;

use Closure;
use Statamic\Contracts\Auth\User;

/**
 * Which run makes the saves made now, the item it applies, and whom they are for: its creator, or nobody for a run from
 * the command line. Their events cannot tell, because a queue worker has no signed-in user, so the automatic update such
 * a save starts (T11.1) asks here: a chain of localizations is updated with the rights of the user who saved its origin,
 * and only once the run is done with the sites it is still to translate from the saved localization.
 */
final class RunSaves
{
    /**
     * @var list<array{creator: ?User, run: Run, item: RunItem}> the runs saving now, the innermost last
     */
    private static array $saves = [];

    public static function as(?User $creator, Run $run, RunItem $item, Closure $callback): mixed
    {
        self::$saves[] = ['creator' => $creator, 'run' => $run, 'item' => $item];

        try {
            return $callback();
        } finally {
            array_pop(self::$saves);
        }
    }

    /**
     * Whom a save made now is for: the creator of the run making it, else $user.
     */
    public static function user(?User $user): ?User
    {
        return self::$saves === [] ? $user : self::$saves[array_key_last(self::$saves)]['creator'];
    }

    /**
     * @return ?array{run: Run, item: RunItem} the run making a save now and the item it applies, or null
     */
    public static function current(): ?array
    {
        if (self::$saves === []) {
            return null;
        }

        ['run' => $run, 'item' => $item] = self::$saves[array_key_last(self::$saves)];

        return compact('run', 'item');
    }
}
