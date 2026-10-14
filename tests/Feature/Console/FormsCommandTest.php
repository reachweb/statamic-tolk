<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\FormFixtures;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Statamic\Facades\Site;

beforeEach(function () {
    $this->useFileCache();
    $this->app->useLangPath($this->temporaryPath('lang'));
    (new Filesystem)->ensureDirectoryExists(lang_path());
    file_put_contents(lang_path('en.json'), json_encode(['Contact us' => 'Contact us', 'Book now' => 'Book now'], JSON_PRETTY_PRINT));
    FormFixtures::contact();
    FormFixtures::newsletter();
});

/**
 * Runs the command in this process and returns its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function formsCommand(array $arguments = []): array
{
    $exitCode = Artisan::call('tolk:forms', $arguments);

    return [$exitCode, Artisan::output()];
}

function formsJson(string $lang): ?array
{
    return file_exists(lang_path("{$lang}.json")) ? json_decode(file_get_contents(lang_path("{$lang}.json")), true) : null;
}

/**
 * Laravel's translator, reading the test's lang path: the app's own loader was built with the path it booted with.
 */
function formsTranslator(string $locale): Translator
{
    return new Translator(new FileLoader(new Filesystem, lang_path()), $locale);
}

it('adds the texts of every form to lang/{lang}.json under their English keys and keeps its other keys', function () {
    file_put_contents(lang_path('de.json'), json_encode(['Contact us' => 'Kontakt', 'Own key' => 'Eigener Text']));
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);
    $written = formsJson('de');

    expect($exitCode)->toBe(0)
        ->and(array_slice($written, 0, 2))->toBe(['Contact us' => 'Kontakt', 'Own key' => 'Eigener Text'])
        ->and($written['Your message'])->toBe('[T] Your message')
        ->and($written['We only use it to reply to you.'])->toBe('[T] We only use it to reply to you.')
        ->and($written['Room booking'])->toBe('[T] Room booking')
        ->and($written['New enquiry from the website'])->toBe('[T] New enquiry from the website')
        ->and($written)->toHaveCount(18)
        ->and($written)->not->toHaveKey('booking')
        ->and($written)->not->toHaveKey('Book now')
        ->and(formsJson('el'))->toBeNull()
        ->and(formsTranslator('de')->get('Your message'))->toBe('[T] Your message')
        ->and(formsTranslator('de')->get('Contact us'))->toBe('Kontakt')
        ->and($output)->toContain('Files: 1 (German).')
        ->and($output)->toContain('1 text is a key of lang/en.json, so tolk:lang translates it.')
        ->and($output)->toContain('these subjects are not translated: Contact us › email subject.')
        ->and($output)->toContain('lang/de.json: done, 16 fields')
        ->and($output)->toMatch('/\| 1 +\| 0 +\| 0 +\| 0 +\|/')
        ->and($output)->toContain('{{ display | trans }}, {{ instructions | trans }}, {{ placeholder | trans }}, and {{ label | trans }} for options');
});

it('leaves the texts that are keys of a PHP translation file to tolk:lang, so the German form keeps their translations', function () {
    FormFixtures::keys('en', ['Write to us', 'Your full name', 'A new enquiry']);
    FormFixtures::keys('de', ['Schreiben Sie uns', 'Ihr Name', 'Eine neue Anfrage']);
    app('translator')->addNamespace('acme', $this->temporaryPath('acme/lang'));
    FormFixtures::keyed();
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);
    $written = formsJson('de');

    expect($exitCode)->toBe(0)
        ->and(array_intersect_key($written, array_flip(['forms.contact.title', 'forms.contact.name', 'forms.contact.subject', 'acme::forms.placeholder'])))->toBe([])
        ->and($written['forms.contact.missing'])->toBe('[T] forms.contact.missing')
        ->and(formsTranslator('de')->get('forms.contact.name'))->toBe('Ihr Name')
        ->and(formsTranslator('de')->get('forms.contact.title'))->toBe('Schreiben Sie uns')
        ->and(formsTranslator('de')->get('forms.contact.subject'))->toBe('Eine neue Anfrage')
        ->and($output)->toContain('1 text is a key of lang/en.json, so tolk:lang translates it.')
        ->and($output)->toContain('3 texts are keys of lang/en/forms.php, so tolk:lang translates them.')
        ->and($output)->toContain('1 text is a key of a package, which translates it: acme::forms.placeholder.')
        ->and($output)->toContain('lang/de.json: done, created, 17 fields');
});

it('names the keys an earlier run wrote for texts that are translation keys now, which hide their translations', function () {
    FormFixtures::keyed();
    $this->fakeTranslations();
    formsCommand(['sites' => ['de']]);
    FormFixtures::keys('en', ['Write to us', 'Your full name', 'A new enquiry']);
    FormFixtures::keys('de', ['Schreiben Sie uns', 'Ihr Name', 'Eine neue Anfrage']);

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(0)
        ->and(formsTranslator('de')->get('forms.contact.name'))->toBe('[T] forms.contact.name')
        ->and($output)->toContain('An earlier run wrote 3 keys for form texts that are translation keys. Laravel looks in the JSON file first, so they hide the translations of those keys. Delete them from the file:')
        ->and($output)->toMatch('/\| lang\/de\.json +\| forms\.contact\.name +\|/')
        ->and($output)->not->toContain('No form uses');

    [, $dryRun] = formsCommand(['sites' => ['de'], '--dry-run' => true]);

    expect($dryRun)->toContain('An earlier run wrote 3 keys');
});

it('sends nothing on a second run', function () {
    $this->fakeTranslations();
    formsCommand(['sites' => ['de']]);
    $calls = $this->modelCalls();
    $written = file_get_contents(lang_path('de.json'));

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(0)
        ->and($this->modelCalls())->toBe($calls)
        ->and(file_get_contents(lang_path('de.json')))->toBe($written)
        ->and($output)->toContain('lang/de.json: skipped, nothing to do')
        ->and($output)->not->toContain('Unused key');
});

it('adds a new key for a renamed label and reports the old one as unused', function () {
    $this->fakeTranslations();
    formsCommand(['sites' => ['de']]);
    FormFixtures::make('newsletter', 'Newsletter', [
        'email' => ['type' => 'text', 'display' => 'Email address'],
        'interests' => ['type' => 'checkboxes', 'display' => 'Your interests', 'options' => ['Food', 'Wine']],
        'consent' => ['type' => 'radio', 'display' => 'Consent', 'options' => [['key' => 'yes', 'value' => 'Yes, send me news'], ['key' => 'no', 'value' => null]]],
    ], ['to' => 'news@example.com', 'subject' => 'New subscriber']);
    $sent = new ArrayObject;
    $this->fakeTranslations(function (string $text) use ($sent) {
        $sent[] = $text;

        return '[T] '.$text;
    });

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(0)
        ->and($sent->getArrayCopy())->toBe(['Your interests'])
        ->and(formsJson('de')['Your interests'])->toBe('[T] Your interests')
        ->and(formsJson('de')['Interests'])->toBe('[T] Interests')
        ->and($output)->toContain('lang/de.json: done, 1 field')
        ->and($output)->toContain('No form uses 1 key any more: the text was changed or removed.')
        ->and($output)->toMatch('/\| lang\/de\.json +\| Interests +\|/');
});

it('touches only the texts of the forms --form names', function () {
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand(['sites' => ['de'], '--form' => ['contact']]);
    $written = formsJson('de');

    expect($exitCode)->toBe(0)
        ->and($written)->toHaveKey('Your message')
        ->and($written)->toHaveKey('Email address')
        ->and($written)->not->toHaveKey('Newsletter')
        ->and($written)->not->toHaveKey('Food')
        ->and($written)->toHaveCount(9)
        ->and($output)->toContain('lang/de.json: done, created, 9 fields');

    [, $dryRun] = formsCommand(['sites' => ['de'], '--form' => ['newsletter'], '--dry-run' => true]);

    expect($dryRun)->toContain('lang/de.json: 7 untranslated, 1 current → 7 texts to translate');
});

it('translates a text that holds a | whole, since Statamic looks form texts up with trans(), which picks no plural form', function () {
    FormFixtures::make('feedback', 'Feedback', [
        'rating' => ['type' => 'text', 'display' => 'Rating | 1 to 5'],
    ]);
    $hints = new ArrayObject;
    $this->fakeTranslations(function (string $text, array $segment) use ($hints) {
        $hints[$text] = $segment['hint'] ?? null;

        return $text === 'Rating | 1 to 5' ? 'Bewertung | 1 bis 5' : '[T] '.$text;
    });

    [$exitCode, $output] = formsCommand(['sites' => ['de'], '--form' => ['feedback']]);

    expect($exitCode)->toBe(0)
        ->and($hints->getArrayCopy())->toBe(['Feedback' => null, 'Rating | 1 to 5' => null])
        ->and(formsJson('de'))->toBe(['Feedback' => '[T] Feedback', 'Rating | 1 to 5' => 'Bewertung | 1 bis 5'])
        ->and(formsTranslator('de')->get('Rating | 1 to 5'))->toBe('Bewertung | 1 bis 5')
        ->and($output)->toContain('lang/de.json: done, created, 2 fields');
});

it('translates into every site whose language differs from the source, once per language', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
        'at' => ['name' => 'Austria', 'url' => 'http://localhost/at/', 'locale' => 'de_AT', 'lang' => 'de'],
        'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
    ]);
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Files: 2 (German, Greek).')
        ->and(substr_count($output, 'lang/de.json: done'))->toBe(1)
        ->and(formsJson('el')['Your name'])->toBe('[T] Your name')
        ->and(formsJson('de')['Your name'])->toBe('[T] Your name');
});

it('shows the texts by status, the plan and the estimate in a dry run, and writes nothing', function () {
    file_put_contents(lang_path('de.json'), json_encode(['Topic' => 'Thema']));
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand(['sites' => ['de'], '--dry-run' => true, '-v' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lang/de.json: 15 untranslated, 1 manual, 1 not applicable → 15 texts to translate (15 segments,')
        ->and($output)->toContain('    Your name, Email address, We only use it to reply to you., Room booking,')
        ->and($output)->toContain('Estimated cost')
        ->and($output)->toContain('Dry run: 1 of 1 file would be translated (15 segments,')
        ->and($output)->toContain('{{ label | trans }}')
        ->and(formsJson('de'))->toBe(['Topic' => 'Thema'])
        ->and(file_exists($this->temporaryPath('state/forms/de.yaml')))->toBeFalse();
    $this->assertNoModelCalls();
});

it('takes over the record of a string that moved out of lang/en.json into the forms, so its translation stays machine output', function () {
    $this->fakeTranslations();
    Artisan::call('tolk:lang', ['sites' => ['de']]);

    file_put_contents(lang_path('en.json'), json_encode(['Book now' => 'Book now'], JSON_PRETTY_PRINT));
    [$exitCode, $output] = formsCommand(['sites' => ['de']]);
    $status = app(StatusResolver::class)->resolve(app(TargetFactory::class)->forForms('de', 'en'))->fields['Contact us'];

    expect($exitCode)->toBe(0)
        ->and($status->status)->toBe(FieldStatus::Current)
        ->and($output)->toContain('lang/de.json: done, 16 fields')
        ->and($output)->not->toContain('manual edits kept')
        ->and(formsJson('de')['Contact us'])->toBe('[T] Contact us')
        ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Lang, '_json', 'de'))->fields)->not->toHaveKey('Contact us');
});

it('keeps machine output the record of a string with a | that moved out of lang/en.json, which tolk:lang cut and the forms do not', function () {
    FormFixtures::make('feedback', 'Feedback', ['rating' => ['type' => 'text', 'display' => 'Rating | 1 to 5']]);
    file_put_contents(lang_path('en.json'), json_encode(['Book now' => 'Book now', 'Rating | 1 to 5' => 'Rating | 1 to 5'], JSON_PRETTY_PRINT));
    $this->fakeTranslations();
    Artisan::call('tolk:lang', ['sites' => ['de']]);

    file_put_contents(lang_path('en.json'), json_encode(['Book now' => 'Book now'], JSON_PRETTY_PRINT));
    [$exitCode, $output] = formsCommand(['sites' => ['de'], '--form' => ['feedback']]);
    $forms = app(TargetFactory::class)->forForms('de', 'en');

    expect($exitCode)->toBe(0)
        ->and(app(StatusResolver::class)->resolve($forms)->fields['Rating | 1 to 5']->status)->toBe(FieldStatus::Current)
        ->and($output)->toContain('lang/de.json: done, 1 field')
        ->and($output)->not->toContain('manual edits kept')
        ->and(formsJson('de')['Rating | 1 to 5'])->toBe('[T] Rating | [T] 1 to 5');

    file_put_contents(lang_path('de.json'), json_encode(['Rating | 1 to 5' => 'Bewertung | 1 bis 5', ...array_diff_key(formsJson('de'), ['Rating | 1 to 5' => true])]));

    expect(app(StatusResolver::class)->resolve($forms->fresh())->fields['Rating | 1 to 5']->status)->toBe(FieldStatus::Manual);
});

it('keeps a text edited by hand unless --force is given', function () {
    $this->fakeTranslations();
    formsCommand(['sites' => ['de']]);
    file_put_contents(lang_path('de.json'), json_encode(['Your name' => 'Ihr Name', ...array_diff_key(formsJson('de'), ['Your name' => true])]));

    [, $output] = formsCommand(['sites' => ['de'], '--mode' => 'all']);

    expect(formsJson('de')['Your name'])->toBe('Ihr Name')
        ->and($output)->toContain('manual edits kept: Your name');

    formsCommand(['sites' => ['de'], '--force' => true]);

    expect(formsJson('de')['Your name'])->toBe('[T] Your name');
});

it('exits 1 for invalid input and sends nothing', function (array $arguments, string $message) {
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand($arguments);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($message)
        ->and(formsJson('de'))->toBeNull();
    $this->assertNoModelCalls();
})->with([
    'unknown site' => [['sites' => ['fr']], 'Unknown site [fr].'],
    'the source language' => [['sites' => ['en']], 'The site [en] uses the source language [en], so there is nothing to translate into it.'],
    'unknown form' => [['sites' => ['de'], '--form' => ['booking']], 'The form [booking] was not found.'],
    'invalid mode' => [['sites' => ['de'], '--mode' => 'some'], 'Invalid mode [some]. Use missing, update, all or review.'],
    '--no-review with --reviewer' => [['sites' => ['de'], '--reviewer' => 'openai:gpt-6.1-sol', '--no-review' => true], '--no-review cannot be combined with --review, --reviewer or --mode=review.'],
]);

it('exits 1 when the JSON file cannot be read', function () {
    $this->fakeTranslations();
    file_put_contents(lang_path('de.json'), '{"Your name": ');

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('The translation file [lang/de.json] could not be read')
        ->and(file_get_contents(lang_path('de.json')))->toBe('{"Your name": ');
    $this->assertNoModelCalls();
});

it('exits 1 when a PHP file of the source language that a form text names cannot be read', function () {
    (new Filesystem)->ensureDirectoryExists(lang_path('en'));
    file_put_contents(lang_path('en/forms.php'), "<?php\n\nreturn 'forms';\n");
    FormFixtures::keyed();
    $this->fakeTranslations();

    [$exitCode, $output] = formsCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('The translation file [lang/en/forms.php] could not be read')
        ->and(formsJson('de'))->toBeNull();
    $this->assertNoModelCalls();
});

it('says so when there are no forms or no language to translate into', function () {
    Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'], 'uk' => ['name' => 'UK', 'url' => 'http://localhost/uk/', 'locale' => 'en_GB', 'lang' => 'en']]);
    [$oneLanguage, $single] = formsCommand();

    expect($oneLanguage)->toBe(0)
        ->and($single)->toContain('Every site uses the source language [en], so there is nothing to translate into.');

    $this->app['config']->set('statamic.forms.forms', $this->temporaryPath('no-forms'));
    Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'], 'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de']]);
    [$noForms, $output] = formsCommand();

    expect($noForms)->toBe(0)
        ->and($output)->toContain('There are no forms.');
});

it('stops at ProviderRejected with its message and exits 1', function () {
    $commits = RunFixtures::commits();
    ScriptedEngine::bind(fn (EngineCall $call) => throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));

    [$exitCode, $output] = formsCommand(['sites' => ['de', 'el']]);

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/lang\/de\.json: failed, .*Invalid API key\./')
        ->and($output)->toContain('1 file was not translated: lang/el.json.')
        ->and($output)->toMatch('/\| 0 +\| 0 +\| 1 +\| 0 +\|/')
        ->and(formsJson('de'))->toBeNull()
        ->and(formsJson('el'))->toBeNull()
        ->and($commits->getArrayCopy())->toBe([]);
});

it('commits once when the file was saved, and not when nothing was', function () {
    $this->fakeTranslations();
    $commits = RunFixtures::commits();

    formsCommand(['sites' => ['de']]);
    formsCommand(['sites' => ['de']]);

    expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
});

it('warns when Statamic\'s git integration does not track the folder of the file it wrote', function () {
    $this->fakeTranslations();
    RunFixtures::commits();
    config(['statamic.git.paths' => [config('tolk.state.path')]]);

    [, $untracked] = formsCommand(['sites' => ['de']]);

    config(['statamic.git.paths' => [dirname(lang_path())]]);
    [, $tracked] = formsCommand(['sites' => ['el']]);

    expect(preg_replace('/\s+/', ' ', $untracked))->toContain("Statamic's git integration does not track lang/, so it does not commit the files this command wrote with their state.")
        ->and($tracked)->toContain('lang/el.json: done')
        ->and($tracked)->not->toContain('does not track');
});
