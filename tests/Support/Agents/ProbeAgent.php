<?php

namespace Reach\StatamicTolk\Tests\Support\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

#[Strict]
class ProbeAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public readonly string $providerName,
        public readonly string $modelId,
        public readonly string $systemPrompt = 'Translate every segment and return one translation per segment with the same id.',
        public readonly array $options = [],
        public readonly int $timeoutSeconds = 60,
        public readonly ?int $outputCap = null,
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    public function provider(): Provider
    {
        $config = [
            'name' => "tolk_{$this->providerName}",
            'driver' => $this->providerName,
            'key' => config("ai.providers.{$this->providerName}.key"),
        ];

        if ($this->providerName === 'openai') {
            $config['store'] = false;
        }

        return Ai::build($config);
    }

    public function model(): string
    {
        return $this->modelId;
    }

    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }

    public function maxTokens(): ?int
    {
        return $this->outputCap;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->options;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translations' => $schema->array()->items(
                $schema->object(fn (JsonSchema $schema) => [
                    'id' => $schema->string()->required(),
                    'text' => $schema->string()->required(),
                ])
            )->required(),
        ];
    }
}
