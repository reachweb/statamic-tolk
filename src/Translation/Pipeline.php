<?php

namespace Reach\StatamicTolk\Translation;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\EngineResult;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Ai\Throttle;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\ProviderTimeout;
use Reach\StatamicTolk\Exceptions\RequestRejected;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TransientProviderFailure;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownUnit;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Languages;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Fields\Field;

/**
 * Runs a stored plan unit by unit and assembles its fields (§A8, §A10). Every unit makes at most one provider call and
 * never sleeps; transient failures store nothing and are thrown with the wait, so the caller decides when to try again.
 */
final readonly class Pipeline
{
    public function __construct(
        private PlanStore $store,
        private Engine $engine,
        private SegmentValidator $validator,
        private ContentWalker $walker,
        private StatusResolver $resolver,
        private Throttle $throttle,
        private Languages $languages,
        private Settings $settings,
    ) {}

    /**
     * @return array<string, Unit>
     */
    public function units(Plan $plan): array
    {
        return array_map(fn (array $node) => $node['unit'], $this->store->units($plan));
    }

    /**
     * @return array<string, Unit> the units without an outcome
     */
    public function runnable(Plan $plan): array
    {
        return array_map(
            fn (array $node) => $node['unit'],
            array_filter($this->store->units($plan), fn (array $node) => $node['outcome'] === null),
        );
    }

    public function isFinished(Plan $plan): bool
    {
        return $this->runnable($plan) === [];
    }

    /**
     * Returns the stored outcome when there is one; otherwise makes the unit's one call and stores what it led to.
     *
     * @param  ?Closure(ModelRef, Usage): void  $recordUsage  receives the usage of the call
     *
     * @throws UnitBusy while another request runs the same unit
     * @throws TransientProviderFailure when the call can be made again after its `seconds`
     */
    public function runUnit(Plan $plan, string $unitId, ?Closure $recordUsage = null): UnitOutcome
    {
        if ($outcome = $this->store->outcome($plan->id, $unitId)) {
            return $outcome;
        }

        $unit = $this->units($plan)[$unitId] ?? throw UnknownUnit::in($plan->id, $unitId);
        $timeout = (int) config("tolk.http.timeout.{$plan->options->profile}", 45);

        $outcome = $this->store->lock($plan->id, $unitId, $timeout + 10)->get(function () use ($plan, $unit, $timeout, $recordUsage) {
            if ($outcome = $this->store->outcome($plan->id, $unit->id)) {
                return $outcome;
            }

            $outcome = $unit->kind === UnitKind::Review
                ? $this->review($plan, $unit, $timeout, $recordUsage)
                : $this->call($plan, $unit, $timeout, $recordUsage);
            $this->store->putOutcome($plan->id, $outcome);

            return $outcome;
        });

        return $outcome ?: throw UnitBusy::for($plan->id, $unitId);
    }

    /**
     * The final text of a segment (§A8 step 3): a review correction, else the valid translation, else the reused text.
     */
    public function translation(Plan $plan, string $segmentId): ?string
    {
        return $this->resolve($plan)['translations'][$segmentId] ?? null;
    }

    /**
     * Why a segment failed for good, or null when it has a translation or a unit still holds it.
     */
    public function failure(Plan $plan, string $segmentId): ?string
    {
        return $this->resolve($plan)['failed'][$segmentId] ?? null;
    }

    /**
     * @return list<string> the fields no runnable unit holds a segment of
     */
    public function readyFields(Plan $plan): array
    {
        $pending = $this->resolve($plan)['pending'];

        return array_values(array_filter(
            $plan->fieldHandles(),
            fn (string $handle) => array_intersect_key($plan->segmentsOfField($handle), $pending) === [],
        ));
    }

    /**
     * Builds the field's target value from its current source value, or for a review plan from the value under review.
     * Pass a target resolved after the units ran, so a source edited meanwhile is noticed. This process may still hold
     * a source that another process saved since (Target::fresh()), so Target::apply() makes the same SourceCheck again
     * under the write lock, where that save is seen.
     *
     * @throws SourceChanged when the field is out of scope or its source value is not the one that was planned
     * @throws FieldFailed when a part failed for good and the plan is strict
     */
    public function assemble(Plan $plan, Target $target, string $handle): AssembledField
    {
        $planned = $plan->fields[$handle] ?? throw new InvalidArgumentException("The plan [{$plan->id}] has no field [{$handle}].");
        SourceCheck::verify($target, $handle, $planned['source_hash']);
        $field = $target->fields()->get($handle);
        $sourceValue = $target->sourceValue($handle);

        $resolved = $this->resolve($plan);
        $segments = $plan->segmentsOfField($handle);

        if (array_intersect_key($segments, $resolved['pending']) !== []) {
            throw new LogicException("The field [{$handle}] still has segments to translate.");
        }

        if ($plan->kind === UnitKind::Review) {
            return $this->reviewed($plan->id, $planned, $field, $sourceValue, $segments, $resolved);
        }

        $sourceTexts = array_column($segments, 'text');

        // The same source value with another walk means the blueprint changed since planning.
        if (array_column($this->walker->leaves($field, $sourceValue), 'text') !== $sourceTexts) {
            throw SourceChanged::field($handle);
        }

        // What the handlers noticed on the walk, such as a Bard field in a format they leave untouched.
        $warnings = $this->walker->warnings();

        if ($planned['clear']) {
            return $this->cleared($plan->id, $planned, $field, $sourceValue, $warnings);
        }

        $texts = $hashes = $failed = [];

        foreach ($segments as $id => $segment) {
            $translation = $resolved['translations'][$id] ?? null;
            array_push($warnings, ...($resolved['warnings'][$id] ?? []));

            if ($translation === null) {
                $failed[$id] = $reason = $resolved['failed'][$id] ?? UnitOutcome::MISSING;
                $texts[] = $segment['text'];
                $hashes[] = null;
                $warnings[] = __(':label: ":text" was left untranslated (:reason).', ['label' => $segment['label'], 'text' => $this->excerpt($segment), 'reason' => UnitOutcome::reasonLabel($reason)]);

                continue;
            }

            $texts[] = $translation;
            $hashes[] = Fingerprint::ofText($segment['text']);
        }

        if ($failed !== [] && $plan->options->strict) {
            throw FieldFailed::for($handle, $failed);
        }

        $value = $this->walker->inject($field, $sourceValue, $texts);

        return new AssembledField(
            handle: $handle,
            plan: $plan->id,
            value: $value,
            remove: false,
            sourceHash: $planned['source_hash'],
            targetHash: $this->resolver->targetHash($field, $value),
            targetBefore: $planned['target_before'],
            segments: $this->aligned($field, $value, $texts) ? $hashes : [],
            identical: $texts === $sourceTexts,
            incomplete: $failed !== [],
            translator: $this->mostFrequent($resolved['models'], $segments),
            corrections: array_values(array_intersect_key($resolved['corrections'], $segments)),
            warnings: array_values(array_unique($warnings)),
            reviewer: $this->mostFrequent($resolved['reviewers'], $segments),
        );
    }

    private function call(Plan $plan, Unit $unit, int $timeout, ?Closure $recordUsage): UnitOutcome
    {
        $failures = $this->store->failures($plan->id, $unit->id);
        $fallback = $this->usesFallback($plan, $unit, $failures);
        $model = $fallback ? $plan->options->fallback : $plan->options->translator;

        try {
            $result = $this->engine->translate($this->engineCall($plan, $model, $this->segments($plan, $unit), $timeout, $failures + 1, $unit->capMultiplier));
        } catch (ProviderTimeout $exception) {
            if ($unit->canSplit()) {
                return new UnitOutcome(unit: $unit->id, spawned: $unit->halves($fallback));
            }

            $this->store->countFailure($plan->id, $unit->id);

            throw $exception;
        } catch (TransientProviderFailure $exception) {
            $this->store->countFailure($plan->id, $unit->id);

            throw $exception;
        } catch (RequestRejected $exception) {
            return $exception->tooLarge()
                ? $this->splitOrFail($unit, $model, $fallback, UnitOutcome::REQUEST_REJECTED)
                : $this->failed($unit, $model, UnitOutcome::REQUEST_REJECTED);
        }

        $usage = $this->usage($result, $recordUsage);

        return match ($result->finish) {
            Finish::Stop => $this->accept($plan, $unit, $result, $usage, $fallback),
            Finish::Length, Finish::Empty => ! $unit->canSplit() && $unit->capMultiplier === 1
                ? new UnitOutcome(unit: $unit->id, model: (string) $model, usage: $usage, spawned: [$unit->withDoubledCap($fallback)])
                : $this->splitOrFail($unit, $model, $fallback, UnitOutcome::TOO_LONG, $usage),
            Finish::Refused => $this->splitOrFail($unit, $model, $fallback, UnitOutcome::REFUSED, $usage),
        };
    }

    private function splitOrFail(Unit $unit, ModelRef $model, bool $fallback, string $reason, UsageMap $usage = new UsageMap): UnitOutcome
    {
        return $unit->canSplit()
            ? new UnitOutcome(unit: $unit->id, model: (string) $model, usage: $usage, spawned: $unit->halves($fallback))
            : $this->failed($unit, $model, $reason, $usage);
    }

    private function failed(Unit $unit, ModelRef $model, string $reason, UsageMap $usage = new UsageMap): UnitOutcome
    {
        return new UnitOutcome(unit: $unit->id, model: (string) $model, failed: array_fill_keys($unit->segmentIds, $reason), usage: $usage);
    }

    /**
     * Validates each translation of a finished call: invalid ones get a repair, missing ids one more request. Accepted
     * ones are checked against the glossary, whose notes are warnings only.
     */
    private function accept(Plan $plan, Unit $unit, EngineResult $result, UsageMap $usage, bool $fallback): UnitOutcome
    {
        $translations = $failed = $warnings = $spawned = $missing = [];
        $glossary = Glossary::fromSettings($this->settings, $plan->targetSite);

        foreach ($unit->segmentIds as $id) {
            $segment = $plan->segments[$id];
            $text = $result->items[$id] ?? null;

            if (! is_string($text)) {
                $missing[] = $id;

                continue;
            }

            if ($this->validator->placeholders($segment, $text) !== null) {
                if ($unit->repair < Unit::MAX_REPAIRS) {
                    $spawned[] = $unit->repairOf($id, $this->validator->validate($segment, $text), $fallback);
                } else {
                    $failed[$id] = UnitOutcome::INVALID;
                }

                continue;
            }

            // A translation over its length limit gets one repair and is then accepted (§A10).
            if (($problem = $this->validator->length($segment, $text)) !== null) {
                if ($unit->repair === 0) {
                    $spawned[] = $unit->repairOf($id, $problem, $fallback);

                    continue;
                }

                $warnings[$id][] = "{$segment['label']}: the translation of \"{$this->excerpt($segment)}\" is longer than {$segment['max_length']} characters.";
            }

            $translations[$id] = $text;

            foreach ($glossary->check($segment['text'], $text, SegmentKind::from($segment['kind'])) as $note) {
                $warnings[$id][] = "{$segment['label']}: {$note['message']}";
            }
        }

        if ($missing !== []) {
            if ($unit->missingRetry) {
                $failed += array_fill_keys($missing, UnitOutcome::MISSING);
            } else {
                $spawned[] = $unit->forMissing($missing, $fallback);
            }
        }

        if ($plan->options->review && $translations !== []) {
            $spawned[] = $unit->forReview(array_keys($translations));
        }

        return new UnitOutcome(
            unit: $unit->id,
            model: (string) $result->model,
            translations: $translations,
            failed: $failed,
            warnings: $warnings,
            usage: $usage,
            spawned: $spawned,
        );
    }

    /**
     * The reviewer's one call over translations accepted at translation time (D8). A review never fails a segment: when
     * it cannot be made, the translations stand and their fields get the warning "review skipped". Only ProviderRejected
     * propagates, because then the reviewer's key or model is wrong.
     */
    private function review(Plan $plan, Unit $unit, int $timeout, ?Closure $recordUsage): UnitOutcome
    {
        $known = $this->resolve($plan)['translations'];
        $translations = [];

        foreach ($unit->segmentIds as $id) {
            if (isset($known[$id])) {
                $translations[$id] = $known[$id];
            }
        }

        if ($translations === []) {
            return new UnitOutcome(unit: $unit->id);
        }

        $model = $plan->options->reviewer ?? $plan->options->translator;
        $failures = $this->store->failures($plan->id, $unit->id);

        try {
            $result = $this->engine->review($this->engineCall($plan, $model, $this->reviewSegments($plan, $translations), $timeout, $failures + 1));
        } catch (TransientProviderFailure $exception) {
            if ($this->store->countFailure($plan->id, $unit->id) < (int) config('tolk.retry.max_attempts', 3)) {
                throw $exception;
            }

            return $this->reviewSkipped($plan, $unit, UnitOutcome::UNAVAILABLE);
        } catch (RequestRejected) {
            return $this->reviewSkipped($plan, $unit, UnitOutcome::REQUEST_REJECTED);
        }

        $usage = $this->usage($result, $recordUsage);

        return match ($result->finish) {
            Finish::Stop => $this->acceptCorrections($plan, $unit, $result, $translations, $usage),
            Finish::Length, Finish::Empty => $this->reviewSkipped($plan, $unit, UnitOutcome::TOO_LONG, $usage),
            Finish::Refused => $this->reviewSkipped($plan, $unit, UnitOutcome::REFUSED, $usage),
        };
    }

    /**
     * Keeps the corrections that are valid against the translation they correct, which at translation time is valid
     * against its source segment. An invalid correction is discarded with a warning and one equal to the translation
     * silently; accepted ones are checked against the glossary.
     *
     * @param  array<string, string>  $translations  the translations under review
     */
    private function acceptCorrections(Plan $plan, Unit $unit, EngineResult $result, array $translations, UsageMap $usage): UnitOutcome
    {
        $corrections = $warnings = [];
        $glossary = Glossary::fromSettings($this->settings, $plan->targetSite);

        foreach ($translations as $id => $translation) {
            $text = $result->items[$id]['text'] ?? null;

            if (! is_string($text) || $text === $translation) {
                continue;
            }

            $segment = $plan->segments[$id];
            // A review plan's translation is the target's text, whose placeholders can be fewer than its source's (T7.4).
            $corrected = $plan->kind === UnitKind::Review ? [...$segment, 'text' => $translation] : $segment;

            if ($this->validator->validate($corrected, $text) !== null) {
                $warnings[$id][] = "{$segment['label']}: the reviewer's correction of \"{$this->excerpt($segment)}\" was not valid and was discarded.";

                continue;
            }

            $corrections[] = ['id' => $id, 'before' => $translation, 'after' => $text, 'reason' => trim($result->items[$id]['reason'] ?? '')];

            foreach ($glossary->check($segment['text'], $text, SegmentKind::from($segment['kind'])) as $note) {
                $warnings[$id][] = "{$segment['label']}: {$note['message']}";
            }
        }

        return new UnitOutcome(unit: $unit->id, model: (string) $result->model, corrections: $corrections, warnings: $warnings, usage: $usage);
    }

    private function reviewSkipped(Plan $plan, Unit $unit, string $reason, UsageMap $usage = new UsageMap): UnitOutcome
    {
        $warnings = [];

        foreach ($unit->segmentIds as $id) {
            $warnings[$id] = [__(':label: review skipped (:reason).', ['label' => $plan->segments[$id]['label'], 'reason' => UnitOutcome::reasonLabel($reason)])];
        }

        return new UnitOutcome(unit: $unit->id, warnings: $warnings, usage: $usage);
    }

    /**
     * From `retry.max_attempts` transient failures on, and for units spawned by a fallback answer, the fallback takes
     * over while its provider is not paused (§A10).
     */
    private function usesFallback(Plan $plan, Unit $unit, int $failures): bool
    {
        $fallback = $plan->options->fallback;

        return $fallback !== null
            && ($unit->useFallback || $failures >= (int) config('tolk.retry.max_attempts', 3))
            && $this->throttle->pausedFor($fallback->provider) === 0;
    }

    /**
     * @param  list<array>  $segments
     */
    private function engineCall(Plan $plan, ModelRef $model, array $segments, int $timeout, int $attempt, int $capMultiplier = 1): EngineCall
    {
        return new EngineCall(
            model: $model,
            source: $this->languages->forSite($plan->sourceSite),
            target: $this->languages->forSite($plan->targetSite),
            context: PromptContext::fromSettings($this->settings, $plan->targetSite),
            document: $plan->document,
            segments: $segments,
            timeout: $timeout,
            capMultiplier: $capMultiplier,
            attempt: $attempt,
        );
    }

    private function usage(EngineResult $result, ?Closure $recordUsage): UsageMap
    {
        if ($recordUsage !== null) {
            $recordUsage($result->model, $result->usage);
        }

        return (new UsageMap)->add($result->model, $result->usage);
    }

    /**
     * The unit's segments to translate.
     *
     * @return list<array>
     */
    private function segments(Plan $plan, Unit $unit): array
    {
        return array_map(fn (string $id) => [
            ...$plan->promptSegment($id),
            'text' => $plan->segments[$id]['text'],
            'note' => $unit->notes[$id] ?? null,
        ], $unit->segmentIds);
    }

    /**
     * The segments to review: the source text as `source`, and the translation under review.
     *
     * @param  array<string, string>  $translations
     * @return list<array>
     */
    private function reviewSegments(Plan $plan, array $translations): array
    {
        return array_map(fn (string $id, string $translation) => [
            ...$plan->promptSegment($id),
            'source' => $plan->segments[$id]['text'],
            'translation' => $translation,
        ], array_keys($translations), $translations);
    }

    /**
     * The state of every segment over all stored outcomes (§A8 step 3).
     *
     * A corrected segment keeps only the warnings of the review that corrected it: the others describe text that no
     * longer stands.
     *
     * @return array{translations: array<string, string>, failed: array<string, string>, corrections: array<string, array>, warnings: array<string, list<string>>, models: array<string, ?string>, reviewers: array<string, string>, pending: array<string, true>}
     */
    private function resolve(Plan $plan): array
    {
        $translations = $models = [];

        foreach ($plan->segments as $id => $segment) {
            if (is_string($segment['translation'] ?? null)) {
                $translations[$id] = $segment['translation'];
                // A reused translation counts for the model that made it.
                $models[$id] = $plan->fields[$segment['field']]['reused_translator'] ?? null;
            }
        }

        $failed = $corrections = $warnings = $correctedWarnings = $reviewers = $pending = [];

        foreach ($this->store->units($plan) as ['unit' => $unit, 'outcome' => $outcome]) {
            if ($outcome === null) {
                $pending += array_fill_keys($unit->segmentIds, true);

                continue;
            }

            foreach ($outcome->translations as $id => $text) {
                $translations[$id] = $text;
                $models[$id] = $outcome->model;
            }

            foreach ($outcome->corrections as $correction) {
                $corrections[$correction['id']] = $correction;
                $translations[$correction['id']] = $correction['after'];
                $correctedWarnings[$correction['id']] = $outcome->warnings[$correction['id']] ?? [];
            }

            if ($unit->kind === UnitKind::Review && $outcome->model !== null) {
                $reviewers += array_fill_keys($unit->segmentIds, $outcome->model);
            }

            foreach ($outcome->warnings as $id => $messages) {
                $warnings[$id] = [...($warnings[$id] ?? []), ...$messages];
            }

            $failed += $outcome->failed;
        }

        return [
            'translations' => $translations,
            'failed' => array_diff_key($failed, $translations),
            'corrections' => $corrections,
            'warnings' => array_replace($warnings, $correctedWarnings),
            'models' => $models,
            'reviewers' => $reviewers,
            'pending' => $pending,
        ];
    }

    /**
     * A reviewed field (T7.4): the value under review with only its corrected leaves replaced, each correction taken back
     * into the target's own numbering. A machine field keeps the source fingerprint, `segments` and translator of the
     * state record its text matched; a manual field stays manual, so its pending entry becomes a baseline.
     *
     * @param  array<string, array>  $segments  the field's segments
     *
     * @throws SourceChanged when the walks no longer give the planned leaves, because the blueprint changed
     */
    private function reviewed(string $planId, array $planned, Field $field, mixed $sourceValue, array $segments, array $resolved): AssembledField
    {
        $sourceLeaves = $this->walker->leaves($field, $sourceValue);
        $targetLeaves = $this->walker->leaves($field, $planned['value'], target: true);

        if (count($sourceLeaves) !== $planned['leaf_count'] || count($targetLeaves) !== $planned['leaf_count']) {
            throw SourceChanged::field($field->handle());
        }

        $texts = array_map(fn (Leaf $leaf) => $leaf->text, $targetLeaves);
        $corrections = $warnings = [];

        foreach ($segments as $id => $segment) {
            $source = $sourceLeaves[$segment['index']];
            $target = $targetLeaves[$segment['index']];

            if ($source->text !== $segment['text'] || Renumber::like($target, $source) !== $segment['translation']) {
                throw SourceChanged::field($field->handle());
            }

            array_push($warnings, ...($resolved['warnings'][$id] ?? []));

            if (isset($resolved['corrections'][$id])) {
                // A valid correction holds only placeholders of the translation, so it always renumbers back.
                $texts[$segment['index']] = Renumber::back($resolved['corrections'][$id]['after'], $target, $source);
                $corrections[] = $resolved['corrections'][$id];
            }
        }

        $value = $corrections === [] ? $planned['value'] : $this->walker->inject($field, $planned['value'], $texts, target: true);
        $targetHash = $this->resolver->targetHash($field, $value);
        $manual = $planned['status_before'] === FieldStatus::Manual->value;

        return new AssembledField(
            handle: $field->handle(),
            plan: $planId,
            value: $value,
            remove: false,
            sourceHash: $planned['source_hash'],
            targetHash: $targetHash,
            targetBefore: $planned['target_before'],
            segments: ! $manual && $this->aligned($field, $value, $texts) ? $planned['record']['segments'] : [],
            identical: ! $manual && $targetHash === $this->resolver->targetHash($field, $sourceValue),
            incomplete: false,
            translator: $planned['record']['translator'],
            corrections: $corrections,
            warnings: array_values(array_unique($warnings)),
            reviewer: $this->mostFrequent($resolved['reviewers'], $segments),
            baseline: $manual,
            unchanged: $corrections === [],
        );
    }

    /**
     * A field whose source lost its text: the override is removed when the source value is empty, and the source value
     * is copied when it only has no text (§A7).
     */
    private function cleared(string $planId, array $planned, Field $field, mixed $sourceValue, array $warnings): AssembledField
    {
        $remove = in_array(Fingerprint::canonical($sourceValue), [null, '', []], true);

        return new AssembledField(
            handle: $field->handle(),
            plan: $planId,
            value: $remove ? null : $sourceValue,
            remove: $remove,
            sourceHash: $planned['source_hash'],
            targetHash: $remove ? null : $this->resolver->targetHash($field, $sourceValue),
            targetBefore: $planned['target_before'],
            segments: [],
            identical: ! $remove,
            incomplete: false,
            translator: null,
            warnings: $warnings,
        );
    }

    /**
     * The alignment guard: the produced value, walked as a target (§A3), must yield the injected texts one by one and in
     * order, or reuse on a later update would pair leaves wrongly (§A7). Placeholder numbering and escaping may differ,
     * because the handler re-tokenizes the value it wrote.
     *
     * @param  list<string>  $texts
     */
    private function aligned(Field $field, mixed $value, array $texts): bool
    {
        $leaves = $this->walker->leaves($field, $value, target: true);

        if (count($leaves) !== count($texts)) {
            return false;
        }

        foreach ($leaves as $index => $leaf) {
            if (Placeholders::visible($leaf->text, $leaf->kind) !== Placeholders::visible($texts[$index], $leaf->kind)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The model that handled most of the field's segments; a tie goes to the one that handled an earlier segment.
     *
     * @param  array<string, ?string>  $models  segment id → model
     * @param  array<string, array>  $segments  the field's segments
     */
    private function mostFrequent(array $models, array $segments): ?string
    {
        return collect(array_keys($segments))->map(fn (string $id) => $models[$id] ?? null)->filter()->mode()[0] ?? null;
    }

    private function excerpt(array $segment): string
    {
        return Str::limit(Str::squish(Placeholders::visible($segment['text'], SegmentKind::from($segment['kind']))), 40);
    }
}
