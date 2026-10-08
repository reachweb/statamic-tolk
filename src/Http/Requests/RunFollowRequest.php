<?php

namespace Reach\StatamicTolk\Http\Requests;

/**
 * A request that follows or drives a run (`state`, `tick`, `apply`, `cancel`): allowed with `run bulk translations`, and
 * to the run's creator with `translate content`, who may have started it from the editor without that permission (§A14);
 * either way only with access to every site of the run.
 */
class RunFollowRequest extends RunStateRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $creator = $this->run()?->createdBy['id'] ?? null;

        $permitted = $user->can('run bulk translations')
            || ($creator === (string) $user->id() && $user->can('translate content'));

        return $permitted && parent::authorize();
    }
}
