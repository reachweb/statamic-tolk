<?php

namespace Reach\StatamicTolk\Http\Requests;

/**
 * Removes items from a run, by index, and answers with the run's state like the other actions on a run.
 */
class RunRemoveRequest extends RunStateRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'items.*' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return list<int>
     */
    public function indexes(): array
    {
        return array_values(array_map(intval(...), $this->validated('items')));
    }
}
