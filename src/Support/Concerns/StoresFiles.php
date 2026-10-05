<?php

namespace Reach\StatamicTolk\Support\Concerns;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * The addon's own files, not Statamic content, read and written directly through `$this->files`. A write goes through a
 * temporary file and rename(), so a reader never sees half a file.
 */
trait StoresFiles
{
    private function readJson(string $path): ?array
    {
        try {
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (FileNotFoundException|JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  string  $what  names the file in the error, such as "the run file"
     */
    private function writeJson(string $path, array $data, string $what, bool $pretty = true): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

        $this->writeAtomically($path, json_encode($data, $flags | ($pretty ? JSON_PRETTY_PRINT : 0)), $what);
    }

    private function writeAtomically(string $path, string $contents, string $what): void
    {
        $temporary = $path.'.'.Str::random(16).'.tmp';

        $this->files->ensureDirectoryExists(dirname($path));

        try {
            if ($this->files->put($temporary, $contents) === false || ! $this->files->move($temporary, $path)) {
                throw new RuntimeException("Unable to write {$what} [{$path}].");
            }
        } finally {
            $this->files->delete($temporary);
        }
    }
}
