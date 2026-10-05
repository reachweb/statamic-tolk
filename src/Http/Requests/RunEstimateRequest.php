<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunEstimateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'integer', 'min:0'],
        ];
    }
}
