<?php

namespace Reach\StatamicTolk\Ai;

use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Stringable;

final readonly class ModelRef implements Stringable
{
    public const array PROVIDERS = ['anthropic', 'openai', 'gemini'];

    public function __construct(
        public string $provider,
        public string $model,
    ) {
        if (! in_array($provider, self::PROVIDERS, true) || $model === '' || preg_match('/\s/', $model)) {
            throw InvalidModelRef::for($provider.':'.$model);
        }
    }

    public static function fromString(string $reference): self
    {
        if (! str_contains($reference, ':')) {
            throw InvalidModelRef::for($reference);
        }

        [$provider, $model] = explode(':', $reference, 2);

        return new self($provider, $model);
    }

    public function __toString(): string
    {
        return $this->provider.':'.$this->model;
    }
}
