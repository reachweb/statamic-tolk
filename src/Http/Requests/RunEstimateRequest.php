<?php

namespace Reach\StatamicTolk\Http\Requests;

class RunEstimateRequest extends RunStateRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'integer', 'min:0'],
        ];
    }
}
