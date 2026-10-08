<?php

namespace Reach\StatamicTolk\Runs;

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Prompts\PromptBuilder;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Support\Languages;
use Reach\StatamicTolk\Translation\Plan;

/**
 * Estimates the tokens and cost of a plan before it runs (D19), from characters: tokens = characters ÷
 * `tokens.chars_per_token`. Every batch is one call that carries the whole system prompt, and with review on one review
 * call more. Cache discounts are never assumed (§A9).
 */
final readonly class Estimator
{
    /**
     * Output tokens per source token: a translation is longer than its source, and German or Greek take more tokens per
     * character than English (1.25 to 1.7 on four models in T13.4).
     */
    public const float OUTPUT_RATIO = 1.5;

    /**
     * Output tokens per segment for the JSON around its translation (15 to 26 in T13.4).
     */
    public const int SEGMENT_OUTPUT_TOKENS = 20;

    /**
     * Review output tokens per segment token: the reviewer returns only the segments it corrects, but each in full and
     * with a reason. Correcting one segment in nine, as in T13.4, took this much.
     */
    public const float REVIEW_OUTPUT_RATIO = 1.0;

    public function __construct(
        private PromptBuilder $prompts,
        private Languages $languages,
        private Pricing $pricing,
    ) {}

    /**
     * Counts only the segments of the plan's batches: a reused segment already has its translation and costs nothing.
     * A review plan (mode review) makes only review calls.
     */
    public function forPlan(Plan $plan, PromptContext $context): Estimate
    {
        if ($plan->batches === []) {
            return new Estimate;
        }

        $source = $this->languages->forSite($plan->sourceSite);
        $target = $this->languages->forSite($plan->targetSite);
        $translates = $plan->kind === UnitKind::Translate;
        $reviews = ! $translates || $plan->options->review;

        $system = $translates ? mb_strlen($this->prompts->system($source, $target, $context)) : 0;
        $reviewSystem = $reviews ? mb_strlen($this->prompts->reviewSystem($source, $target, $context)) : 0;
        $translation = $review = new Usage;
        $segments = $chars = 0;

        foreach ($plan->batches as $batch) {
            $ids = $batch['segment_ids'];
            $batchChars = array_sum(array_map(fn (string $id) => mb_strlen($plan->segments[$id]['text']), $ids));
            $segments += count($ids);
            $chars += $batchChars;

            if ($translates) {
                $translation = $translation->add(new Usage(
                    input: $this->tokens($system + $this->userChars($plan, $ids, $this->translationSegment(...))),
                    output: $this->tokens($batchChars * self::OUTPUT_RATIO) + count($ids) * self::SEGMENT_OUTPUT_TOKENS,
                ));
            }

            if ($reviews) {
                $review = $review->add(new Usage(
                    input: $this->tokens($reviewSystem + $this->userChars($plan, $ids, $this->reviewSegment(...))),
                    output: $this->tokens($batchChars * self::REVIEW_OUTPUT_RATIO),
                ));
            }
        }

        $costs = [
            $this->cost($plan->options->translator, $translation),
            $this->cost($plan->options->reviewer ?? $plan->options->translator, $review),
        ];

        return new Estimate(
            segments: $segments,
            chars: $chars,
            inputTokens: $translation->input + $review->input,
            outputTokens: $translation->output + $review->output,
            cost: in_array(null, $costs, true) ? null : array_sum($costs),
        );
    }

    /**
     * @param  list<string>  $ids
     */
    private function userChars(Plan $plan, array $ids, callable $segment): int
    {
        return mb_strlen($this->prompts->user($plan->document, array_map(fn (string $id) => $segment($plan, $id), $ids)));
    }

    private function translationSegment(Plan $plan, string $id): array
    {
        return [...$plan->promptSegment($id), 'text' => $plan->segments[$id]['text']];
    }

    /**
     * Before the translation exists, its source text stands in for it.
     */
    private function reviewSegment(Plan $plan, string $id): array
    {
        $segment = $plan->segments[$id];

        return [...$plan->promptSegment($id), 'source' => $segment['text'], 'translation' => $segment['translation'] ?? $segment['text']];
    }

    private function cost(ModelRef $model, Usage $usage): ?float
    {
        return $usage->input + $usage->output === 0 ? 0.0 : $this->pricing->cost($model, $usage);
    }

    private function tokens(float|int $chars): int
    {
        $charsPerToken = (float) config('tolk.tokens.chars_per_token', 3);

        return (int) ceil($chars / ($charsPerToken > 0 ? $charsPerToken : 3.0));
    }
}
