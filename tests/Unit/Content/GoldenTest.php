<?php

use Composer\InstalledVersions;
use Composer\Semver\Semver;
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

it('runs on a Pest that compares the committed snapshots instead of writing new ones', function () {
    $constraint = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true)['require-dev']['pestphp/pest'];
    $reason = 'Pest 4.0.0 to 4.1.2 name data-set snapshots differently, so the snapshot test writes new files and passes without comparing.';

    expect(Semver::satisfies('4.0.0', $constraint))->toBeFalse($reason)
        ->and(Semver::satisfies('4.1.2', $constraint))->toBeFalse($reason)
        ->and(version_compare((string) InstalledVersions::getVersion('pestphp/pest'), '4.1.3', '>='))->toBeTrue($reason);
});

it('changes nothing but the texts of the leaves', function (string $fixture) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    // Every letter outside the placeholders changed, which changes no Markdown structure: the Markdown handler keeps the
    // source of a block whose translation would change what of the document is code (§A3 rule 7).
    $injected = goldenWalker()->mapField($field, $value, fn (Leaf $leaf) => preg_replace('/(?:<\/?\d+>|<x\d+\/>|<br\/>)(*SKIP)(*FAIL)|\p{L}/u', 'ä', $leaf->text));
    // A text that does not depend on the leaf's but keeps its placeholders, as a valid translation must (§A3 rules 1 to 3).
    $constant = fn (Leaf $leaf) => 'X'.implode('', array_map(
        fn (string|int $id) => str_starts_with((string) $id, 'x') ? "<{$id}/>" : "<{$id}>X</{$id}>",
        array_keys($leaf->placeholders),
    ));

    expect($injected)->not->toBe($value)
        ->and(goldenWalker()->mapField($field, $injected, $constant))->toBe(goldenWalker()->mapField($field, $value, $constant));
})->with(ContentFixtures::names());
