<?php

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Targets\TargetRef;

it('round-trips every type', function (string $reference, ContentType $type, string $id, ?string $site, string $storageKey) {
    $ref = TargetRef::parse($reference);

    expect($ref->type)->toBe($type)
        ->and($ref->id)->toBe($id)
        ->and($ref->site)->toBe($site)
        ->and((string) $ref)->toBe($reference)
        ->and($ref)->toEqual(new TargetRef($type, $id, $site))
        ->and($ref->storageKey())->toBe($storageKey);
})->with([
    'entry' => ['entry::0b5f2c1e-7a3d-4c1b-9e2f-5d6a7b8c9d0e', ContentType::Entry, '0b5f2c1e-7a3d-4c1b-9e2f-5d6a7b8c9d0e', null, 'entries/0b5f2c1e-7a3d-4c1b-9e2f-5d6a7b8c9d0e'],
    'term' => ['term::topics::local-food::el', ContentType::Term, 'topics::local-food', 'el', 'terms/topics/local-food/el'],
    'global set' => ['globals::site_settings::de', ContentType::GlobalSet, 'site_settings', 'de', 'globals/site_settings/de'],
    'nav' => ['nav::main::el', ContentType::Nav, 'main', 'el', 'navs/main/el'],
    'translation file' => ['lang::messages::de', ContentType::Lang, 'messages', 'de', 'lang/messages/de'],
    'json translation file' => ['lang::_json::pt_BR', ContentType::Lang, '_json', 'pt_BR', 'lang/_json/pt_br.11bb446e'],
    'form texts' => ['forms::de', ContentType::Forms, 'de', null, 'forms/de'],
    'form texts in a regional language' => ['forms::pt_BR', ContentType::Forms, 'pt_BR', null, 'forms/pt_br.11bb446e'],
]);

it('accepts integer entry ids', function () {
    $ref = TargetRef::parse('entry::42');

    expect($ref->id)->toBe('42')
        ->and($ref->storageKey())->toBe('entries/42')
        ->and((string) new TargetRef(ContentType::Entry, 42))->toBe('entry::42')
        ->and(new TargetRef(ContentType::Entry, 42))->toEqual($ref);
});

it('keeps dots in slugs and gives them a safe path of their own', function () {
    $dotted = TargetRef::parse('term::topics::v1.2::el');
    $underscored = TargetRef::parse('term::topics::v1_2::el');

    expect($dotted->id)->toBe('topics::v1.2')
        ->and((string) $dotted)->toBe('term::topics::v1.2::el')
        ->and($dotted->storageKey())->toBe('terms/topics/v1_2.0e4f5bce/el')
        ->and($underscored->storageKey())->toBe('terms/topics/v1_2/el');
});

it('gives two ids that differ only by case different lower-case paths', function () {
    $upper = TargetRef::parse('entry::AbC')->storageKey();
    $lower = TargetRef::parse('entry::abc')->storageKey();

    expect($upper)->toBe('entries/abc.482e1ae7')
        ->and($lower)->toBe('entries/abc')
        ->and(strtolower($upper))->not->toBe(strtolower($lower));

    expect(TargetRef::parse('term::topics::Food::el')->storageKey())
        ->not->toBe(TargetRef::parse('term::topics::food::el')->storageKey());
});

it('makes every storage segment filesystem-safe', function (string $reference, string $storageKey) {
    $key = TargetRef::parse($reference)->storageKey();

    expect($key)->toBe($storageKey);

    foreach (explode('/', $key) as $segment) {
        expect($segment)->toMatch('/^[a-z0-9_-]+(\.[0-9a-f]{8})?$/');
    }
})->with([
    'greek slug' => ['term::topics::καλημέρα::el', 'terms/topics/_.bf9fcd6f/el'],
    'parent directory' => ['entry::..', 'entries/_.5ec1f7e7'],
    'slash' => ['entry::a/b', 'entries/a_b.c14cddc0'],
    'space and capitals' => ['term::topics::Local Food::el', 'terms/topics/local_food.77c49d79/el'],
    'capitals in a uuid' => ['entry::UUID-0B5F', 'entries/uuid-0b5f.c87ee2d4'],
]);

it('shortens long segments and keeps them apart', function () {
    $first = TargetRef::parse('term::topics::'.str_repeat('a', 300).'::el')->storageKey();
    $second = TargetRef::parse('term::topics::'.str_repeat('a', 299).'b::el')->storageKey();

    expect(strlen(explode('/', $first)[2]))->toBe(109)
        ->and($first)->toStartWith('terms/topics/'.str_repeat('a', 100).'.')
        ->and($first)->not->toBe($second);
});

it('rejects invalid references', function (string $reference) {
    expect(fn () => TargetRef::parse($reference))
        ->toThrow(InvalidTargetRef::class, "Invalid target reference [{$reference}]");
})->with([
    'empty' => [''],
    'no separator' => ['entry'],
    'single colon' => ['entry:abc'],
    'unknown type' => ['page::abc'],
    'type in another case' => ['ENTRY::abc'],
    'entry without id' => ['entry::'],
    'term without slug' => ['term::topics::el'],
    'term with an extra part' => ['term::topics::food::extra::el'],
    'term without taxonomy' => ['term::::food::el'],
    'term with a colon before the separator' => ['term::topics::food:::el'],
    'global set without site' => ['globals::site_settings'],
    'global set with an extra part' => ['globals::site_settings::extra::de'],
    'nav without site' => ['nav::main::'],
    'nav without handle' => ['nav::::el'],
    'translation file without language' => ['lang::messages'],
    'translation file with an extra part' => ['lang::messages::extra::de'],
    'form texts without language' => ['forms::'],
    'form texts with a site' => ['forms::de::de'],
    'form texts with a colon' => ['forms::de:'],
]);

it('validates when constructed directly', function (ContentType $type, string $id, ?string $site) {
    expect(fn () => new TargetRef($type, $id, $site))->toThrow(InvalidTargetRef::class);
})->with([
    'entry with a site' => [ContentType::Entry, 'abc', 'en'],
    'term id without slug' => [ContentType::Term, 'topics', 'el'],
    'term without site' => [ContentType::Term, 'topics::food', null],
    'slug ending in a colon' => [ContentType::Term, 'topics::food:', 'el'],
    'global set without site' => [ContentType::GlobalSet, 'site_settings', null],
    'nav handle with a separator' => [ContentType::Nav, 'main::footer', 'el'],
    'site with a separator' => [ContentType::Nav, 'main', 'el::de'],
    'translation file without language' => [ContentType::Lang, 'messages', null],
    'form texts with a site' => [ContentType::Forms, 'de', 'de'],
    'form texts without language' => [ContentType::Forms, '', null],
]);
