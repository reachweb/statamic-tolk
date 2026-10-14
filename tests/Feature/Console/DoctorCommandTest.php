<?php

use Illuminate\Support\Facades\Artisan;
use Reach\StatamicTolk\Support\Audit;
use Reach\StatamicTolk\Tests\Support\CliRun;
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

    expect(array_column($checks, 'id'))->toBe(['sites', 'providers', 'queue', 'cache', 'state', 'localizable', 'technical_fields', 'content_sites', 'command_line', 'information'])
        ->and($checks[5]['items'][0]['fields'][0])->toBe(['handle' => 'internal_note', 'label' => 'Internal note', 'type' => 'text', 'fieldset' => null]);

    config(['tolk.cache_store' => 'missing']);

    expect(Artisan::call('tolk:doctor', ['--json' => true]))->toBe(1);
});

it('warns when the PHP of the command line has no posix extension, which the workers of a killed tolk:translate need to cancel its run', function () {
    $directory = $this->temporaryPath();

    // As on Windows, which has neither pcntl nor posix; the signals are on, as outside tests, so pcntl reads as there.
    $doctor = CliRun::start($directory, ['artisan' => ['tolk:doctor', ['--json' => true]], 'ini' => ['disable_functions=posix_getppid,posix_kill'], 'signals' => true]);
    $doctor->wait();

    $checks = json_decode((string) @file_get_contents("{$directory}/output/command"), true) ?? [];
    $check = collect($checks)->firstWhere('id', 'command_line');

    expect($check, $doctor->getOutput().$doctor->getErrorOutput())->not->toBeNull()
        ->and($check['level'])->toBe('warning')
        ->and($check['items'])->toBe([
            ['level' => 'ok', 'text' => 'PHP on the command line has the pcntl extension, so Ctrl-C cancels a run of tolk:translate after the current requests.'],
            ['level' => 'warning', 'text' => 'PHP on the command line has no posix extension (Windows never has it), so the workers of a tolk:translate that was killed (kill -9, the out-of-memory killer) go on translating every item left, which the provider charges for and nothing saves. Press Cancel on the run page then, and Resume to save what they translated; or add posix to PHP.'],
        ]);
})->group('slow');

it('warns when the PHP of the command line has pcntl but its signal functions are disabled, which tolk:translate needs to trap Ctrl-C', function () {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('Needs the pcntl extension.');
    }

    $directory = $this->temporaryPath();
    $doctor = CliRun::start($directory, ['artisan' => ['tolk:doctor', ['--json' => true]], 'ini' => ['disable_functions=pcntl_signal'], 'signals' => true]);
    $doctor->wait();

    $checks = json_decode((string) @file_get_contents("{$directory}/output/command"), true) ?? [];
    $check = collect($checks)->firstWhere('id', 'command_line');

    expect($check, $doctor->getOutput().$doctor->getErrorOutput())->not->toBeNull()
        ->and($check['level'])->toBe('warning')
        ->and($check['items'][0])->toBe(['level' => 'warning', 'text' => 'PHP on the command line has the pcntl extension, but disable_functions in php.ini disables pcntl_signal, so Ctrl-C ends tolk:translate and its workers at once, as without pcntl: the requests under way are lost, and the run stays running until you resume it on its run page or tolk:prune stops it. Stop a run with Cancel on its run page instead, or take pcntl_signal out of disable_functions.']);
})->group('slow');

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
