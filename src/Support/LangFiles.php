<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Filesystem\Filesystem;
use Reach\StatamicTolk\Exceptions\InvalidLangFile;
use Reach\StatamicTolk\Support\Concerns\StoresFiles;
use Statamic\Support\Arr;
use Throwable;

/**
 * The host's Laravel translation files under lang_path(), and the only code that reads or writes them (§A16). They are
 * not Statamic content, so they are read and written directly. The group `_json` is `lang/{lang}.json`; any other group
 * is `lang/{lang}/{group}.php`, whose nested arrays read as dot keys. `lang/vendor/` is out of scope.
 */
final class LangFiles
{
    use StoresFiles;

    public const string JSON = '_json';

    private const string INDENT = '    ';

    public function __construct(private readonly Filesystem $files) {}

    /**
     * `_json` when `lang/{lang}.json` exists, then the PHP files in `lang/{lang}/` by name.
     *
     * @return list<string>
     */
    public function groups(string $lang): array
    {
        $groups = $this->exists($lang, self::JSON) ? [self::JSON] : [];
        $directory = lang_path($lang);

        if (! $this->files->isDirectory($directory)) {
            return $groups;
        }

        foreach ($this->files->files($directory) as $file) {
            $group = $file->getFilenameWithoutExtension();

            // A colon cannot be part of a reference (lang::{group}::{lang}), and `_json` names the JSON file.
            if ($file->getExtension() === 'php' && $group !== self::JSON && ! str_contains($group, ':')) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function exists(string $lang, string $group): bool
    {
        return $this->files->isFile($this->path($lang, $group));
    }

    public function path(string $lang, string $group): string
    {
        return lang_path(self::relative($lang, $group));
    }

    /**
     * The file as the commands name it: `lang/de.json`, `lang/de/messages.php`.
     */
    public static function name(string $lang, string $group): string
    {
        return 'lang/'.self::relative($lang, $group);
    }

    private static function relative(string $lang, string $group): string
    {
        return $group === self::JSON ? "{$lang}.json" : "{$lang}/{$group}.php";
    }

    /**
     * Flat key → value, empty for a missing file. JSON is decoded; a PHP file is included, and its nested arrays are
     * flattened to dot keys.
     *
     * @throws InvalidLangFile for a file that is not a JSON object or does not return an array
     */
    public function read(string $lang, string $group): array
    {
        return self::flat($group, $this->contents($lang, $group));
    }

    /**
     * The array the file holds, empty for a missing file: a PHP file's nested arrays stay nested, and a key that holds a
     * dot stays one key.
     *
     * @throws InvalidLangFile for a file that is not a JSON object or does not return an array
     */
    public function contents(string $lang, string $group): array
    {
        if (! $this->exists($lang, $group)) {
            return [];
        }

        $path = $this->path($lang, $group);

        try {
            $data = $group === self::JSON
                ? json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR)
                : $this->files->getRequire($path);
        } catch (Throwable $exception) {
            throw InvalidLangFile::unreadable(self::name($lang, $group), $exception);
        }

        if (! is_array($data)) {
            throw InvalidLangFile::unreadable(self::name($lang, $group));
        }

        return $data;
    }

    /**
     * Re-reads the file, sets and removes the given keys and keeps every other key with its value (§A16). Keys follow
     * the source's order, then the keys the source lacks in their own order; a PHP file puts each key where its source
     * has it, nested or as a key that holds a dot, and a key the source lacks where the file had it, unless the source
     * nests its keys differently there: one array cannot hold a string and keys nested under it, and Laravel looks up
     * the source's keys, so such a key of the file is dropped. The file is replaced through a temporary file, so it is
     * never half-written, and a failure leaves it as it was.
     *
     * @param  array<string, string>  $set  flat key → value
     * @param  list<string>  $remove
     * @param  array<string, mixed>  $sourceShape  the source file's array, as contents() gives it
     * @param  list<int|string>  $carry  the flat keys of the source's values that have nothing to translate
     * @return list<int|string> the keys only the file had that were dropped
     *
     * @throws InvalidLangFile also when the file cannot hold every key of $set with its value
     */
    public function write(string $lang, string $group, array $set, array $remove, array $sourceShape, array $carry = []): array
    {
        $file = $this->contents($lang, $group);
        $source = self::flat($group, $sourceShape);
        $values = array_replace(array_diff_key(self::flat($group, $file), array_flip($remove)), $set);
        // Values with nothing to translate are no fields: the source's are carried over as they are where the file has none.
        $values += array_intersect_key($source, array_flip($carry));
        $values = array_replace(array_intersect_key($source, $values), $values);
        $name = self::name($lang, $group);
        $path = $this->path($lang, $group);
        $dropped = [];

        if ($group === self::JSON) {
            $contents = self::json($values);
        } else {
            $sourcePlaces = self::places($sourceShape);
            [$nested, $dropped] = self::nest($values, array_diff_key($values, $sourcePlaces, $set), $sourcePlaces + self::places($file));
            self::holds($nested, $set, $name);
            $contents = self::php($nested, $name);
        }

        $this->writeAtomically($path, $contents, "the translation file {$name}");

        // A long-lived process with OPcache for the CLI would otherwise include the old file for a while.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return $dropped;
    }

    /**
     * Whether writing the file keeps its layout: false for an existing PHP file that write() did not produce, whose
     * comments and formatting a rewrite loses.
     */
    public function keepsLayout(string $lang, string $group): bool
    {
        if ($group === self::JSON || ! $this->exists($lang, $group)) {
            return true;
        }

        try {
            return $this->files->get($this->path($lang, $group)) === self::php($this->contents($lang, $group), self::name($lang, $group));
        } catch (InvalidLangFile) {
            return false;
        }
    }

    private static function flat(string $group, array $data): array
    {
        return $group === self::JSON ? $data : Arr::dot($data);
    }

    /**
     * Where each key of a PHP file's flat array is in the file: the keys of its path, any of which may hold a dot.
     *
     * @return array<string, list<int|string>>
     */
    private static function places(array $data, array $path = []): array
    {
        $places = [];

        foreach ($data as $key => $value) {
            $place = [...$path, $key];
            // As Arr::dot() flattens: an empty array is a value.
            $places += is_array($value) && $value !== [] ? self::places($value, $place) : [implode('.', $place) => $place];
        }

        return $places;
    }

    /**
     * The flat values in their places; a key without one is nested at every dot, as Arr::undot() nests it. Laravel
     * finds a key either as one key that holds its dots or nested at every dot, so neither may become the other. The
     * keys only the file had go last, each where nothing else is: one that would replace a value or the keys nested
     * under it is dropped.
     *
     * @param  array<int|string, mixed>  $kept  the values only the file had
     * @param  array<string, list<int|string>>  $places
     * @return array{0: array, 1: list<int|string>} the nested values, and the keys of $kept that were dropped
     */
    private static function nest(array $values, array $kept, array $places): array
    {
        $nested = $dropped = [];

        foreach ([array_diff_key($values, $kept), $kept] as $pass => $group) {
            foreach ($group as $key => $value) {
                $path = $places[$key] ?? explode('.', (string) $key);

                if ($pass === 1 && self::taken($nested, $path)) {
                    $dropped[] = $key;
                } else {
                    $nested = self::place($nested, $path, $value);
                }
            }
        }

        return [$nested, $dropped];
    }

    /**
     * Whether a value at the path would replace one: there is a value at it or above it, or keys nested under it.
     *
     * @param  list<int|string>  $path
     */
    private static function taken(array $array, array $path): bool
    {
        $key = array_shift($path);

        if (! array_key_exists($key, $array)) {
            return false;
        }

        return $path === [] || ! is_array($array[$key]) || self::taken($array[$key], $path);
    }

    /**
     * The values set must read back as they were set, or write() would report translations the file does not hold.
     *
     * @param  array<string, string>  $set
     *
     * @throws InvalidLangFile
     */
    private static function holds(array $nested, array $set, string $name): void
    {
        $written = Arr::dot($nested);

        foreach ($set as $key => $value) {
            if (($written[$key] ?? null) !== $value) {
                throw InvalidLangFile::unholdable($name, (string) $key);
            }
        }
    }

    /**
     * @param  list<int|string>  $path
     */
    private static function place(array $array, array $path, mixed $value): array
    {
        $key = array_shift($path);
        $array[$key] = $path === [] ? $value : self::place(is_array($array[$key] ?? null) ? $array[$key] : [], $path, $value);

        return $array;
    }

    private static function json(array $values): string
    {
        // An object also when it is empty or its keys are 0, 1, …
        return json_encode((object) $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Every string and key goes through var_export(), so no value can end the string it is in or run code.
     */
    private static function php(array $values, string $name): string
    {
        return "<?php\n\nreturn ".self::export($values, $name, 0).";\n";
    }

    private static function export(array $values, string $name, int $depth): string
    {
        if ($values === []) {
            return '[]';
        }

        $list = array_is_list($values);
        $indent = str_repeat(self::INDENT, $depth + 1);
        $lines = '';

        foreach ($values as $key => $value) {
            $lines .= $indent.($list ? '' : var_export($key, true).' => ').match (true) {
                is_array($value) => self::export($value, $name, $depth + 1),
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value), is_int($value), is_float($value) => var_export($value, true),
                default => throw InvalidLangFile::unexportable($name, (string) $key),
            }.",\n";
        }

        return "[\n{$lines}".str_repeat(self::INDENT, $depth).']';
    }
}
