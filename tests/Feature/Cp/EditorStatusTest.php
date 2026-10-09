<?php

use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Http\EditorStatus;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'note' => ['type' => 'text', 'display' => 'Note'],
        'featured' => ['type' => 'toggle', 'localizable' => true],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.', 'note' => 'Internal'], 'hello');
});

function editorStatus(EntryContract|string $entry): TestResponse
{
    return test()->getJson(cp_route('tolk.editor.status', [
        'reference' => $entry instanceof EntryContract ? $entry->reference() : $entry,
    ]));
}

function translateForStatus(EntryContract $source, string $site): void
{
    test()->fakeTranslations();

    app(TargetTranslator::class)->translate(
        app(TargetFactory::class)->forEntry($source, $site),
        PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']),
        new ApplyOptions,
    );
}

function statusCounts(array $counts = []): array
{
    return [...['untranslated' => 0, 'current' => 0, 'outdated' => 0, 'manual' => 0, 'attention' => 0, 'untracked' => 0], ...$counts];
}

describe('a localization', function () {
    it('returns its fields, counts, out-of-scope fields and the origin slug', function () {
        $this->signInAdmin();
        $greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        editorStatus($greek)
            ->assertOk()
            ->assertJsonPath('translatable', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('origin', null)
            ->assertJsonPath('localization', [
                'source' => ['site' => 'en', 'name' => 'English'],
                'target' => ['site' => 'el', 'name' => 'Greek'],
                'status' => 'untranslated',
                'fields' => [
                    'title' => ['status' => 'manual', 'label' => 'Title', 'source_changed' => null, 'incomplete' => false],
                    'intro' => ['status' => 'untranslated', 'label' => 'Introduction', 'source_changed' => null, 'incomplete' => false],
                ],
                'counts' => statusCounts(['untranslated' => 1, 'manual' => 1, 'untracked' => 1]),
                'out_of_scope' => [
                    ['handle' => 'note', 'label' => 'Note', 'type' => 'text', 'reason' => 'not_localizable'],
                ],
                'origin_slug' => 'hello',
                'translate_slug' => false,
            ]);
    });

    it('says whether the collection gives new localizations translated slugs', function () {
        $this->signInAdmin();
        $greek = $this->localize($this->origin, 'el');

        editorStatus($greek)->assertJsonPath('localization.translate_slug', false);

        $this->withSettings(['translated_slugs' => [$this->origin->collectionHandle()]]);

        editorStatus($greek)
            ->assertJsonPath('localization.translate_slug', true)
            ->assertJsonPath('localization.origin_slug', 'hello');
    });

    it('reads machine output as current and an origin edit as outdated', function () {
        $this->signInAdmin();
        translateForStatus($this->origin, 'el');
        $greek = $this->origin->in('el');

        editorStatus($greek)
            ->assertJsonPath('localization.status', 'current')
            ->assertJsonPath('localization.counts', statusCounts(['current' => 2]));

        $this->origin->set('intro', 'A longer intro.')->save();

        editorStatus($greek)
            ->assertJsonPath('localization.status', 'outdated')
            ->assertJsonPath('localization.fields.intro.status', 'outdated')
            ->assertJsonPath('localization.fields.intro.source_changed', true)
            ->assertJsonPath('localization.fields.title.status', 'current');
    });
});

describe('an origin', function () {
    it('lists each site it is the origin of, missing ones included', function () {
        $this->signInAdmin();
        translateForStatus($this->origin, 'el');
        $greek = $this->origin->in('el');

        editorStatus($this->origin)
            ->assertOk()
            ->assertJsonPath('translatable', true)
            ->assertJsonPath('localization', null)
            ->assertJsonPath('origin.localizations', [
                [
                    'site' => 'el',
                    'name' => 'Greek',
                    'exists' => true,
                    'status' => 'current',
                    'counts' => statusCounts(['current' => 2]),
                    'edit_url' => $greek->editUrl(),
                ],
                [
                    'site' => 'de',
                    'name' => 'German',
                    'exists' => false,
                    'status' => 'missing',
                    'counts' => statusCounts(['untranslated' => 2]),
                    'edit_url' => null,
                ],
            ]);
    });

    it('lists only the sites the user may access', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);

        editorStatus($this->origin)
            ->assertOk()
            ->assertJsonPath('origin.localizations.*.site', ['el']);
    });

    it('is not translatable for a user who may access none of its other sites', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'edit articles entries']);

        editorStatus($this->origin)
            ->assertOk()
            ->assertJsonPath('translatable', false)
            ->assertJsonPath('reason', EditorStatus::NO_ACCESS)
            ->assertJsonPath('localization', null)
            ->assertJsonPath('origin', null);
    });
});

describe('a chain', function () {
    beforeEach(function () {
        $this->german = $this->localize($this->origin, 'de', ['title' => 'Hallo', 'intro' => 'Eine kurze Einleitung.']);
        $this->greek = $this->localize($this->german, 'el');
    });

    it('gives an entry in the middle of the chain both blocks', function () {
        $this->signInAdmin();

        editorStatus($this->german)
            ->assertOk()
            ->assertJsonPath('localization.source', ['site' => 'en', 'name' => 'English'])
            ->assertJsonPath('localization.target', ['site' => 'de', 'name' => 'German'])
            ->assertJsonPath('origin.localizations.*.site', ['el'])
            ->assertJsonPath('origin.localizations.0.exists', true)
            ->assertJsonPath('origin.localizations.0.status', 'untranslated')
            ->assertJsonPath('origin.localizations.0.edit_url', $this->greek->editUrl());
    });

    it('lists under the root only its own localizations', function () {
        $this->signInAdmin();

        editorStatus($this->origin)->assertJsonPath('origin.localizations.*.site', ['de']);
        editorStatus($this->greek)
            ->assertJsonPath('localization.source.site', 'de')
            ->assertJsonPath('origin', null);
    });
});

it('is not translatable in a single-site collection', function () {
    $this->signInAdmin();
    $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);

    editorStatus($this->makeEntry('notes', 'en', ['title' => 'Memo']))
        ->assertOk()
        ->assertJsonPath('translatable', false)
        ->assertJsonPath('reason', EditorStatus::SINGLE_SITE)
        ->assertJsonPath('localization', null)
        ->assertJsonPath('origin', null);
});

it('is not translatable in a collection the settings exclude, as a localization or an origin', function () {
    $this->signInAdmin();
    $this->withSettings(['excluded_collections' => ['articles']]);

    editorStatus($this->localize($this->origin, 'el'))
        ->assertOk()
        ->assertJsonPath('translatable', false)
        ->assertJsonPath('reason', EditorStatus::EXCLUDED)
        ->assertJsonPath('localization', null)
        ->assertJsonPath('origin', null);
    editorStatus($this->origin)->assertOk()->assertJsonPath('reason', EditorStatus::EXCLUDED);
});

it('is not translatable for an unknown reference', function (string $reference) {
    $this->signInAdmin();

    editorStatus($reference)
        ->assertOk()
        ->assertJsonPath('translatable', false)
        ->assertJsonPath('reason', EditorStatus::UNKNOWN_REFERENCE);
})->with([
    'a missing entry' => 'entry::missing',
    'no reference format' => 'hello',
    'an unsupported type' => 'term::tags::hello::el',
]);

describe('access', function () {
    it('forbids a user who cannot edit the entry', function () {
        $greek = $this->localize($this->origin, 'el');
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']);

        editorStatus($greek)->assertForbidden();
        editorStatus($this->origin)->assertForbidden();
    });

    it('forbids a user without access to the entry\'s site', function () {
        $greek = $this->localize($this->origin, 'el');
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'edit articles entries']);

        editorStatus($greek)->assertForbidden();
    });

    it('lets an editor of the entry through', function () {
        $greek = $this->localize($this->origin, 'el');
        $this->signInWithPermissions(['access cp', 'translate content', 'access el site', 'edit articles entries']);

        editorStatus($greek)->assertOk()->assertJsonPath('localization.target.site', 'el');
    });
});

describe('defaults', function () {
    it('names the translator and the offer, review and new localization settings', function () {
        $this->signInAdmin();

        editorStatus($this->origin)
            ->assertJsonPath('defaults', ['translator' => 'anthropic:claude-sonnet-5-5', 'providers_configured' => true, 'fake_engine' => false, 'review' => false, 'new_status' => 'draft'])
            ->assertJsonPath('offer', true);

        $this->withSettings(['offer_on_create' => false, 'translator_model' => 'gemini:gemini-3.8-flash', 'review_enabled' => true, 'new_localization_status' => 'mirror']);

        editorStatus($this->origin)
            ->assertJsonPath('defaults.translator', 'gemini:gemini-3.8-flash')
            ->assertJsonPath('defaults.review', true)
            ->assertJsonPath('defaults.new_status', 'mirror')
            ->assertJsonPath('offer', false);
    });

    it('has no translator without a provider, unless the fake engine is on', function () {
        $this->signInAdmin();
        $this->withProviderKeys();

        editorStatus($this->origin)
            ->assertJsonPath('defaults', ['translator' => null, 'providers_configured' => false, 'fake_engine' => false, 'review' => false, 'new_status' => 'draft']);

        config(['tolk.fake_engine' => true]);

        editorStatus($this->origin)
            ->assertJsonPath('defaults', ['translator' => 'anthropic:claude-sonnet-5-5', 'providers_configured' => false, 'fake_engine' => true, 'review' => false, 'new_status' => 'draft']);
    });

    it('comes with a payload that is not translatable too', function () {
        $this->signInAdmin();

        editorStatus('entry::missing')
            ->assertJsonStructure(['translatable', 'reason', 'localization', 'origin', 'active_run', 'defaults' => ['translator', 'providers_configured', 'fake_engine', 'review', 'new_status'], 'offer'])
            ->assertJsonPath('active_run', null);
    });
});
