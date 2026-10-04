<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditorConfirmRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
            'delivered' => ['array'],
            'delivered.*' => ['string'],
        ];
    }
}
