<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use Illuminate\Translation\MessageSelector;
use LogicException;
use Reach\StatamicTolk\Content\Handlers\LangStringHandler;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Targets\Concerns\AppliesFields;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Throwable;

/**
 * One Laravel translation file in one language, translated from the same group in the source language (§A16). Each
 * string of the source is a synthetic `text` field, whose handle and label are its key and whose walk is
 * LangStringHandler's. Prompts use the languages of the sites given: the first site with each `lang()`. There is no
 * CP surface, so no user may translate it there. Build it with TargetFactory.
 */
final class LangTarget implements Target
{
    use AppliesFields;

    private const string FIELDTYPE = 'text';

    private ?array $source = null;

    private ?array $target = null;

    private ?Collection $fields = null;

    public function __construct(
        private readonly string $group,
        private readonly string $lang,
        private readonly string $sourceLang,
        private readonly string $site,
        private readonly string $sourceSite,
        private readonly LangFiles $files,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
    ) {}

    /**
     * The plural forms Laravel distinguishes in a language: the distinct indexes MessageSelector picks for 0 to 199.
     */
    public static function pluralForms(string $lang): int
    {
        $selector = new MessageSelector;

        return count(array_unique(array_map(fn (int $number) => $selector->getPluralIndex($lang, $number), range(0, 199))));
    }

    public function group(): string
    {
        return $this->group;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function sourceLang(): string
    {
        return $this->sourceLang;
    }

    /**
     * The target file as the commands name it, such as `lang/de/messages.php`.
     */
    public function name(): string
    {
        return LangFiles::name($this->lang, $this->group);
    }

    /**
     * The keys of the source's pluralised strings, which have more than one variant.
     *
     * @return list<string>
     */
    public function pluralKeys(): array
    {
        return $this->fields()->keys()
            ->filter(fn (string $key) => count(LangStringHandler::variants($this->source()[$key])) > 1)
            ->values()
            ->all();
    }

    // Null while the file does not exist.
    public function ref(): ?TargetRef
    {
        return $this->exists() ? $this->targetRef() : null;
    }

    public function type(): ContentType
    {
        return ContentType::Lang;
    }

    public function exists(): bool
    {
        return $this->files->exists($this->lang, $this->group);
    }

    public function sourceSite(): string
    {
        return $this->sourceSite;
    }

    public function targetSite(): string
    {
        return $this->site;
    }

    public function container(): string
    {
        return $this->group;
    }

    // The source file's name: `messages.php`, `en.json`.
    public function title(): string
    {
        return basename(LangFiles::name($this->sourceLang, $this->group));
    }

    public function editUrl(): ?string
    {
        return null;
    }

    // A translation file is named by its group and both languages, not by sites.
    public function describe(): array
    {
        return ['type' => ContentType::Lang->value, 'source_id' => $this->group.'::'.$this->sourceLang, 'site' => $this->lang];
    }

    /**
     * One field per string of the source, in its order. Other values are not text: write() carries them over.
     */
    public function fields(): Collection
    {
        return $this->fields ??= collect($this->source())
            ->filter(fn (mixed $value) => is_string($value))
            ->keys()
            ->mapWithKeys(fn (int|string $key) => [(string) $key => new Field((string) $key, [
                'type' => self::FIELDTYPE,
                'display' => (string) $key,
                'tolk_handler' => LangStringHandler::HANDLE,
            ])]);
    }

    public function explainFields(): array
    {
        return $this->fields()
            ->map(fn (Field $field, string $key) => ['handle' => $key, 'display' => $key, 'type' => self::FIELDTYPE, 'in_scope' => true, 'reason' => 'ok'])
            ->values()
            ->all();
    }

    public function sourceHasValue(string $handle): bool
    {
        return is_string($this->source()[$handle] ?? null);
    }

    public function sourceValue(string $handle): mixed
    {
        return $this->sourceHasValue($handle) ? $this->source()[$handle] : null;
    }

    // Languages do not chain.
    public function sourceIsLocalization(): bool
    {
        return false;
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

    public function formFields(array $values, bool $source = false): Fields
    {
        throw new LogicException('A translation file has no publish form.');
    }

    public function document(): array
    {
        return [
            'type' => ContentType::Lang->value,
            'title' => $this->title(),
            'container' => 'Interface strings of the website (a Laravel translation file)',
            'url' => null,
            'summary' => '',
        ];
    }

    // Translation files are translated from the CLI only (§A16).
    public function authorize(User $user): bool
    {
        return false;
    }

    public function fresh(): static
    {
        return new self($this->group, $this->lang, $this->sourceLang, $this->site, $this->sourceSite, $this->files, $this->resolver, $this->stateWriter);
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
            $set = [];

            foreach ($writes as $handle => $field) {
                if (! $field->remove) {
                    $set[$handle] = $field->value;
                    $this->stateWriter->recordPending($ref, $this->sourceSite, $handle, $this->pending($field, $field->value));
                }
            }

            $created = ! $this->exists();

            try {
                $this->files->write($this->lang, $this->group, $set, $this->removedHandles($writes), $this->source());
            } catch (Throwable $exception) {
                throw ApplyFailed::notWritten($this->name(), $exception);
            }

            $this->confirmWrites($ref, $writes);
        }

        return new ApplyResult(ref: $ref, created: $created, applied: array_keys($writes), skipped: $skipped);
    }

    private function targetRef(): TargetRef
    {
        return new TargetRef(ContentType::Lang, $this->group, $this->lang);
    }

    private function source(): array
    {
        return $this->source ??= $this->files->read($this->sourceLang, $this->group);
    }

    private function target(): array
    {
        return $this->target ??= $this->files->read($this->lang, $this->group);
    }
}
