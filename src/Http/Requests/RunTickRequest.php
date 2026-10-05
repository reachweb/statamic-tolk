<?php

namespace Reach\StatamicTolk\Http\Requests;

use Reach\StatamicTolk\Runs\RunDispatcher;

/**
 * One lane's tick. The worker id is built from the user, the page's nonce and the lane, never taken from the request.
 */
class RunTickRequest extends RunFollowRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'nonce' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,64}$/'],
            'lane' => ['required', 'integer', 'min:1', 'max:'.RunDispatcher::browserLanes()],
        ];
    }

    public function workerId(): string
    {
        return 'browser-'.$this->user()->id().'-'.$this->validated('nonce').'-'.(int) $this->validated('lane');
    }
}
