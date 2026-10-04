<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditorBaselineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
            'protect' => ['boolean'],
        ];
    }
}
