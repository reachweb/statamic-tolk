<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use Illuminate\Translation\MessageSelector;
use LogicException;
use Reach\StatamicTolk\Content\Handlers\LangStringHandler;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Targets\Concerns\WritesLangFile;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * One Laravel translation file in one language, translated from the same group in the source language (§A16). Each
 * string of the source is a synthetic `text` field, whose handle and label are its key and whose walk is
 * LangStringHandler's. Prompts use the languages of the sites given: the first site with each `lang()`. There is no
 * CP surface, so no user may translate it there. Build it with TargetFactory.
 */
final class LangTarget implements Target
{
    use WritesLangFile;

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

    public function type(): ContentType
    {
        return ContentType::Lang;
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

    public function sourceHasValue(string $handle): bool
    {
        return is_string($this->source()[$handle] ?? null);
    }

    public function sourceValue(string $handle): mixed
    {
        return $this->sourceHasValue($handle) ? $this->source()[$handle] : null;
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

    public function fresh(): static
    {
        return new self($this->group, $this->lang, $this->sourceLang, $this->site, $this->sourceSite, $this->files, $this->resolver, $this->stateWriter);
    }

    private function targetRef(): TargetRef
    {
        return new TargetRef(ContentType::Lang, $this->group, $this->lang);
    }

    // The written file follows the source's order and carries over its values that are not text.
    private function sourceShape(): array
    {
        return $this->source();
    }
}
