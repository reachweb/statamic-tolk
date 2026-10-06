<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Enums\ContentType;
use Statamic\Facades\Site;

/**
 * One slice of an Overview cell: the content type, the container's handle, the target site and the cursor `scan`
 * returned, or none to start. `fresh` counts again instead of answering from the cache. A site the user may not access
 * is refused; an unknown one fails in the scanner.
 */
class OverviewScanRequest extends FormRequest
{
    public const array TYPES = [ContentType::Entry, ContentType::Term, ContentType::GlobalSet, ContentType::Nav];

    public function authorize(): bool
    {
        $site = is_string($this->input('site')) ? Site::get($this->input('site')) : null;

        return $site === null || $this->user()->can('view', $site);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_column(self::TYPES, 'value'))],
            'container' => ['required', 'string'],
            'site' => ['required', 'string'],
            'cursor' => ['nullable', 'string'],
            'fresh' => ['boolean'],
        ];
    }

    public function type(): ContentType
    {
        return ContentType::from($this->validated('type'));
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }

    public function fresh(): bool
    {
        return (bool) $this->validated('fresh', false);
    }
}
