<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Http\RunState;

/**
 * A request answered with the run's state: which page of its items to include, optionally of one status.
 */
class RunStateRequest extends FormRequest
{
    public const int MAX_PER_PAGE = 200;

    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'status' => ['nullable', Rule::enum(RunItemStatus::class)],
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', RunState::PER_PAGE);
    }

    public function status(): ?RunItemStatus
    {
        return RunItemStatus::tryFrom((string) $this->validated('status'));
    }
}
