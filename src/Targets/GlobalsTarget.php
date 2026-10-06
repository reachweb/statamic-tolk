<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\Concerns\AppliesFields;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;

/**
 * The variables of a global set in one site, translated from the variables of the origin the set names for that site
 * (§A11 "Global sets"). Build it with TargetFactory. A site the set does not have or has no origin for, and a set
 * without a blueprint, give a target that is not translatable: it has no fields, and untranslatableReason() says why.
 */
final class GlobalsTarget implements Target
{
    use AppliesFields;

    public const string SITE_NOT_IN_SET = 'site_not_in_global_set';

    public const string NO_ORIGIN = 'no_origin';

    // Statamic infers text fields from the values, which are not localizable (R4 §4.2).
    public const string NO_BLUEPRINT = 'no_blueprint';

    private ?GlobalSetContract $set = null;

    private ?Variables $variables = null;

    private ?Variables $source = null;

    private bool $resolved = false;

    private ?Collection $fields = null;

    public function __construct(
        private readonly string $handle,
        private readonly string $site,
        private readonly TranslatableFields $translatableFields,
        private readonly SourceSummary $summary,
        private readonly Settings $settings,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
        ?GlobalSetContract $set = null,
    ) {
        $this->set = $set;
    }

    /**
     * Why the set's variables in $site cannot be translated, or null when they can.
     */
    public static function untranslatableReasonFor(GlobalSetContract $set, string $site): ?string
    {
        return match (true) {
            ! $set->sites()->contains($site) => self::SITE_NOT_IN_SET,
            self::originOf($set, $site) === null => self::NO_ORIGIN,
            $set->blueprint() === null => self::NO_BLUEPRINT,
            default => null,
        };
    }

    /**
     * Whether $site has no origin to be translated from and is not the origin of another site, which would make it
     * the set's source: the sites the audit reports and tolk:site offers to give an origin.
     */
    public static function needsOrigin(GlobalSetContract $set, string $site): bool
    {
        return ! $set->origins()->containsStrict($site) && self::untranslatableReasonFor($set, $site) === self::NO_ORIGIN;
    }

    public function untranslatableReason(): ?string
    {
        return self::untranslatableReasonFor($this->set(), $this->site);
    }

    // Null for a site the set does not have, whose variables cannot exist.
    public function ref(): ?TargetRef
    {
        return $this->exists() ? new TargetRef(ContentType::GlobalSet, $this->handle, $this->site) : null;
    }

    public function type(): ContentType
    {
        return ContentType::GlobalSet;
    }

    // Saving the set creates the variables of every site it has; until then, saving them creates them.
    public function exists(): bool
    {
        return $this->variables() !== null;
    }

    // A target without an origin is its own source; it has no fields, so nothing is read from it.
    public function sourceSite(): string
    {
        return $this->source()?->locale() ?? $this->site;
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
        return (string) $this->set()->title();
    }

    public function editUrl(): ?string
    {
        return $this->variables()?->editUrl();
    }

    public function describe(): array
    {
        return ['type' => ContentType::GlobalSet->value, 'source_id' => $this->handle, 'site' => $this->site];
    }

    public function fields(): Collection
    {
        return $this->fields ??= $this->untranslatableReason() === null
            ? $this->translatableFields->for($this->blueprint(), $this->fieldContext())
            : collect();
    }

    public function explainFields(): array
    {
        return $this->untranslatableReason() === null ? $this->translatableFields->explain($this->blueprint(), $this->fieldContext()) : [];
    }

    public function sourceHasValue(string $handle): bool
    {
        return (bool) $this->source()?->has($handle);
    }

    public function sourceValue(string $handle): mixed
    {
        return $this->source()?->get($handle);
    }

    // Origins can chain (de_CH → de → en).
    public function sourceIsLocalization(): bool
    {
        $source = $this->source();

        return $source !== null && self::originOf($this->set(), $source->locale()) !== null;
    }

    public function hasOverride(string $handle): bool
    {
        return (bool) $this->variables()?->has($handle);
    }

    public function targetValue(string $handle): mixed
    {
        return $this->variables()?->get($handle);
    }

    // The origin's own value, else what it inherits, as core's globals edit page reads `originValues`.
    public function inheritedValue(string $handle): mixed
    {
        return $this->source()?->value($handle);
    }

    public function formFields(array $values, bool $source = false): Fields
    {
        $variables = $this->variables() ?? throw TargetNotFound::globalSet($this->handle, $this->site);

        // Every site of a set shares its blueprint object, so the parent is set right before fields().
        return $variables->blueprint()
            ->setParent($source ? ($this->source() ?? $variables) : $variables)
            ->fields()
            ->addValues($values);
    }

    public function document(): array
    {
        return [
            'type' => ContentType::GlobalSet->value,
            'title' => $this->title(),
            'container' => $this->title(),
            'url' => null,
            'summary' => $this->summary->of($this),
        ];
    }

    // The set's own edit ability is denied to ordinary users; the per-site variables carry the site check (§A14).
    public function authorize(User $user): bool
    {
        $site = Site::get($this->site);
        $variables = $this->variables();

        return $site !== null
            && $variables !== null
            && $user->can('view', $site)
            && $user->can('edit', $variables);
    }

    public function fresh(): static
    {
        return new self($this->handle, $this->site, $this->translatableFields, $this->summary, $this->settings, $this->resolver, $this->stateWriter);
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        $variables = $this->variables() ?? throw TargetNotFound::globalSet($this->handle, $this->site);
        [$writes, $skipped] = $this->writable($this->keyByHandle($fields), $options);
        $ref = $this->ref();
        $values = $this->prepareWrites($variables, $ref, $writes);

        if ($writes !== []) {
            foreach ($this->removedHandles($writes) as $handle) {
                $variables->remove($handle);
            }

            // Variables have no publish state, no slug, no revisions and no last-modified data.
            if ($variables->merge($values)->save() === false) {
                throw ApplyFailed::vetoed((string) $ref);
            }

            $this->confirmWrites($ref, $writes);
        }

        return new ApplyResult(ref: $ref, applied: array_keys($writes), skipped: $skipped);
    }

    private function set(): GlobalSetContract
    {
        return $this->set ??= GlobalSet::findByHandle($this->handle) ?? throw TargetNotFound::globalSet($this->handle);
    }

    /**
     * The site the set's variables in $site are translated from, read from the set: the variables Stache caches keep
     * a copy of the set as it was when they were cached, so their origin() can name an origin the set has changed.
     */
    private static function originOf(GlobalSetContract $set, string $site): ?string
    {
        $origin = $set->origins()->get($site);

        return $origin !== null && $set->sites()->contains($origin) ? $origin : null;
    }

    private function variables(): ?Variables
    {
        $this->resolve();

        return $this->variables;
    }

    private function source(): ?Variables
    {
        $this->resolve();

        return $this->source;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $set = $this->set();
        $origin = self::originOf($set, $this->site);
        $this->variables = $set->in($this->site);
        $this->source = $origin === null ? null : $set->in($origin);
        $this->resolved = true;
    }

    private function blueprint(): Blueprint
    {
        return $this->variables()->blueprint()->setParent($this->variables());
    }

    // A global set has no title of its own to generate.
    private function fieldContext(): array
    {
        return [
            'auto_title' => false,
            'excluded_fields' => $this->settings->excludedFields(),
        ];
    }
}
