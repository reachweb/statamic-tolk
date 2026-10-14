# Statuses

Tolk tracks every translated field: whether its origin changed since it was translated, and whether someone edited its text by hand. That is what lets Update send only what changed and never overwrite your edits.

## Field statuses

| Status | Shown as | Meaning | Translate | Update |
| --- | --- | --- | --- | --- |
| Untranslated | *untranslated* | The localization has no translation of the field: it inherits the origin's text, or holds a copy of it that nobody translated. | translates it | translates it |
| Up to date | *up to date* | Tolk translated it, nobody changed its text since, and the origin has not changed. | leaves it | leaves it |
| Outdated | *outdated* | Tolk translated it and nobody changed its text, but the origin changed since, or part of it is still untranslated. | leaves it | translates it again |
| Edited by hand | *edited by hand* | Someone changed its text after Tolk translated it, or wrote it by hand. | leaves it | leaves it |
| Not applicable | not counted | The origin's field is empty or has no text to translate. | | |

Two kinds of fields edited by hand are counted on their own:

- **Needs attention**: edited by hand, and the origin changed since. Check the text against the origin, edit it if needed, then mark it as up to date: editing alone does not clear the flag.
- **Untracked**: edited by hand before Tolk knew which origin text it belongs to (a site translated by hand before Tolk was installed). A change to the origin cannot be noticed until it is recorded ([baselines](#baselines)).

**Retranslate everything** (mode `all`) also translates up-to-date fields again, and **Overwrite manual edits** (`--force`) also replaces fields edited by hand. The field action **Translate from origin** does both for one field.

## Item statuses

An entry, a term, a global set or a navigation in one site counts once, by the worst status among its fields:

1. **Missing**: the localization does not exist yet.
2. **Untranslated**: at least one field is untranslated.
3. **Outdated**: at least one field is outdated.
4. **Needs attention**: at least one field edited by hand has a changed origin.
5. **Up to date** (*Current* on the Overview and in `tolk:status`): everything else.

The Overview and `tolk:status` count items this way, and also report the untracked fields.

## How Tolk decides

Nothing is decided from a stored status. Tolk keeps a small record per translated field in `content/tolk/` (a fingerprint of the origin's value it translated from, a fingerprint of the text it produced, the model and the time), and compares it with the content every time a status is shown:

- The origin's value is compared with the fingerprint it was translated from. Any change to it, text or structure, makes the translation outdated.
- The localization's **text** is compared with the text Tolk produced. Only the text: not the structure around it.

Because the records live next to the content and are compared, not trusted, Tolk notices changes made anywhere: in the CP, in a file, by a git pull, by another addon.

### Only text makes a field manual

A field counts as machine-translated as long as its text is exactly what Tolk produced. Changes that are not text are not tracked:

- swapping an image, toggling an option or changing a link target in a translated field does not make it "edited by hand";
- neither do the ids, `enabled` flags and other values Statamic's forms add when they save content.

The other side: **Update rebuilds a field from the origin's structure.** When it translates an outdated field again, it takes the origin's current value and puts the translations into it, so a non-text change made only in the localization (a different image in a Bard field) is replaced by the origin's. The same goes for a navigation's pages: a page added, removed or moved by hand in a translated tree, without a change to any title, leaves the tree up to date, and once it reads outdated, Update puts the origin's pages back in the origin's order ([Navigation](content-types.md#navigation)). If a localization is meant to differ in more than its text, protect it ([below](#protecting-translations)) or mark the field as edited by hand by changing a word.

Defaults Statamic fills in do not count either. When a field with a text default (a "Read more" label) is added to a Replicator or Bard set later, Statamic's next save puts that default, in the source language, into every existing set. Tolk ignores nested values that equal their field's default when it reads a localization, so they do not turn the field "edited by hand".

### A copy of the origin is untranslated

A field whose text is exactly its origin's, with no record of a translation, reads **untranslated**, not "edited by hand": no human changed its text. Statamic saves such copies by itself. A Bard field a localization inherits can rewrite its own value while the form loads (normalising an image, for example), and the next Save stores the origin's Bard content as the localization's own value. Tolk treats that copy as untranslated, and Translate replaces it.

A field that is meant to read the same in both languages (a product name) and was written by hand reads untranslated for the same reason. **Protect this entry from updates** (offered while the entry also has a machine-translated field) or `tolk:baseline --include-copies` keeps such a field: once recorded, it is edited by hand. (**Mark manual fields as up to date** does not: it records only fields that already read as edited by hand.)

### Content written outside the CP

The first time an editor saves origin content in the CP that was written another way (imported, seeded, saved by code), Statamic adds row ids and `enabled` flags to its Replicator, Grid and Bard values. That changes the origin's fingerprint, so its machine translations read **outdated once**, although no text changed. **Update** fixes it without calling a model: every paragraph is reused from the earlier translation, and only the new structure is written.

### Saving without changes

Opening a localization and saving it without editing a text changes no status.

## How Update reuses earlier work

When a field is outdated and nobody edited its text, Update sends only the paragraphs whose origin text changed (or that are new, or were left untranslated before). Every unchanged paragraph keeps its earlier translation, even when paragraphs were added, removed or moved around it. A changed paragraph is sent with its neighbours as context. A field whose origin changed only in structure is rebuilt without a model call.

Mode `all` (**Retranslate everything**) reuses nothing.

## Manual fields

A field is edited by hand as soon as its text differs from what Tolk produced, however small the change. From then on:

- Translate and Update leave it alone, so your edits are never overwritten.
- When its origin changes and Tolk knows which origin text it belongs to, it **needs attention**.
- **Overwrite manual edits** (`--force`) replaces it; it is machine-translated again afterwards.
- A review leaves it alone unless you ask for it with **Overwrite manual edits**; then the reviewed field stays edited by hand.

Editing a filled field in the editor before saving it makes it edited by hand right away.

## Baselines

A **baseline** records which origin text a field edited by hand belongs to, without claiming Tolk translated it. The field stays edited by hand, and a later change to the origin makes it need attention.

- **Mark manual fields as up to date**, in the editor's options, records the current origin for the entry's fields edited by hand. Use it after checking them against the origin, which also clears "needs attention".
- `tolk:baseline` does the same in bulk, for a site translated by hand before Tolk ([The command line](cli.md#tolkbaseline)). It records every field edited by hand it selects, so it also clears "needs attention" there.

## Protecting translations

**Protect this entry from updates**, in the editor's options, turns the entry's machine-translated fields into fields edited by hand, with a baseline. Translate and Update then leave them alone, and a change to the origin shows as needing attention instead of being translated over. It also marks the fields already edited by hand as up to date. The action is shown while the entry has a machine-translated field.

Global sets and navigations have no editor, so they have no such action. Protect them from the command line instead: `tolk:baseline --protect` does the same for every item of the containers it names, for example `php please tolk:baseline --nav=main --site=el --protect` ([The command line](cli.md#tolkbaseline)). It works for collections and taxonomies too.

To protect one field everywhere instead, switch off its **Translate automatically** option in the blueprint ([Configuration](configuration.md#per-field-options)).

## Fields left partly untranslated

When one part of a field cannot be translated (the model refuses it, or still returns text that breaks its formatting after two repairs), the field is delivered anyway with that part in the source language:

- the field reads **outdated**, so the next Update tries the missing part again, and only that part;
- the editor's banner names it ("Intro is partly untranslated. Update translates the rest after you save."), runs list it as left incomplete, and the command line prints the part;
- a new localization with such a field stays a draft even with **Same as origin**.

With the **Strict fields** setting on, such a field is not delivered at all and keeps its previous value.

## When the origin is emptied

When a field is emptied in the origin, its translation reads outdated, and Update removes it, so the localization inherits the empty field again. A field edited by hand is kept and needs attention.
