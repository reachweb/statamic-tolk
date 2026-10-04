<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Http\Rules\CatalogModel;
use Reach\StatamicTolk\Http\Rules\ModelReference;

class EditorPlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
            'mode' => ['required', Rule::enum(Mode::class)->except([Mode::Review])],
            'overwrite_manual' => ['boolean'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
            'skip' => ['array'],
            'skip.*' => ['string'],
            'translator' => ['nullable', 'string', new ModelReference, new CatalogModel($this->user())],
        ];
    }
}
