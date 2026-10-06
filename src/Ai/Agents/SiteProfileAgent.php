<?php

namespace Reach\StatamicTolk\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;

#[Strict]
class SiteProfileAgent extends StructuredAgent
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'profile' => $schema->string()->required(),
            'terms' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
