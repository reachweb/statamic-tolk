<?php

use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\FormsTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\FormFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useFileCache();
    $this->app->useLangPath($this->temporaryPath('lang'));
    mkdir(lang_path(), 0755, true);
    file_put_contents(lang_path('en.json'), '{"Contact us": "Contact us"}');
    FormFixtures::contact();
    FormFixtures::newsletter();
});

function formsTarget(string $lang = 'de', string $sourceLang = 'en'): FormsTarget
{
    return app(TargetFactory::class)->forForms($lang, $sourceLang);
}

/**
 * @return array<string, FieldStatus>
 */
function formsStatuses(FormsTarget $target): array
{
    return array_map(fn (FieldStatusInfo $field) => $field->status, app(StatusResolver::class)->resolve($target)->fields);
}

function translateForms(?FormsTarget $target = null): void
{
    $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);

    app(TargetTranslator::class)->translate($target ?? formsTarget(), $options, new ApplyOptions);
}

it('collects the titles, displays, instructions, placeholders, option labels and email subjects, but no option keys', function () {
    $target = formsTarget();

    expect($target->fields()->keys()->all())->toBe([
        'Your name',
        'Email address',
        'We only use it to reply to you.',
        'name@example.com',
        'Topic',
        'Room booking',
        'Weddings and events',
        'Your message',
        'Hello, we would like to stay in June…',
        'New enquiry from the website',
        'Newsletter',
        'Interests',
        'Food',
        'Wine',
        'Consent',
        'Yes, send me news',
        'New subscriber',
    ])
        ->and($target->fields()->keys())->not->toContain('booking')
        ->and($target->fields()->keys())->not->toContain('yes')
        ->and($target->fields()->get('Your name')->get('tolk_handler'))->toBe('lang_string')
        ->and($target->sourceValue('Room booking'))->toBe('Room booking')
        ->and($target->sourceHasValue('booking'))->toBeFalse()
        ->and($target->sourceValue('booking'))->toBeNull();
});

it('reads an email config that is a single map, as Statamic sends it', function () {
    file_put_contents(config('statamic.forms.forms').'/legacy.yaml', "title: Legacy\nemail:\n  to: hotel@example.com\n  subject: 'Legacy subject'\n");

    expect(formsTarget()->fields()->keys()->all())->toContain('Legacy', 'Legacy subject')
        ->and(formsTarget()->fields()->get('Legacy subject')->display())->toBe('Legacy › email subject');
});

it('collects the label Statamic makes from the handle of a field without a display', function () {
    FormFixtures::make('feedback', 'Feedback', [
        'your_message' => ['type' => 'textarea', 'validate' => ['required']],
    ]);

    expect(Form::find('feedback')->blueprint()->field('your_message')->display())->toBe('Your Message')
        ->and(formsTarget()->fields()->keys()->all())->toContain('Feedback', 'Your Message')
        ->and(formsTarget()->fields()->get('Your Message')->display())->toBe('Feedback › Your Message › display');
});

it('labels a text with its place, and a text used in several places with the first and the count', function () {
    $target = formsTarget();

    expect($target->fields()->get('We only use it to reply to you.')->display())->toBe('Contact us › Email address › instructions')
        ->and($target->fields()->get('Room booking')->display())->toBe('Contact us › Topic › option booking')
        ->and($target->fields()->get('Wine')->display())->toBe('Newsletter › Interests › option Wine')
        ->and($target->fields()->get('Newsletter')->display())->toBe('Newsletter › title')
        ->and($target->fields()->get('New subscriber')->display())->toBe('Newsletter › email subject')
        ->and($target->fields()->get('Email address')->display())->toBe('Contact us › Email address › display (used in 2 places)')
        ->and($target->explainFields()[1])->toBe(['handle' => 'Email address', 'display' => 'Contact us › Email address › display (used in 2 places)', 'type' => 'text', 'in_scope' => true, 'reason' => 'ok']);
});

it('leaves a key of the JSON file to tolk:lang, and a subject with Antlers out', function () {
    $target = formsTarget();

    expect($target->fields()->has('Contact us'))->toBeFalse()
        ->and($target->leftToLang())->toBe(['Contact us'])
        ->and($target->fields()->keys())->not->toContain('Thank you, {{ name }}')
        ->and($target->renderedSubjects())->toBe(['Contact us › email subject']);
});

it('gives the texts of the named forms, shared texts included', function () {
    expect(formsTarget()->textsOf(['newsletter']))->toBe(['Email address', 'Newsletter', 'Interests', 'Food', 'Wine', 'Consent', 'Yes, send me news', 'New subscriber'])
        ->and(formsTarget()->textsOf(['contact']))->not->toContain('Newsletter');
});

it('uses the first site with each language for prompts, and has no CP surface', function () {
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'at' => ['name' => 'Austria', 'url' => 'http://localhost/at/', 'locale' => 'de_AT', 'lang' => 'de'],
        'de' => ['name' => 'German', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
    ]);
    $target = formsTarget();

    expect($target->sourceSite())->toBe('en')
        ->and($target->targetSite())->toBe('at')
        ->and($target->type())->toBe(ContentType::Forms)
        ->and($target->container())->toBe('forms')
        ->and($target->title())->toBe('Forms')
        ->and($target->name())->toBe('lang/de.json')
        ->and($target->editUrl())->toBeNull()
        ->and($target->authorize(User::make()->makeSuper()))->toBeFalse()
        ->and($target->document())->toBe(['type' => 'forms', 'title' => 'Forms', 'container' => "Texts of the website's forms: titles, field labels, instructions, placeholders, options and email subjects", 'url' => null, 'summary' => ''])
        ->and(fn () => $target->formFields([]))->toThrow(LogicException::class);
});

it('exists with a reference once the JSON file does, and is built again from both', function () {
    expect(formsTarget()->exists())->toBeFalse()
        ->and(formsTarget()->ref())->toBeNull();

    file_put_contents(lang_path('de.json'), '{}');
    $target = formsTarget();

    expect($target->exists())->toBeTrue()
        ->and((string) $target->ref())->toBe('forms::de')
        ->and($target->describe())->toBe(['type' => 'forms', 'source_id' => 'en', 'site' => 'de'])
        ->and(app(TargetFactory::class)->fromReference('forms::de'))->toEqual($target)
        ->and(app(TargetFactory::class)->fromDescription($target->describe()))->toEqual($target);
});

it('refuses a reference or a target it cannot translate', function (Closure $build, string $exception, string $message) {
    expect($build)->toThrow($exception, $message);
})->with([
    'the source language' => [fn () => app(TargetFactory::class)->fromReference('forms::en'), NotALocalization::class, 'forms::en'],
    'a language no site uses' => [fn () => app(TargetFactory::class)->fromReference('forms::fr'), InvalidTarget::class, 'No site uses the language [fr].'],
    'the same language' => [fn () => formsTarget('de', 'de'), InvalidTarget::class, 'The language [de] is the source language'],
]);

it('reads lang/{lang}.json for the target: a copy of the source is untranslated, a text edited by hand manual', function () {
    file_put_contents(lang_path('de.json'), json_encode(['Your name' => 'Your name', 'Topic' => 'Thema', 'Something' => 'Etwas']));
    $target = formsTarget();

    expect($target->hasOverride('Topic'))->toBeTrue()
        ->and($target->targetValue('Topic'))->toBe('Thema')
        ->and($target->hasOverride('Interests'))->toBeFalse()
        ->and($target->targetValue('Interests'))->toBeNull()
        ->and(formsStatuses($target))->toMatchArray([
            'Your name' => FieldStatus::Untranslated,
            'Topic' => FieldStatus::Manual,
            'Interests' => FieldStatus::Untranslated,
            'name@example.com' => FieldStatus::NotApplicable,
        ]);
});

it('writes the texts under their source text, keeping the other keys and their order and appending new ones', function () {
    $this->fakeTranslations();
    file_put_contents(lang_path('de.json'), json_encode(['Topic' => 'Thema', 'Contact us' => 'Kontakt', 'Own' => 'Eigen']));

    translateForms();
    $written = app(LangFiles::class)->read('de', LangFiles::JSON);

    expect(array_slice($written, 0, 3))->toBe(['Topic' => 'Thema', 'Contact us' => 'Kontakt', 'Own' => 'Eigen'])
        ->and($written['Your name'])->toBe('[T] Your name')
        ->and($written['Yes, send me news'])->toBe('[T] Yes, send me news')
        ->and($written)->not->toHaveKey('name@example.com')
        ->and($written)->not->toHaveKey('booking')
        ->and(array_keys($written)[3])->toBe('Your name')
        ->and(formsStatuses(formsTarget())['Your name'])->toBe(FieldStatus::Current)
        ->and(formsStatuses(formsTarget())['Topic'])->toBe(FieldStatus::Manual);
});

it('shares the JSON file with the _json translation file without dropping its keys', function () {
    $this->fakeTranslations();
    file_put_contents(lang_path('en.json'), '{"Contact us": "Contact us", "Book now": "Book now"}');

    translateForms();
    app(TargetTranslator::class)->translate(
        app(TargetFactory::class)->forLang(LangFiles::JSON, 'de', 'en'),
        PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']),
        new ApplyOptions,
    );
    translateForms();
    $written = app(LangFiles::class)->read('de', LangFiles::JSON);

    expect(array_keys($written))->toHaveCount(18)
        ->and(array_slice($written, 0, 2))->toBe(['Contact us' => '[T] Contact us', 'Book now' => '[T] Book now'])
        ->and($written['Your name'])->toBe('[T] Your name')
        ->and(formsStatuses(formsTarget())['Your name'])->toBe(FieldStatus::Current);
});

it('names the keys it translated that no form uses any more', function () {
    $this->fakeTranslations();
    file_put_contents(lang_path('de.json'), json_encode(['Hand-made' => 'Handgemacht']));
    translateForms();
    FormFixtures::make('newsletter', 'Newsletter', ['email' => ['type' => 'text', 'display' => 'Your email address']]);
    $state = app(StateRepository::class)->get(new TargetRef(ContentType::Forms, 'de'));

    expect(formsTarget()->unusedKeys($state))->toBe(['Interests', 'Food', 'Wine', 'Consent', 'Yes, send me news', 'New subscriber']);

    app(LangFiles::class)->write('de', LangFiles::JSON, [], ['Food', 'Wine'], []);

    expect(formsTarget()->unusedKeys($state))->toBe(['Interests', 'Consent', 'Yes, send me news', 'New subscriber']);
});
