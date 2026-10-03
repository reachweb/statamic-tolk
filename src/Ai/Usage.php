<?php

namespace Reach\StatamicTolk\Ai;

use Laravel\Ai\Responses\TextResponse;

/**
 * Tokens of one or more provider calls. As in laravel/ai 1.x, `input` includes the cache reads and writes and `output`
 * includes the reasoning tokens (R2 §12.2, T2.1).
 */
final readonly class Usage
{
    private const array KEYS = [
        'input' => 'input',
        'output' => 'output',
        'cache_read' => 'cacheRead',
        'cache_write' => 'cacheWrite',
        'reasoning' => 'reasoning',
        'calls' => 'calls',
    ];

    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $reasoning = 0,
        public int $calls = 0,
    ) {}

    public static function fromResponse(TextResponse $response): self
    {
        $usage = $response->usage;

        return new self(
            input: $usage->inputTokens,
            output: $usage->outputTokens,
            cacheRead: $usage->cacheReadInputTokens ?? 0,
            cacheWrite: $usage->cacheWriteInputTokens ?? 0,
            reasoning: $usage->reasoningTokens ?? 0,
            calls: 1,
        );
    }

    public function add(self $usage): self
    {
        return new self(
            $this->input + $usage->input,
            $this->output + $usage->output,
            $this->cacheRead + $usage->cacheRead,
            $this->cacheWrite + $usage->cacheWrite,
            $this->reasoning + $usage->reasoning,
            $this->calls + $usage->calls,
        );
    }

    /**
     * @return array{input: int, output: int, cache_read: int, cache_write: int, reasoning: int, calls: int}
     */
    public function toArray(): array
    {
        return array_map(fn (string $property) => $this->{$property}, self::KEYS);
    }

    public static function fromArray(array $data): self
    {
        return new self(...array_map(
            fn (string $key) => is_numeric($data[$key] ?? null) ? max(0, (int) $data[$key]) : 0,
            array_flip(self::KEYS),
        ));
    }
}
