<?php

namespace Reach\StatamicTolk\Ai;

use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Support\Language;

final readonly class EngineCall
{
    /**
     * @param  list<array>  $segments  segments with the keys of §A9
     * @param  int  $attempt  1 + the transient failures of this unit so far; sets the backoff when the provider sends no retry-after
     */
    public function __construct(
        public ModelRef $model,
        public Language $source,
        public Language $target,
        public PromptContext $context,
        public array $document,
        public array $segments,
        public int $timeout,
        public int $capMultiplier = 1,
        public int $attempt = 1,
    ) {}

    /**
     * The `text` of translation segments, the `source` of review segments.
     */
    public function sourceChars(): int
    {
        return $this->chars(fn (array $segment) => $segment['text'] ?? $segment['source'] ?? '');
    }

    public function translationChars(): int
    {
        return $this->chars(fn (array $segment) => $segment['translation'] ?? '');
    }

    private function chars(callable $text): int
    {
        return array_sum(array_map(fn (array $segment) => mb_strlen((string) $text($segment)), $this->segments));
    }
}
