<?php

namespace Reach\StatamicTolk\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\Strict;

#[Strict]
#[CacheInstructions]
class ReviewAgent extends StructuredAgent
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'corrections' => $schema->array()->items(
                $schema->object(fn (JsonSchema $schema) => [
                    'id' => $schema->string()->required(),
                    'text' => $schema->string()->required(),
                    'reason' => $schema->string()->required(),
                ])
            )->required(),
        ];
    }
}
