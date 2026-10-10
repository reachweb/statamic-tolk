# Extending

## Translating another fieldtype

Tolk translates the text-bearing core fieldtypes. A fieldtype of another addon, or of your site, is left alone until a **handler** for it is registered. A handler knows where the text is in the fieldtype's value: it hands each piece of text to Tolk and puts the translation back in its place.

Tolk does not ship handlers for third-party fieldtypes. If your addon has a fieldtype with text in it, register a handler for it; if your site uses such an addon, ask its author, or register one in your site.

### A handler

A handler implements `Reach\StatamicTolk\Contracts\FieldHandler`:

```php
public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed;
```

`map()` receives a field's stored value and returns it with every piece of text replaced by `$translate($leaf)`, and nothing else changed. Tolk calls the same method to read the text out of a value (it collects the leaves and returns their own text) and to write translations in (it returns the translations in the same order), so the order of the pieces is the same both ways by construction.

The rule a handler must keep: **when `$translate` returns each leaf's own text, `map()` returns a value identical (`===`) to the one it was given.** No reformatting, no reordering, no normalising.

### A worked example: an SEO fieldtype

Say an addon has a fieldtype `seo_meta` that stores a meta title, a meta description, a social image and a "no index" flag:

```yaml
seo:
  title: 'Rooms with a sea view'
  description: 'Six rooms and suites above the beach in Chania, each with its own balcony.'
  image: seo/rooms.jpg
  noindex: false
```

Only the title and the description are text. This handler translates those two and leaves the rest alone:

```php
<?php

namespace App\Tolk;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

final class SeoMetaHandler implements FieldHandler
{
    use MakesLeaves;

    private const array TEXTS = [
        'title' => 'Meta title',
        'description' => 'Meta description',
    ];

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $hint = $this->hint($field->fieldtype());

        foreach (self::TEXTS as $key => $label) {
            if (is_string($value[$key] ?? null)) {
                $value[$key] = $this->plain($value[$key], $hint, $translate, $context->with($key, $label));
            }
        }

        return $value;
    }
}
```

What it uses:

- `MakesLeaves` is the trait Tolk's own handlers use. `plain()` offers one string as a leaf and returns its translation, or the string itself when it is not text (empty, a URL, a class name, see [Content types](content-types.md#what-counts-as-text)) or kept its text. It escapes `<`, `>` and `&` for the model and carries Antlers tags through as placeholders. `hint()` reads the field's **Translation hint** option.
- `$context->with($key, $label)` extends the leaf's path (`seo.title`) and its label. The model sees the label path ("SEO › Meta title"), which tells it what the text is for; so does the hint.
- A value of an unexpected shape is returned untouched. When that is worth telling the user, `$context->warn('…')` records a note that is shown with the field's result, on the run page and in the editor; Tolk's own Bard handler does this for data in the format of Statamic 2, which it leaves as it is.

For other shapes, look at the handlers in `src/Content/Handlers/`: `ListHandler` and `ArrayHandler` for lists and maps of strings, `GridHandler` and `ReplicatorHandler` for fieldtypes with fields of their own, which hand their rows to `$context->walker->mapFields()`. `$context->target` is true when Tolk walks the value of the target it translates into, rather than the value it translates from.

### Registering it

From an addon, call `Tolk::registerFieldtype()` in your service provider's `bootAddon()`, after your fieldtypes are registered. Guard it, so your addon also works on sites without Tolk:

```php
use Reach\StatamicTolk\Tolk;
use YourVendor\YourAddon\Tolk\SeoMetaHandler;

public function bootAddon(): void
{
    if (class_exists(Tolk::class)) {
        Tolk::registerFieldtype('seo_meta', SeoMetaHandler::class);
    }
}
```

This registers the handler and adds Tolk's per-field options (**Translate automatically** and **Translation hint**) to the fieldtype's settings in the blueprint editor. Fields of the fieldtype then follow the same rules as core fields: a top-level field must be localizable, and either option works on it.

In a site, register it in the published `config/tolk.php`:

```php
'fieldtypes' => [
    'seo_meta' => \App\Tolk\SeoMetaHandler::class,
],
```

or call `Tolk::registerFieldtype()` from the `boot()` method of your `AppServiceProvider`.

Precedence and boot order:

- A handler the site configures in `tolk.fieldtypes` wins over one an addon registers for the same fieldtype, so a site can replace an addon's handler, or a built-in one.
- An addon that registers a handler for a core fieldtype (`text`, `bard`, …) replaces Tolk's own.
- Tolk adds its per-field options to the fieldtypes in `tolk.fieldtypes` when it boots. A fieldtype that an addon booting after Tolk registers is not there yet, so a config entry for it translates its fields but gives it no options in the blueprint editor. Registering through `Tolk::registerFieldtype()` from that addon (or from your `AppServiceProvider`) adds them.

### Testing a handler

Tolk holds its own handlers to three properties with golden fixtures (`tests/Unit/Content/GoldenTest.php` in Tolk's repository). Test yours the same way, with values your fieldtype really stores:

1. **Identity**: when every leaf keeps its text, the value comes back identical, also on a walk over a localization (`target: true`).
2. **Stable leaves**: walking the same value twice gives the same leaves, and a translated value gives as many leaves as the original, so a later Update can pair them.
3. **Containment**: a translation changes the texts of the leaves and nothing else.

```php
<?php

use App\Tolk\SeoMetaHandler;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Tolk;
use Statamic\Fields\Field;

beforeEach(function () {
    Tolk::registerFieldtype('seo_meta', SeoMetaHandler::class);

    $this->walker = app(ContentWalker::class);
    $this->field = new Field('seo', ['type' => 'seo_meta', 'display' => 'SEO']);
    $this->value = [
        'title' => 'Rooms with a sea view',
        'description' => 'Six rooms and suites above the beach in Chania, each with its own balcony.',
        'image' => 'seo/rooms.jpg',
        'noindex' => false,
    ];
});

it('returns the value unchanged when every leaf keeps its text', function () {
    $identity = fn (Leaf $leaf) => $leaf->text;

    expect($this->walker->mapField($this->field, $this->value, $identity))->toBe($this->value)
        ->and($this->walker->mapField($this->field, $this->value, $identity, target: true))->toBe($this->value);
});

it('gives the same leaves on every walk', function () {
    $leaves = $this->walker->leaves($this->field, $this->value);
    $translated = $this->walker->mapField($this->field, $this->value, fn (Leaf $leaf) => 'Ω '.$leaf->text);

    expect(array_map(fn (Leaf $leaf) => $leaf->label, $leaves))->toBe(['SEO › Meta title', 'SEO › Meta description'])
        ->and($this->walker->leaves($this->field, $this->value))->toEqual($leaves)
        ->and($this->walker->leaves($this->field, $translated))->toHaveCount(count($leaves));
});

it('changes nothing but the texts of the leaves', function () {
    $translated = $this->walker->mapField($this->field, $this->value, fn (Leaf $leaf) => '«'.$leaf->text.'»');
    $constant = fn () => 'X';

    expect($translated)->not->toBe($this->value)
        ->and($this->walker->mapField($this->field, $translated, $constant))
        ->toBe($this->walker->mapField($this->field, $this->value, $constant));
});
```

The test needs the `seo_meta` fieldtype to be registered, as it is in the app or addon that has it.

## Events

Tolk dispatches three events, all in `Reach\StatamicTolk\Events`:

| Event | When | Properties |
| --- | --- | --- |
| `TargetTranslated` | Translations were saved into a target server-side: by a run, Translate into…, or the command line. | `ref` (a `TargetRef`: `type`, `id`, `site`; as a string `entry::{id}`, `term::{taxonomy}::{slug}::{site}`, `globals::{handle}::{site}`, `nav::{handle}::{site}`, `lang::{group}::{lang}` or `forms::{lang}`), `handles` (the fields saved), `models` (the models called, as `provider:model`), `usage` |
| `RunStarted` | A run moved from pending to running. | `runId`, `driver` (`RunDriver`: `browser`, `queue` or `cli`; `cli` also for an automatic update driven by the process that saved the entry), `createdBy` (`['id' => …, 'name' => …]`, or `null` from the command line and for an automatic update of an entry saved without a signed-in user), `counts` (items per status) |
| `RunFinished` | A run ended: completed, completed with errors, cancelled or aborted. A run that is resumed and ends again fires it again. | `runId`, `status` (`RunStatus`), `counts` (items per status), `usage` |

`usage` is a `UsageMap`; `$event->usage->toArray()` gives, per model, the `input`, `output`, `cache_read`, `cache_write` and `reasoning` tokens and the number of `calls`.

The editor does not save content itself, so it fires none of these: when an editor saves a translated localization, Statamic fires its own `EntrySaved` or `TermSaved`.

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Reach\StatamicTolk\Events\RunFinished;

Event::listen(function (RunFinished $event) {
    Log::info("Tolk run {$event->runId} ended: {$event->status->value}", $event->counts);
});
```

## What not to touch

- The files under `content/tolk/` are Tolk's record of what it translated. Commit them with the content, but do not edit them by hand: a wrong value makes a field read untranslated, outdated or edited by hand. `tolk:prune` cleans up the records of deleted content.
- Classes under `src/` other than `Tolk`, the `FieldHandler` contract, the `Leaf`, `WalkContext` and `ContentWalker` classes, the `MakesLeaves` trait and the events are internal and may change in any release.
