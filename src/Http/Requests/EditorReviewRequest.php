<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Reach\StatamicTolk\Http\Rules\CatalogModel;
use Reach\StatamicTolk\Http\Rules\ModelReference;

class EditorReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
            'values' => ['present', 'array'],
            'overwrite_manual' => ['boolean'],
            'reviewer' => ['nullable', 'string', new ModelReference, new CatalogModel($this->user())],
        ];
    }
}
