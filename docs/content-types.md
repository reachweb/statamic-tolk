# Content types

Tolk translates entries, taxonomy terms, global sets and navigation, in the CP and from the command line, and two kinds of files from the command line only: the app's translation files and the texts of forms.

The source of a translation is always the target's origin as Statamic defines it: a localization is translated from its origin, never from a sibling. Translations go from the origin into its localizations, not the other way.

## Which fields are translated

A field directly on a blueprint is translated when all of these hold:

1. It is **localizable**. A field that is not localizable is shared by every site, so there is nothing to translate. The Audit page and `tolk:doctor --fix-localizable` list the text fields that are not localizable and make them localizable for you.
2. Tolk has a handler for its fieldtype (below).
3. Its **Translate automatically** option is not switched off.
4. It is not hidden or computed.
5. Its handle is not in the **Excluded fields** setting.

A field nested inside a Replicator, Grid, Group or Bard set has no `localizable` flag of its own: it is translated when its fieldtype has a handler and its **Translate automatically** option is on.

Two fields are special:

- `slug` is never translated. A new localization keeps its origin's slug (see [Entries](#entries)).
- `title` is an ordinary text field, but it is left out on a collection that generates its titles, because Statamic writes it again on save.

### Fieldtypes

| Fieldtype | What is translated |
| --- | --- |
| Text | The text, when its input type is `text` (the default). Email, URL, number and other input types are left alone. |
| Textarea | The text. |
| Markdown | Each block (see [Markdown](#markdown)). |
| Bard | Each paragraph, heading, list item and table cell, with its formatting; link titles and image alt texts; the fields of its sets (see [Bard](#bard)). |
| Replicator, Grid, Group | The fields inside them, by the same rules. |
| Table | Each cell. |
| List | Each item. |
| Array | Each value; the keys stay. |

Any other fieldtype is left alone, unless a handler is registered for it: assets, toggles, selects, relationships, dates, and the fieldtypes of other addons. An addon or the site's developer can add a handler ([Extending](extending.md)).

### What counts as text

Inside those fields, a string is not sent to the model, and stays as it is, when it is empty, has no letter, or is only a reference or an identifier: a URL, a path such as `/contact`, a `mailto:` or `tel:` link, `entry::…` and `asset::…` references, a `#fragment`, an email address, a hex colour, or lowercase words joined by `-` or `_` such as `text-center` or `icon_arrow`, which is almost always a class name or a key.

### What stays as it is

Formatting, links, set types, ids and every value that is not text are kept exactly as in the origin; only the text changes. Inside the text:

- **Antlers tags** (`{{ site:name }}`, `{{ partial:cta }}`) are kept unchanged, and a translation may not add one.
- **HTML tags** in text fields are kept: the model sees placeholders in their place and must return each one. The `alt` and `title` attributes inside HTML tags are not translated; Bard's own link titles and image alt texts are.
- **Laravel parameters** (`:name`) are kept in translation files.
- A translation may not introduce markup the source did not have: a `<script>` or any other new tag, an escaped tag, or new placeholders. Tolk asks the model again, and keeps the source text if it does not comply.

### Markdown

A Markdown field is translated block by block (blank lines separate blocks), so a long article goes out in parts and an update sends only the changed blocks.

- Code (fenced, indented and inline), link and image targets, reference definitions (their titles too), footnote references, autolinks, attribute lists and raw HTML tags stay as they are. Link texts and titles are translated.
- A collapsed or shortcut reference (`[price list][]`, `[our story]`) becomes a full one once translated (`[τιμοκατάλογος][price list]`), so it still points to its definition.
- An indented block counts as code, unless it continues a list item.
- A translation may not add or change a link, an image or a web or email address (`https://…`, `www.…`, `name@example.com`): a Markdown field with automatic links would turn such text into a link. The source's own addresses are kept.

### Bard

Bard content is translated paragraph by paragraph, never through HTML. Bold, italic, links and other marks reach the model as numbered placeholders around the words they cover, and come back around the translated words, so formatting and links survive even when the word order changes. Inline images, hard breaks and code are kept in place. Link titles and image alt texts are translated as separate pieces. Code blocks are left alone. The fields of Bard sets are translated like any nested field.

A Bard field stored as HTML (`save_html: true`) is translated block by block too. As in text fields, `alt` and `title` attributes inside its HTML are not translated.

## Entries

All the CP's ways and every command translate entries.

- **New localizations** are created by Tolk server-side (runs, Translate into…, the command line) as **drafts**, unless the setting or the run says **Same as origin**: then they are published when their origin is, the user may publish, and every field was translated completely. In the editor nothing is saved until you save.
- **Slugs** are never translated. A new localization keeps its origin's slug. When another entry of the collection in that site already uses it, Tolk appends `-2`, `-3`, … and adds a warning. An existing slug is never changed.
- **Structure**: a new page is placed under its parent's localization in the target site's tree, so a run translates parents before children. In a collection whose tree has a root page, the first page placed in a site's empty tree becomes that site's home page, so the root page's localization is created first; a page whose root page has no localization there is skipped with "Translate Home into Greek first."
- **Chains**: in a site translated from another localization (Swiss German from German), a field is translated only when that localization holds its own text for it. While it still inherits from its origin, the text would not be in the language the model is told, so the field waits ("not translated in its source yet").
- **Revisions**: on a collection with revisions, translations of a published localization go to its working copy, as the CP's own saves do. See [Bulk translations and runs](bulk-and-runs.md#revisions).

## Terms

A taxonomy's terms exist in every site of the taxonomy, so a term never needs creating. They are translated from the taxonomy's **first site** into the others.

- In the term form of another site, the banner shows the term's status with **Translate** and **Update**, as for entries. There is no offer, because the CP never creates a term localization.
- In the term form of the first site, the banner lists the other sites with **Translate into…** and **Update translations**.
- In a taxonomy's term listing, **Translate…** starts a run.
- From the command line: `tolk:translate --taxonomy=topics --site=el`.

Term slugs are never localized.

## Global sets

A global set is translated, for each site, from the origin the set names for that site (Statamic's **Origin** in the set's site settings), never from another site. A site translated from a localization waits for it: Swiss German from German is translated after German. `--from` does not apply to global sets.

- Translate them from the Overview's cells or with `tolk:translate --globals=site_settings --site=el`.
- The global set form has no banner.
- A site without an origin is not translated and is reported: the Overview leaves it out, the audit lists it and `tolk:translate` names it. A set without a blueprint is left out the same way. `tolk:site` offers to give a new site an origin.

## Navigation

Navigation trees are separate per site, with nothing inherited, so Tolk translates a whole tree as one piece.

- The source is the default site's tree, or the tree of the `--from` site when the navigation has one there. Any other site can be translated into.
- A navigation is translated after the entries of the same run, so its branches can point at the new localizations.
- An **entry branch** links the target site's localization of its entry. A branch whose entry has no localization there is left out; when branches below it remain, it is kept as a text branch titled with its entry's title, translated with the tree. Either way the item gets a warning, and the tree reads outdated until the localization exists, so a later Update adds the branch.
- Branch ids are kept. Branch titles and the fields of the navigation's blueprint are translated; URLs are kept.
- Translate navigation from the Overview's cells or with `tolk:translate --nav=main --site=el`. The navigation editor has no banner.

A navigation whose tree has no text of its own (only entry branches without custom titles) has nothing to translate, so Tolk does not create its tree in a site that has none. Create the tree in that site by hand.

## Translation files and forms

Two commands translate what is not Statamic content. They run from the command line only and have no CP surface:

- `tolk:lang` translates the app's Laravel translation files, `lang/{lang}.json` and `lang/{lang}/*.php`, with every `:parameter` and plural form kept.
- `tolk:forms` translates the titles, field labels, instructions, placeholders, option labels and email subjects of the site's forms into `lang/{lang}.json`. Statamic passes only email subjects through the translator by itself, so the form templates need `| trans` for the rest.

Both track their strings like content: a second run sends nothing, and a string edited by hand is kept. See [`tolk:lang`](cli.md#tolklang) and [`tolk:forms`](cli.md#tolkforms).

## Not translated in this version

- Asset alt text and other asset fields. A later version translates alt text.
- `lang/vendor/` files, form texts inside Grid or Group fields, and texts written in templates.
- Fields of third-party fieldtypes, such as those of SEO addons, until a handler is registered ([Extending](extending.md)).
- Slugs, by design.
