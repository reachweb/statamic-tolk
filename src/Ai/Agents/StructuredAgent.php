<?php

namespace Reach\StatamicTolk\Ai\Agents;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;

abstract class StructuredAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public readonly ModelRef $model,
        public readonly string $instructions,
        public readonly array $providerOptions = [],
        public readonly int $timeout = 180,
        public readonly ?int $maxTokens = null,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * Built on every call, so the API key is never held in a property.
     */
    public function provider(): Provider
    {
        return Ai::build(app(Providers::class)->buildConfig($this->model->provider));
    }

    public function model(): string
    {
        return $this->model->model;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [...$this->cacheOptions(), ...$this->providerOptions];
    }

    /**
     * From GPT-5.6 on, OpenAI's default implicit mode writes the whole request to the cache at 1.25 times the input
     * price, and no later request reads it back, because each ends in a different user message. Explicit mode without
     * a breakpoint writes nothing (§A9). Earlier models refuse the key with a 400. A fine-tuned id (`ft:gpt-…`) gets
     * nothing, because no live check showed that fine-tunes accept the key; its catalog entry's options can add it.
     */
    private function cacheOptions(): array
    {
        if ($this->model->provider !== 'openai' || ! preg_match('/^gpt-(\d+(?:\.\d+)?)/', $this->model->model, $version)) {
            return [];
        }

        return version_compare($version[1], '5.6', '>=') ? ['prompt_cache_options' => ['mode' => 'explicit']] : [];
    }
}
