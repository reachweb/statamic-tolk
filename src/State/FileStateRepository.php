<?php

namespace Reach\StatamicTolk\State;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Support\Concerns\StoresFiles;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Facades\YAML;
use Statamic\Yaml\ParseException;
use Symfony\Component\Finder\Finder;

/**
 * The addon's own files, not Statamic content, so they are read and written directly.
 */
final class FileStateRepository implements StateRepository
{
    use StoresFiles;

    public function __construct(private readonly Filesystem $files) {}

    public function get(TargetRef $ref): ItemState
    {
        try {
            $data = $this->parse($this->path($ref));
        } catch (FileNotFoundException) {
            return ItemState::empty($ref);
        }

        return ($data['reference'] ?? null) === (string) $ref ? ItemState::fromArray($data) : ItemState::empty($ref);
    }

    public function update(TargetRef $ref, Closure $callback): ItemState
    {
        return $this->lock($ref)->block(5, function () use ($ref, $callback) {
            $stored = $this->get($ref);
            $state = $callback($stored);

            if (! $state instanceof ItemState || (string) $state->reference !== (string) $ref) {
                throw new LogicException("A state update of [{$ref}] must return the ItemState of that target.");
            }

            if ($state->isEmpty()) {
                $this->remove($ref);
            } elseif ($state->toArray() !== $stored->toArray()) {
                $this->write($ref, $state);
            }

            return $state;
        });
    }

    public function delete(TargetRef $ref): void
    {
        $this->lock($ref)->block(5, fn () => $this->remove($ref));
    }

    public function references(): iterable
    {
        if (! $this->files->isDirectory($this->root())) {
            return;
        }

        foreach (Finder::create()->files()->in($this->root())->name('*.yaml') as $file) {
            try {
                $reference = $this->parse($file->getPathname())['reference'] ?? null;
                $ref = TargetRef::parse(is_string($reference) ? $reference : '');
            } catch (FileNotFoundException|InvalidTargetRef|ParseException) {
                continue;
            }

            yield $ref;
        }
    }

    public function path(TargetRef $ref): string
    {
        return $this->root().'/'.$ref->storageKey().'.yaml';
    }

    private function parse(string $path): array
    {
        $data = YAML::file($path)->parse($this->files->get($path));

        return is_array($data) ? $data : [];
    }

    private function write(TargetRef $ref, ItemState $state): void
    {
        $path = $this->path($ref);
        $contents = YAML::dump($state->toArray());

        // Removing another target's empty state directory can race with this write, so it gets a second try.
        retry(2, fn () => $this->writeAtomically($path, $contents, 'the translation state file'));
    }

    private function remove(TargetRef $ref): void
    {
        $path = $this->path($ref);
        $root = $this->root();

        $this->files->delete($path);

        for ($directory = dirname($path); strlen($directory) > strlen($root) && str_starts_with($directory, $root); $directory = dirname($directory)) {
            if (! @rmdir($directory)) {
                return;
            }
        }
    }

    private function lock(TargetRef $ref): Lock
    {
        return Cache::store(config('tolk.cache_store'))->lock('tolk:state:'.sha1((string) $ref), 10);
    }

    private function root(): string
    {
        return rtrim(config('tolk.state.path'), '/\\');
    }
}
