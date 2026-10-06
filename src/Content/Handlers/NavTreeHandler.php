<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Maps the branches of a navigation tree depth-first, the value of NavTarget's synthetic `tree` field (§A11
 * "Navigation"). A branch's `title` is a `plain` leaf under {…path, index, title}; its `data` is mapped with the nav
 * blueprint's fields, which the field's config holds as `fields`, under {…path, index, data}; its `children` follow
 * under {…path, index, children}. `id`, `entry` and `url` are never touched.
 */
final class NavTreeHandler implements FieldHandler
{
    use MakesLeaves;

    public const string HANDLE = 'nav_tree';

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        return $this->branches(new Fields($field->get('fields', [])), $value, $translate, $context);
    }

    private function branches(Fields $fields, array $branches, Closure $translate, WalkContext $context): array
    {
        // Core's page editor offers `title` as a field of the nav blueprint, so a blueprint may configure it.
        $title = $fields->get('title');
        $translatesTitle = $title === null || $title->fieldtype()->config('tolk_translate') !== false;
        $hint = $title === null ? null : $this->hint($title->fieldtype());

        foreach ($branches as $index => $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $branchContext = $context->with($index);

            if ($translatesTitle && is_string($branch['title'] ?? null)) {
                $branches[$index]['title'] = $this->plain($branch['title'], $hint, $translate, $branchContext->with('title', $title?->display() ?? __('Title')));
            }

            if (is_array($branch['data'] ?? null) && $branch['data'] !== [] && ! array_is_list($branch['data'])) {
                $branches[$index]['data'] = $context->walker->mapFields($fields, $branch['data'], $translate, $branchContext->with('data'));
            }

            if (is_array($branch['children'] ?? null) && array_is_list($branch['children'])) {
                $branches[$index]['children'] = $this->branches($fields, $branch['children'], $translate, $branchContext->with('children'));
            }
        }

        return $branches;
    }
}
