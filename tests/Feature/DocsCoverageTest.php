<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Tests\Support\Fieldtypes\NoteFieldtype;
use Reach\StatamicTolk\Tolk;
use Statamic\Facades\YAML;
use Statamic\Fields\Field;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\SplFileInfo;

const DOCS_ROOT = __DIR__.'/../..';

/**
 * Every Markdown file of `docs/` (the research reports are not documentation), keyed by file name.
 *
 * @return array<string, string>
 */
function docsFiles(): array
{
    return collect(File::files(DOCS_ROOT.'/docs'))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'md')
        ->mapWithKeys(fn (SplFileInfo $file) => [$file->getFilename() => $file->getContents()])
        ->sortKeys()
        ->all();
}

function docsText(): string
{
    return implode("\n", docsFiles());
}

function docsFile(string $name): string
{
    return docsFiles()[$name] ?? throw new RuntimeException("docs/{$name} is missing.");
}

/**
 * The commands in `src/Console/Commands`, keyed by name.
 *
 * @return array<string, Command>
 */
function docsCommands(): array
{
    return collect(File::files(DOCS_ROOT.'/src/Console/Commands'))
        ->map(fn (SplFileInfo $file) => app('Reach\\StatamicTolk\\Console\\Commands\\'.$file->getFilenameWithoutExtension()))
        ->filter(fn ($command) => $command instanceof Command)
        ->mapWithKeys(fn (Command $command) => [$command->getName() => $command])
        ->sortKeys()
        ->all();
}

/**
 * The section of `docs/cli.md` under the heading of one command, up to the next heading of that level.
 */
function docsCommandSection(string $name): ?string
{
    $sections = preg_split('/^## /m', docsFile('cli.md'));

    foreach ($sections as $section) {
        if (str_starts_with($section, "`{$name}`\n")) {
            return $section;
        }
    }

    return null;
}

/**
 * The keys of `config/tolk.php` as written in the file, dotted. A list, an empty array and a scalar end a key.
 *
 * @return list<string>
 */
function docsConfigKeys(?array $config = null, string $prefix = ''): array
{
    $config ??= require DOCS_ROOT.'/config/tolk.php';
    $keys = [];

    foreach ($config as $key => $value) {
        $path = $prefix.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            array_push($keys, ...docsConfigKeys($value, $path.'.'));
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}

/**
 * The keys of one entry of the model catalog, dotted (`price.input`).
 *
 * @return list<string>
 */
function docsCatalogEntryKeys(): array
{
    return collect(require DOCS_ROOT.'/resources/models.php')
        ->flatMap(fn (array $entries) => collect($entries)->flatMap(fn (array $entry) => array_keys(Arr::dot($entry))))
        ->map(fn (string $key) => preg_replace('/^options\..*/', 'options', $key))
        ->unique()
        ->values()
        ->all();
}

/**
 * Every path a doc may name in the config: the keys, their groups, and catalog entry keys under `models.*`.
 *
 * @return list<string>
 */
function docsConfigPaths(): array
{
    $paths = [];

    foreach (docsConfigKeys() as $key) {
        $segments = explode('.', $key);

        foreach (range(1, count($segments)) as $length) {
            $paths[] = implode('.', array_slice($segments, 0, $length));
        }
    }

    foreach (docsCatalogEntryKeys() as $key) {
        $paths[] = 'models.*.'.$key;
        $paths[] = 'models.*.'.Str::before($key, '.');
    }

    return array_values(array_unique($paths));
}

/**
 * The handles of the settings blueprint: its fields and the fields of its grids.
 *
 * @return list<string>
 */
function docsSettingKeys(): array
{
    $blueprint = YAML::parse(File::get(DOCS_ROOT.'/resources/blueprints/settings.yaml'));
    $handles = [];

    foreach ($blueprint['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $handles[] = $field['handle'];

                foreach ($field['field']['fields'] ?? [] as $nested) {
                    $handles[] = $nested['handle'];
                }
            }
        }
    }

    return array_values(array_unique($handles));
}

/**
 * The fenced code blocks of a doc with their language.
 *
 * @return list<array{language: string, code: string}>
 */
function docsCodeBlocks(string $name): array
{
    preg_match_all('/^```(\w*)\n(.*?)^```$/ms', docsFile($name), $matches, PREG_SET_ORDER);

    return array_map(fn (array $match) => ['language' => $match[1], 'code' => $match[2]], $matches);
}

/**
 * GitHub's anchor of a heading: lower case, punctuation removed, spaces as hyphens.
 */
function docsAnchor(string $heading): string
{
    $text = mb_strtolower(trim($heading));
    $text = preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', $text);

    return str_replace(' ', '-', $text);
}

/**
 * @return list<string>
 */
function docsAnchors(string $markdown): array
{
    $markdown = preg_replace('/^```.*?^```$/ms', '', $markdown);
    preg_match_all('/^#{1,6} (.+)$/m', $markdown, $matches);

    return array_map('docsAnchor', $matches[1]);
}

it('documents every command in its own section of cli.md, with every argument and option', function () {
    $commands = docsCommands();

    expect(array_keys($commands))->toContain('tolk:translate', 'tolk:site', 'tolk:lang', 'tolk:forms', 'tolk:work');

    foreach ($commands as $name => $command) {
        $section = docsCommandSection($name);

        expect($section)->not->toBeNull("docs/cli.md has no section for {$name}.");

        foreach ($command->getDefinition()->getArguments() as $argument) {
            expect($section)->toContain("`{$argument->getName()}`");
        }

        foreach ($command->getDefinition()->getOptions() as $option) {
            $written = $option->acceptValue() ? "`--{$option->getName()}=`" : "`--{$option->getName()}`";

            expect($section)->toContain($written);
        }

        expect(docsFile('cli.md'))->toContain("[`{$name}`](#".docsAnchor("`{$name}`").')');
    }
});

it('names no command or option that does not exist', function () {
    $commands = docsCommands();

    preg_match_all('/\btolk:[a-z-]+\b/', docsText(), $named);

    expect(array_diff(array_unique($named[0]), array_keys($commands)))->toBe([]);

    foreach ($commands as $name => $command) {
        preg_match_all('/`--([a-z-]+)=?`/', docsCommandSection($name), $options);

        foreach (array_unique($options[1]) as $option) {
            expect($command->getDefinition()->hasOption($option))->toBeTrue("docs/cli.md names --{$option} for {$name}.");
        }
    }
});

it('documents every config key, catalog entry key and environment variable in configuration.md', function () {
    $configuration = docsFile('configuration.md');

    foreach (docsConfigKeys() as $key) {
        expect($configuration)->toContain("`{$key}`");
    }

    foreach (docsCatalogEntryKeys() as $key) {
        expect($configuration)->toContain("`{$key}`");
    }

    preg_match_all("/env\('([A-Z_]+)'/", File::get(DOCS_ROOT.'/config/tolk.php'), $variables);

    expect($variables[1])->not->toBe([]);

    foreach ($variables[1] as $variable) {
        expect($configuration)->toContain("`{$variable}`");
    }
});

it('names no config key that does not exist', function () {
    $paths = docsConfigPaths();
    $groups = array_unique(array_map(fn (string $key) => Str::before($key, '.'), docsConfigKeys()));
    $pattern = '/`(?:tolk\.)?((?:'.implode('|', $groups).')(?:\.[a-z_*]+)*)`/';

    preg_match_all($pattern, docsText(), $named);

    foreach (array_unique($named[1]) as $path) {
        $regex = '/^'.str_replace('\*', '[^.]+', preg_quote($path, '/')).'$/';

        expect(collect($paths)->contains(fn (string $known) => preg_match($regex, $known) === 1))
            ->toBeTrue("The docs name the config key {$path}, which config/tolk.php does not have.");
    }
});

it('documents every setting and per-field option in configuration.md', function () {
    $configuration = docsFile('configuration.md');

    foreach ([...docsSettingKeys(), ...array_keys(FieldtypeRegistry::CONFIG_FIELDS)] as $key) {
        expect($configuration)->toContain("`{$key}`");
    }
});

it('documents the permissions and the events', function () {
    expect(docsFile('installation.md'))->toContain('`translate content`', '`run bulk translations`');

    foreach (File::files(DOCS_ROOT.'/src/Events') as $file) {
        $class = 'Reach\\StatamicTolk\\Events\\'.$file->getFilenameWithoutExtension();

        expect(docsFile('extending.md'))->toContain("`{$file->getFilenameWithoutExtension()}`");

        foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            expect(docsFile('extending.md'))->toContain("`{$parameter->getName()}`");
        }
    }
});

it('links only to files and headings that exist', function () {
    $files = ['README.md' => File::get(DOCS_ROOT.'/README.md')];

    foreach (docsFiles() as $name => $markdown) {
        $files["docs/{$name}"] = $markdown;
    }

    foreach ($files as $path => $markdown) {
        $prose = preg_replace('/^```.*?^```$/ms', '', $markdown);
        preg_match_all('/\]\(([^)\s]+)\)/', $prose, $links);

        foreach ($links[1] as $link) {
            if (preg_match('/^(https?:|mailto:)/', $link)) {
                continue;
            }

            [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $file = $target === '' ? $path : ltrim(dirname($path).'/'.$target, './');

            expect(File::exists(DOCS_ROOT.'/'.$file))->toBeTrue("{$path} links to {$link}, which does not exist.");

            if ($anchor !== null) {
                expect(docsAnchors(File::get(DOCS_ROOT.'/'.$file)))->toContain($anchor);
            }
        }
    }
});

it('runs the handler of the extension guide and holds it to the golden properties', function () {
    $code = collect(docsCodeBlocks('extending.md'))->first(fn (array $block) => str_contains($block['code'], 'implements FieldHandler'))['code'];
    $file = $this->temporaryPath('SeoMetaHandler.php');
    File::ensureDirectoryExists(dirname($file));
    File::put($file, $code);
    require_once $file;

    NoteFieldtype::register();
    Tolk::registerFieldtype('tolk_test_note', 'App\\Tolk\\SeoMetaHandler');

    $walker = app(ContentWalker::class);
    $field = new Field('seo', ['type' => 'tolk_test_note', 'display' => 'SEO', 'tolk_hint' => 'Search result snippet']);
    $value = [
        'title' => 'Rooms with a sea view',
        'description' => 'Six rooms and suites above the beach in Chania, each with its own balcony.',
        'image' => 'seo/rooms.jpg',
        'noindex' => false,
    ];
    $identity = fn (Leaf $leaf) => $leaf->text;
    $leaves = $walker->leaves($field, $value);
    $translated = $walker->mapField($field, $value, fn (Leaf $leaf) => '«'.$leaf->text.'»');
    $constant = fn () => 'X';

    expect($walker->mapField($field, $value, $identity))->toBe($value)
        ->and($walker->mapField($field, $value, $identity, target: true))->toBe($value)
        ->and(array_map(fn (Leaf $leaf) => [$leaf->label, $leaf->path, $leaf->hint], $leaves))->toBe([
            ['SEO › Meta title', ['seo', 'title'], 'Search result snippet'],
            ['SEO › Meta description', ['seo', 'description'], 'Search result snippet'],
        ])
        ->and($walker->leaves($field, $translated))->toHaveCount(2)
        ->and($translated)->toBe([...$value, 'title' => '«Rooms with a sea view»', 'description' => '«'.$value['description'].'»'])
        ->and($walker->mapField($field, $translated, $constant))->toBe($walker->mapField($field, $value, $constant))
        ->and($walker->mapField($field, 'not an array', $constant))->toBe('not an array');
});

it('shows a site registering its handler from the AppServiceProvider once Statamic has booted', function () {
    $boot = collect(docsCodeBlocks('extending.md'))->first(fn (array $block) => str_contains($block['code'], 'public function boot()'))['code'] ?? '';

    expect($boot)->toContain('Statamic::booted(fn () => Tolk::registerFieldtype(');
});

it('reads the per-field options of the configuration sample as documented', function () {
    $yaml = collect(docsCodeBlocks('configuration.md'))->first(fn (array $block) => $block['language'] === 'yaml')['code'];
    $blueprint = $this->makeBlueprint('collections.pages', 'page', YAML::parse($yaml));
    $explained = collect(app(TranslatableFields::class)->explain($blueprint))->keyBy('handle');

    expect($explained['product_code']['in_scope'])->toBeFalse()
        ->and($explained['product_code']['reason'])->toBe('disabled')
        ->and($explained['tagline']['in_scope'])->toBeTrue()
        ->and(app(ContentWalker::class)->leaves($blueprint->field('tagline'), 'Your summer by the sea')[0]->hint)
        ->toBe('Short marketing line, at most eight words');
});

it('includes a screenshot for each image the docs show', function () {
    preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/', docsText(), $images);

    expect($images[1])->not->toBe([]);

    foreach ($images[1] as $image) {
        expect(File::exists(DOCS_ROOT.'/docs/'.$image))->toBeTrue("docs/{$image} is missing.");
    }
});
