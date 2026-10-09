<?php

use Illuminate\Filesystem\Filesystem;
use Reach\StatamicTolk\Exceptions\InvalidLangFile;
use Reach\StatamicTolk\Support\LangFiles;

beforeEach(function () {
    $this->app->useLangPath($this->temporaryPath('lang'));
    $this->files = new Filesystem;
    $this->files->ensureDirectoryExists(lang_path('en'));
});

function langFiles(): LangFiles
{
    return app(LangFiles::class);
}

function putLangFile(string $path, string $contents): void
{
    (new Filesystem)->ensureDirectoryExists(dirname(lang_path($path)));
    (new Filesystem)->put(lang_path($path), $contents);
}

function includeLangFile(string $path): mixed
{
    return (static fn (string $file) => include $file)(lang_path($path));
}

it('lists the JSON file first, then the PHP files by name', function () {
    putLangFile('en.json', '{}');
    putLangFile('en/validation.php', '<?php return [];');
    putLangFile('en/messages.php', '<?php return [];');
    putLangFile('en/notes.txt', 'not a group');
    putLangFile('en/nested/deep.php', '<?php return [];');
    putLangFile('de/messages.php', '<?php return [];');

    expect(langFiles()->groups('en'))->toBe(['_json', 'messages', 'validation'])
        ->and(langFiles()->groups('de'))->toBe(['messages'])
        ->and(langFiles()->groups('fr'))->toBe([]);
});

it('round-trips a JSON file', function () {
    putLangFile('en.json', json_encode(['Book now' => 'Book now', 'Contact us' => 'Contact us', 'Hello, :name' => 'Hello, :name']));
    $source = langFiles()->read('en', '_json');

    langFiles()->write('de', '_json', ['Contact us' => 'Kontakt', 'Book now' => 'Jetzt buchen', 'Hello, :name' => 'Hallo, :name'], [], $source);

    expect($source)->toBe(['Book now' => 'Book now', 'Contact us' => 'Contact us', 'Hello, :name' => 'Hello, :name'])
        ->and(file_get_contents(lang_path('de.json')))->toBe(<<<'JSON'
            {
                "Book now": "Jetzt buchen",
                "Contact us": "Kontakt",
                "Hello, :name": "Hallo, :name"
            }

            JSON)
        ->and(langFiles()->read('de', '_json'))->toBe(['Book now' => 'Jetzt buchen', 'Contact us' => 'Kontakt', 'Hello, :name' => 'Hallo, :name']);
});

it('round-trips a nested PHP file with its nesting', function () {
    putLangFile('en/messages.php', <<<'PHP'
        <?php

        // The booking widget.
        return [
            'booking' => [
                'title' => 'Book your stay',
                'rooms_left' => '{0} No rooms|{1} One room|[2,*] :count rooms',
            ],
            'days' => ['Monday', 'Tuesday'],
            'footer' => 'Akti Kanari 12',
        ];
        PHP);
    $source = langFiles()->read('en', 'messages');

    langFiles()->write('de', 'messages', [
        'footer' => 'Akti Kanari 12, Chania',
        'days.1' => 'Dienstag',
        'booking.rooms_left' => '{0} Keine Zimmer|{1} Ein Zimmer|[2,*] :count Zimmer',
        'days.0' => 'Montag',
        'booking.title' => 'Buchen Sie Ihren Aufenthalt',
    ], [], langFiles()->contents('en', 'messages'));

    expect($source)->toBe([
        'booking.title' => 'Book your stay',
        'booking.rooms_left' => '{0} No rooms|{1} One room|[2,*] :count rooms',
        'days.0' => 'Monday',
        'days.1' => 'Tuesday',
        'footer' => 'Akti Kanari 12',
    ])->and(file_get_contents(lang_path('de/messages.php')))->toBe(<<<'PHP'
        <?php

        return [
            'booking' => [
                'title' => 'Buchen Sie Ihren Aufenthalt',
                'rooms_left' => '{0} Keine Zimmer|{1} Ein Zimmer|[2,*] :count Zimmer',
            ],
            'days' => [
                'Montag',
                'Dienstag',
            ],
            'footer' => 'Akti Kanari 12, Chania',
        ];

        PHP)
        ->and(includeLangFile('de/messages.php'))->toBe([
            'booking' => ['title' => 'Buchen Sie Ihren Aufenthalt', 'rooms_left' => '{0} Keine Zimmer|{1} Ein Zimmer|[2,*] :count Zimmer'],
            'days' => ['Montag', 'Dienstag'],
            'footer' => 'Akti Kanari 12, Chania',
        ]);
});

it('writes a key that holds a dot as one key, next to a key that is also its prefix, where the source has it', function () {
    putLangFile('en/messages.php', <<<'PHP'
        <?php

        return [
            'faq' => 'FAQ',
            'faq.title' => 'Frequently asked questions',
            'nav' => ['home' => 'Home'],
            'nav.about' => 'About us',
        ];
        PHP);
    putLangFile('de/messages.php', "<?php return ['faq' => ['title' => 'Alt'], 'own.key' => 'Eigen', 'own' => ['nested' => 'Verschachtelt']];");

    langFiles()->write('de', 'messages', [
        'nav.about' => 'Über uns',
        'faq.title' => 'Häufige Fragen',
        'faq' => 'FAQ',
        'nav.home' => 'Startseite',
    ], [], langFiles()->contents('en', 'messages'));

    expect(langFiles()->read('en', 'messages'))->toBe(['faq' => 'FAQ', 'faq.title' => 'Frequently asked questions', 'nav.home' => 'Home', 'nav.about' => 'About us'])
        ->and(file_get_contents(lang_path('de/messages.php')))->toBe(<<<'PHP'
            <?php

            return [
                'faq' => 'FAQ',
                'faq.title' => 'Häufige Fragen',
                'nav' => [
                    'home' => 'Startseite',
                ],
                'nav.about' => 'Über uns',
                'own.key' => 'Eigen',
                'own' => [
                    'nested' => 'Verschachtelt',
                ],
            ];

            PHP)
        ->and(langFiles()->keepsLayout('de', 'messages'))->toBeTrue();
});

it('keeps the keys only the target has, after the source keys, and removes only the keys it is given', function () {
    putLangFile('en/messages.php', "<?php return ['a' => 'A', 'b' => 'B', 'c' => 'C'];");
    putLangFile('de/messages.php', "<?php return ['own' => ['x' => 'Eigen'], 'c' => 'Cee', 'b' => 'Bee'];");

    langFiles()->write('de', 'messages', ['a' => 'Ah'], ['c'], langFiles()->contents('en', 'messages'));

    expect(includeLangFile('de/messages.php'))->toBe(['a' => 'Ah', 'b' => 'Bee', 'own' => ['x' => 'Eigen']]);

    putLangFile('en.json', '{"One": "One", "Two": "Two"}');
    putLangFile('de.json', '{"Mine": "Meins", "Two": "Zwei"}');

    langFiles()->write('de', '_json', ['One' => 'Eins'], [], langFiles()->read('en', '_json'));

    expect(json_decode(file_get_contents(lang_path('de.json')), true))->toBe(['One' => 'Eins', 'Two' => 'Zwei', 'Mine' => 'Meins']);
});

it('carries the values it is told to over from the source where the target has none', function () {
    putLangFile('en/settings.php', "<?php return ['limit' => 5, 'title' => 'Title', 'enabled' => true, 'empty' => [], 'none' => null, 'url' => 'https://kymabay.example', 'other' => 'Other'];");
    putLangFile('de/settings.php', "<?php return ['limit' => 7];");

    langFiles()->write('de', 'settings', ['title' => 'Titel'], [], langFiles()->contents('en', 'settings'), ['limit', 'enabled', 'empty', 'none', 'url']);

    expect(includeLangFile('de/settings.php'))->toBe(['limit' => 7, 'title' => 'Titel', 'enabled' => true, 'empty' => [], 'none' => null, 'url' => 'https://kymabay.example']);
});

it('writes a JSON file without keys as an object, and numeric keys as keys', function () {
    putLangFile('en.json', '{"0": "Zero"}');

    langFiles()->write('de', '_json', [], [], []);
    expect(file_get_contents(lang_path('de.json')))->toBe("{}\n");

    langFiles()->write('de', '_json', ['0' => 'Null'], [], langFiles()->read('en', '_json'));
    expect(file_get_contents(lang_path('de.json')))->toBe("{\n    \"0\": \"Null\"\n}\n");
});

it('writes a translation so that including the file returns exactly that string and runs nothing else', function () {
    $marker = $this->temporaryPath('ran');
    $strings = [
        'quote' => "It's a guest's \\ room",
        'tags' => '?> <?php echo "run"; ?> ${x} {$x} <?= 1 ?>',
        'escape' => "'; file_put_contents('{$marker}', 'x'); \$a = '",
        'backslash' => 'C:\\',
        'heredoc' => "<<<EOT\nline\nEOT;\n",
        'control' => "nul\0byte",
    ];
    putLangFile('en/messages.php', '<?php return '.var_export(array_map(fn () => 'x', $strings), true).';');

    langFiles()->write('de', 'messages', $strings, [], langFiles()->contents('en', 'messages'));

    ob_start();
    $included = includeLangFile('de/messages.php');
    $output = ob_get_clean();

    expect($included)->toBe($strings)
        ->and($output)->toBe('')
        ->and(file_exists($marker))->toBeFalse()
        ->and(langFiles()->read('de', 'messages'))->toBe($strings);
});

it('leaves the old file in place when a write fails', function () {
    putLangFile('en.json', '{"One": "One"}');
    putLangFile('de.json', "{\"One\": \"Eins\"}\n");
    putLangFile('en/messages.php', "<?php return ['a' => 'A'];");
    putLangFile('de/messages.php', "<?php return ['a' => 'Ah'];");

    // JSON cannot encode a string that is not UTF-8.
    expect(fn () => langFiles()->write('de', '_json', ['One' => "\xB1\x31"], [], langFiles()->read('en', '_json')))->toThrow(JsonException::class)
        ->and(file_get_contents(lang_path('de.json')))->toBe("{\"One\": \"Eins\"}\n");

    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('move')->andReturnFalse();
    $failing = new LangFiles($files);

    expect(fn () => $failing->write('de', 'messages', ['a' => 'Neu'], [], $failing->contents('en', 'messages')))->toThrow(RuntimeException::class, 'Unable to write the translation file lang/de/messages.php')
        ->and(includeLangFile('de/messages.php'))->toBe(['a' => 'Ah'])
        ->and($this->files->files(lang_path('de')))->toHaveCount(1);
});

it('refuses to read a file that is not a JSON object or does not return an array', function (string $path, string $contents, string $lang, string $group) {
    putLangFile($path, $contents);

    expect(fn () => langFiles()->read($lang, $group))->toThrow(InvalidLangFile::class);
})->with([
    'invalid JSON' => ['de.json', '{"One": ', 'de', '_json'],
    'JSON string' => ['de.json', '"One"', 'de', '_json'],
    'PHP without an array' => ['de/messages.php', '<?php return "x";', 'de', 'messages'],
    'PHP that does not parse' => ['de/messages.php', '<?php return [', 'de', 'messages'],
]);

it('reads a missing file as empty', function () {
    expect(langFiles()->read('de', 'messages'))->toBe([])
        ->and(langFiles()->read('de', '_json'))->toBe([])
        ->and(langFiles()->exists('de', 'messages'))->toBeFalse();
});

it('knows when a rewrite would lose the layout of a PHP file', function () {
    putLangFile('en/messages.php', "<?php\n\n// Booking.\nreturn ['a' => 'A'];\n");
    putLangFile('en.json', "{\n  \"One\": \"One\"\n}");

    expect(langFiles()->keepsLayout('en', 'messages'))->toBeFalse()
        ->and(langFiles()->keepsLayout('en', '_json'))->toBeTrue()
        ->and(langFiles()->keepsLayout('de', 'messages'))->toBeTrue();

    langFiles()->write('de', 'messages', ['a' => 'Ah'], [], langFiles()->contents('en', 'messages'));

    expect(langFiles()->keepsLayout('de', 'messages'))->toBeTrue();
});
