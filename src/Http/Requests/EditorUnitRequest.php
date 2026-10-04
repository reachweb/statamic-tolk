<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditorUnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],
            'unit' => ['required', 'string'],
        ];
    }
}
