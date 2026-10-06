<?php

use Illuminate\Support\Facades\Artisan;
use Reach\StatamicTolk\Support\Audit;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;

beforeEach(function () {
    $this->useFileCache();

    Fieldset::make('seo')->setContents(['fields' => [
        ['handle' => 'description', 'field' => ['type' => 'textarea']],
    ]])->save();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'internal_note' => ['type' => 'text', 'display' => 'Internal note'],
        ['import' => 'seo', 'prefix' => 'seo_'],
    ]);
    $this->makeCollection('pages', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'css_class' => ['type' => 'text', 'display' => 'CSS class'],
    ]);
});

function articleField(string $handle): bool
{
    return Collection::find('articles')->entryBlueprint()->field($handle)->isLocalizable();
}

function pageField(string $handle): bool
{
    return Collection::find('pages')->entryBlueprint()->field($handle)->isLocalizable();
}

it('prints every check with its level, the detail of a check that is not ok, and the items', function () {
    $exitCode = Artisan::call('tolk:doctor');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Sites \.+ OK/')
        ->and($output)->toContain('✓ 3 sites: English (en), Greek (el), German (de). English is the default site.')
        ->and($output)->toMatch('/Localizable fields \.+ WARNING/')
        ->and($output)->toContain('A text field that is not localizable is shared by every site, so Tolk does not translate it.')
        ->and($output)->toContain('! Collection Articles › Article: Internal note (internal_note), Seo Description (seo_description, from the fieldset seo).')
        ->and($output)->toContain('! Collection Pages › Page: CSS class (css_class).')
        ->and($output)->toMatch('/How Tolk works on this site \.+ OK/')
        ->and($output)->not->toContain('Information only.');
});

it('exits with 1 when a check fails', function () {
    config(['tolk.cache_store' => 'array', 'cache.stores.array' => ['driver' => 'array']]);

    $exitCode = Artisan::call('tolk:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toMatch('/Cache \.+ ERROR/');
});

it('prints the checks as JSON', function () {
    expect(Artisan::call('tolk:doctor', ['--json' => true]))->toBe(0);

    $checks = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($checks, 'id'))->toBe(['sites', 'providers', 'queue', 'cache', 'state', 'localizable', 'technical_fields', 'content_sites', 'information'])
        ->and($checks[5]['items'][0]['fields'][0])->toBe(['handle' => 'internal_note', 'label' => 'Internal note', 'type' => 'text', 'fieldset' => null]);

    config(['tolk.cache_store' => 'missing']);

    expect(Artisan::call('tolk:doctor', ['--json' => true]))->toBe(1);
});

it('refuses --json with --fix-localizable', function () {
    $this->artisan('tolk:doctor', ['--json' => true, '--fix-localizable' => true])
        ->expectsOutputToContain('--json and --fix-localizable cannot be combined.')
        ->assertExitCode(1);
});

it('asks per blueprint before making its fields localizable, and leaves fields of an imported fieldset alone', function () {
    $this->artisan('tolk:doctor', ['--fix-localizable' => true])
        ->expectsOutputToContain('Collection Articles › Article: Seo Description comes from the fieldset seo, which this command does not change.')
        ->expectsConfirmation('Make Internal note localizable in Collection Articles › Article?', 'yes')
        ->expectsOutputToContain('Collection Articles › Article: 1 field is now localizable.')
        ->expectsConfirmation('Make CSS class localizable in Collection Pages › Page?', 'no')
        ->assertExitCode(0);

    expect(articleField('internal_note'))->toBeTrue()
        ->and(articleField('seo_description'))->toBeFalse()
        ->and(pageField('css_class'))->toBeFalse();
});

it('says so when every field is localizable', function () {
    Fieldset::find('seo')->setContents(['fields' => [
        ['handle' => 'description', 'field' => ['type' => 'textarea', 'localizable' => true]],
    ]])->save();
    app(Audit::class)->makeLocalizable('collections.articles', 'article', ['internal_note']);
    app(Audit::class)->makeLocalizable('collections.pages', 'page', ['css_class']);

    $this->artisan('tolk:doctor', ['--fix-localizable' => true])
        ->expectsOutputToContain('Every text field Tolk translates is localizable.')
        ->assertExitCode(0);
});
