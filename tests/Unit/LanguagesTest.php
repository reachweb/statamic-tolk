<?php

use Illuminate\Support\Facades\Lang;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Support\Language;
use Reach\StatamicTolk\Support\Languages;
use Statamic\Facades\Site;

function languageOf(array $config): Language
{
    Site::setSites([
        'origin' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'target' => ['url' => 'http://localhost/target/', ...$config],
    ]);

    return app(Languages::class)->forSite('target');
}

it('describes a site in English', function (array $config, string $name, string $lang, ?string $region, string $describe) {
    $language = languageOf($config);

    expect($language->site)->toBe('target')
        ->and($language->name)->toBe($name)
        ->and($language->locale)->toBe($config['locale'])
        ->and($language->lang)->toBe($lang)
        ->and($language->region)->toBe($region)
        ->and($language->direction)->toBe('ltr')
        ->and($language->describe())->toBe($describe);
})->with([
    'en_US' => [['name' => 'English', 'locale' => 'en_US'], 'English', 'en', 'United States', 'English (United States), locale en_US'],
    'el_GR' => [['name' => 'Ελληνικά', 'locale' => 'el_GR'], 'Greek', 'el', 'Greece', 'Greek (Greece), locale el_GR'],
    'de_DE' => [['name' => 'Deutsch', 'locale' => 'de_DE'], 'German', 'de', 'Germany', 'German (Germany), locale de_DE'],
    'pt_BR' => [['name' => 'Português', 'locale' => 'pt_BR'], 'Portuguese', 'pt', 'Brazil', 'Portuguese (Brazil), locale pt_BR'],
    'pt_BR with lang pt_BR' => [['name' => 'Português', 'locale' => 'pt_BR', 'lang' => 'pt_BR'], 'Portuguese', 'pt_BR', 'Brazil', 'Portuguese (Brazil), locale pt_BR'],
    'a locale without a region' => [['name' => 'Greek', 'locale' => 'el'], 'Greek', 'el', null, 'Greek, locale el'],
    'a locale with a charset' => [['name' => 'Greek', 'locale' => 'el_GR.UTF-8'], 'Greek', 'el', 'Greece', 'Greek (Greece), locale el_GR.UTF-8'],
    'a hyphenated locale' => [['name' => 'Österreich', 'locale' => 'de-AT'], 'German', 'de', 'Austria', 'German (Austria), locale de-AT'],
]);

it('takes the language from lang when it overrides the locale', function () {
    $overridden = languageOf(['name' => 'Norsk', 'locale' => 'nb_NO', 'lang' => 'no']);

    expect($overridden->lang)->toBe('no')
        ->and($overridden->name)->toBe('Norwegian')
        ->and($overridden->describe())->toBe('Norwegian (Norway), locale nb_NO');

    $fromLocale = languageOf(['name' => 'Norsk', 'locale' => 'nb_NO']);

    expect($fromLocale->lang)->toBe('nb')
        ->and($fromLocale->name)->toBe('Norwegian Bokmål')
        ->and($fromLocale->describe())->toBe('Norwegian Bokmål (Norway), locale nb_NO');
});

it('falls back to the site name for an unknown language', function () {
    $language = languageOf(['name' => 'Elvish', 'locale' => 'qaa_QM']);

    expect($language->name)->toBe('Elvish')
        ->and($language->lang)->toBe('qaa')
        ->and($language->region)->toBeNull()
        ->and($language->describe())->toBe('Elvish, locale qaa_QM');

    expect(languageOf(['name' => 'Elvish', 'locale' => 'qaa_GR'])->describe())->toBe('Elvish (Greece), locale qaa_GR')
        ->and(languageOf(['locale' => 'qaa'])->name)->toBe('target');
});

it('keeps the name in English whatever the app locale', function (string $locale) {
    app()->setLocale($locale);
    Lang::setFallback($locale);

    expect(app(Languages::class)->forSite('de')->name)->toBe('German')
        ->and(app(Languages::class)->forSite('el')->describe())->toBe('Greek (Greece), locale el_GR')
        ->and(__('statamic::dictionary-languages.de'))->not->toBe('German');
})->with(['el', 'de']);

it('never falls back to another locale of the dictionary', function () {
    Lang::addLines(['dictionary-languages.qaa' => 'Elbisch'], 'de', 'statamic');
    app()->setLocale('de');
    Lang::setFallback('de');

    expect(languageOf(['name' => 'Elvish', 'locale' => 'qaa'])->name)->toBe('Elvish');
});

it('prefers the host translation of the English dictionary', function () {
    Lang::addLines(['dictionary-languages.el' => 'Modern Greek'], 'en', 'statamic');

    expect(languageOf(['name' => 'Greek', 'locale' => 'el_GR'])->describe())->toBe('Modern Greek (Greece), locale el_GR');
});

it('reports right-to-left languages', function () {
    $language = languageOf(['name' => 'עברית', 'locale' => 'he_IL']);

    expect($language->direction)->toBe('rtl')
        ->and($language->describe())->toBe('Hebrew (Israel), locale he_IL');
});

it('works without intl', function () {
    $languages = new class extends Languages
    {
        protected function intl(): bool
        {
            return false;
        }
    };

    expect($languages->forSite('el')->describe())->toBe('Greek, locale el_GR')
        ->and($languages->forSite('de')->region)->toBeNull();

    Site::setSites(['nb' => ['name' => 'Norsk', 'url' => 'http://localhost/', 'locale' => 'nb_NO']]);

    expect($languages->forSite('nb')->describe())->toBe('Norsk, locale nb_NO');
});

it('accepts a site object', function () {
    expect(app(Languages::class)->forSite(Site::get('el'))->describe())->toBe('Greek (Greece), locale el_GR');
});

it('rejects an unknown site handle', function () {
    expect(fn () => app(Languages::class)->forSite('fr'))->toThrow(UnknownSite::class, 'Unknown site [fr].');
});
