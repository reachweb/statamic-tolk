<?php

namespace Reach\StatamicTolk\Content;

use Illuminate\Contracts\Container\Container;
use Reach\StatamicTolk\Content\Handlers\NavTreeHandler;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Exceptions\InvalidFieldHandler;
use Statamic\Fields\Field;

final class FieldtypeRegistry
{
    // The CP translates config field displays and instructions itself, so they are English keys, not __() at boot.
    public const array CONFIG_FIELDS = [
        'tolk_translate' => [
            'type' => 'toggle',
            'display' => 'Translate automatically',
            'default' => true,
            'width' => 50,
        ],
        'tolk_hint' => [
            'type' => 'text',
            'display' => 'Translation hint',
            'instructions' => 'Guidance for the translator, for example: product name, keep in English',
            'width' => 50,
        ],
    ];

    /**
     * The handlers of synthetic fields, which a target builds itself and no fieldtype stands for. Such a field names
     * its handler in its config as `tolk_handler`.
     */
    public const array INTERNAL_HANDLERS = [
        NavTreeHandler::HANDLE => NavTreeHandler::class,
    ];

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

    // Whether a walk translates the field: its fieldtype has a handler and its "Translate automatically" option is on.
    public function translates(Field $field): bool
    {
        return $this->handlerClass($field) !== null && $field->fieldtype()->config('tolk_translate') !== false;
    }

    public function for(Field $field): ?FieldHandler
    {
        $handlerClass = $this->handlerClass($field);

        return $handlerClass === null ? null : $this->container->make($handlerClass);
    }

    public function handles(): array
    {
        return array_keys($this->handlers);
    }

    private function handlerClass(Field $field): ?string
    {
        $internal = $field->get('tolk_handler');

        return is_string($internal) && isset(self::INTERNAL_HANDLERS[$internal])
            ? self::INTERNAL_HANDLERS[$internal]
            : $this->handlers[$field->type()] ?? null;
    }

    /**
     * Appends the per-field options to the fieldtype class of every registered handle. Statamic keys extra config
     * fields by the exact class (Bard needs its own call although it extends Replicator), and merges them by handle,
     * so calling this again changes nothing. A handle whose fieldtype is not registered yet is skipped: another
     * addon may register it after this one boots.
     */
    public function appendConfigFields(): void
    {
        $classes = $this->container->make('statamic.fieldtypes');

        foreach ($this->handles() as $handle) {
            if (is_string($class = $classes->get($handle))) {
                $class::appendConfigFields(self::CONFIG_FIELDS);
            }
        }
    }
}
