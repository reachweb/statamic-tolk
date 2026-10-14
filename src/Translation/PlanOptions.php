<?php

namespace Reach\StatamicTolk\Translation;

use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Support\Settings;

final readonly class PlanOptions
{
    public const array PROFILES = ['web', 'background'];

    /**
     * @param  ?list<string>  $fields  the handles to plan; null for every in-scope field
     * @param  array<string, string>  $skip  handles the caller rules out, with the reason the plan records for each
     * @param  string  $profile  `web` for the editor and for runs in the browser or Tolk's queue lanes, `background` for runs
     *                           with the CLI driver (the commands, and automatic updates that no queue lane drives);
     *                           it sizes the batches, and a run calls with the profile of its driver at the time
     * @param  bool  $parallel  whether the caller runs the plan's units in parallel, as the editor does: the `web` profile
     *                          then splits a small target over `concurrency.editor` batches (T6.11)
     */
    public function __construct(
        public ModelRef $translator,
        public Mode $mode = Mode::Missing,
        public bool $overwriteManual = false,
        public ?array $fields = null,
        public array $skip = [],
        public bool $review = false,
        public ?ModelRef $reviewer = null,
        public ?ModelRef $fallback = null,
        public string $profile = 'web',
        public bool $strict = false,
        public ?string $userId = null,
        public bool $parallel = false,
    ) {
        if (! in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException("Invalid plan profile [{$profile}]. Use web or background.");
        }

        if ($fields !== null && (! array_is_list($fields) || array_filter($fields, fn ($handle) => ! is_string($handle)) !== [])) {
            throw new InvalidArgumentException('The fields of plan options must be a list of handles.');
        }

        foreach ($skip as $handle => $reason) {
            if (! is_string($handle) || ! is_string($reason) || $reason === '') {
                throw new InvalidArgumentException('The skipped fields of plan options must map handles to reasons.');
            }
        }
    }

    /**
     * Models, `review` and `strict` come from the settings unless overridden; mode `review` sets `review`. Overrides use
     * the constructor's parameter names; a null override means the default, and model references and the mode may be
     * given as strings.
     */
    public static function fromSettings(Settings $settings, ModelCatalog $catalog, array $overrides = []): self
    {
        $overrides = array_filter($overrides, fn ($value) => $value !== null);

        foreach (['translator', 'reviewer', 'fallback'] as $key) {
            if (is_string($overrides[$key] ?? null)) {
                $overrides[$key] = ModelRef::fromString($overrides[$key]);
            }
        }

        if (is_string($overrides['mode'] ?? null)) {
            $overrides['mode'] = Mode::from($overrides['mode']);
        }

        $translator = $catalog->resolveTranslator($settings, $overrides['translator'] ?? null);
        // Mode review runs only the reviewer, so it always has one.
        $review = ($overrides['mode'] ?? null) === Mode::Review || (bool) ($overrides['review'] ?? $settings->reviewEnabled());

        return new self(...[
            'strict' => $settings->strictFields(),
            'fallback' => $catalog->resolveFallback($settings, $translator),
            ...$overrides,
            'translator' => $translator,
            'review' => $review,
            'reviewer' => $review ? $catalog->resolveReviewer($settings, $translator, $overrides['reviewer'] ?? null) : null,
        ]);
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'overwrite_manual' => $this->overwriteManual,
            'fields' => $this->fields,
            'skip' => $this->skip,
            'translator' => (string) $this->translator,
            'review' => $this->review,
            'reviewer' => $this->reviewer?->__toString(),
            'fallback' => $this->fallback?->__toString(),
            'profile' => $this->profile,
            'strict' => $this->strict,
            'user_id' => $this->userId,
            'parallel' => $this->parallel,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            translator: ModelRef::fromString($data['translator']),
            mode: Mode::from($data['mode']),
            overwriteManual: (bool) ($data['overwrite_manual'] ?? false),
            fields: $data['fields'] ?? null,
            skip: $data['skip'] ?? [],
            review: (bool) ($data['review'] ?? false),
            reviewer: isset($data['reviewer']) ? ModelRef::fromString($data['reviewer']) : null,
            fallback: isset($data['fallback']) ? ModelRef::fromString($data['fallback']) : null,
            profile: $data['profile'] ?? 'web',
            strict: (bool) ($data['strict'] ?? false),
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            parallel: (bool) ($data['parallel'] ?? false),
        );
    }
}
