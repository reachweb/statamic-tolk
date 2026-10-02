<?php

use Reach\StatamicTolk\Content\LeafRules;

it('skips strings that hold no text to translate', function (string $text) {
    expect(LeafRules::isTranslatable($text))->toBeFalse();
})->with([
    'empty' => '',
    'whitespace' => " \n\t\u{00A0} ",
    'digits' => '2026',
    'a time' => '14:00',
    'a price' => '€30',
    'punctuation' => '— … !',
    'an absolute url' => 'https://example.com/rooms?view=sea#top',
    'an http url' => 'http://example.com',
    'a protocol-relative url' => '//cdn.example.com/app.js',
    'a root-relative path' => '/contact',
    'a nested path' => '/rooms/sea-view-suite',
    'mailto' => 'mailto:info@example.com',
    'tel' => 'tel:+302821012345',
    'a fragment' => '#top',
    'an email' => 'info@example.com',
    'a hex colour' => '#ff0000',
    'a short hex colour' => '#FFF',
    'a statamic link' => 'statamic://entry::1',
    'an entry reference' => 'entry::0b5f2a6c-3d1e-4f7a-9c8b-1a2b3c4d5e6f',
    'an asset reference' => 'asset::assets::images/hero.jpg',
    'a class name' => 'text-center',
    'a key' => 'icon_arrow',
    'a class name with digits' => 'col-6',
    'surrounding whitespace' => '  text-center  ',
    'only an antlers tag' => '{{ site:name }}',
    'only antlers tags' => '{{ partial:hero }} {{# note #}}',
    'a path behind an antlers tag' => '{{ site:url }}/contact',
    'mailto with an antlers tag' => 'mailto:{{ email }}',
]);

it('keeps strings with text to translate', function (string $text) {
    expect(LeafRules::isTranslatable($text))->toBeTrue();
})->with([
    'a word' => 'Home',
    'a lowercase word' => 'home',
    'greek' => 'Καλημέρα',
    'a sentence with a time' => 'Check-in from 14:00',
    'a capitalised hyphenated word' => 'Check-in',
    'a price with a word' => '€30 per night',
    'text with an antlers tag' => 'Welcome to {{ site:name }}',
    'text with a url' => 'Visit https://example.com today',
    'text with an email' => 'Write to info@example.com',
    'a slash with spaces' => '/ night',
    'a hashtag sentence' => '#1 hotel in Chania',
    'two lowercase words' => 'text center',
    'snake case with a capital' => 'Icon_arrow',
]);
