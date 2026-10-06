<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use LogicException;
use Reach\StatamicTolk\Content\Handlers\LangStringHandler;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Targets\Concerns\AppliesFields;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Throwable;

/**
 * The texts of every form in one language, stored in `lang/{lang}.json` under the source text, as Laravel's JSON
 * convention has it (§A16). Each distinct text is a synthetic `text` field whose handle is the text and whose label is
 * where it is used; a text the source language's JSON file already has is left to the `_json` LangTarget. There is no
 * CP surface, so no user may translate it there. Build it with TargetFactory.
 */
final class FormsTarget implements Target
{
    use AppliesFields;

    public const string CONTAINER = 'forms';

    private const string FIELDTYPE = 'text';

    // The texts of a form field, in the order they are collected.
    private const array FIELD_TEXTS = ['display', 'instructions', 'placeholder'];

    /** @var ?array<string, array{places: list<string>, forms: list<string>}> */
    private ?array $texts = null;

    /** @var list<string> */
    private array $renderedSubjects = [];

    private ?array $source = null;

    private ?array $target = null;

    private ?Collection $fields = null;

    public function __construct(
        private readonly string $lang,
        private readonly string $sourceLang,
        private readonly string $site,
        private readonly string $sourceSite,
        private readonly LangFiles $files,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
    ) {}

    public function lang(): string
    {
        return $this->lang;
    }

    public function sourceLang(): string
    {
        return $this->sourceLang;
    }

    /**
     * The file the texts are written to, such as `lang/de.json`.
     */
    public function name(): string
    {
        return LangFiles::name($this->lang, LangFiles::JSON);
    }

    /**
     * The fields of the texts the given forms use; a text other forms use too is among them.
     *
     * @param  list<string>  $forms  handles
     * @return list<string>
     */
    public function textsOf(array $forms): array
    {
        return $this->fields()->keys()
            ->filter(fn (string $text) => array_intersect($this->texts()[$text]['forms'], $forms) !== [])
            ->values()
            ->all();
    }

    /**
     * The texts left to the `_json` LangTarget, because the source language's JSON file has them as keys.
     *
     * @return list<string>
     */
    public function leftToLang(): array
    {
        return array_values(array_filter(array_map('strval', array_keys($this->texts())), fn (string $text) => $this->inSourceJson($text)));
    }

    /**
     * Where the email subjects are that hold Antlers: Statamic renders a subject before it looks for its translation, so
     * a translation stored under the subject as written is never found, and they are left out.
     *
     * @return list<string>
     */
    public function renderedSubjects(): array
    {
        $this->texts();

        return $this->renderedSubjects;
    }

    /**
     * The keys of the target file that hold a translation this target recorded but no form uses any more, such as the
     * old text of a renamed label.
     *
     * @return list<string>
     */
    public function unusedKeys(ItemState $state): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($state->fields)),
            fn (string $key) => ! $this->fields()->has($key) && ! $this->inSourceJson($key) && array_key_exists($key, $this->target()),
        ));
    }

    // Null while the file does not exist.
    public function ref(): ?TargetRef
    {
        return $this->exists() ? $this->targetRef() : null;
    }

    public function type(): ContentType
    {
        return ContentType::Forms;
    }

    public function exists(): bool
    {
        return $this->files->exists($this->lang, LangFiles::JSON);
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
        return self::CONTAINER;
    }

    public function title(): string
    {
        return 'Forms';
    }

    public function editUrl(): ?string
    {
        return null;
    }

    // The form texts are named by both languages, not by sites.
    public function describe(): array
    {
        return ['type' => ContentType::Forms->value, 'source_id' => $this->sourceLang, 'site' => $this->lang];
    }

    /**
     * One field per distinct text, in the order the forms (by handle) use them first.
     */
    public function fields(): Collection
    {
        return $this->fields ??= collect($this->texts())
            ->reject(fn (array $text, string $handle) => $this->inSourceJson($handle))
            ->mapWithKeys(fn (array $text, string $handle) => [$handle => new Field($handle, [
                'type' => self::FIELDTYPE,
                'display' => count($text['places']) === 1 ? $text['places'][0] : sprintf('%s (used in %d places)', $text['places'][0], count($text['places'])),
                'tolk_handler' => LangStringHandler::HANDLE,
            ])]);
    }

    public function explainFields(): array
    {
        return $this->fields()
            ->map(fn (Field $field, string $handle) => ['handle' => $handle, 'display' => $field->display(), 'type' => self::FIELDTYPE, 'in_scope' => true, 'reason' => 'ok'])
            ->values()
            ->all();
    }

    public function sourceHasValue(string $handle): bool
    {
        return $this->fields()->has($handle);
    }

    // The key is the text.
    public function sourceValue(string $handle): mixed
    {
        return $this->sourceHasValue($handle) ? $handle : null;
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
        throw new LogicException('The form texts have no publish form.');
    }

    public function document(): array
    {
        return [
            'type' => ContentType::Forms->value,
            'title' => $this->title(),
            'container' => "Texts of the website's forms: titles, field labels, instructions, placeholders, options and email subjects",
            'url' => null,
            'summary' => '',
        ];
    }

    // Form texts are translated from the CLI only (§A16).
    public function authorize(User $user): bool
    {
        return false;
    }

    public function fresh(): static
    {
        return new self($this->lang, $this->sourceLang, $this->site, $this->sourceSite, $this->files, $this->resolver, $this->stateWriter);
    }

    /**
     * Writes the translated texts into the JSON file, which LangFiles re-reads first, so every other key keeps its value
     * and its place; new texts are appended. The `_json` LangTarget writes the same file, also inside
     * ContentWriter::write(), so neither drops the other's keys.
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
                $this->files->write($this->lang, LangFiles::JSON, $set, $this->removedHandles($writes), []);
            } catch (Throwable $exception) {
                throw ApplyFailed::notWritten($this->name(), $exception);
            }

            $this->confirmWrites($ref, $writes);
        }

        return new ApplyResult(ref: $ref, created: $created, applied: array_keys($writes), skipped: $skipped);
    }

    /**
     * Every distinct text of every form, with where it is used and by which forms. Forms come by handle, because the
     * repository keeps no order; within a form the title, then each top-level field's texts and option labels, then
     * the email subjects. A text without a letter is no text (§A3).
     *
     * @return array<string, array{places: list<string>, forms: list<string>}>
     */
    private function texts(): array
    {
        if ($this->texts !== null) {
            return $this->texts;
        }

        $this->texts = [];
        $this->renderedSubjects = [];

        foreach (Form::all()->filter()->sortBy(fn (FormContract $form) => $form->handle()) as $form) {
            foreach ($this->occurrences($form) as [$text, $place]) {
                if (! is_string($text) || preg_match('/\p{L}/u', $text) !== 1) {
                    continue;
                }

                $this->texts[$text]['places'][] = $place;
                $this->texts[$text]['forms'] = array_values(array_unique([...$this->texts[$text]['forms'] ?? [], $form->handle()]));
            }
        }

        return $this->texts;
    }

    /**
     * @return list<array{0: mixed, 1: string}> each text of the form with its place, such as "Contact › Email › instructions"
     */
    private function occurrences(FormContract $form): array
    {
        $title = (string) $form->title();
        $occurrences = [[$form->title(), "{$title} › title"]];

        foreach ($form->blueprint()->fields()->all() as $field) {
            $prefix = $title.' › '.$field->display();

            foreach (self::FIELD_TEXTS as $key) {
                $occurrences[] = [$field->get($key), "{$prefix} › {$key}"];
            }

            foreach (self::optionLabels($field->get('options')) as [$key, $label]) {
                $occurrences[] = [$label, "{$prefix} › option {$key}"];
            }
        }

        foreach (self::emails($form->email()) as $email) {
            $subject = $email['subject'] ?? null;
            $place = "{$title} › email subject";

            if (is_string($subject) && str_contains($subject, '{{')) {
                $this->renderedSubjects[] = $place;
            } else {
                $occurrences[] = [$subject, $place];
            }
        }

        return $occurrences;
    }

    /**
     * The labels of a field's options, as Statamic reads them: a list of values, each its own label; a map of value to
     * label; or rows of `key` and `value`. Values are not texts.
     *
     * @return list<array{0: string, 1: mixed}> the value and the label of each option
     */
    private static function optionLabels(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $labels = [];

        foreach ($options as $key => $option) {
            $labels[] = match (true) {
                is_array($option) => [is_scalar($option['key'] ?? null) ? (string) $option['key'] : (string) $key, $option['value'] ?? null],
                array_is_list($options) => [is_scalar($option) ? (string) $option : '', $option],
                default => [(string) $key, $option],
            };
        }

        return $labels;
    }

    /**
     * A form's email configs, as Statamic sends them: one config with `to`, or a list of configs.
     *
     * @return list<array>
     */
    private static function emails(mixed $email): array
    {
        if (! is_array($email)) {
            return [];
        }

        return array_values(array_filter(isset($email['to']) ? [$email] : $email, 'is_array'));
    }

    private function inSourceJson(string $text): bool
    {
        return array_key_exists($text, $this->source());
    }

    private function targetRef(): TargetRef
    {
        return new TargetRef(ContentType::Forms, $this->lang);
    }

    private function source(): array
    {
        return $this->source ??= $this->files->read($this->sourceLang, LangFiles::JSON);
    }

    private function target(): array
    {
        return $this->target ??= $this->files->read($this->lang, LangFiles::JSON);
    }
}
