<?php

use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;

beforeEach(function () {
    $this->useFileCache();

    Fieldset::make('seo')->setContents(['fields' => [
        ['handle' => 'description', 'field' => ['type' => 'textarea']],
    ]])->save();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'teaser' => ['type' => 'textarea', 'localizable' => true],
        'internal_note' => ['type' => 'text', 'display' => 'Internal note'],
        ['import' => 'seo', 'prefix' => 'seo_'],
    ]);
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => null], ['phone' => ['type' => 'text']], []);
});

function auditArticleField(string $handle): array
{
    return collect(Blueprint::find('collections.articles.article')->contents()['tabs'])
        ->flatMap(fn (array $tab) => collect($tab['sections'])->flatMap(fn (array $section) => $section['fields']))
        ->firstWhere('handle', $handle)['field'];
}

function postMakeLocalizable(array $body): TestResponse
{
    return test()->postJson(cp_route('tolk.audit.localizable'), $body);
}

const AUDIT_ARTICLE = ['namespace' => 'collections.articles', 'blueprint' => 'article'];

describe('page', function () {
    it('lists the checks with their levels, and the fields that are not localizable per blueprint with links to edit them', function () {
        $this->signInAdmin();

        $this->get(cp_route('tolk.audit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tolk::Audit')
                ->where('canMakeLocalizable', true)
                ->where('urls', ['localizable' => cp_route('tolk.audit.localizable'), 'overview' => cp_route('tolk.overview')])
                ->where('checks', fn ($checks) => collect($checks)->pluck('level', 'id')->all() === [
                    'sites' => 'ok',
                    'providers' => 'ok',
                    'queue' => 'ok',
                    'cache' => 'ok',
                    'state' => 'ok',
                    'localizable' => 'warning',
                    'technical_fields' => 'ok',
                    'content_sites' => 'warning',
                    'information' => 'ok',
                ])
                ->where('checks', function ($checks) {
                    $localizable = collect($checks)->firstWhere('id', 'localizable');

                    expect($localizable['items'])->toHaveCount(2)
                        ->and($localizable['items'][0])->toMatchArray([
                            'level' => 'warning',
                            'type' => 'collection',
                            'container' => 'articles',
                            'namespace' => 'collections.articles',
                            'blueprint' => 'article',
                            'name' => 'Collection Articles › Article',
                            'edit_url' => cp_route('blueprints.collections.edit', ['articles', 'article']),
                            'fields' => [
                                ['handle' => 'internal_note', 'label' => 'Internal note', 'type' => 'text', 'fieldset' => null, 'fieldset_url' => null],
                                ['handle' => 'seo_description', 'label' => 'Seo Description', 'type' => 'textarea', 'fieldset' => 'seo', 'fieldset_url' => cp_route('fieldsets.edit', 'seo')],
                            ],
                        ])
                        ->and($localizable['items'][1])->toMatchArray([
                            'type' => 'globals',
                            'namespace' => 'globals',
                            'blueprint' => 'site_settings',
                            'edit_url' => cp_route('blueprints.globals.edit', 'site_settings'),
                            'fields' => [['handle' => 'phone', 'label' => 'Phone', 'type' => 'text', 'fieldset' => null, 'fieldset_url' => null]],
                        ])
                        ->and(collect($checks)->firstWhere('id', 'content_sites')['items'])->toBe([[
                            'level' => 'warning',
                            'text' => "Global set Site Settings names no origin for German (de), so Tolk does not translate it. Choose the site to translate from as the origin in the set's sites.",
                        ]]);

                    return true;
                }));
    });

    it('lets only a user with the settings and the blueprint permission make fields localizable, and links blueprints for whoever may edit them', function (array $permissions, bool $canMake, bool $linked) {
        $this->signInWithPermissions(['access cp', 'run bulk translations', ...$permissions]);

        $this->get(cp_route('tolk.audit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canMakeLocalizable', $canMake)
                ->where('checks.5.items.0.edit_url', $linked ? cp_route('blueprints.collections.edit', ['articles', 'article']) : null)
                ->where('checks.5.items.0.fields.1.fieldset_url', $linked ? cp_route('fieldsets.edit', 'seo') : null));
    })->with([
        'bulk translations only' => [[], false, false],
        'the settings' => [['edit reachweb/statamic-tolk settings'], false, false],
        'the blueprints' => [['configure fields'], false, true],
        'both' => [['edit reachweb/statamic-tolk settings', 'configure fields'], true, true],
        'configure addons and the blueprints' => [['configure addons', 'configure fields'], true, true],
    ]);

    it('is refused without run bulk translations', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'edit reachweb/statamic-tolk settings', 'configure fields']);

        $this->getJson(cp_route('tolk.audit'))->assertForbidden();
    });
});

describe('making fields localizable', function () {
    it('changes the blueprint and answers with the checks as they are now', function () {
        $this->signInWithPermissions(['access cp', 'edit reachweb/statamic-tolk settings', 'configure fields']);

        $response = postMakeLocalizable([...AUDIT_ARTICLE, 'fields' => ['internal_note']])->assertOk();

        expect($response->json('changed'))->toBe(1)
            ->and(auditArticleField('internal_note'))->toMatchArray(['type' => 'text', 'localizable' => true])
            ->and(collect($response->json('checks'))->firstWhere('id', 'localizable')['items'][0]['fields'])->toBe([
                ['handle' => 'seo_description', 'label' => 'Seo Description', 'type' => 'textarea', 'fieldset' => 'seo', 'fieldset_url' => cp_route('fieldsets.edit', 'seo')],
            ]);
    });

    it('leaves fields of an imported fieldset and fields that are already localizable alone', function () {
        $this->signInAdmin();
        $path = Blueprint::find('collections.articles.article')->path();
        $before = file_get_contents($path);

        postMakeLocalizable([...AUDIT_ARTICLE, 'fields' => ['seo_description', 'teaser']])
            ->assertOk()
            ->assertJsonPath('changed', 0);

        expect(file_get_contents($path))->toBe($before)
            ->and(Fieldset::find('seo')->fields()->get('description')->isLocalizable())->toBeFalse();
    });

    it('is refused without the blueprint permission or the settings, and changes nothing', function (array $permissions) {
        $this->signInWithPermissions(['access cp', 'run bulk translations', ...$permissions]);

        postMakeLocalizable([...AUDIT_ARTICLE, 'fields' => ['internal_note']])->assertForbidden();

        expect(auditArticleField('internal_note'))->not->toHaveKey('localizable');
    })->with([
        'the settings' => [['edit reachweb/statamic-tolk settings']],
        'configure addons' => [['configure addons']],
        'the blueprints' => [['configure fields']],
    ]);

    it('refuses a blueprint it does not know', function () {
        $this->signInAdmin();

        postMakeLocalizable(['namespace' => 'collections.articles', 'blueprint' => 'missing', 'fields' => ['internal_note']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_blueprint');
    });

    it('validates the request', function (array $body, string $error) {
        $this->signInAdmin();

        postMakeLocalizable($body)->assertStatus(422)->assertJsonValidationErrors($error);
    })->with([
        'no namespace' => [['blueprint' => 'article', 'fields' => ['internal_note']], 'namespace'],
        'no blueprint' => [['namespace' => 'collections.articles', 'fields' => ['internal_note']], 'blueprint'],
        'no fields' => [[...AUDIT_ARTICLE, 'fields' => []], 'fields'],
        'fields as a map' => [[...AUDIT_ARTICLE, 'fields' => ['a' => 'internal_note']], 'fields'],
        'a field twice' => [[...AUDIT_ARTICLE, 'fields' => ['internal_note', 'internal_note']], 'fields.0'],
        'a field that is not a string' => [[...AUDIT_ARTICLE, 'fields' => [['internal_note']]], 'fields.0'],
    ]);
});
