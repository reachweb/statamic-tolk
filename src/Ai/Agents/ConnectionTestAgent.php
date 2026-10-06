<?php

namespace Reach\StatamicTolk\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;

#[Strict]
class ConnectionTestAgent extends StructuredAgent
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->required(),
        ];
    }
}
