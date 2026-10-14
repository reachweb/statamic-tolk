<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\SplFileInfo;

const BOOST_GUIDELINE = __DIR__.'/../../resources/boost/guidelines/core.blade.php';

function boostGuideline(): string
{
    return File::get(BOOST_GUIDELINE);
}

/**
 * Renders the guideline as Laravel Boost does (`RendersBladeGuidelines`): backticks and PHP open tags are hidden from
 * Blade, the rest is compiled, so a `{{ }}` outside `@verbatim` is a Blade expression.
 */
function boostRender(string $content): string
{
    $placeholders = ['`' => '___SINGLE_BACKTICK___', '<?php' => '___OPEN_PHP_TAG___'];

    $rendered = Blade::render(str_replace(array_keys($placeholders), array_values($placeholders), $content), ['assist' => null]);

    return str_replace(array_values($placeholders), array_keys($placeholders), html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5));
}

/**
 * @return list<string>
 */
function boostLines(string $content): array
{
    return collect(preg_split('/\R/', $content))
        ->map(fn (string $line) => rtrim($line))
        ->reject(fn (string $line) => $line === '' || in_array($line, ['@verbatim', '@endverbatim'], true))
        ->values()
        ->all();
}

it('renders through Blade without changing a line', function () {
    $source = boostGuideline();

    expect(boostLines(boostRender($source)))->toBe(boostLines($source));
});

it('keeps every Blade-like expression inside @verbatim', function () {
    $outside = preg_replace('/@verbatim.*?@endverbatim/s', '', boostGuideline());

    expect($outside)->not->toMatch('/\{\{|\{!!|@(?!verbatim|endverbatim)[a-z]/i');
});

it('names every command, and no other', function () {
    $commands = collect(File::files(__DIR__.'/../../src/Console/Commands'))
        ->map(fn (SplFileInfo $file) => app('Reach\\StatamicTolk\\Console\\Commands\\'.$file->getFilenameWithoutExtension()))
        ->filter(fn ($command) => $command instanceof Command)
        ->map(fn (Command $command) => $command->getName())
        ->sort()
        ->values()
        ->all();

    preg_match_all('/\btolk:[a-z-]+\b/', boostGuideline(), $named);

    expect($commands)->toContain('tolk:translate', 'tolk:site', 'tolk:lang', 'tolk:forms', 'tolk:work')
        ->and(collect($named[0])->unique()->sort()->values()->all())->toBe($commands);
});

it('names the events with their properties, the permissions, the per-field options and the state folder', function () {
    $guideline = boostGuideline();

    foreach (File::files(__DIR__.'/../../src/Events') as $file) {
        $class = 'Reach\\StatamicTolk\\Events\\'.$file->getFilenameWithoutExtension();

        expect($guideline)->toContain("`{$file->getFilenameWithoutExtension()}`");

        foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            expect($guideline)->toContain("`{$parameter->getName()}`");
        }
    }

    foreach (array_keys(FieldtypeRegistry::CONFIG_FIELDS) as $option) {
        expect($guideline)->toContain("`{$option}`");
    }

    expect($guideline)
        ->toContain('`translate content`', '`run bulk translations`', 'Statamic::booted(fn () => Tolk::registerFieldtype(', 'Do not edit the files under `content/tolk/` by hand');
});

it('names Tolk\'s own key variables before the SDK\'s, which Tolk reads only without them', function () {
    $guideline = boostGuideline();

    foreach (ModelRef::PROVIDERS as $provider) {
        $own = '`'.Providers::variable($provider, addon: true).'`';
        $shared = '`'.Providers::variable($provider).'`';

        expect($guideline)->toContain($own, $shared)
            ->and(strpos($guideline, $own))->toBeLessThan(strpos($guideline, $shared));
    }

    expect($guideline)->toContain('`tolk.providers.*.key`', '`ai.providers.*.key`');
});
