<?php

use Illuminate\Filesystem\Filesystem;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\LangTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Facades\Site;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useFileCache();
    $this->app->useLangPath($this->temporaryPath('lang'));
    (new Filesystem)->ensureDirectoryExists(lang_path('en'));
    file_put_contents(lang_path('en/messages.php'), "<?php return ['booking' => ['title' => 'Book your stay', 'rooms' => 'room|rooms'], 'limit' => 5];");
    file_put_contents(lang_path('en.json'), '{"Book now": "Book now"}');
});

function langTarget(string $group = 'messages', string $lang = 'de', string $sourceLang = 'en'): LangTarget
{
    return app(TargetFactory::class)->forLang($group, $lang, $sourceLang);
}

/**
 * @return array<string, FieldStatus>
 */
function langStatuses(LangTarget $target): array
{
    return array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve($target)->fields);
}

it('has a field for each string of the source, keyed and labelled by its key', function () {
    $target = langTarget();

    expect($target->fields()->keys()->all())->toBe(['booking.title', 'booking.rooms'])
        ->and($target->fields()->get('booking.title')->display())->toBe('booking.title')
        ->and($target->fields()->get('booking.title')->get('tolk_handler'))->toBe('lang_string')
        ->and($target->explainFields())->toBe([
            ['handle' => 'booking.title', 'display' => 'booking.title', 'type' => 'text', 'in_scope' => true, 'reason' => 'ok'],
            ['handle' => 'booking.rooms', 'display' => 'booking.rooms', 'type' => 'text', 'in_scope' => true, 'reason' => 'ok'],
        ])
        ->and($target->sourceValue('booking.title'))->toBe('Book your stay')
        ->and($target->sourceValue('limit'))->toBeNull()
        ->and($target->pluralKeys())->toBe(['booking.rooms'])
        ->and($target->type())->toBe(ContentType::Lang)
        ->and($target->container())->toBe('messages')
        ->and($target->title())->toBe('messages.php')
        ->and($target->name())->toBe('lang/de/messages.php')
        ->and(langTarget('_json')->title())->toBe('en.json')
        ->and(langTarget('_json')->name())->toBe('lang/de.json');
});

it('has no field for a string with nothing to translate, but one for an empty string', function () {
    file_put_contents(lang_path('en.json'), json_encode([
        'Book now' => 'Book now',
        'site.url' => 'https://kymabay.example',
        'site.email' => 'info@kymabay.example',
        'site.phone' => '+30 28210 12345',
        'Coming soon' => '',
    ]));

    expect(langTarget('_json')->fields()->keys()->all())->toBe(['Book now', 'Coming soon']);
});

it('uses the first site with each language for prompts, and has no CP surface', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'at' => ['name' => 'Austria', 'url' => 'http://localhost/at/', 'locale' => 'de_AT', 'lang' => 'de'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
    ]);
    $target = langTarget();

    expect($target->sourceSite())->toBe('en')
        ->and($target->targetSite())->toBe('at')
        ->and($target->editUrl())->toBeNull()
        ->and($target->authorize(User::make()->makeSuper()))->toBeFalse()
        ->and($target->document())->toBe(['type' => 'lang', 'title' => 'messages.php', 'container' => 'Interface strings of the website (a Laravel translation file)', 'url' => null, 'summary' => ''])
        ->and(fn () => $target->formFields([]))->toThrow(LogicException::class);
});

it('exists with a reference once its file does', function () {
    $target = langTarget();

    expect($target->exists())->toBeFalse()
        ->and($target->ref())->toBeNull();

    file_put_contents(lang_path('de.json'), '{}');
    (new Filesystem)->ensureDirectoryExists(lang_path('de'));
    file_put_contents(lang_path('de/messages.php'), '<?php return [];');

    expect(langTarget()->exists())->toBeTrue()
        ->and((string) langTarget()->ref())->toBe('lang::messages::de')
        ->and((string) langTarget('_json')->ref())->toBe('lang::_json::de');
});

it('is built again from its reference and its description', function () {
    file_put_contents(lang_path('el.json'), '{}');
    $target = langTarget('_json', 'el');

    expect(app(TargetFactory::class)->fromReference('lang::_json::el'))->toEqual($target)
        ->and($target->describe())->toBe(['type' => 'lang', 'source_id' => '_json::en', 'site' => 'el'])
        ->and(app(TargetFactory::class)->fromDescription($target->describe()))->toEqual($target);
});

it('refuses a reference or a target it cannot translate', function (Closure $build, string $exception, string $message) {
    expect($build)->toThrow($exception, $message);
})->with([
    'the source language' => [fn () => app(TargetFactory::class)->fromReference('lang::messages::en'), NotALocalization::class, 'lang::messages::en'],
    'a missing source file' => [fn () => app(TargetFactory::class)->fromReference('lang::auth::de'), TargetNotFound::class, 'Translation file [lang/en/auth.php] not found.'],
    'a language no site uses' => [fn () => langTarget('messages', 'fr'), InvalidTarget::class, 'No site uses the language [fr].'],
    'the same language' => [fn () => langTarget('messages', 'de', 'de'), InvalidTarget::class, 'The language [de] is the source language'],
]);

it('reads a copy of the source as untranslated, and a string edited by hand as manual', function () {
    (new Filesystem)->ensureDirectoryExists(lang_path('de'));
    file_put_contents(lang_path('de/messages.php'), "<?php return ['booking' => ['title' => 'Book your stay', 'rooms' => 'Zimmer|Zimmer']];");

    expect(langStatuses(langTarget()))->toBe(['booking.title' => FieldStatus::Untranslated, 'booking.rooms' => FieldStatus::Manual]);
});

it('writes only the applied keys inside the writer, and skips a key edited since the plan', function () {
    $this->fakeTranslations();
    (new Filesystem)->ensureDirectoryExists(lang_path('de'));
    file_put_contents(lang_path('de/messages.php'), "<?php return ['own' => 'Eigen'];");
    $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);
    $target = langTarget();

    expect(fn () => $target->apply([], new ApplyOptions))->toThrow(LogicException::class);

    $result = app(TargetTranslator::class)->translate($target, $options, new ApplyOptions);

    expect($result->appliedFields())->toBe(['booking.title', 'booking.rooms'])
        ->and(app(LangFiles::class)->read('de', 'messages'))->toBe(['booking.title' => '[T] Book your stay', 'booking.rooms' => '[T] room|[T] rooms', 'limit' => 5, 'own' => 'Eigen'])
        ->and(langStatuses(langTarget()))->toBe(['booking.title' => FieldStatus::Current, 'booking.rooms' => FieldStatus::Current]);

    $plan = app(Planner::class)->plan(langTarget(), new PlanOptions(...[...get_object_vars($options), 'mode' => Mode::All]));
    app(Pipeline::class)->runUnit($plan, 'b1');
    $fields = array_map(fn (string $handle) => app(Pipeline::class)->assemble($plan, langTarget(), $handle), $plan->fieldHandles());
    app(LangFiles::class)->write('de', 'messages', ['booking.title' => 'Ihr Aufenthalt'], [], []);

    $applied = ContentWriter::write(fn () => langTarget()->apply($fields, new ApplyOptions));

    expect($applied->applied)->toBe(['booking.rooms'])
        ->and($applied->skipped)->toBe(['booking.title' => 'target_changed'])
        ->and(app(LangFiles::class)->read('de', 'messages')['booking.title'])->toBe('Ihr Aufenthalt');
});

it('fails the target when its file cannot be written', function () {
    $this->fakeTranslations();
    (new Filesystem)->ensureDirectoryExists(lang_path('de'));
    file_put_contents(lang_path('de/messages.php'), '<?php return [];');
    chmod(lang_path('de'), 0555);
    $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);

    try {
        $result = app(TargetTranslator::class)->translate(langTarget(), $options, new ApplyOptions);
    } finally {
        chmod(lang_path('de'), 0755);
    }

    expect($result->status->value)->toBe('failed')
        ->and($result->error)->toStartWith('The translation file [lang/de/messages.php] could not be written:')
        ->and(file_get_contents(lang_path('de/messages.php')))->toBe('<?php return [];');
})->skip(fn () => function_exists('posix_getuid') && posix_getuid() === 0, 'root ignores directory permissions');
