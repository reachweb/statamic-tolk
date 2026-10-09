<?php

namespace Reach\StatamicTolk\Targets\Concerns;

use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Fields\Field;
use Throwable;

/**
 * What LangTarget and FormsTarget share (§A16): the target is the translation file group() in `$lang`, read and written
 * through the LangFiles in `$files`, translated from `$sourceLang`, with the sites `$site` and `$sourceSite` for the
 * prompts and no CP surface. The using class names the reference in targetRef() and the source file whose order and
 * nesting the written file follows in sourceShape().
 */
trait WritesLangFile
{
    use AppliesFields;

    private const string FIELDTYPE = 'text';

    private ?array $source = null;

    private ?array $target = null;

    abstract private function group(): string;

    abstract private function targetRef(): TargetRef;

    /**
     * @return array<string, mixed> the source file's array, as LangFiles::write() takes it
     */
    abstract private function sourceShape(): array;

    public function lang(): string
    {
        return $this->lang;
    }

    public function sourceLang(): string
    {
        return $this->sourceLang;
    }

    // Null while the file does not exist.
    public function ref(): ?TargetRef
    {
        return $this->exists() ? $this->targetRef() : null;
    }

    public function exists(): bool
    {
        return $this->files->exists($this->lang, $this->group());
    }

    public function sourceSite(): string
    {
        return $this->sourceSite;
    }

    public function targetSite(): string
    {
        return $this->site;
    }

    public function editUrl(): ?string
    {
        return null;
    }

    // Languages do not chain.
    public function sourceIsLocalization(): bool
    {
        return false;
    }

    public function explainFields(): array
    {
        return $this->fields()
            ->map(fn (Field $field, string $handle) => ['handle' => $handle, 'display' => $field->display(), 'type' => self::FIELDTYPE, 'in_scope' => true, 'reason' => 'ok'])
            ->values()
            ->all();
    }

    public function hasOverride(string $handle): bool
    {
        return array_key_exists($handle, $this->target());
    }

    public function targetValue(string $handle): mixed
    {
        return $this->target()[$handle] ?? null;
    }

    // There is no publish form to show an inherited value in.
    public function inheritedValue(string $handle): mixed
    {
        return null;
    }

    // Translation files and form texts are translated from the CLI only (§A16).
    public function authorize(User $user): bool
    {
        return false;
    }

    /**
     * Writes the translated strings into the file, which LangFiles re-reads first, so every other key keeps its value.
     * A string the source emptied is removed. There is no normalisation step: a string is not a blueprint's value.
     *
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        [$writes, $skipped] = $this->writable($this->keyByHandle($fields), $options);
        $ref = $this->targetRef();
        $created = false;

        if ($writes !== []) {
            $set = $this->prepareWrites(null, $ref, $writes);
            $created = ! $this->exists();

            try {
                $this->files->write($this->lang, $this->group(), $set, $this->removedHandles($writes), $this->sourceShape());
            } catch (Throwable $exception) {
                throw ApplyFailed::notWritten($this->name(), $exception);
            }

            $this->confirmWrites($ref, $writes);
        }

        return new ApplyResult(ref: $ref, created: $created, applied: array_keys($writes), skipped: $skipped);
    }

    private function source(): array
    {
        return $this->source ??= $this->files->read($this->sourceLang, $this->group());
    }

    private function target(): array
    {
        return $this->target ??= $this->files->read($this->lang, $this->group());
    }
}
