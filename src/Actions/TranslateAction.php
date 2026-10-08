<?php

namespace Reach\StatamicTolk\Actions;

use Illuminate\Support\Collection;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * "Translate…" in entry and term listings and the tree view (§A13): creates a pending run of the selected items and
 * opens its page, where the estimate comes first and Start follows (D19).
 */
class TranslateAction extends Action
{
    protected static $handle = 'tolk_translate';

    protected $icon = 'dictionary-language-book';

    private ?string $runId = null;

    public static function title()
    {
        return __('Translate…');
    }

    /**
     * Not in the publish form: a PHP action resets the form's values there but not its localized fields, so the next
     * save would drop fields the run translated (R3 §7.3). The banner covers the form.
     */
    public function visibleTo($item)
    {
        return in_array($this->context['view'] ?? 'list', ['list', 'tree'], true) && $this->translatable($item);
    }

    public function authorize($user, $item)
    {
        return $this->translatable($item) && $user->can('run bulk translations') && $user->can('edit', $item);
    }

    public function buttonText()
    {
        /** @translation */
        return 'Translate|Translate :count items';
    }

    public function confirmationText()
    {
        if ($this->onlyTerms()) {
            /** @translation */
            return 'The next page shows an estimate before this term is translated.|The next page shows an estimate before these :count terms are translated.';
        }

        /** @translation */
        return 'The next page shows an estimate before this entry is translated.|The next page shows an estimate before these :count entries are translated.';
    }

    public function run($items, $values)
    {
        $ids = fn (string $type) => $items->filter(fn ($item) => $item instanceof $type)->map(fn ($item) => (string) $item->id())->values()->all();

        $planned = app(RunPlanner::class)->build(new RunRequest(
            sites: array_values(array_map(strval(...), (array) ($values['sites'] ?? []))),
            driver: RunDispatcher::resolveDriver(),
            entryIds: $ids(Entry::class),
            termIds: $ids(Term::class),
            options: [
                'mode' => $values['mode'] ?? null,
                'overwrite_manual' => (bool) ($values['overwrite_manual'] ?? false),
                'review' => (bool) ($values['review'] ?? false),
                'new_status' => $values['new_status'] ?? null,
            ],
            user: User::current(),
        ));

        $this->runId = app(RunRepository::class)->create($planned->run, $planned->items)->id;
    }

    /**
     * Core asks for the redirect even when run() threw, and a redirect replaces the error message in the answer.
     */
    public function redirect($items, $values)
    {
        return $this->runId === null ? false : cp_route('tolk.runs.show', $this->runId);
    }

    protected function fieldItems()
    {
        $settings = app(Settings::class);
        $sites = $this->siteOptions();
        // A term is translated from its taxonomy's first site, which need not be the default site.
        $sources = $this->onlyTerms()
            ? $this->items->map(fn (Term $term) => $term->taxonomy()->sites()->first())->unique()->all()
            : [Site::default()->handle()];

        $fields = [
            'sites' => [
                'type' => 'checkboxes',
                'display' => __('Translate into'),
                'options' => $sites,
                'default' => array_values(array_diff(array_keys($sites), $sources)),
                // `in` checks each value of a checkboxes field only together with `array` (§A14).
                'validate' => ['required', 'array', 'in:'.implode(',', array_keys($sites))],
            ],
            'mode' => [
                'type' => 'select',
                'display' => __('What to translate'),
                'options' => [
                    Mode::Missing->value => __('Only what is not translated yet'),
                    Mode::Update->value => __('Also update outdated translations'),
                    Mode::All->value => __('Retranslate everything'),
                    Mode::Review->value => __('Review existing translations'),
                ],
                'default' => Mode::Missing->value,
                'clearable' => false,
                'validate' => ['required'],
            ],
            'overwrite_manual' => [
                'type' => 'toggle',
                'display' => __('Overwrite manual edits'),
                'instructions' => __('Also replaces the text of fields edited by hand.'),
                'default' => false,
            ],
            'review' => [
                'type' => 'toggle',
                'display' => __('Review after translating'),
                'instructions' => __('A second model checks the translation and corrects it.'),
                'default' => $settings->reviewEnabled(),
            ],
            'new_status' => [
                'type' => 'button_group',
                'display' => __('New localizations'),
                'options' => [
                    'draft' => __('Drafts'),
                    'mirror' => __('Same as origin'),
                ],
                'default' => $settings->newLocalizationStatus(),
            ],
        ];

        // Every site of a taxonomy has its localization of every term, so terms create none.
        if ($this->onlyTerms()) {
            unset($fields['new_status']);
        }

        return $fields;
    }

    /**
     * An entry of a collection, or a term of a taxonomy, with more than one site that the settings do not exclude.
     */
    private function translatable(mixed $item): bool
    {
        $settings = app(Settings::class);

        return $this->sitesOf($item)->count() > 1 && match (true) {
            $item instanceof Entry => ! in_array($item->collectionHandle(), $settings->excludedCollections(), true),
            $item instanceof Term => ! in_array($item->taxonomyHandle(), $settings->excludedTaxonomies(), true),
            default => false,
        };
    }

    /**
     * The sites of the item's collection or taxonomy; none for anything else.
     *
     * @return Collection<int, string>
     */
    private function sitesOf(mixed $item): Collection
    {
        return match (true) {
            $item instanceof Entry => $item->collection()->sites(),
            $item instanceof Term => $item->taxonomy()->sites(),
            default => collect(),
        };
    }

    // Core lists the actions of one listing at a time, so the items are all entries or all terms.
    private function onlyTerms(): bool
    {
        return $this->items->isNotEmpty() && $this->items->every(fn ($item) => $item instanceof Term);
    }

    /**
     * The sites of the selected items' collections or taxonomies that the user may access, in the order of the site config.
     *
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $handles = $this->items
            ->flatMap(fn ($item) => $this->sitesOf($item))
            ->unique()
            ->all();

        return Site::authorized()
            ->filter(fn ($site) => in_array($site->handle(), $handles, true))
            ->mapWithKeys(fn ($site) => [$site->handle() => $site->name()])
            ->all();
    }
}
