<?php

use Illuminate\Support\Facades\File;
use Reach\StatamicTolk\Tests\Support\TemplateStrings;

/**
 * The classes in the `utilities` layer of core's CP stylesheet.
 *
 * @return array<string, true>
 */
function coreUtilities(string $css): array
{
    $classes = [];
    $offset = 0;

    while (($start = strpos($css, '@layer utilities{', $offset)) !== false) {
        $depth = 1;
        $i = $start + strlen('@layer utilities{');

        for (; $depth > 0 && $i < strlen($css); $i++) {
            $depth += match ($css[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
        }

        preg_match_all('/\.((?:[A-Za-z0-9_-]|\\\\.)+)/', substr($css, $start, $i - $start), $matches);

        foreach ($matches[1] as $class) {
            $classes[stripslashes($class)] = true;
        }

        $offset = $i;
    }

    return $classes;
}

/**
 * What a utility sets, for the utilities whose colour may change with a variant; null for the others.
 */
function utilityGroup(string $utility): ?string
{
    $utility = trim($utility, '!');

    if (preg_match('/^(bg|text|border|decoration|ring|outline|fill|stroke|divide)(?:-|$)/', $utility, $match) !== 1) {
        return null;
    }

    return match (true) {
        $match[1] === 'text' && preg_match('/^text-(?:2?xs|sm|base|lg|[2-9]?xl|left|right|center|start|end|\[.*\])$/', $utility) === 1 => 'text-layout',
        $match[1] === 'border' && preg_match('/^border(?:-[trblxyse])?(?:-\d+)?$/', $utility) === 1 => 'border-width',
        default => $match[1],
    };
}

// Statamic declares `addon-theme, addon-utilities` as the first layers of its stylesheet, so the addon's utilities rank
// below core's own: `bg-blue-600 dark:bg-blue-400` stays blue-600 in dark mode, because core's stylesheet has
// `bg-blue-600` and not the variant. A variant like that needs `!`, whose important declaration wins from the first layer.
it('lets no dark or hover colour of the CP scripts lose to a class of core on the same element', function () {
    $stylesheets = File::glob(dirname(__DIR__, 2).'/vendor/statamic/cms/resources/dist/build/assets/app-*.css');

    expect($stylesheets)->toHaveCount(1);

    $css = File::get($stylesheets[0]);
    $core = coreUtilities($css);

    expect($css)->toContain('@layer addon-theme,addon-utilities;')
        ->and($core)->toHaveKey('bg-blue-600');

    $findings = [];

    foreach (TemplateStrings::cpScripts() as $file) {
        preg_match_all('/class="([^"]+)"|\'([^\'\n]*:[^\'\n]*)\'|"([^"\n]*:[^"\n]*)"/', $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $tokens = preg_split('/\s+/', trim(implode(' ', array_slice($match, 1))));

            foreach ($tokens as $variant) {
                $group = str_contains($variant, ':') && ! str_ends_with($variant, '!') ? utilityGroup(substr($variant, strrpos($variant, ':') + 1)) : null;

                if ($group === null || isset($core[$variant])) {
                    continue;
                }

                foreach ($tokens as $base) {
                    if (! str_contains($base, ':') && isset($core[$base]) && utilityGroup($base) === $group) {
                        $findings[] = "{$file->getRelativePathname()}: {$base} {$variant}";
                    }
                }
            }
        }
    }

    expect(array_values(array_unique($findings)))->toBe([]);
});
