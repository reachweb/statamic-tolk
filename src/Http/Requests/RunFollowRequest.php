<?php

namespace Reach\StatamicTolk\Http\Requests;

use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Runs\Run;

/**
 * A request that follows or drives a run (`state`, `tick`, `apply`, `cancel`): allowed with `run bulk translations`, and
 * to the run's creator with `translate content`, who may have started it from the editor without that permission (§A14).
 */
class RunFollowRequest extends RunStateRequest
{
    private ?Run $run = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user->can('run bulk translations')) {
            return true;
        }

        $creator = $this->run()?->createdBy['id'] ?? null;

        return $creator !== null && $creator === (string) $user->id() && $user->can('translate content');
    }

    /**
     * The run of the route, or null when there is none.
     */
    public function run(): ?Run
    {
        return $this->run ??= app(RunRepository::class)->find((string) $this->route('run'));
    }
}
