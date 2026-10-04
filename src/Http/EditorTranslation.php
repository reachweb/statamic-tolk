<?php

namespace Reach\StatamicTolk\Http;

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TransientProviderFailure;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownUnit;
use Reach\StatamicTolk\Translation\EditorDelivery;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\Unit;

/**
 * The payloads of `editor/plan` and `editor/unit` (D24): the browser runs a plan's units in parallel and fills each field
 * into the open form as soon as no unit holds a part of it any more.
 */
final readonly class EditorTranslation
{
    /**
     * The skip reason of a field the editor has unsaved changes in.
     */
    public const string EDITED_IN_FORM = 'edited_in_form';

    public function __construct(
        private Planner $planner,
        private PlanStore $store,
        private Pipeline $pipeline,
        private EditorDelivery $delivery,
    ) {}

    /**
     * Plans and stores the plan. Fields that need no model call, such as one whose source lost its text, are delivered
     * at once as `ready`.
     *
     * @throws SourceChanged
     */
    public function plan(Target $target, PlanOptions $options): array
    {
        $plan = $this->planner->plan($target, $options);
        $this->store->put($plan);

        [$ready] = $this->deliver($plan, $target, $this->pipeline->readyFields($plan));

        return [
            'plan' => $plan->id,
            'units' => array_map(fn (array $batch) => $this->describe($plan, Unit::batch($batch, $plan->kind)), $plan->batches),
            'fields' => $plan->fieldHandles(),
            'skipped' => $plan->skipped,
            'ready' => $ready,
        ];
    }

    /**
     * Runs one unit and delivers the fields it completed: those it holds a part of that no runnable unit holds a part of
     * any more. A unit that already ran makes no call and delivers its fields again.
     *
     * @throws UnknownUnit|UnitBusy|TransientProviderFailure|ProviderRejected|SourceChanged
     */
    public function unit(Plan $plan, Target $target, string $unitId): array
    {
        $unit = $this->pipeline->units($plan)[$unitId] ?? throw UnknownUnit::in($plan->id, $unitId);
        $usage = new UsageMap;

        $outcome = $this->pipeline->runUnit($plan, $unitId, function (ModelRef $model, Usage $call) use (&$usage) {
            $usage = $usage->add($model, $call);
        });

        $ready = array_intersect($this->pipeline->readyFields($plan), $this->fieldsOf($plan, $unit));
        [$fields, $failed, $warnings] = $this->deliver($plan, $target->fresh(), array_values($ready));

        return [
            'fields' => $fields,
            'spawned' => array_map(fn (Unit $spawned) => $this->describe($plan, $spawned), $outcome->spawned),
            'failed' => $failed,
            'warnings' => $warnings,
            'usage' => $usage->toArray(),
            'remaining' => count($this->pipeline->runnable($plan)),
        ];
    }

    /**
     * Delivers the fields. In a strict plan a field with a part that failed is left out and reported with the reason of
     * its first failed part (D28); delivery assembles every field before it writes anything, so the others are
     * delivered again without it.
     *
     * @param  list<string>  $handles
     * @return array{0: array<string, array>, 1: array<string, string>, 2: list<string>}
     */
    private function deliver(Plan $plan, Target $target, array $handles): array
    {
        $fields = $failed = $warnings = [];

        while ($handles !== []) {
            try {
                $fields = $this->delivery->deliver($plan, $target, $handles);

                break;
            } catch (FieldFailed $failure) {
                $failed[$failure->handle] = array_values($failure->reasons)[0];
                $warnings[] = $failure->getMessage();
                $handles = array_values(array_diff($handles, [$failure->handle]));
            }
        }

        foreach ($fields as $field) {
            array_push($warnings, ...($field['warnings'] ?? []));
        }

        return [$fields, $failed, array_values(array_unique($warnings))];
    }

    /**
     * @return array{id: string, fields: list<string>, chars: int}
     */
    private function describe(Plan $plan, Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'fields' => $this->fieldsOf($plan, $unit),
            'chars' => array_sum(array_map(fn (string $id) => mb_strlen($plan->segments[$id]['text']), $unit->segmentIds)),
        ];
    }

    /**
     * @return list<string>
     */
    private function fieldsOf(Plan $plan, Unit $unit): array
    {
        return array_values(array_unique(array_map(fn (string $id) => $plan->segments[$id]['field'], $unit->segmentIds)));
    }
}
