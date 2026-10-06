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

        return $group === self::JSON ? $data : Arr::dot($data);
    }

    /**
     * Re-reads the file, sets and removes the given keys and keeps every other key with its value (§A16). Keys follow
     * the source's order, then the keys the source lacks in their own order; a PHP file nests dot keys again, as its
     * source does. The file is replaced through a temporary file, so it is never half-written, and a failure leaves it
     * as it was.
     *
     * @param  array<string, string>  $set  flat key → value
     * @param  list<string>  $remove
     * @param  array<string, mixed>  $sourceShape  the source file's flat keys and values, as read() gives them
     *
     * @throws InvalidLangFile
     */
    public function write(string $lang, string $group, array $set, array $remove, array $sourceShape): void
    {
        $values = array_replace(array_diff_key($this->read($lang, $group), array_flip($remove)), $set);
        // Values that are not text are no fields: the source's are carried over as they are where the file has none.
        $values += array_filter($sourceShape, fn (mixed $value) => ! is_string($value));
        $values = array_replace(array_intersect_key($sourceShape, $values), $values);
        $name = self::name($lang, $group);
        $path = $this->path($lang, $group);

        $this->writeAtomically($path, $group === self::JSON ? self::json($values) : self::php(Arr::undot($values), $name), "the translation file {$name}");

        // A long-lived process with OPcache for the CLI would otherwise include the old file for a while.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
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
            return $this->files->get($this->path($lang, $group)) === self::php(Arr::undot($this->read($lang, $group)), self::name($lang, $group));
        } catch (InvalidLangFile) {
            return false;
        }
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
