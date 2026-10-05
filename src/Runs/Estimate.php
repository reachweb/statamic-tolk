<?php

namespace Reach\StatamicTolk\Runs;

/**
 * What translating one or more plans is expected to take (T8.2). Show these numbers only as an estimate: characters per
 * token vary by language, and no vendor publishes per-language figures (R6 §4c).
 */
final readonly class Estimate
{
    /**
     * @param  ?float  $cost  USD; null when a model that would be called has no price
     */
    public function __construct(
        public int $segments = 0,
        public int $chars = 0,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $cost = 0.0,
    ) {}

    public function add(self $estimate): self
    {
        return new self(
            $this->segments + $estimate->segments,
            $this->chars + $estimate->chars,
            $this->inputTokens + $estimate->inputTokens,
            $this->outputTokens + $estimate->outputTokens,
            $this->cost === null || $estimate->cost === null ? null : $this->cost + $estimate->cost,
        );
    }

    /**
     * @return array{segments: int, chars: int, input_tokens: int, output_tokens: int, cost: ?float}
     */
    public function toArray(): array
    {
        return [
            'segments' => $this->segments,
            'chars' => $this->chars,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost' => $this->cost,
        ];
    }

    public static function fromArray(array $data): self
    {
        $count = fn (string $key) => is_numeric($data[$key] ?? null) ? max(0, (int) $data[$key]) : 0;
        $cost = $data['cost'] ?? null;

        return new self(
            segments: $count('segments'),
            chars: $count('chars'),
            inputTokens: $count('input_tokens'),
            outputTokens: $count('output_tokens'),
            cost: match (true) {
                ! array_key_exists('cost', $data) => 0.0,
                is_numeric($cost) => max(0.0, (float) $cost),
                default => null,
            },
        );
    }
}
