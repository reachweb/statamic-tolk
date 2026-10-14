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
use Reach\StatamicTolk\Enums\Finish;
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

const LANG_MESSAGES = <<<'PHP'
    <?php

    return [
        'booking' => [
            'title' => 'Book your stay',
            'welcome' => 'Welcome back, :name!',
            'rooms_left' => '{0} No rooms|{1} One room|[2,*] :count rooms',
        ],
        'footer' => 'Open daily from 10:30',
        'limit' => 5,
    ];
    PHP;

const LANG_PLURAL_NOTE = 'These strings hold a |, which Laravel reads as dividing the forms of a pluralised string: their translations keep the 2 forms of the source language, but their language has more. Add the missing forms by hand where a string is pluralised, and leave a string whose | is part of its text as it is:';

beforeEach(function () {
    $this->useFileCache();
    $this->app->useLangPath($this->temporaryPath('lang'));

    writeLang('en.json', json_encode(['Book now' => 'Book now', 'Contact us' => 'Contact us', 'Hello, :name' => 'Hello, :name'], JSON_PRETTY_PRINT));
    writeLang('en/messages.php', LANG_MESSAGES);
});

function writeLang(string $path, string $contents): void
{
    (new Filesystem)->ensureDirectoryExists(dirname(lang_path($path)));
    (new Filesystem)->put(lang_path($path), $contents);
}

function readLang(string $path): mixed
{
    return str_ends_with($path, '.json')
        ? json_decode(file_get_contents(lang_path($path)), true)
        : (static fn (string $file) => include $file)(lang_path($path));
}

/**
 * Runs the command in this process and returns its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function langCommand(array $arguments = []): array
{
    $exitCode = Artisan::call('tolk:lang', $arguments);

    return [$exitCode, Artisan::output()];
}

/**
 * Laravel's translator, reading the test's lang path: the app's own loader was built with the path it booted with.
 */
function langTranslator(string $locale): Translator
{
    return new Translator(new FileLoader(new Filesystem, lang_path()), $locale);
}

it('translates the JSON file and the PHP files into a new language with every parameter and plural variant intact', function () {
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(0)
        ->and(readLang('de.json'))->toBe(['Book now' => '[T] Book now', 'Contact us' => '[T] Contact us', 'Hello, :name' => '[T] Hello, :name'])
        ->and(readLang('de/messages.php'))->toBe([
            'booking' => [
                'title' => '[T] Book your stay',
                'welcome' => '[T] Welcome back, :name!',
                'rooms_left' => '{0} [T] No rooms|{1} [T] One room|[2,*] [T] :count rooms',
            ],
            'footer' => '[T] Open daily from 10:30',
            'limit' => 5,
        ])
        ->and(file_exists(lang_path('el.json')))->toBeFalse()
        ->and($output)->toContain('Files: 2 (German).')
        ->and($output)->toContain('lang/de.json: done, created, 3 fields')
        ->and($output)->toContain('lang/de/messages.php: done, created, 4 fields')
        ->and($output)->toMatch('/\| 2 +\| 0 +\| 0 +\| 0 +\|/')
        ->and(langTranslator('de')->get('Hello, :name', ['name' => 'Ana']))->toBe('[T] Hello, Ana')
        ->and(langTranslator('de')->get('messages.booking.welcome', ['name' => 'Ana']))->toBe('[T] Welcome back, Ana!')
        ->and(langTranslator('de')->choice('messages.booking.rooms_left', 3))->toBe('[T] 3 rooms')
        ->and(langTranslator('de')->choice('messages.booking.rooms_left', 1))->toBe('[T] One room');

    $state = app(StateRepository::class)->get(new TargetRef(ContentType::Lang, 'messages', 'de'));

    expect($state->sourceSite)->toBe('en')
        ->and(array_keys($state->fields))->toBe(['booking.title', 'booking.welcome', 'booking.rooms_left', 'footer'])
        ->and($state->fields['booking.rooms_left']->segments)->toHaveCount(3)
        ->and(file_exists($this->temporaryPath('state/lang/messages/de.yaml')))->toBeTrue()
        ->and(file_exists($this->temporaryPath('state/lang/_json/de.yaml')))->toBeTrue();
});

it('sends nothing on a second run', function () {
    $this->fakeTranslations();
    langCommand(['sites' => ['de']]);
    $calls = $this->modelCalls();

    [$exitCode, $output] = langCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(0)
        ->and($this->modelCalls())->toBe($calls)
        ->and($output)->toContain('lang/de.json: skipped, nothing to do')
        ->and($output)->toContain('lang/de/messages.php: skipped, nothing to do');
});

it('writes a key that holds a dot as the source has it, so a key that is also its prefix keeps its translation', function () {
    writeLang('en/help.php', "<?php\n\nreturn ['faq' => 'FAQ', 'faq.title' => 'Frequently asked questions', 'nav' => ['home' => 'Home']];\n");
    $this->fakeTranslations();

    [$exitCode] = langCommand(['sites' => ['de'], '--group' => ['help']]);
    $calls = $this->modelCalls();
    [, $second] = langCommand(['sites' => ['de'], '--group' => ['help']]);

    expect($exitCode)->toBe(0)
        ->and(readLang('de/help.php'))->toBe(['faq' => '[T] FAQ', 'faq.title' => '[T] Frequently asked questions', 'nav' => ['home' => '[T] Home']])
        ->and(langTranslator('de')->get('help.faq'))->toBe('[T] FAQ')
        ->and(langTranslator('de')->get('help.faq.title'))->toBe('[T] Frequently asked questions')
        ->and(langTranslator('de')->get('help.nav.home'))->toBe('[T] Home')
        ->and($this->modelCalls())->toBe($calls)
        ->and($second)->toContain('lang/de/help.php: skipped, nothing to do')
        ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Lang, 'help', 'de'))->hasPending())->toBeFalse();
});

it('writes the translations of keys the source nests differently from the target file, and says which keys of the file gave way', function () {
    writeLang('en/validation.php', "<?php\n\nreturn ['password' => ['letters' => 'Use a letter.', 'mixed' => 'Mix the case.'], 'title' => 'Book', 'nav' => ['home' => 'Home']];\n");
    writeLang('de/validation.php', "<?php\n\nreturn ['password' => 'Falsches Passwort.', 'title' => ['short' => 'Buchen', 'long' => 'Jetzt buchen'], 'nav' => ['own' => 'Eigen']];\n");
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['validation'], '--yes' => true]);
    $calls = $this->modelCalls();
    [, $second] = langCommand(['sites' => ['de'], '--group' => ['validation']]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lang/de/validation.php: done, 4 fields')
        ->and($output)->toContain('Removed password, title.short, title.long: lang/en/validation.php nests its keys differently there.')
        ->and(readLang('de/validation.php'))->toBe([
            'password' => ['letters' => '[T] Use a letter.', 'mixed' => '[T] Mix the case.'],
            'title' => '[T] Book',
            'nav' => ['home' => '[T] Home', 'own' => 'Eigen'],
        ])
        ->and(langTranslator('de')->get('validation.password.letters'))->toBe('[T] Use a letter.')
        ->and(langTranslator('de')->get('validation.title'))->toBe('[T] Book')
        ->and($this->modelCalls())->toBe($calls)
        ->and($second)->toContain('lang/de/validation.php: skipped, nothing to do');
});

it('tracks keys that read as integers, so their translations read current and update refreshes them', function () {
    writeLang('en.json', json_encode(['Book now' => 'Book now', '2026' => 'Season 2026', 'Contact us' => 'Contact us'], JSON_PRETTY_PRINT));
    writeLang('en/errors.php', "<?php\n\nreturn ['404' => 'Page not found', '500' => 'Something went wrong', 'title' => 'Error'];\n");
    $this->fakeTranslations();
    $statuses = fn (string $group) => array_map(fn ($field) => $field->status, app(StatusResolver::class)->resolve(app(TargetFactory::class)->forLang($group, 'de', 'en'))->fields);

    [$exitCode] = langCommand(['sites' => ['de'], '--group' => ['_json', 'errors']]);

    expect($exitCode)->toBe(0)
        ->and($statuses('_json'))->toBe(['Book now' => FieldStatus::Current, '2026' => FieldStatus::Current, 'Contact us' => FieldStatus::Current])
        ->and($statuses('errors'))->toBe(['404' => FieldStatus::Current, '500' => FieldStatus::Current, 'title' => FieldStatus::Current]);

    writeLang('en/errors.php', "<?php\n\nreturn ['404' => 'This page was not found', '500' => 'Something went wrong', 'title' => 'Error'];\n");
    [, $output] = langCommand(['sites' => ['de'], '--group' => ['_json', 'errors'], '--mode' => 'update']);

    expect($output)->toContain('lang/de.json: skipped, nothing to do')
        ->and($output)->toContain('lang/de/errors.php: done, 1 field')
        ->and($output)->not->toContain('manual edits kept')
        ->and(readLang('de/errors.php'))->toBe(['404' => '[T] This page was not found', '500' => '[T] Something went wrong', 'title' => '[T] Error']);
});

it('copies the strings with nothing to translate, such as URLs and email addresses, where the target file has none', function () {
    writeLang('en.json', json_encode(['Book now' => 'Book now', 'site.url' => 'https://kymabay.example', 'site.email' => 'info@kymabay.example', 'site.phone' => '+30 28210 12345'], JSON_PRETTY_PRINT));
    writeLang('el.json', json_encode(['site.url' => 'https://kymabay.example/el']));
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand(['sites' => ['de', 'el'], '--group' => ['_json']]);
    [, $second] = langCommand(['sites' => ['de', 'el'], '--group' => ['_json']]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lang/de.json: done, created, 1 field')
        ->and(readLang('de.json'))->toBe(['Book now' => '[T] Book now', 'site.url' => 'https://kymabay.example', 'site.email' => 'info@kymabay.example', 'site.phone' => '+30 28210 12345'])
        ->and(readLang('el.json'))->toBe(['Book now' => '[T] Book now', 'site.url' => 'https://kymabay.example/el', 'site.email' => 'info@kymabay.example', 'site.phone' => '+30 28210 12345'])
        ->and(langTranslator('de')->get('site.url'))->toBe('https://kymabay.example')
        ->and(langTranslator('de')->get('site.email'))->toBe('info@kymabay.example')
        ->and($second)->toContain('lang/de.json: skipped, nothing to do')
        ->and($second)->toContain('lang/el.json: skipped, nothing to do')
        ->and($second)->not->toContain('manual edits kept');
});

it('takes over the record of a form text that moved into lang/en.json, so its translation stays machine output', function () {
    FormFixtures::contact();
    $this->fakeTranslations();
    Artisan::call('tolk:forms', ['sites' => ['de']]);

    writeLang('en.json', json_encode(['Book now' => 'Book now', 'Contact us' => 'Contact us', 'Hello, :name' => 'Hello, :name', 'Your message' => 'Your message'], JSON_PRETTY_PRINT));
    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['_json']]);
    $status = app(StatusResolver::class)->resolve(app(TargetFactory::class)->forLang('_json', 'de', 'en'))->fields['Your message'];

    expect($exitCode)->toBe(0)
        ->and($status->status)->toBe(FieldStatus::Current)
        ->and($output)->toContain('lang/de.json: done, 3 fields')
        ->and($output)->not->toContain('manual edits kept')
        ->and(readLang('de.json')['Your message'])->toBe('[T] Your message')
        ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Forms, 'de'))->fields)->not->toHaveKey('Your message');
});

it('keeps machine output the record of a form text with a | that moved into lang/en.json, which the forms did not cut and tolk:lang does', function () {
    FormFixtures::make('feedback', 'Feedback', ['rating' => ['type' => 'text', 'display' => 'Rating | 1 to 5']]);
    $this->fakeTranslations();
    Artisan::call('tolk:forms', ['sites' => ['de']]);

    writeLang('en.json', json_encode(['Book now' => 'Book now', 'Contact us' => 'Contact us', 'Hello, :name' => 'Hello, :name', 'Rating | 1 to 5' => 'Rating | 1 to 5'], JSON_PRETTY_PRINT));
    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['_json']]);
    $status = app(StatusResolver::class)->resolve(app(TargetFactory::class)->forLang('_json', 'de', 'en'))->fields['Rating | 1 to 5'];

    expect($exitCode)->toBe(0)
        ->and($status->status)->toBe(FieldStatus::Current)
        ->and($output)->toContain('lang/de.json: done, 3 fields')
        ->and($output)->not->toContain('manual edits kept')
        ->and(readLang('de.json')['Rating | 1 to 5'])->toBe('[T] Rating | 1 to 5');
});

it('re-sends only the edited key in mode update', function () {
    $this->fakeTranslations();
    langCommand(['sites' => ['de']]);
    writeLang('en/messages.php', str_replace("'Book your stay'", "'Book your summer stay'", LANG_MESSAGES));
    $sent = new ArrayObject;
    $this->fakeTranslations(function (string $text) use ($sent) {
        $sent[] = $text;

        return '[T] '.$text;
    });

    [, $missing] = langCommand(['sites' => ['de']]);
    [$exitCode, $output] = langCommand(['sites' => ['de'], '--mode' => 'update']);

    expect($exitCode)->toBe(0)
        ->and($missing)->toContain('lang/de/messages.php: skipped, nothing to do')
        ->and($sent->getArrayCopy())->toBe(['Book your summer stay'])
        ->and($output)->toContain('lang/de/messages.php: done, 1 field')
        ->and(readLang('de/messages.php')['booking'])->toBe([
            'title' => '[T] Book your summer stay',
            'welcome' => '[T] Welcome back, :name!',
            'rooms_left' => '{0} [T] No rooms|{1} [T] One room|[2,*] [T] :count rooms',
        ]);
});

it('keeps a string edited by hand unless --force is given', function () {
    $this->fakeTranslations();
    langCommand(['sites' => ['de']]);
    $edited = readLang('de.json');
    $edited['Contact us'] = 'Kontaktieren Sie uns';
    writeLang('de.json', json_encode($edited));

    [, $output] = langCommand(['sites' => ['de'], '--mode' => 'all']);

    expect(readLang('de.json')['Contact us'])->toBe('Kontaktieren Sie uns')
        ->and($output)->toContain('lang/de.json: done, 2 fields; manual edits kept: Contact us');

    langCommand(['sites' => ['de'], '--force' => true]);

    expect(readLang('de.json')['Contact us'])->toBe('[T] Contact us');
});

it('writes one file for sites that share a language', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
        'at' => ['name' => 'Austria', 'url' => 'http://localhost/at/', 'locale' => 'de_AT', 'lang' => 'de'],
    ]);
    $sent = new ArrayObject;
    $this->fakeTranslations(function (string $text) use ($sent) {
        $sent[] = $text;

        return '[T] '.$text;
    });

    [$exitCode, $output] = langCommand(['sites' => ['de', 'at']]);
    [, $all] = langCommand(['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Files: 2 (German).')
        ->and(substr_count($output, 'lang/de.json:'))->toBe(1)
        ->and(count($sent))->toBe(9)
        ->and((new Filesystem)->files(lang_path()))->toHaveCount(2)
        ->and($all)->toContain('Files: 2 (German).');
});

it('translates into every site whose language differs from the source when no site is named', function () {
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Files: 4 (Greek, German).')
        ->and(readLang('el.json')['Book now'])->toBe('[T] Book now')
        ->and(readLang('de/messages.php')['footer'])->toBe('[T] Open daily from 10:30');
});

it('translates from the language of the --from site', function () {
    writeLang('de.json', json_encode(['Book now' => 'Jetzt buchen']));
    writeLang('en.json', '{}');
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand(['sites' => ['el'], '--from' => 'de']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Files: 1 (Greek).')
        ->and(readLang('el.json'))->toBe(['Book now' => '[T] Jetzt buchen'])
        ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Lang, '_json', 'el'))->sourceSite)->toBe('de');
});

it('keeps through tolk:prune the state of files translated with --from, into the default site\'s language and from a file it lacks, so update refreshes them', function () {
    writeLang('de.json', json_encode(['Book now' => 'Jetzt buchen']));
    writeLang('en.json', '{}');
    writeLang('de/greetings.php', "<?php return ['hello' => 'Hallo'];");
    $this->fakeTranslations();
    langCommand(['sites' => ['en', 'el'], '--from' => 'de', '--group' => ['_json', 'greetings']]);

    $this->artisan('tolk:prune')->expectsOutputToContain('Nothing to clean up.')->assertExitCode(0);

    writeLang('de.json', json_encode(['Book now' => 'Jetzt sofort buchen']));
    writeLang('de/greetings.php', "<?php return ['hello' => 'Hallo zusammen'];");
    [$exitCode, $output] = langCommand(['sites' => ['en', 'el'], '--from' => 'de', '--group' => ['_json', 'greetings'], '--mode' => 'update']);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('manual edits kept')
        ->and(readLang('en.json')['Book now'])->toBe('[T] Jetzt sofort buchen')
        ->and(readLang('el.json')['Book now'])->toBe('[T] Jetzt sofort buchen')
        ->and(readLang('en/greetings.php'))->toBe(['hello' => '[T] Hallo zusammen'])
        ->and(readLang('el/greetings.php'))->toBe(['hello' => '[T] Hallo zusammen']);
});

it('limits the files to --group', function () {
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['_json']]);

    expect($exitCode)->toBe(0)
        ->and(file_exists(lang_path('de.json')))->toBeTrue()
        ->and(file_exists(lang_path('de/messages.php')))->toBeFalse()
        ->and($output)->not->toContain('messages.php');
});

it('shows the keys by status, the plan and the estimate in a dry run, and writes nothing', function () {
    $this->fakeTranslations();
    langCommand(['sites' => ['de'], '--group' => ['_json']]);
    writeLang('en.json', json_encode(['Book now' => 'Book now today', 'Contact us' => 'Contact us', 'Hello, :name' => 'Hello, :name', 'New' => 'New']));
    $before = file_get_contents(lang_path('de.json'));
    $state = file_get_contents($this->temporaryPath('state/lang/_json/de.yaml'));
    $calls = $this->modelCalls();

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--mode' => 'update', '--dry-run' => true, '-v' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lang/de.json: 1 untranslated, 1 outdated, 2 current → 2 keys to translate (2 segments, 17 characters)')
        ->and($output)->toContain('    Book now, New')
        ->and($output)->toContain('lang/de/messages.php: 4 untranslated → 4 keys to translate (6 segments,')
        ->and($output)->toContain('Estimated tokens')
        ->and($output)->toContain('Estimated cost')
        ->and($output)->toContain('Dry run: 2 of 2 files would be translated (8 segments,')
        ->and($output)->toContain('Nothing was sent or written.')
        ->and($this->modelCalls())->toBe($calls)
        ->and(file_get_contents(lang_path('de.json')))->toBe($before)
        ->and(file_get_contents($this->temporaryPath('state/lang/_json/de.yaml')))->toBe($state)
        ->and(file_exists(lang_path('de/messages.php')))->toBeFalse()
        ->and(file_exists($this->temporaryPath('state/lang/messages/de.yaml')))->toBeFalse();
});

it('asks before rewriting a PHP file it did not write, and not again once it wrote it', function () {
    $this->fakeTranslations();
    writeLang('de/messages.php', "<?php\n\n// Kept by hand.\nreturn ['footer' => 'Täglich geöffnet ab 10:30'];\n");

    $this->artisan('tolk:lang', ['sites' => ['de']])
        ->expectsOutputToContain('These files are written anew, so their comments and layout are lost: lang/de/messages.php.')
        ->expectsConfirmation('Translate and rewrite them?', 'no')
        ->expectsOutputToContain('Nothing was translated.')
        ->assertExitCode(0);

    expect(file_exists(lang_path('de.json')))->toBeFalse();
    $this->assertNoModelCalls();

    $this->artisan('tolk:lang', ['sites' => ['de']])
        ->expectsConfirmation('Translate and rewrite them?', 'yes')
        ->assertExitCode(0);

    expect(readLang('de/messages.php')['footer'])->toBe('Täglich geöffnet ab 10:30')
        ->and(readLang('de/messages.php')['booking']['title'])->toBe('[T] Book your stay');

    writeLang('en/messages.php', str_replace("'Book your stay'", "'Book your summer stay'", LANG_MESSAGES));

    $this->artisan('tolk:lang', ['sites' => ['de'], '--mode' => 'update'])
        ->doesntExpectOutputToContain('These files are written anew')
        ->assertExitCode(0);

    expect(readLang('de/messages.php')['booking']['title'])->toBe('[T] Book your summer stay');
});

it('does not ask with --yes, or about a file with nothing to do', function () {
    $this->fakeTranslations();
    writeLang('de/messages.php', "<?php\n\n// Kept by hand.\nreturn ['footer' => 'Täglich'];\n");

    $this->artisan('tolk:lang', ['sites' => ['de'], '--yes' => true])
        ->doesntExpectOutputToContain('These files are written anew')
        ->assertExitCode(0);

    writeLang('de/messages.php', "<?php\n\n// Kept by hand.\nreturn ".var_export(readLang('de/messages.php'), true).";\n");

    $this->artisan('tolk:lang', ['sites' => ['de']])
        ->doesntExpectOutputToContain('These files are written anew')
        ->assertExitCode(0);
});

it('lists the pluralised strings of a language with more plural forms than the source', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'ru' => ['name' => 'Russian', 'url' => 'http://localhost/ru/', 'locale' => 'ru_RU', 'lang' => 'ru'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
    ]);
    $this->fakeTranslations();

    [, $output] = langCommand();
    [, $dryRun] = langCommand(['--dry-run' => true]);

    expect($output)->toContain(LANG_PLURAL_NOTE)
        ->and($output)->toMatch('/\| lang\/ru\/messages\.php +\| booking\.rooms_left +\| 3 +\|/')
        ->and($output)->not->toContain('lang/de/messages.php | booking.rooms_left')
        ->and($output)->not->toMatch('/\| lang\/ru\/messages\.php +\| booking\.(title|welcome) /')
        ->and($dryRun)->toMatch('/\| lang\/ru\/messages\.php +\| booking\.rooms_left +\| 3 +\|/');
});

it('sends the parts of a string with a | without calling it pluralised, and says that the note lists every such string', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'ru' => ['name' => 'Russian', 'url' => 'http://localhost/ru/', 'locale' => 'ru_RU', 'lang' => 'ru'],
    ]);
    writeLang('en/site.php', "<?php\n\nreturn ['title' => 'Blog | :site', 'apples' => 'One apple|Many apples'];\n");
    $hints = new ArrayObject;
    $this->fakeTranslations(function (string $text, array $segment) use ($hints) {
        $hints[$text] = $segment['hint'] ?? null;

        return '[T] '.$text;
    });

    [$exitCode, $output] = langCommand(['--group' => ['site']]);

    expect($exitCode)->toBe(0)
        ->and($hints->getArrayCopy())->toBe([
            'Blog' => 'Part 1 of 2 of a string divided by |. Translate only this part, without a | character.',
            'One apple' => 'Part 1 of 2 of a string divided by |. Translate only this part, without a | character.',
            'Many apples' => 'Part 2 of 2 of a string divided by |. Translate only this part, without a | character.',
        ])
        ->and(readLang('ru/site.php'))->toBe(['title' => '[T] Blog | :site', 'apples' => '[T] One apple|[T] Many apples'])
        ->and($output)->toContain(LANG_PLURAL_NOTE)
        ->and($output)->toMatch('/\| lang\/ru\/site\.php +\| title +\| 3 +\|/')
        ->and($output)->toMatch('/\| lang\/ru\/site\.php +\| apples +\| 3 +\|/')
        ->and($output)->not->toContain('pluralised strings keep');
});

it('repairs a translated plural form that holds a |, so the string keeps the forms of its source', function () {
    writeLang('en/shop.php', "<?php\n\nreturn ['apples' => 'One apple|Many apples'];\n");
    $notes = new ArrayObject;
    $this->fakeTranslations(function (string $text, array $segment) use ($notes) {
        if (isset($segment['note'])) {
            $notes[] = $segment['note'];
        }

        return $text === 'One apple' && ! isset($segment['note']) ? 'Ein Apfel | ein Stück' : '[T] '.$text;
    });

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['shop']]);
    $calls = $this->modelCalls();
    [, $update] = langCommand(['sites' => ['de'], '--group' => ['shop'], '--mode' => 'update']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lang/de/shop.php: done, created, 1 field')
        ->and($notes->getArrayCopy())->toBe(['The translation contains |, which divides this string into its parts. Translate only this part, without a | character.'])
        ->and(readLang('de/shop.php'))->toBe(['apples' => '[T] One apple|[T] Many apples'])
        ->and(langTranslator('de')->choice('shop.apples', 1))->toBe('[T] One apple')
        ->and(langTranslator('de')->choice('shop.apples', 5))->toBe('[T] Many apples')
        ->and($this->modelCalls())->toBe($calls)
        ->and($update)->toContain('lang/de/shop.php: skipped, nothing to do');
});

it('leaves a plural form in the source language when its translation keeps holding a |, and discards a correction that holds one', function () {
    writeLang('en/shop.php', "<?php\n\nreturn ['apples' => 'One apple|Many apples', 'pears' => 'One pear|Many pears'];\n");
    $this->fakeTranslations(fn (string $text) => $text === 'One apple' ? 'Ein Apfel | ein Stück' : '[T] '.$text);
    $this->fakeReview(fn (string $source) => $source === 'Many pears' ? 'Viele Birnen | Birnen' : null);

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['shop'], '--reviewer' => 'openai:gpt-6.1-sol']);

    expect($exitCode)->toBe(0)
        ->and(readLang('de/shop.php'))->toBe(['apples' => 'One apple|[T] Many apples', 'pears' => '[T] One pear|[T] Many pears'])
        ->and(langTranslator('de')->choice('shop.apples', 5))->toBe('[T] Many apples')
        ->and(langTranslator('de')->choice('shop.pears', 5))->toBe('[T] Many pears')
        ->and($output)->toContain('"One apple" was left untranslated (the model kept breaking its formatting)')
        ->and($output)->toContain('the reviewer\'s correction of "Many pears" was not valid and was discarded');
});

it('reviews with --reviewer and applies the corrections', function () {
    $this->fakeTranslations();
    $this->fakeReview(fn (string $source, string $translation) => $source === 'Book now' ? 'Jetzt buchen' : null);

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['_json'], '--reviewer' => 'openai:gpt-6.1-sol']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Reviewer: ')
        ->and($output)->toContain('lang/de.json: done, created, 3 fields, 1 correction')
        ->and(readLang('de.json')['Book now'])->toBe('Jetzt buchen');
});

it('reviews existing translations in mode review', function () {
    $this->fakeTranslations();
    langCommand(['sites' => ['de'], '--group' => ['_json']]);
    $this->fakeReview(fn (string $source) => $source === 'Contact us' ? 'Kontakt' : null);

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['_json'], '--mode' => 'review']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lang/de.json: done, 1 field, 1 correction')
        ->and(readLang('de.json')['Contact us'])->toBe('Kontakt');
});

it('fails a file in mode review whose review could not be made, and exits 1', function () {
    $this->fakeTranslations();
    langCommand(['sites' => ['de'], '--group' => ['_json']]);
    $before = readLang('de.json');
    ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call), fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Refused));

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--group' => ['_json'], '--mode' => 'review']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('lang/de.json: failed, Part of the text could not be reviewed and was kept as it was.; not reviewed: ')
        ->and($output)->toContain('review skipped (refused).')
        ->and($output)->not->toContain('nothing to correct')
        ->and(readLang('de.json'))->toBe($before);
});

it('exits 1 for invalid input and sends nothing', function (array $arguments, string $message) {
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand($arguments);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($message)
        ->and(file_exists(lang_path('de.json')))->toBeFalse();
    $this->assertNoModelCalls();
})->with([
    'unknown site' => [['sites' => ['fr']], 'Unknown site [fr].'],
    'the source language' => [['sites' => ['en']], 'The site [en] uses the source language [en], so there is nothing to translate into it.'],
    'unknown --from' => [['sites' => ['de'], '--from' => 'fr'], 'Unknown site [fr].'],
    'unknown group' => [['sites' => ['de'], '--group' => ['auth']], 'The translation file [lang/en/auth.php] was not found.'],
    'invalid mode' => [['sites' => ['de'], '--mode' => 'some'], 'Invalid mode [some]. Use missing, update, all or review.'],
    '--no-review with --review' => [['sites' => ['de'], '--review' => true, '--no-review' => true], '--no-review cannot be combined with --review, --reviewer or --mode=review.'],
    '--no-review with --reviewer' => [['sites' => ['de'], '--reviewer' => 'openai:gpt-6.1-sol', '--no-review' => true], '--no-review cannot be combined with --review, --reviewer or --mode=review.'],
    'invalid translator' => [['sites' => ['de'], '--translator' => 'nope'], 'nope'],
]);

it('exits 1 when a file cannot be read', function () {
    $this->fakeTranslations();
    writeLang('de.json', '{"Book now": ');

    [$exitCode, $output] = langCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('The translation file [lang/de.json] could not be read')
        ->and(file_get_contents(lang_path('de.json')))->toBe('{"Book now": ')
        ->and(file_exists(lang_path('de/messages.php')))->toBeFalse();
    $this->assertNoModelCalls();
});

it('checks that the translator and the reviewer have a provider with a key', function () {
    $this->withProviderKeys(['ai.providers.anthropic.key' => 'key']);
    $this->fakeTranslations();

    [$exitCode, $output] = langCommand(['sites' => ['de'], '--reviewer' => 'openai:gpt-6.1-sol']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('openai');
    $this->assertNoModelCalls();
});

it('says so when there is nothing to translate', function () {
    [$noFiles, $output] = langCommand(['sites' => ['de'], '--from' => 'el']);

    Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'], 'uk' => ['name' => 'UK', 'url' => 'http://localhost/uk/', 'locale' => 'en_GB', 'lang' => 'en']]);
    [$oneLanguage, $single] = langCommand();

    expect($noFiles)->toBe(0)
        ->and($output)->toContain('There are no translation files in lang/el.')
        ->and($oneLanguage)->toBe(0)
        ->and($single)->toContain('Every site uses the source language [en], so there is nothing to translate into.');
});

it('stops at ProviderRejected with its message, commits what was saved and exits 1', function () {
    $commits = RunFixtures::commits();
    ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
        ? ScriptedEngine::translated($call)
        : throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));

    [$exitCode, $output] = langCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('lang/de.json: done, created, 3 fields')
        ->and($output)->toMatch('/lang\/de\/messages\.php: failed, .*Invalid API key\./')
        ->and($output)->toMatch('/\| 1 +\| 0 +\| 1 +\| 0 +\|/')
        ->and($output)->not->toContain('not translated')
        ->and(file_exists(lang_path('de/messages.php')))->toBeFalse()
        ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
});

it('names the files it did not get to after ProviderRejected', function () {
    ScriptedEngine::bind(fn (EngineCall $call) => throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));

    [$exitCode, $output] = langCommand(['sites' => ['de']]);

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/lang\/de\.json: failed, .*Invalid API key\./')
        ->and($output)->toContain('1 file was not translated: lang/de/messages.php.')
        ->and($output)->toMatch('/\| 0 +\| 0 +\| 1 +\| 0 +\|/')
        ->and(file_exists(lang_path('de.json')))->toBeFalse();
});

it('commits once when a file was saved, and not when nothing was', function () {
    $this->fakeTranslations();
    $commits = RunFixtures::commits();

    langCommand(['sites' => ['de']]);
    langCommand(['sites' => ['de']]);

    expect($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
});

it('warns when Statamic\'s git integration does not track the folder of the files it wrote', function () {
    $this->fakeTranslations();
    RunFixtures::commits();
    config(['statamic.git.paths' => [config('tolk.state.path')]]);

    [, $untracked] = langCommand(['sites' => ['de']]);

    config(['statamic.git.paths' => [config('tolk.state.path'), lang_path()]]);
    [, $tracked] = langCommand(['sites' => ['el']]);

    config(['statamic.git.enabled' => false]);
    [, $off] = langCommand(['sites' => ['de'], '--mode' => 'all']);

    expect(preg_replace('/\s+/', ' ', $untracked))->toContain("Statamic's git integration does not track lang/, so it does not commit the files this command wrote with their state. Add lang_path() to statamic.git.paths, or commit lang/ yourself.")
        ->and($tracked)->toContain('lang/el.json: done')
        ->and($tracked)->not->toContain('does not track')
        ->and($off)->toContain('lang/de.json: done')
        ->and($off)->not->toContain('does not track');
});
