<?php

namespace Reach\StatamicTolk\Http;

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\TransientProviderFailure;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Exceptions\UnknownUnit;
use Reach\StatamicTolk\Runs\UsageRecorder;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\EditorDelivery;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\Unit;

/**
 * The payloads of `editor/plan`, `editor/review` and `editor/unit` (D24): the browser runs a plan's units in parallel and
 * fills each field into the open form as soon as no unit holds a part of it any more.
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
        private UsageRecorder $recorder,
        private TargetFactory $targets,
    ) {}

    /**
     * Plans and stores the plan. Fields that need no model call, such as one whose source lost its text, are delivered
     * at once as `ready`.
     *
     * @throws SourceChanged
     */
    public function plan(Target $target, PlanOptions $options): array
    {
        return $this->payload($this->planner->plan($target, $options), $target);
    }

    /**
     * Plans a review of the open form's values (T7.5), given in publish-form shape, and stores the plan. They are
     * processed as the CP processes a save, so the review sees what a save would store.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws SourceChanged
     */
    public function review(Target $target, PlanOptions $options, array $values): array
    {
        $stored = $target->formFields($values)->process()->values()->only(array_keys($values))->all();

        return $this->payload($this->planner->planReview($target, $options, $stored), $target);
    }

    /**
     * The target a plan was made for, as this process sees it now: the localization the plan's reference names, built
     * again from the plan's description, with its source. The unit endpoint checks it before a unit runs, and unit()
     * again before it delivers.
     *
     * @throws TargetNotFound when the origin or the term no longer exists, the origin has no localization in the site any
     *                        more (deleted or detached), its localization there is not the one the plan's reference
     *                        names (deleted and created again), or the site no longer fits the description
     *                        (InvalidTarget, UnknownSite)
     */
    public function target(Plan $plan): Target
    {
        try {
            $target = $this->targets->fromDescription($plan->target);
        } catch (InvalidTarget|UnknownSite) {
            throw TargetNotFound::reference((string) $plan->reference);
        }

        // fromDescription() finds the origin or the term, so a deleted one has thrown already; reading the source keeps
        // this check from depending on that.
        $target->sourceSite();

        return $target->exists() && (string) $target->ref() === $plan->reference
            ? $target
            : throw TargetNotFound::reference((string) $plan->reference);
    }

    /**
     * Runs one unit and delivers the fields it completed: those it holds a part of that no runnable unit holds a part of
     * any more. A unit that already ran makes no call and delivers its fields again. The fields are delivered from the
     * target as stored once the unit ran; a unit that completes no field reads no content.
     *
     * @throws UnknownUnit|UnitBusy|TransientProviderFailure|ProviderRejected|SourceChanged
     * @throws TargetNotFound when the target is gone once the unit ran (target())
     */
    public function unit(Plan $plan, string $unitId): array
    {
        $unit = $this->pipeline->units($plan)[$unitId] ?? throw UnknownUnit::in($plan->id, $unitId);
        $usage = new UsageMap;

        $outcome = $this->pipeline->runUnit($plan, $unitId, function (ModelRef $model, Usage $call) use (&$usage) {
            $usage = $usage->add($model, $call);
            $this->recorder->record($model, $call, UsageSource::Editor);
        });

        $ready = array_values(array_intersect($this->pipeline->readyFields($plan), $this->fieldsOf($plan, $unit)));
        [$fields, $failed, $warnings, $unchanged] = $ready === [] ? [[], [], [], []] : $this->deliver($plan, $this->current($plan), $ready);

        return [
            'fields' => $fields,
            'unchanged' => $unchanged,
            'spawned' => array_map(fn (Unit $spawned) => $this->describe($plan, $spawned), $outcome->spawned),
            'failed' => $failed,
            'warnings' => $warnings,
            'usage' => $usage->toArray(),
            'remaining' => count($this->pipeline->runnable($plan)),
            // A review that could not be made keeps the translation as it was (§A10).
            'review_skipped' => $unit->kind === UnitKind::Review && $outcome->model === null,
            'reviewed' => $unit->kind === UnitKind::Review && $outcome->model !== null,
        ];
    }

    /**
     * The plan's target as stored now. Another request may have saved or deleted it or its source while the model
     * worked, and this process may still hold them as they were: a localization's origin and an entry's localizations
     * in Blink, a localization detached elsewhere in the memoized `origin` index (07-spike-findings.md "Stache and
     * parallel writers"). Delivery assembles from it, and that assembly is the delivery's only source check.
     *
     * @throws TargetNotFound
     */
    private function current(Plan $plan): Target
    {
        ContentWriter::refreshStache();

        return $this->target($plan);
    }

    private function payload(Plan $plan, Target $target): array
    {
        $this->store->put($plan);

        [$ready, , $warnings] = $this->deliver($plan, $target, $this->pipeline->readyFields($plan));

        return [
            'plan' => $plan->id,
            'units' => array_map(fn (array $batch) => $this->describe($plan, Unit::batch($batch, $plan->kind)), $plan->batches),
            'fields' => $plan->fieldHandles(),
            'skipped' => $plan->skipped,
            'ready' => $ready,
            'warnings' => array_values(array_unique([...$plan->noteList(), ...$warnings])),
            'reviewer' => $plan->options->reviewer?->__toString(),
        ];
    }

    /**
     * Delivers the fields. In a strict plan a field with a part that failed is left out and reported with the reason of
     * its first failed part (D28); delivery assembles every field before it writes anything, so the others are
     * delivered again without it. A reviewed field without corrections is not delivered but listed as unchanged.
     *
     * @param  list<string>  $handles
     * @return array{0: array<string, array>, 1: array<string, string>, 2: list<string>, 3: list<string>}
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

        $unchanged = array_filter($fields, fn (array $field) => $field['unchanged'] ?? false);

        return [array_diff_key($fields, $unchanged), $failed, array_values(array_unique($warnings)), array_keys($unchanged)];
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
