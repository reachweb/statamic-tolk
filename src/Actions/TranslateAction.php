<?php

namespace Reach\StatamicTolk\Actions;

use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * "Translate…" in entry listings and the tree view (§A13): creates a pending run of the selected items and opens its
 * page, where the estimate comes first and Start follows (D19).
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
        /** @translation */
        return 'The next page shows an estimate before this entry is translated.|The next page shows an estimate before these :count entries are translated.';
    }

    public function run($items, $values)
    {
        $planned = app(RunPlanner::class)->build(new RunRequest(
            sites: array_values(array_map(strval(...), (array) ($values['sites'] ?? []))),
            driver: RunDispatcher::resolveDriver(),
            entryIds: $items->map(fn (Entry $entry) => (string) $entry->id())->values()->all(),
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

        return [
            'sites' => [
                'type' => 'checkboxes',
                'display' => __('Translate into'),
                'options' => $sites,
                'default' => array_values(array_diff(array_keys($sites), [Site::default()->handle()])),
                'validate' => ['required'],
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
    }

    /**
     * An entry of a collection with more than one site that the settings do not exclude.
     */
    private function translatable(mixed $item): bool
    {
        return $item instanceof Entry
            && $item->collection()->sites()->count() > 1
            && ! in_array($item->collectionHandle(), app(Settings::class)->excludedCollections(), true);
    }

    /**
     * The sites of the selected items' collections that the user may access, in the order of the site config.
     *
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $handles = $this->items
            ->filter(fn ($item) => $item instanceof Entry)
            ->flatMap(fn (Entry $entry) => $entry->collection()->sites()->all())
            ->unique()
            ->all();
        $user = User::current();

        return Site::all()
            ->filter(fn ($site) => in_array($site->handle(), $handles, true) && ($user?->can('view', $site) ?? false))
            ->mapWithKeys(fn ($site) => [$site->handle() => $site->name()])
            ->all();
    }
}
