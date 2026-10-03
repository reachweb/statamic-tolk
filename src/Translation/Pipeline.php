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
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\Finish;
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

        if ($unit->kind === UnitKind::Review) {
            throw new LogicException('Review units are not built yet (T7.3).');
        }

        $timeout = (int) config("tolk.http.timeout.{$plan->options->profile}", 45);
        $lock = $this->store->lock($plan->id, $unitId, $timeout + 10);

        if (! $lock->get()) {
            throw UnitBusy::for($plan->id, $unitId);
        }

        try {
            if ($outcome = $this->store->outcome($plan->id, $unitId)) {
                return $outcome;
            }

            $outcome = $this->call($plan, $unit, $timeout, $recordUsage);
            $this->store->putOutcome($plan->id, $outcome);

            return $outcome;
        } finally {
            $lock->release();
        }
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
            fn (string $handle) => array_intersect_key(array_flip($plan->fields[$handle]['segment_ids']), $pending) === [],
        ));
    }

    /**
     * Builds the field's target value from its current source value. Pass a target resolved after the units ran, so a
     * source edited meanwhile is noticed.
     *
     * @throws SourceChanged when the source value is not the one that was planned
     * @throws FieldFailed when a part failed for good and the plan is strict
     */
    public function assemble(Plan $plan, Target $target, string $handle): AssembledField
    {
        if ($plan->kind === UnitKind::Review) {
            throw new LogicException('Review plans are not built yet (T7.4).');
        }

        $planned = $plan->fields[$handle] ?? throw new InvalidArgumentException("The plan [{$plan->id}] has no field [{$handle}].");
        $field = $target->fields()->get($handle);
        $sourceValue = $target->sourceValue($handle);

        if ($field === null || Fingerprint::of($sourceValue) !== $planned['source_hash']) {
            throw SourceChanged::field($handle);
        }

        $resolved = $this->resolve($plan);
        $segments = $plan->segmentsOfField($handle);

        if (array_intersect_key($segments, $resolved['pending']) !== []) {
            throw new LogicException("The field [{$handle}] still has segments to translate.");
        }

        if ($planned['clear']) {
            return $this->cleared($plan, $field, $sourceValue);
        }

        // The same source value with another walk means the blueprint changed since planning.
        if (array_map(fn (Leaf $leaf) => $leaf->text, $this->walker->leaves($field, $sourceValue)) !== array_column($segments, 'text')) {
            throw SourceChanged::field($handle);
        }

        $texts = $hashes = $failed = $warnings = $models = [];

        foreach ($segments as $id => $segment) {
            $translation = $resolved['translations'][$id] ?? null;
            array_push($warnings, ...($resolved['warnings'][$id] ?? []));

            if ($translation === null) {
                $failed[$id] = $reason = $resolved['failed'][$id] ?? UnitOutcome::MISSING;
                $texts[] = $segment['text'];
                $hashes[] = null;
                $warnings[] = "{$this->label($segment)}: \"{$this->excerpt($segment['text'])}\" was left untranslated ({$reason}).";

                continue;
            }

            $texts[] = $translation;
            $hashes[] = Fingerprint::ofText($segment['text']);

            if (isset($resolved['models'][$id])) {
                $models[] = $resolved['models'][$id];
            }
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
            identical: $texts === array_column($segments, 'text'),
            incomplete: $failed !== [],
            translator: $this->mostUsed($models),
            corrections: array_values(array_intersect_key($resolved['corrections'], $segments)),
            warnings: array_values(array_unique($warnings)),
        );
    }

    private function call(Plan $plan, Unit $unit, int $timeout, ?Closure $recordUsage): UnitOutcome
    {
        $failures = $this->store->failures($plan->id, $unit->id);
        $model = $this->model($plan, $unit, $failures);
        $fallback = $plan->options->fallback !== null && (string) $model === (string) $plan->options->fallback;

        try {
            $result = $this->engine->translate(new EngineCall(
                model: $model,
                source: $this->languages->forSite($plan->sourceSite),
                target: $this->languages->forSite($plan->targetSite),
                context: PromptContext::fromSettings($this->settings, $plan->targetSite),
                document: $plan->document,
                segments: $this->segments($plan, $unit),
                timeout: $timeout,
                capMultiplier: $unit->capMultiplier,
                attempt: $failures + 1,
            ));
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
            return $exception->tooLarge() && $unit->canSplit()
                ? new UnitOutcome(unit: $unit->id, model: (string) $model, spawned: $unit->halves($fallback))
                : new UnitOutcome(unit: $unit->id, model: (string) $model, failed: $this->failAll($unit, UnitOutcome::REQUEST_REJECTED));
        }

        if ($recordUsage !== null) {
            $recordUsage($result->model, $result->usage);
        }

        $usage = (new UsageMap)->add($result->model, $result->usage);

        return match ($result->finish) {
            Finish::Stop => $this->accept($plan, $unit, $result, $usage, $fallback),
            Finish::Length, Finish::Empty => match (true) {
                $unit->canSplit() => new UnitOutcome(unit: $unit->id, model: (string) $model, usage: $usage, spawned: $unit->halves($fallback)),
                $unit->capMultiplier === 1 => new UnitOutcome(unit: $unit->id, model: (string) $model, usage: $usage, spawned: [$unit->withDoubledCap($fallback)]),
                default => new UnitOutcome(unit: $unit->id, model: (string) $model, failed: $this->failAll($unit, UnitOutcome::TOO_LONG), usage: $usage),
            },
            Finish::Refused => $unit->canSplit()
                ? new UnitOutcome(unit: $unit->id, model: (string) $model, usage: $usage, spawned: $unit->halves($fallback))
                : new UnitOutcome(unit: $unit->id, model: (string) $model, failed: $this->failAll($unit, UnitOutcome::REFUSED), usage: $usage),
        };
    }

    /**
     * Validates each translation of a finished call: invalid ones get a repair, missing ids one more request.
     */
    private function accept(Plan $plan, Unit $unit, EngineResult $result, UsageMap $usage, bool $fallback): UnitOutcome
    {
        $translations = $failed = $warnings = $spawned = $missing = [];

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

                $warnings[$id][] = "{$this->label($segment)}: the translation of \"{$this->excerpt($segment['text'])}\" is longer than {$segment['max_length']} characters.";
            }

            $translations[$id] = $text;
        }

        if ($missing !== [] && $unit->missingRetry) {
            $failed += array_fill_keys($missing, UnitOutcome::MISSING);
        } elseif ($missing !== []) {
            $spawned[] = $unit->forMissing($missing, $fallback);
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
     * From `retry.max_attempts` transient failures on, and for units spawned by a fallback answer, the fallback takes
     * over while its provider is not paused (§A10).
     */
    private function model(Plan $plan, Unit $unit, int $failures): ModelRef
    {
        $fallback = $plan->options->fallback;
        $wanted = $unit->useFallback || $failures >= (int) config('tolk.retry.max_attempts', 3);

        return $fallback !== null && $wanted && $this->throttle->pausedFor($fallback->provider) === 0
            ? $fallback
            : $plan->options->translator;
    }

    /**
     * The unit's segments with the keys of §A9. The prompt's `field` is the label path, which says what the text is for.
     *
     * @return list<array>
     */
    private function segments(Plan $plan, Unit $unit): array
    {
        return array_map(function (string $id) use ($plan, $unit) {
            $segment = $plan->segments[$id];

            return [
                'id' => $id,
                'field' => $this->label($segment),
                'kind' => $segment['kind'],
                'hint' => $segment['hint'] ?? null,
                'max_length' => $segment['max_length'] ?? null,
                'before' => $segment['before'] ?? null,
                'after' => $segment['after'] ?? null,
                'text' => $segment['text'],
                'note' => $unit->notes[$id] ?? null,
            ];
        }, $unit->segmentIds);
    }

    /**
     * @return array<string, string>
     */
    private function failAll(Unit $unit, string $reason): array
    {
        return array_fill_keys($unit->segmentIds, $reason);
    }

    /**
     * The state of every segment over all stored outcomes (§A8 step 3).
     *
     * @return array{translations: array<string, string>, failed: array<string, string>, corrections: array<string, array>, warnings: array<string, list<string>>, models: array<string, string>, pending: array<string, true>}
     */
    private function resolve(Plan $plan): array
    {
        $translations = array_filter(
            array_map(fn (array $segment) => $segment['translation'] ?? null, $plan->segments),
            'is_string',
        );
        $failed = $corrections = $warnings = $models = $pending = [];

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
            'warnings' => $warnings,
            'models' => array_filter($models, 'is_string'),
            'pending' => $pending,
        ];
    }

    /**
     * A field whose source lost its text: the override is removed when the source value is empty, and the source value
     * is copied when it only has no text (§A7).
     */
    private function cleared(Plan $plan, Field $field, mixed $sourceValue): AssembledField
    {
        $planned = $plan->fields[$field->handle()];
        $remove = in_array(Fingerprint::canonical($sourceValue), [null, '', []], true);

        return new AssembledField(
            handle: $field->handle(),
            plan: $plan->id,
            value: $remove ? null : $sourceValue,
            remove: $remove,
            sourceHash: $planned['source_hash'],
            targetHash: $remove ? null : $this->resolver->targetHash($field, $sourceValue),
            targetBefore: $planned['target_before'],
            segments: [],
            identical: ! $remove,
            incomplete: false,
            translator: null,
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
            if ($this->visible($leaf->text) !== $this->visible($texts[$index])) {
                return false;
            }
        }

        return true;
    }

    private function visible(string $text): string
    {
        return Tokenizer::unescape(Placeholders::strip($text));
    }

    /**
     * @param  list<string>  $models
     */
    private function mostUsed(array $models): ?string
    {
        if ($models === []) {
            return null;
        }

        $counts = array_count_values($models);

        return array_search(max($counts), $counts, true);
    }

    private function label(array $segment): string
    {
        return ($segment['label'] ?? '') !== '' ? $segment['label'] : $segment['field'];
    }

    private function excerpt(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $this->visible($text))), 40);
    }
}
