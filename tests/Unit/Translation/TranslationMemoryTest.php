<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\Translation\TranslationMemory;
use Statamic\Fields\Field;

function memoryField(): Field
{
    return new Field('body', ['type' => 'bard', 'display' => 'Body', 'buttons' => ['bold', 'italic', 'anchor']]);
}

/**
 * A Bard paragraph; a string is a plain text node, [text, mark] a text node with one mark.
 */
function memoryParagraph(string|array ...$runs): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (string|array $run) => is_string($run)
        ? ['type' => 'text', 'text' => $run]
        : ['type' => 'text', 'marks' => [$run[1]], 'text' => $run[0]], $runs)];
}

function memoryLink(): array
{
    return ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms']];
}

/**
 * @return list<Leaf>
 */
function memoryLeaves(mixed $value): array
{
    return app(ContentWalker::class)->leaves(memoryField(), $value);
}

/**
 * The value with each leaf replaced by its translation, given in the leaf's own numbering.
 */
function memoryTranslated(mixed $value, array $translations): mixed
{
    return app(ContentWalker::class)->mapField(memoryField(), $value, fn (Leaf $leaf) => $translations[$leaf->text] ?? $leaf->text);
}

/**
 * A machine record of the value: the fingerprint of each leaf, or null for the indexes left untranslated.
 */
function memoryState(mixed $source, array $untranslated = []): FieldState
{
    $segments = array_map(fn (Leaf $leaf) => Fingerprint::ofText($leaf->text), memoryLeaves($source));

    foreach ($untranslated as $index) {
        $segments[$index] = null;
    }

    return new FieldState(sourceHash: Fingerprint::of($source), targetHash: 'machine', segments: $segments);
}

function memoryFor(FieldState $state, mixed $target, mixed $source): array
{
    return app(TranslationMemory::class)->forField(memoryField(), $state, $target, memoryLeaves($source));
}

beforeEach(function () {
    $this->source = [
        memoryParagraph('Welcome to ', ['Chania', ['type' => 'bold']], '.'),
        memoryParagraph('Our rooms face the sea.'),
        memoryParagraph('Breakfast is served until ten.'),
    ];

    $this->target = memoryTranslated($this->source, [
        'Welcome to <1>Chania</1>.' => 'Καλώς ήρθατε στα <1>Χανιά</1>.',
        'Our rooms face the sea.' => 'Τα δωμάτιά μας βλέπουν τη θάλασσα.',
        'Breakfast is served until ten.' => 'Το πρωινό σερβίρεται έως τις δέκα.',
    ]);
});

it('pairs each target leaf of an aligned Bard field with its source leaf', function () {
    expect(memoryFor(memoryState($this->source), $this->target, $this->source))->toBe([
        Fingerprint::ofText('Welcome to <1>Chania</1>.') => 'Καλώς ήρθατε στα <1>Χανιά</1>.',
        Fingerprint::ofText('Our rooms face the sea.') => 'Τα δωμάτιά μας βλέπουν τη θάλασσα.',
        Fingerprint::ofText('Breakfast is served until ten.') => 'Το πρωινό σερβίρεται έως τις δέκα.',
    ]);
});

it('knows only the leaves the current source still has, wherever they moved', function () {
    $current = [
        memoryParagraph('Breakfast is served until ten.'),
        memoryParagraph('Our rooms face the bay.'),
        memoryParagraph('Welcome to ', ['Chania', ['type' => 'bold']], '.'),
    ];

    expect(memoryFor(memoryState($this->source), $this->target, $current))->toBe([
        Fingerprint::ofText('Welcome to <1>Chania</1>.') => 'Καλώς ήρθατε στα <1>Χανιά</1>.',
        Fingerprint::ofText('Breakfast is served until ten.') => 'Το πρωινό σερβίρεται έως τις δέκα.',
    ]);
});

it('gives nothing when the target has another number of leaves than the record', function () {
    $longer = [...$this->target, memoryParagraph('Ένα κείμενο που πρόσθεσε κάποιος.')];

    expect(memoryFor(memoryState($this->source), $longer, $this->source))->toBe([])
        ->and(memoryFor(memoryState($this->source), array_slice($this->target, 1), $this->source))->toBe([]);
});

it('gives nothing for a record without leaf fingerprints', function () {
    expect(memoryFor(new FieldState(sourceHash: 'source', targetHash: 'machine'), $this->target, $this->source))->toBe([]);
});

it('does not reuse a leaf that was left untranslated', function () {
    $target = memoryTranslated($this->source, [
        'Welcome to <1>Chania</1>.' => 'Καλώς ήρθατε στα <1>Χανιά</1>.',
        'Breakfast is served until ten.' => 'Το πρωινό σερβίρεται έως τις δέκα.',
    ]);

    expect(memoryFor(memoryState($this->source, untranslated: [1]), $target, $this->source))->toBe([
        Fingerprint::ofText('Welcome to <1>Chania</1>.') => 'Καλώς ήρθατε στα <1>Χανιά</1>.',
        Fingerprint::ofText('Breakfast is served until ten.') => 'Το πρωινό σερβίρεται έως τις δέκα.',
    ]);
});

it('gives back formatted runs that the translation reordered in the source\'s numbering', function () {
    $source = [memoryParagraph(['Book', ['type' => 'bold']], ' a ', ['sea view suite', memoryLink()], '.')];
    $target = memoryTranslated($source, ['<1>Book</1> a <2>sea view suite</2>.' => 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.']);

    expect(array_column(app(ContentWalker::class)->leaves(memoryField(), $target, target: true), 'text'))->toBe(['Μια <1>σουίτα με θέα</1> <2>κλείστε</2>.'])
        ->and(memoryFor(memoryState($source), $target, $source))->toBe([
            Fingerprint::ofText('<1>Book</1> a <2>sea view suite</2>.') => 'Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.',
        ]);
});

it('leaves out a target leaf whose formatting the source leaf does not have', function () {
    $source = [memoryParagraph(['Book', ['type' => 'bold']], ' now.')];
    $target = [memoryParagraph(['Κλείστε', ['type' => 'italic']], ' τώρα.')];

    expect(memoryFor(memoryState($source), $target, $source))->toBe([]);
});

it('keeps the first translation of a source text that appears twice', function () {
    $source = [memoryParagraph('Welcome.'), memoryParagraph('Our rooms face the sea.'), memoryParagraph('Welcome.')];
    $target = [memoryParagraph('Καλώς ήρθατε.'), memoryParagraph('Τα δωμάτιά μας βλέπουν τη θάλασσα.'), memoryParagraph('Καλωσορίσατε.')];

    expect(memoryFor(memoryState($source), $target, $source))->toBe([
        Fingerprint::ofText('Welcome.') => 'Καλώς ήρθατε.',
        Fingerprint::ofText('Our rooms face the sea.') => 'Τα δωμάτιά μας βλέπουν τη θάλασσα.',
    ]);
});

it('takes the next translation of a repeated source text when the first cannot be renumbered', function () {
    $source = [memoryParagraph(['Book', ['type' => 'bold']], ' now.'), memoryParagraph(['Book', ['type' => 'bold']], ' now.')];
    $target = [memoryParagraph(['Κλείστε', ['type' => 'italic']], ' τώρα.'), memoryParagraph(['Κλείστε', ['type' => 'bold']], ' τώρα.')];

    expect(memoryFor(memoryState($source), $target, $source))->toBe([
        Fingerprint::ofText('<1>Book</1> now.') => '<1>Κλείστε</1> τώρα.',
    ]);
});
