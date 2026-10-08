<?php

namespace Reach\StatamicTolk\Http\Requests;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Http\Requests\Concerns\ChecksSiteAccess;
use Reach\StatamicTolk\Http\RunState;
use Reach\StatamicTolk\Runs\Run;

/**
 * A request about one run, answered with the run's state: which page of its items to include, optionally of one status.
 * Only a user who may access every site of the run sees it (T12.3).
 */
class RunStateRequest extends FormRequest
{
    use ChecksSiteAccess;

    public const int MAX_PER_PAGE = 200;

    private ?Run $run = null;

    /**
     * Whether $user may see and drive $run: they may access each site it translates from or into. A site that no
     * longer exists guards nothing.
     */
    public static function allows(?Authorizable $user, Run $run): bool
    {
        return $user !== null && self::mayAccessSites($user, $run->sites());
    }

    /**
     * @param  list<Run>  $runs
     * @return list<Run> the runs $user may see, in their order
     */
    public static function visible(array $runs, ?Authorizable $user): array
    {
        return array_values(array_filter($runs, fn (Run $run) => self::allows($user, $run)));
    }

    /**
     * A missing run passes, so the controller answers 404.
     */
    public function authorize(): bool
    {
        $run = $this->run();

        return $run === null || self::allows($this->user(), $run);
    }

    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'status' => ['nullable', Rule::enum(RunItemStatus::class)],
        ];
    }

    /**
     * The run of the route, or null when there is none.
     */
    public function run(): ?Run
    {
        return $this->run ??= app(RunRepository::class)->find((string) $this->route('run'));
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
