<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Http\Rules\CatalogModel;
use Reach\StatamicTolk\Http\Rules\ModelReference;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Support\Settings;

/**
 * A RunRequest in snake_case: the sites, what to translate (at least one list) and the options.
 */
class RunStoreRequest extends FormRequest
{
    public const array SCOPE = ['collections', 'taxonomies', 'globals', 'navs', 'entry_ids', 'term_ids'];

    public function rules(): array
    {
        $rules = [
            'sites' => ['required', 'list', 'min:1'],
            'sites.*' => ['required', 'string'],
            'source_site' => ['nullable', 'string'],
            'options' => ['array:'.implode(',', array_keys(RunRequest::OPTIONS))],
            'options.mode' => ['nullable', Rule::enum(Mode::class)],
            'options.overwrite_manual' => ['nullable', 'boolean'],
            'options.review' => ['nullable', 'boolean'],
            'options.translator' => ['nullable', 'string', new ModelReference, new CatalogModel($this->user())],
            'options.reviewer' => ['nullable', 'string', new ModelReference, new CatalogModel($this->user())],
            'options.new_status' => ['nullable', Rule::in(Settings::NEW_LOCALIZATION_STATUSES)],
            'options.publish_changes' => ['nullable', 'boolean'],
        ];

        foreach (self::SCOPE as $key) {
            $rules[$key] = ['list', 'required_without_all:'.implode(',', array_diff(self::SCOPE, [$key]))];
            $rules[$key.'.*'] = ['required', 'string'];
        }

        return $rules;
    }
}
