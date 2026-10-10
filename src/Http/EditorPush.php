<?php

namespace Reach\StatamicTolk\Http;

use Illuminate\Support\Arr;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Statamic\Contracts\Auth\User;

/**
 * The runs an editor pushes from an origin into its localizations (T8.13), or starts by saving it with automatic
 * updates on (T11.1): the one still running for the item they have open, and what the banner needs to follow a run as
 * its creator (§A14).
 */
final readonly class EditorPush
{
    /**
     * How many of the most recent runs are searched for a running push. A push runs for minutes, so it is among them
     * unless it was left behind long ago.
     */
    public const int RECENT = 50;

    /**
     * The routes the creator of a run may call without `run bulk translations`.
     */
    public const array URLS = ['state', 'tick', 'apply', 'cancel'];

    public function __construct(
        private RunRepository $runs,
        private RunState $state,
    ) {}

    /**
     * The user's running push from the item, the latest first. A pushed run starts at once, so a pending one never
     * needs following.
     */
    public function active(string $reference, User $user): ?Run
    {
        foreach ($this->runs->recent(self::RECENT) as $run) {
            if (
                $run->status === RunStatus::Running
                && ($run->scope['reference'] ?? null) === $reference
                && ($run->createdBy['id'] ?? null) === (string) $user->id()
            ) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @return array{run: string, driver: string, automatic: bool, lanes: int, urls: array<string, string>}
     */
    public function payload(Run $run): array
    {
        return [
            'run' => $run->id,
            'driver' => $run->driver->value,
            'automatic' => ($run->scope['automatic'] ?? false) === true,
            'lanes' => RunDispatcher::browserLanes(),
            'urls' => Arr::only($this->state->urls($run), self::URLS),
        ];
    }
}
