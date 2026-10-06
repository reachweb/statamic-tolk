<?php

namespace Reach\StatamicTolk\Runs;

use InvalidArgumentException;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Auth\User;

/**
 * What a bulk run should translate (§A12), as the CP and the CLI ask for it. The RunPlanner turns it into a run.
 */
final readonly class RunRequest
{
    public const string ALL = '*';

    /**
     * The options and their defaults; null means the settings decide.
     */
    public const array OPTIONS = [
        'mode' => Mode::Missing->value,
        'overwrite_manual' => false,
        'review' => null,
        'translator' => null,
        'reviewer' => null,
        'new_status' => null,
        'publish_changes' => false,
    ];

    /**
     * @var list<string>
     */
    public array $sites;

    /**
     * @var array{mode: string, overwrite_manual: bool, review: ?bool, translator: ?string, reviewer: ?string, new_status: ?string, publish_changes: bool}
     */
    public array $options;

    /**
     * @param  list<string>  $sites  the sites to translate into
     * @param  RunDriver  $driver  what drives the run once it starts
     * @param  ?string  $sourceSite  the site to translate a missing localization from, when the family has one there,
     *                               and a navigation from, when it has a tree there
     * @param  list<string>  $collections  handles, or [ALL] for every multi-site collection
     * @param  list<string>  $taxonomies  handles, or [ALL] for every multi-site taxonomy
     * @param  list<string>  $globals  handles, or [ALL] for every multi-site global set
     * @param  list<string>  $navs  handles, or [ALL] for every navigation
     * @param  list<string>  $entryIds  any entry of each family
     * @param  list<string>  $termIds  term ids, `{taxonomy}::{default slug}`
     * @param  ?string  $reference  the item open in the editor when the run was pushed from it (T8.13)
     */
    public function __construct(
        array $sites,
        public RunDriver $driver,
        public ?string $sourceSite = null,
        public array $collections = [],
        public array $taxonomies = [],
        public array $globals = [],
        public array $navs = [],
        public array $entryIds = [],
        public array $termIds = [],
        array $options = [],
        public ?User $user = null,
        public ?string $reference = null,
    ) {
        if ($sites === []) {
            throw new InvalidArgumentException('A run needs at least one site to translate into.');
        }

        foreach (compact('sites', 'collections', 'taxonomies', 'globals', 'navs', 'entryIds', 'termIds') as $name => $list) {
            if (! array_is_list($list) || array_filter($list, fn ($value) => ! is_string($value) || $value === '') !== []) {
                throw new InvalidArgumentException("The {$name} of a run request must be a list of handles or ids.");
            }
        }

        foreach (['translator', 'reviewer'] as $key) {
            if (isset($options[$key]) && ! is_string($options[$key])) {
                throw new InvalidArgumentException("The {$key} of a run request must be a model reference such as provider:model.");
            }
        }

        if ($unknown = array_diff_key($options, self::OPTIONS)) {
            throw new InvalidArgumentException('Unknown run options ['.implode(', ', array_keys($unknown)).'].');
        }

        $options = array_replace(self::OPTIONS, array_filter($options, fn ($value) => $value !== null));

        if (Mode::tryFrom((string) $options['mode']) === null) {
            throw new InvalidArgumentException("Invalid mode [{$options['mode']}]. Use missing, update, all or review.");
        }

        if ($options['new_status'] !== null && ! in_array($options['new_status'], Settings::NEW_LOCALIZATION_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid new localization status [{$options['new_status']}]. Use draft or mirror.");
        }

        $this->sites = array_values(array_unique($sites));
        $this->options = [
            ...$options,
            'mode' => (string) $options['mode'],
            'overwrite_manual' => (bool) $options['overwrite_manual'],
            'review' => $options['review'] === null ? null : (bool) $options['review'],
            'publish_changes' => (bool) $options['publish_changes'],
        ];
    }

    /**
     * Whether a list of handles asks for every container of its kind.
     *
     * @param  list<string>  $handles
     */
    public static function all(array $handles): bool
    {
        return in_array(self::ALL, $handles, true);
    }

    /**
     * What was asked for, as the run's `scope` stores it.
     */
    public function scope(): array
    {
        return [
            'sites' => $this->sites,
            'source_site' => $this->sourceSite,
            'collections' => $this->collections,
            'taxonomies' => $this->taxonomies,
            'globals' => $this->globals,
            'navs' => $this->navs,
            'entry_ids' => $this->entryIds,
            'term_ids' => $this->termIds,
            'reference' => $this->reference,
        ];
    }
}
