<?php

namespace Reach\StatamicTolk\Http\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Facades\Site;

/**
 * A rule on a field of a grid row, whose attribute is `{grid}.{index}.{field}` (core's `Grid::extraRules()`).
 */
abstract class GridRowRule implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    protected function row(string $attribute): array
    {
        $row = Arr::get($this->data, Str::beforeLast($attribute, '.'));

        return is_array($row) ? $row : [];
    }

    /**
     * @return list<array>
     */
    protected function rowsBefore(string $attribute): array
    {
        $rowPath = Str::beforeLast($attribute, '.');
        $rows = Arr::get($this->data, Str::beforeLast($rowPath, '.'));
        $index = Str::afterLast($rowPath, '.');

        return collect(is_array($rows) ? $rows : [])
            ->takeUntil(fn ($row, $key) => (string) $key === $index)
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();
    }

    protected static function siteName(string $site): string
    {
        return Site::get($site)?->name() ?? $site;
    }
}
