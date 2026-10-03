<?php

namespace Reach\StatamicTolk\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\Strict;

#[Strict]
#[CacheInstructions]
class TranslationAgent extends StructuredAgent
{
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
