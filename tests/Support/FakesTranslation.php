<?php

namespace Reach\StatamicTolk\Tests\Support;

use Closure;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Gateway\FakeTextGateway;
use Reach\StatamicTolk\Ai\Agents\ReviewAgent;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;

trait FakesTranslation
{
    public const string FAKE_REVIEW_REASON = 'The fake reviewer corrected this segment.';

    private int $modelCallCount = 0;

    /**
     * Counts the prompts of every agent, faked or not. A test that fakes every event with `Event::fake()` hides them
     * from the count.
     */
    protected function setUpFakesTranslation(): void
    {
        Event::listen(PromptingAgent::class, function () {
            $this->modelCallCount++;
        });
    }

    /**
     * Answers every prompt of the translation agent with one translation per segment of its user JSON. The transform
     * gets the segment's text and the segment; returning null leaves the segment out of the answer.
     *
     * @param  (Closure(string, array): ?string)|null  $transform
     */
    protected function fakeTranslations(?Closure $transform = null): FakeTextGateway
    {
        $transform ??= fn (string $text) => '[T] '.$text;

        return TranslationAgent::fake(function (string $prompt) use ($transform) {
            $translations = [];

            foreach (json_decode($prompt, true, flags: JSON_THROW_ON_ERROR)['segments'] as $segment) {
                $text = $transform((string) ($segment['text'] ?? ''), $segment);

                if ($text !== null) {
                    $translations[] = ['id' => $segment['id'], 'text' => $text];
                }
            }

            return ['translations' => $translations];
        });
    }

    /**
     * Answers every prompt of the review agent with corrections for the segments of its user JSON. An array maps a
     * segment id to its corrected text, or to `text` and `reason`; ids the prompt does not hold are left out. A closure
     * gets the segment's source, its translation and the segment and returns the same, or null for no correction.
     *
     * @param  array<string, string|array{text: string, reason: string}>|(Closure(string, string, array): (string|array{text: string, reason: string}|null))  $corrections
     */
    protected function fakeReview(array|Closure $corrections = []): FakeTextGateway
    {
        $correct = $corrections instanceof Closure
            ? $corrections
            : fn (string $source, string $translation, array $segment) => $corrections[$segment['id']] ?? null;

        return ReviewAgent::fake(function (string $prompt) use ($correct) {
            $items = [];

            foreach (json_decode($prompt, true, flags: JSON_THROW_ON_ERROR)['segments'] as $segment) {
                $correction = $correct((string) ($segment['source'] ?? ''), (string) ($segment['translation'] ?? ''), $segment);

                if ($correction !== null) {
                    $items[] = ['id' => $segment['id'], ...(is_string($correction) ? ['text' => $correction, 'reason' => self::FAKE_REVIEW_REASON] : $correction)];
                }
            }

            return ['corrections' => $items];
        });
    }

    protected function modelCalls(): int
    {
        return $this->modelCallCount;
    }

    protected function assertNoModelCalls(): void
    {
        $this->assertSame(0, $this->modelCallCount, "Expected no model calls, {$this->modelCallCount} were made.");
    }
}
