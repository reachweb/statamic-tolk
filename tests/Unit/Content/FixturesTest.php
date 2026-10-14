<?php

use Illuminate\Support\Arr;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Facades\YAML;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Paths in the value that its blueprint does not describe: a set type without a set, a key without a field.
 */
function undescribedPaths(Field $field, mixed $value, string $path): array
{
    if (! is_array($value)) {
        return [];
    }

    $fieldtype = $field->fieldtype();

    return match ($field->type()) {
        'group' => undescribedKeys($fieldtype->fields(), $value, $path),
        'grid' => collect($value)->flatMap(fn (array $row, int $index) => undescribedKeys($fieldtype->fields(), Arr::except($row, 'id'), "{$path}.{$index}"))->all(),
        'replicator' => collect($value)->flatMap(fn (array $set, int $index) => undescribedSet($field, $set['type'], Arr::except($set, ['id', 'type', 'enabled']), "{$path}.{$index}"))->all(),
        'bard' => collect($value)->filter(fn (array $node) => $node['type'] === 'set')
            ->flatMap(fn (array $node, int $index) => undescribedSet($field, $node['attrs']['values']['type'], Arr::except($node['attrs']['values'], 'type'), "{$path}.{$index}"))
            ->all(),
        default => [],
    };
}

function undescribedSet(Field $field, string $set, array $values, string $path): array
{
    if (! $field->fieldtype()->flattenedSetsConfig()->has($set)) {
        return ["{$path} ({$set})"];
    }

    return undescribedKeys($field->fieldtype()->fields($set), $values, $path);
}

function undescribedKeys(Fields $fields, array $values, string $path): array
{
    $all = $fields->all();

    return collect($values)->flatMap(fn (mixed $value, string $handle) => isset($all[$handle])
        ? undescribedPaths($all[$handle], $value, "{$path}.{$handle}")
        : ["{$path}.{$handle}"])->all();
}

it('has every golden fixture and tokenizer sample', function () {
    expect(ContentFixtures::names())->toBe([
        'array-dynamic', 'array-expanded', 'array-keyed', 'bard-html-string', 'bard-inline', 'bard-rich', 'grid',
        'list-html', 'list', 'markdown-long', 'markdown-references', 'replicator-nested', 'table-html', 'table', 'text-antlers',
        'textarea-html',
    ])->and(array_keys(ContentFixtures::markup()))->toBe([
        'antlers.html', 'antlers.txt', 'br.html', 'code-fences.md', 'comment.html', 'images.html', 'images.md',
        'links-with-titles.html', 'links-with-titles.md', 'nested-inline.html', 'reference-definition.md', 'script.html',
    ])->and(array_keys(ContentFixtures::markup('md')))->toBe([
        'code-fences.md', 'images.md', 'links-with-titles.md', 'reference-definition.md',
    ]);
});

it('loads a fixture as a field config and a value its blueprint describes', function (string $name) {
    $fixture = ContentFixtures::raw($name);
    $field = ContentFixtures::field($name);

    expect(array_keys($fixture))->toBe(['field', 'value'])
        ->and($fixture['value'])->not->toBeEmpty()
        ->and($field->handle())->toBe(str_replace('-', '_', $name))
        ->and($field->fieldtype()->handle())->toBe($fixture['field']['type'])
        ->and(undescribedPaths($field, $fixture['value'], $field->handle()))->toBe([]);
})->with(ContentFixtures::names());

it('resolves the imported fieldset with its prefix', function () {
    $blocks = ContentFixtures::field('replicator-nested')->fieldtype()->fields()->get('blocks');

    expect($blocks->fieldtype()->fields('cta')->all()->keys()->all())->toBe(['cta_label', 'cta_url', 'cta_style', 'cta_new_tab'])
        ->and($blocks->fieldtype()->fields('cta')->get('cta_label')->display())->toBe('Label');
});

it('keeps the fingerprint of a fixture through a YAML dump and parse', function (string $name) {
    $value = ContentFixtures::value($name);
    $parsed = YAML::parse(YAML::dump(['value' => $value]))['value'];

    expect(Fingerprint::of($parsed))->toBe(Fingerprint::of($value));
})->with(ContentFixtures::names());

it('reads a tokenizer sample byte for byte', function () {
    expect(ContentFixtures::markup('md')['code-fences.md'])->toContain("```html\n<div class=\"booking\">\n  {{ partial:booking }}\n</div>\n\n<p>")
        ->and(ContentFixtures::markup('txt')['antlers.txt'])->toEndWith('Stay {{$ $nights $}} nights.'.PHP_EOL);
});
