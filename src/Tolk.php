<?php

namespace Reach\StatamicTolk;

use Reach\StatamicTolk\Content\FieldtypeRegistry;

final class Tolk
{
    /**
     * Registers the handler of a fieldtype and adds Tolk's per-field options to that fieldtype. Call it from your addon's
     * `bootAddon()`, after your fieldtypes are registered: Statamic reads a fieldtype's config fields once per request.
     * A handler the host configures for the same handle in `tolk.fieldtypes` wins.
     *
     * @param  class-string<Contracts\FieldHandler>  $handlerClass
     */
    public static function registerFieldtype(string $handle, string $handlerClass): void
    {
        $registry = app(FieldtypeRegistry::class);

        if (! array_key_exists($handle, config('tolk.fieldtypes', []))) {
            $registry->register($handle, $handlerClass);
        }

        $registry->appendConfigFields();
    }
}
