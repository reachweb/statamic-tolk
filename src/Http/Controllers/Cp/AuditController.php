<?php

namespace Reach\StatamicTolk\Http\Controllers\Cp;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Reach\StatamicTolk\Http\Controllers\Cp\Concerns\FailsWithJson;
use Reach\StatamicTolk\Http\Requests\AuditLocalizableRequest;
use Reach\StatamicTolk\Support\Audit;
use Statamic\Facades\Fieldset;
use Statamic\Facades\User;

/**
 * The Audit page (§A13): the setup checks of tolk:doctor, and the fields that are not localizable made localizable.
 */
class AuditController extends Controller
{
    use FailsWithJson;

    public function index(Audit $audit): Response
    {
        return Inertia::render('tolk::Audit', [
            'checks' => $this->checks($audit),
            'canMakeLocalizable' => AuditLocalizableRequest::allows(User::current()),
            'urls' => [
                'localizable' => cp_route('tolk.audit.localizable'),
                'overview' => cp_route('tolk.overview'),
            ],
        ]);
    }

    /**
     * Makes the named fields of a blueprint localizable, and answers how many changed with the checks as they are now.
     */
    public function localizable(AuditLocalizableRequest $request, Audit $audit): JsonResponse
    {
        try {
            $changed = $audit->makeLocalizable($request->validated('namespace'), $request->validated('blueprint'), $request->validated('fields'));
        } catch (InvalidArgumentException $exception) {
            $this->fail(422, 'unknown_blueprint', $exception->getMessage());
        }

        return response()->json(['changed' => $changed, 'checks' => $this->checks($audit)]);
    }

    /**
     * Audit::run(), with links to the blueprints and fieldsets the items name for a user who may edit them.
     */
    private function checks(Audit $audit): array
    {
        $linked = (bool) User::current()?->can('configure fields');

        return array_map(fn (array $check) => [
            ...$check,
            'items' => array_map(fn (array $item) => $this->item($item, $linked), $check['items']),
        ], $audit->run());
    }

    /**
     * An item that names a blueprint (`localizable`, `technical_fields`) gets `edit_url`, and its fields of an imported
     * fieldset `fieldset_url`; null when not $linked.
     */
    private function item(array $item, bool $linked): array
    {
        if (! isset($item['namespace'], $item['blueprint'])) {
            return $item;
        }

        return [
            ...$item,
            'edit_url' => $linked ? $this->blueprintUrl($item) : null,
            'fields' => array_map(fn (array $field) => array_key_exists('fieldset', $field) ? [
                ...$field,
                'fieldset_url' => $linked && $field['fieldset'] !== null ? Fieldset::find($field['fieldset'])?->editUrl() : null,
            ] : $field, $item['fields']),
        ];
    }

    private function blueprintUrl(array $item): ?string
    {
        return match ($item['type']) {
            'collection' => cp_route('blueprints.collections.edit', [$item['container'], $item['blueprint']]),
            'taxonomy' => cp_route('blueprints.taxonomies.edit', [$item['container'], $item['blueprint']]),
            'globals' => cp_route('blueprints.globals.edit', $item['container']),
            default => null,
        };
    }
}
