<?php

namespace Reach\StatamicTolk\Targets;

use Exception;
use Illuminate\Support\Collection;
use LogicException;
use Reach\StatamicTolk\Content\Handlers\NavTreeHandler;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Targets\Concerns\AppliesFields;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Structures\NavTree;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Support\Arr;

/**
 * The tree of a navigation in one site, rebuilt from its tree in the source site (§A11 "Navigation"). Trees are
 * independent per site, so the whole tree is one synthetic field, `tree`, which NavTreeHandler walks. Build it with
 * TargetFactory.
 */
final class NavTarget implements Target
{
    use AppliesFields;

    public const string TREE = 'tree';

    // A site the navigation has no tree in has nothing to translate from.
    public const string NO_SOURCE_TREE = 'no_source_tree';

    // The site named as the one to translate from, whose tree the other sites' trees are translated from.
    public const string FROM_SITE = 'from_site';

    // The fieldtype of the synthetic field; its walk is NavTreeHandler's, which the field names as `tolk_handler`.
    private const string TREE_FIELDTYPE = 'yaml';

    // How a branch whose entry has no localization in the target site was kept, without a link.
    private const string KEPT_CHILDREN = 'children';

    private const string KEPT_ROOT = 'root';

    private ?NavContract $nav = null;

    private ?NavTree $tree = null;

    private ?NavTree $source = null;

    private bool $resolved = false;

    private ?Collection $fields = null;

    /**
     * @var ?array{tree: ?array, unmapped: list<array{name: string, kept: ?string}>}
     */
    private ?array $mapped = null;

    public function __construct(
        private readonly string $handle,
        private readonly string $site,
        private readonly string $sourceSite,
        private readonly TranslatableFields $translatableFields,
        private readonly SourceSummary $summary,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
        ?NavContract $nav = null,
    ) {
        $this->nav = $nav;
    }

    // Null while the site has no tree yet.
    public function ref(): ?TargetRef
    {
        return $this->exists() ? $this->targetRef() : null;
    }

    public function type(): ContentType
    {
        return ContentType::Nav;
    }

    public function exists(): bool
    {
        return $this->tree() !== null;
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
        return $this->handle;
    }

    public function title(): string
    {
        return (string) $this->nav()->title();
    }

    public function editUrl(): ?string
    {
        return $this->tree()?->showUrl();
    }

    // The tree a navigation is translated from is not its handle alone, so the source id names the source site too.
    public function describe(): array
    {
        return ['type' => ContentType::Nav->value, 'source_id' => $this->handle.'::'.$this->sourceSite, 'site' => $this->site];
    }

    public function fields(): Collection
    {
        return $this->fields ??= collect([self::TREE => new Field(self::TREE, [
            'type' => self::TREE_FIELDTYPE,
            'display' => $this->title(),
            'tolk_handler' => NavTreeHandler::HANDLE,
            'fields' => $this->blueprintFields()->items()->all(),
        ])]);
    }

    /**
     * The tree, and the fields of the nav blueprint that its walk leaves out. `url` is never translated.
     */
    public function explainFields(): array
    {
        $tree = ['handle' => self::TREE, 'display' => $this->title(), 'type' => self::TREE_FIELDTYPE, 'in_scope' => true, 'reason' => 'ok'];
        $leftOut = array_filter(
            $this->translatableFields->explainNested($this->blueprintFields()),
            fn (array $field) => ! $field['in_scope'] && $field['handle'] !== 'url',
        );

        return [$tree, ...array_values($leftOut)];
    }

    public function sourceHasValue(string $handle): bool
    {
        return $handle === self::TREE && ($this->source()?->tree() ?? []) !== [];
    }

    public function sourceValue(string $handle): mixed
    {
        return $handle === self::TREE ? $this->mapped()['tree'] : null;
    }

    // Trees have no origins, so there are no chains.
    public function sourceIsLocalization(): bool
    {
        return false;
    }

    // An empty tree holds nothing, as a site whose tree was never filled.
    public function hasOverride(string $handle): bool
    {
        return $handle === self::TREE && ($this->tree()?->tree() ?? []) !== [];
    }

    public function targetValue(string $handle): mixed
    {
        return $this->hasOverride($handle) ? $this->tree()->tree() : null;
    }

    // Trees do not inherit from each other.
    public function inheritedValue(string $handle): mixed
    {
        return null;
    }

    public function formFields(array $values, bool $source = false): Fields
    {
        throw new LogicException('A navigation has no publish form.');
    }

    public function document(): array
    {
        return [
            'type' => ContentType::Nav->value,
            'title' => $this->title(),
            'container' => $this->title(),
            'url' => null,
            'summary' => $this->summary->of($this),
        ];
    }

    /**
     * Site access, then core's NavTreePolicy, which also decides for a tree the site does not have yet: the permission
     * `edit {nav} nav` (§A14).
     */
    public function authorize(User $user): bool
    {
        $site = Site::get($this->site);

        return $site !== null
            && $user->can('view', $site)
            && $user->can('edit', $this->tree() ?? $this->nav()->makeTree($this->site));
    }

    public function fresh(): static
    {
        return new self($this->handle, $this->site, $this->sourceSite, $this->translatableFields, $this->summary, $this->resolver, $this->stateWriter);
    }

    /**
     * Saves the translated tree, creating the site's tree when it has none (§A11 "Navigation"). There is no
     * normalisation step: a tree is not a blueprint's value.
     *
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        [$writes, $skipped] = $this->writable($this->keyByHandle($fields), $options);
        $ref = $this->targetRef();
        $created = false;
        $warnings = [];

        if ($writes !== []) {
            $field = $writes[self::TREE];
            // A source that lost its tree empties the target's, which keeps the site's tree in place.
            $branches = $field->remove ? [] : $field->value;

            // Core validates a tree only when it is read, so a tree it rejects would be saved and then break every read.
            try {
                $this->nav()->validateTree($branches, $this->site);
            } catch (Exception $exception) {
                throw ApplyFailed::invalidTree((string) $ref, $exception);
            }

            if (! $field->remove) {
                $this->stateWriter->recordPending($ref, $this, self::TREE, $this->pending($field, $branches));
            }

            $created = ! $this->exists();
            $tree = $this->tree() ?? $this->nav()->makeTree($this->site);

            if ($tree->tree($branches)->save() === false) {
                throw ApplyFailed::vetoed((string) $ref);
            }

            $this->confirmWrites($ref, $writes);
            $warnings = $field->remove ? [] : $this->unmappedWarnings();
        }

        return new ApplyResult(ref: $ref, created: $created, applied: array_keys($writes), skipped: $skipped, warnings: $warnings);
    }

    /**
     * @return list<string>
     */
    private function unmappedWarnings(): array
    {
        $site = Site::get($this->site)?->name() ?? $this->site;

        return array_map(fn (array $branch) => match ($branch['kept']) {
            self::KEPT_ROOT => __('The root page :item of the navigation has no link in :site: its entry has no localization there, so only its title was kept.', ['item' => $branch['name'], 'site' => $site]),
            self::KEPT_CHILDREN => __('The navigation item :item has no link in :site: its entry has no localization there, so only its title and the items below it were kept.', ['item' => $branch['name'], 'site' => $site]),
            default => __('The navigation item :item was left out: its entry has no localization in :site.', ['item' => $branch['name'], 'site' => $site]),
        }, $this->mapped()['unmapped']);
    }

    /**
     * The source tree as the target site needs it, null when the source site has no tree: each entry id mapped to the
     * entry's localization in the target site, because a branch must hold the entry of its own site (T2.3). A branch
     * whose entry has no localization there is left out, or, when branches below it remain, kept without a link and
     * titled with the entry's title, which is then translated with the tree. The root page of a navigation that
     * expects one is kept the same way, without children as core requires: leaving it out would make the next branch
     * the root, which core rejects when that branch has children. Doing this here rather than when saving keeps the
     * leaves of the saved tree in line with the plan's segments, and the fingerprint of the source changes when such a
     * localization appears, so the tree reads outdated.
     *
     * @return array{tree: ?array, unmapped: list<array{name: string, kept: ?string}>}
     */
    private function mapped(): array
    {
        if ($this->mapped !== null) {
            return $this->mapped;
        }

        $unmapped = [];
        $branches = $this->source()?->tree();

        return $this->mapped = [
            'tree' => $branches === null ? null : $this->mapBranches($branches, $unmapped, $this->nav()->expectsRoot()),
            'unmapped' => $unmapped,
        ];
    }

    /**
     * @param  list<array{name: string, kept: ?string}>  $unmapped  the branches without a localized entry, in tree order
     * @param  bool  $root  the first branch is the navigation's root page
     */
    private function mapBranches(array $branches, array &$unmapped, bool $root = false): array
    {
        $mapped = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $isRoot = $root;
            $root = false;
            $below = [];

            if (is_array($branch['children'] ?? null)) {
                $branch['children'] = $this->mapBranches($branch['children'], $below);

                if ($branch['children'] === []) {
                    unset($branch['children']);
                }
            }

            if (isset($branch['entry'])) {
                $entry = Entry::find((string) $branch['entry']);
                $localization = $entry?->in($this->site);

                if ($localization === null) {
                    $title = $branch['title'] ?? $entry?->value('title');
                    $title = is_string($title) && $title !== '' ? $title : null;
                    $kept = match (true) {
                        $isRoot => self::KEPT_ROOT,
                        isset($branch['children']) => self::KEPT_CHILDREN,
                        default => null,
                    };
                    $unmapped[] = ['name' => $title === null ? "for the entry [{$branch['entry']}]" : "\"{$title}\"", 'kept' => $kept];
                    array_push($unmapped, ...$below);

                    if ($kept !== null) {
                        $mapped[] = self::withoutLink($branch, $title);
                    }

                    continue;
                }

                $branch['entry'] = (string) $localization->id();
            }

            array_push($unmapped, ...$below);
            $mapped[] = $branch;
        }

        return $mapped;
    }

    // A text-only branch, its keys in the order core stores them.
    private static function withoutLink(array $branch, ?string $title): array
    {
        return array_filter([
            'id' => $branch['id'] ?? null,
            'title' => $title,
            ...Arr::except($branch, ['id', 'entry', 'title', 'children']),
            'children' => $branch['children'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function targetRef(): TargetRef
    {
        return new TargetRef(ContentType::Nav, $this->handle, $this->site);
    }

    private function nav(): NavContract
    {
        return $this->nav ??= Nav::findByHandle($this->handle) ?? throw TargetNotFound::nav($this->handle);
    }

    private function tree(): ?NavTree
    {
        $this->resolve();

        return $this->tree;
    }

    private function source(): ?NavTree
    {
        $this->resolve();

        return $this->source;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->tree = $this->nav()->in($this->site);
        $this->source = $this->nav()->in($this->sourceSite);
        $this->resolved = true;
    }

    private function blueprintFields(): Fields
    {
        return $this->nav()->blueprint()->fields();
    }
}
