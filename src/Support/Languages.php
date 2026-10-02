<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Locale;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;

class Languages
{
    private const string DISPLAY_LOCALE = 'en';

    public function forSite(Site|string $site): Language
    {
        $site = $site instanceof Site ? $site : (Sites::get($site) ?: throw UnknownSite::for($site));

        $locale = (string) $site->locale();
        $lang = (string) $site->lang();

        return new Language(
            site: $site->handle(),
            name: $this->name($lang) ?? $this->siteName($site),
            locale: $locale,
            lang: $lang,
            region: $this->region($locale),
            direction: $site->direction(),
        );
    }

    protected function intl(): bool
    {
        return extension_loaded('intl');
    }

    private function name(string $lang): ?string
    {
        $code = Str::lower(preg_split('/[-_.@]/', $lang)[0]);

        if (! preg_match('/^[a-z]{2,8}$/', $code)) {
            return null;
        }

        $key = 'statamic::dictionary-languages.'.$code;
        $name = Lang::get($key, [], self::DISPLAY_LOCALE, false);

        if (is_string($name) && $name !== $key && trim($name) !== '') {
            return trim($name);
        }

        return $this->intl() ? self::known(Locale::getDisplayLanguage($code, self::DISPLAY_LOCALE), $code) : null;
    }

    private function region(string $locale): ?string
    {
        if (! $this->intl()) {
            return null;
        }

        $code = (string) Locale::getRegion($locale);

        return $code === '' ? null : self::known(Locale::getDisplayRegion($locale, self::DISPLAY_LOCALE), $code);
    }

    private function siteName(Site $site): string
    {
        $name = trim((string) $site->name());

        return $name === '' ? $site->handle() : $name;
    }

    private static function known(string|false $display, string $code): ?string
    {
        return $display === false || trim($display) === '' || strcasecmp($display, $code) === 0 ? null : trim($display);
    }
}
