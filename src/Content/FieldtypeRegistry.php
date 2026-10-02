<?php

namespace Reach\StatamicTolk\Content;

use Illuminate\Contracts\Container\Container;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Exceptions\InvalidFieldHandler;
use Statamic\Fields\Field;

final class FieldtypeRegistry
{
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $handle, string $handlerClass): void
    {
        if (! is_a($handlerClass, FieldHandler::class, true)) {
            throw InvalidFieldHandler::for($handle, $handlerClass);
        }

        $this->handlers[$handle] = $handlerClass;
    }

    public function has(string $handle): bool
    {
        return isset($this->handlers[$handle]);
    }

    public function for(Field $field): ?FieldHandler
    {
        $handlerClass = $this->handlers[$field->type()] ?? null;

        return $handlerClass === null ? null : $this->container->make($handlerClass);
    }

    public function handles(): array
    {
        return array_keys($this->handlers);
    }
}
