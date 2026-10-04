<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;

function goldenWalker(): ContentWalker
{
    return app(ContentWalker::class);
}

/**
 * @param  list<Leaf>  $leaves
 */
function goldenLeaves(array $leaves): array
{
    return array_map(fn (Leaf $leaf) => [
        'path' => $leaf->path,
        'label' => $leaf->label,
        'kind' => $leaf->kind->value,
        'hint' => $leaf->hint,
        'max_length' => $leaf->maxLength,
        'text' => $leaf->text,
        'placeholders' => $leaf->placeholders,
    ], $leaves);
}

it('returns the value unchanged when every leaf keeps its text', function (string $fixture) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $identity = fn (Leaf $leaf) => $leaf->text;

    expect(goldenWalker()->mapField($field, $value, $identity))->toBe($value)
        ->and(goldenWalker()->mapField($field, $value, $identity, target: true))->toBe($value);
})->with(ContentFixtures::names());

it('gives the same leaves on every walk, as in the snapshot', function (string $fixture) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $leaves = goldenLeaves(goldenWalker()->leaves($field, $value));

    expect($leaves)->not->toBe([])
        ->and(goldenLeaves(goldenWalker()->leaves($field, $value)))->toBe($leaves)
        ->and(json_encode($leaves, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n")->toMatchSnapshot();
})->with(ContentFixtures::names());

it('changes nothing but the texts of the leaves', function (string $fixture) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $injected = goldenWalker()->mapField($field, $value, fn (Leaf $leaf) => '«'.$leaf->text.'»');
    $constant = fn () => 'X';

    expect($injected)->not->toBe($value)
        ->and(goldenWalker()->mapField($field, $injected, $constant))->toBe(goldenWalker()->mapField($field, $value, $constant));
})->with(ContentFixtures::names());
