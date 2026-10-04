<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditorStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
        ];
    }
}
