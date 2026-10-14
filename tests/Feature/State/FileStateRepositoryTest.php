<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Exceptions\UnreadableState;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\FileStateRepository;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Facades\YAML;

beforeEach(function () {
    $this->useFileCache();

    $this->repository = app(StateRepository::class);
    $this->root = config('tolk.state.path');
});

function withTitle(string $sourceHash, string $targetHash = '1b7d02c4a9e6f358'): Closure
{
    return fn (ItemState $state) => $state->withField('title', new FieldState(sourceHash: $sourceHash, targetHash: $targetHash));
}

it('is bound to the file driver', function () {
    expect($this->repository)->toBeInstanceOf(FileStateRepository::class)
        ->and(app(StateRepository::class))->toBe($this->repository);
});

it('returns an empty state for a target without a file and creates nothing', function () {
    $ref = TargetRef::parse('entry::0b5f');

    $state = $this->repository->get($ref);

    expect($state)->toEqual(ItemState::empty($ref))
        ->and($state->isEmpty())->toBeTrue()
        ->and($state->version)->toBe(ItemState::VERSION)
        ->and($this->root)->not->toBeDirectory();
});

it('creates, changes and deletes a record', function () {
    $ref = TargetRef::parse('term::topics::food::el');
    $path = $this->root.'/terms/topics/food/el.yaml';

    $created = $this->repository->update($ref, fn (ItemState $state) => new ItemState($ref, 'en', ItemState::VERSION, [
        'title' => new FieldState(sourceHash: '9f2c1a7e5b3d4c10', targetHash: '1b7d02c4a9e6f358'),
    ]));

    expect($this->repository->path($ref))->toBe($path)
        ->and($path)->toBeFile()
        ->and(YAML::parse(File::get($path)))->toBe([
            'reference' => 'term::topics::food::el',
            'source_site' => 'en',
            'v' => 1,
            'fields' => ['title' => ['source_hash' => '9f2c1a7e5b3d4c10', 'target_hash' => '1b7d02c4a9e6f358']],
        ])
        ->and($this->repository->get($ref))->toEqual($created);

    $seen = null;
    $changed = $this->repository->update($ref, function (ItemState $state) use (&$seen) {
        $seen = $state;

        return $state->withField('description', new FieldState(sourceHash: '77aa000000000000'));
    });

    expect($seen)->toEqual($created)
        ->and(array_keys($changed->fields))->toBe(['title', 'description'])
        ->and($this->repository->get($ref))->toEqual($changed);

    $this->repository->update($ref, fn (ItemState $state) => $state->withoutField('title'));

    expect(array_keys($this->repository->get($ref)->fields))->toBe(['description']);

    $deleted = $this->repository->update($ref, fn (ItemState $state) => $state->withoutField('description'));

    expect($deleted->isEmpty())->toBeTrue()
        ->and($path)->not->toBeFile()
        ->and($this->root.'/terms')->not->toBeDirectory()
        ->and($this->root)->toBeDirectory()
        ->and($this->repository->get($ref))->toEqual(ItemState::empty($ref));
});

it('keeps parent directories that still hold another record', function () {
    $greek = TargetRef::parse('term::topics::food::el');
    $german = TargetRef::parse('term::topics::food::de');

    $this->repository->update($greek, withTitle('s1'));
    $this->repository->update($german, withTitle('s2'));
    $this->repository->delete($greek);

    expect($this->repository->path($greek))->not->toBeFile()
        ->and($this->repository->path($german))->toBeFile()
        ->and($this->repository->get($german)->field('title')->sourceHash)->toBe('s2');
});

it('deletes a record and does nothing for a target without one', function () {
    $ref = TargetRef::parse('globals::site_settings::de');

    $this->repository->delete($ref);
    $this->repository->update($ref, withTitle('s1'));
    $this->repository->delete($ref);

    expect($this->repository->path($ref))->not->toBeFile()
        ->and($this->root.'/globals')->not->toBeDirectory()
        ->and($this->repository->get($ref)->isEmpty())->toBeTrue();
});

it('writes nothing for an empty result when no record exists', function () {
    $ref = TargetRef::parse('entry::0b5f');

    $state = $this->repository->update($ref, fn (ItemState $state) => $state);

    expect($state->isEmpty())->toBeTrue()
        ->and($this->root)->not->toBeDirectory();
});

it('leaves no temporary file behind', function () {
    $ref = TargetRef::parse('entry::0b5f');

    $this->repository->update($ref, withTitle('s1'));
    $this->repository->update($ref, withTitle('s2'));

    expect(File::files($this->root.'/entries'))->toHaveCount(1)
        ->and(File::files($this->root.'/entries')[0]->getFilename())->toBe('0b5f.yaml');
});

it('maps a reference with unsafe characters to a safe path and round-trips it', function (string $reference, string $storageKey) {
    $ref = TargetRef::parse($reference);

    $this->repository->update($ref, withTitle('s1'));

    $path = $this->repository->path($ref);

    expect($path)->toBe($this->root.'/'.$storageKey.'.yaml')
        ->and($path)->toBeFile()
        ->and(realpath($path))->toStartWith(realpath($this->root).'/')
        ->and((string) $this->repository->get($ref)->reference)->toBe($reference)
        ->and($this->repository->get($ref)->field('title')->sourceHash)->toBe('s1')
        ->and(array_map('strval', iterator_to_array($this->repository->references(), false)))->toBe([$reference]);
})->with([
    'parent directories' => ['entry::../../outside', 'entries/_outside.e28b700f'],
    'greek slug with a space' => ['term::topics::Τοπικό φαγητό::el', 'terms/topics/_.e66cbaba/el'],
    'slash and capitals' => ['nav::Main/Footer::el', 'navs/main_footer.240f9781/el'],
]);

it('never gives two different ids the same path', function (string $first, string $second) {
    $firstRef = TargetRef::parse($first);
    $secondRef = TargetRef::parse($second);

    $this->repository->update($firstRef, withTitle('first'));
    $this->repository->update($secondRef, withTitle('second'));

    expect($this->repository->path($firstRef))->not->toBe($this->repository->path($secondRef))
        ->and(strtolower($this->repository->path($firstRef)))->not->toBe(strtolower($this->repository->path($secondRef)))
        ->and($this->repository->get($firstRef)->field('title')->sourceHash)->toBe('first')
        ->and($this->repository->get($secondRef)->field('title')->sourceHash)->toBe('second');
})->with([
    'case' => ['entry::AbC', 'entry::abc'],
    'dot and underscore' => ['term::topics::v1.2::el', 'term::topics::v1_2::el'],
    'space and underscore' => ['term::topics::local food::el', 'term::topics::local_food::el'],
    'two greek slugs' => ['term::topics::καλημέρα::el', 'term::topics::καλησπέρα::el'],
]);

it('keeps hashes that look like numbers as strings', function () {
    $ref = TargetRef::parse('entry::0b5f');

    $this->repository->update($ref, fn (ItemState $state) => $state->withField('title', new FieldState(
        sourceHash: '1234567890123456',
        targetHash: '1e10000000000000',
        segments: ['000000000012', '0x0000000000'],
        history: [['source_hash' => '0000000000000000', 'target_hash' => '1111111111111111']],
    )));

    $field = $this->repository->get($ref)->field('title');

    expect($field->sourceHash)->toBe('1234567890123456')
        ->and($field->targetHash)->toBe('1e10000000000000')
        ->and($field->segments)->toBe(['000000000012', '0x0000000000'])
        ->and($field->history)->toBe([['source_hash' => '0000000000000000', 'target_hash' => '1111111111111111']]);
});

it('reads a record stored for another reference as empty', function () {
    $ref = TargetRef::parse('entry::0b5f');
    $path = $this->repository->path($ref);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, YAML::dump(['reference' => 'entry::other', 'v' => 1, 'fields' => ['title' => ['source_hash' => 's1']]]));

    expect($this->repository->get($ref))->toEqual(ItemState::empty($ref));

    $this->repository->update($ref, withTitle('s2'));

    expect(YAML::parse(File::get($path))['reference'])->toBe('entry::0b5f');
});

it('refuses to read or update a record that does not parse, naming its file, and leaves it as it is', function (Closure $damage, string $error) {
    $ref = TargetRef::parse('entry::0b5f');
    $this->repository->update($ref, withTitle('s1'));
    $path = $this->repository->path($ref);
    File::put($path, $damage(File::get($path)));
    $damaged = File::get($path);

    foreach ([fn () => $this->repository->get($ref), fn () => $this->repository->update($ref, withTitle('s2'))] as $call) {
        try {
            $call();
            $thrown = null;
        } catch (UnreadableState $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(UnreadableState::class)
            ->and($thrown->path)->toBe($path)
            ->and($thrown->getMessage())->toContain("The translation state file {$path} cannot be read: {$error}. Fix it");
    }

    expect(File::get($path))->toBe($damaged);

    $this->repository->delete($ref);

    expect($path)->not->toBeFile();
})->with([
    'merge conflict markers' => [
        fn (string $yaml) => str_replace("fields:\n", "fields:\n<<<<<<< HEAD\n", $yaml)."=======\n  title:\n    source_hash: s2\n>>>>>>> translations\n",
        'Unable to parse at line 4 (near "<<<<<<< HEAD")',
    ],
    'a key both sides added' => [
        fn (string $yaml) => $yaml."  title:\n    source_hash: s2\n",
        'Duplicate key "title" detected at line 7',
    ],
]);

it('lists the records that do not parse, which references() leaves out', function () {
    $healthy = TargetRef::parse('entry::0b5f');
    $broken = TargetRef::parse('term::topics::food::el');

    $unreadable = fn () => array_map(fn (UnreadableState $exception) => $exception->path, iterator_to_array($this->repository->unreadable(), false));

    expect($this->repository->unreadable())->toBeInstanceOf(Generator::class)
        ->and($unreadable())->toBe([]);

    $this->repository->update($healthy, withTitle('s1'));
    $this->repository->update($broken, withTitle('s1'));
    File::put($this->repository->path($broken), "<<<<<<< HEAD\nreference: 'term::topics::food::el'\n");
    File::put($this->root.'/entries/no-reference.yaml', YAML::dump(['v' => 1]));

    expect($unreadable())->toBe([$this->repository->path($broken)])
        ->and(array_map('strval', iterator_to_array($this->repository->references(), false)))->toBe(['entry::0b5f']);
});

it('keeps the version a record was stored with', function () {
    $ref = TargetRef::parse('entry::0b5f');
    $path = $this->repository->path($ref);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, YAML::dump(['reference' => 'entry::0b5f', 'fields' => ['title' => ['source_hash' => 's1']]]));

    expect($this->repository->get($ref)->version)->toBe(0)
        ->and($this->repository->get($ref)->field('title')->sourceHash)->toBe('s1');
});

it('rejects a callback that returns the state of another target', function () {
    $ref = TargetRef::parse('entry::0b5f');

    expect(fn () => $this->repository->update($ref, fn () => ItemState::empty(TargetRef::parse('entry::other'))->withField('title', new FieldState(sourceHash: 's1'))))
        ->toThrow(LogicException::class, 'A state update of [entry::0b5f] must return the ItemState of that target.');

    expect(fn () => $this->repository->update($ref, fn () => null))->toThrow(LogicException::class);

    expect($this->root)->not->toBeDirectory();
});

it('holds the lock on the configured store during the update and releases it', function () {
    config([
        'cache.stores.tolk-state-locks' => ['driver' => 'file', 'path' => $this->temporaryPath('locks')],
        'tolk.cache_store' => 'tolk-state-locks',
    ]);

    $ref = TargetRef::parse('entry::0b5f');
    $name = 'tolk:state:'.sha1('entry::0b5f');
    $heldDuringUpdate = null;
    $heldOnDefaultStore = null;

    $this->repository->update($ref, function (ItemState $state) use ($name, &$heldDuringUpdate, &$heldOnDefaultStore) {
        $heldDuringUpdate = ! Cache::store('tolk-state-locks')->lock($name, 10)->get();
        $heldOnDefaultStore = ! Cache::lock($name, 10)->get();
        Cache::lock($name)->forceRelease();

        return $state->withField('title', new FieldState(sourceHash: 's1'));
    });

    $lock = Cache::store('tolk-state-locks')->lock($name, 10);

    expect($heldDuringUpdate)->toBeTrue()
        ->and($heldOnDefaultStore)->toBeFalse()
        ->and($lock->get())->toBeTrue();

    $lock->release();
});

it('releases the lock when the callback throws', function () {
    $ref = TargetRef::parse('entry::0b5f');

    expect(fn () => $this->repository->update($ref, fn () => throw new RuntimeException('Callback failed')))
        ->toThrow(RuntimeException::class, 'Callback failed');

    expect(Cache::lock('tolk:state:'.sha1('entry::0b5f'), 10)->get())->toBeTrue();
});

it('lists every stored reference lazily from the stored key', function () {
    $refs = ['entry::0b5f', 'term::topics::Local Food::el', 'globals::site_settings::de', 'nav::main::el'];

    expect($this->repository->references())->toBeInstanceOf(Generator::class)
        ->and(iterator_to_array($this->repository->references(), false))->toBe([]);

    foreach ($refs as $reference) {
        $this->repository->update(TargetRef::parse($reference), withTitle('s1'));
    }

    File::put($this->root.'/entries/broken.yaml', "reference: [unclosed\n");
    File::put($this->root.'/entries/no-reference.yaml', YAML::dump(['v' => 1]));
    File::put($this->root.'/entries/invalid-reference.yaml', YAML::dump(['reference' => 'page::1']));
    File::put($this->root.'/entries/0b5f.yaml.abc123.tmp', YAML::dump(['reference' => 'entry::leftover']));

    $listed = array_map('strval', iterator_to_array($this->repository->references(), false));
    sort($listed);
    sort($refs);

    expect($listed)->toBe($refs);
});
