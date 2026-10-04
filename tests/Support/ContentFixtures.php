<?php

namespace Reach\StatamicTolk\Tests\Support;

use InvalidArgumentException;
use Statamic\Facades\Fieldset;
use Statamic\Fields\Field;

/**
 * The golden fixtures: `tests/Fixtures/content/{name}.php` returns a blueprint field config and a raw
 * stored value, `tests/Fixtures/markup/*` holds tokenizer samples, and `tests/Fixtures/fieldsets/`
 * the fieldsets the content fixtures import as `tolk_fixtures::{handle}`.
 */
final class ContentFixtures
{
    public const string FIELDSET_NAMESPACE = 'tolk_fixtures';

    private const string ROOT = __DIR__.'/../Fixtures';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (string $path) => basename($path, '.php'), self::glob('content/*.php'));
    }

    /**
     * @return array{field: array, value: mixed}
     */
    public static function raw(string $name): array
    {
        $path = self::ROOT."/content/{$name}.php";

        if (! is_file($path)) {
            throw new InvalidArgumentException("There is no content fixture [{$name}].");
        }

        return require $path;
    }

    /**
     * The fixture's field, with the handle `{name}` in snake case. Registers the fixtures' fieldsets.
     */
    public static function field(string $name): Field
    {
        Fieldset::addNamespace(self::FIELDSET_NAMESPACE, self::ROOT.'/fieldsets');

        return new Field(str_replace('-', '_', $name), self::raw($name)['field']);
    }

    public static function value(string $name): mixed
    {
        return self::raw($name)['value'];
    }

    /**
     * The tokenizer samples, file name => contents, optionally only those with the given extension.
     *
     * @return array<string, string>
     */
    public static function markup(?string $extension = null): array
    {
        $samples = [];

        foreach (self::glob('markup/*.'.($extension ?? '*')) as $path) {
            $samples[basename($path)] = file_get_contents($path);
        }

        return $samples;
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $paths = glob(self::ROOT.'/'.$pattern) ?: [];
        sort($paths);

        return $paths;
    }
}
