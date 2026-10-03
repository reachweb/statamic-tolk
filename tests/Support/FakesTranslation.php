<?php

namespace Reach\StatamicTolk\Tests\Support;

use Closure;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Gateway\FakeTextGateway;
use Reach\StatamicTolk\Ai\Agents\TranslationAgent;

trait FakesTranslation
{
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

    protected function modelCalls(): int
    {
        return $this->modelCallCount;
    }

    protected function assertNoModelCalls(): void
    {
        $this->assertSame(0, $this->modelCallCount, "Expected no model calls, {$this->modelCallCount} were made.");
    }
}
