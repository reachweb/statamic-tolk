<?php

use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Runs\RunRequest;

it('fills in the default options and keeps null where the settings decide', function () {
    $request = new RunRequest(['el', 'de', 'el'], RunDriver::Browser, collections: ['*']);

    expect($request->sites)->toBe(['el', 'de'])
        ->and($request->options)->toBe([
            'mode' => 'missing',
            'overwrite_manual' => false,
            'review' => null,
            'translator' => null,
            'reviewer' => null,
            'new_status' => null,
            'publish_changes' => false,
        ])
        ->and(RunRequest::all($request->collections))->toBeTrue()
        ->and(RunRequest::all(['pages']))->toBeFalse();
});

it('normalises the options it is given', function () {
    $request = new RunRequest(['el'], RunDriver::Cli, options: [
        'mode' => 'update',
        'overwrite_manual' => 1,
        'review' => '0',
        'translator' => 'openai:gpt-6.1-sol',
        'new_status' => 'mirror',
        'publish_changes' => null,
    ]);

    expect($request->options)->toBe([
        'mode' => 'update',
        'overwrite_manual' => true,
        'review' => false,
        'translator' => 'openai:gpt-6.1-sol',
        'reviewer' => null,
        'new_status' => 'mirror',
        'publish_changes' => false,
    ]);
});

it('describes what was asked for as the run scope', function () {
    $request = new RunRequest(['de'], RunDriver::Queue, sourceSite: 'el', collections: ['pages'], navs: ['main'], entryIds: ['a', 'b'], termIds: ['tags::hotel']);

    expect($request->scope())->toBe([
        'sites' => ['de'],
        'source_site' => 'el',
        'collections' => ['pages'],
        'taxonomies' => [],
        'globals' => [],
        'navs' => ['main'],
        'entry_ids' => ['a', 'b'],
        'term_ids' => ['tags::hotel'],
    ]);
});

it('refuses an invalid request', function (array $arguments, string $message) {
    expect(fn () => new RunRequest(...['driver' => RunDriver::Browser, ...$arguments]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no site' => [['sites' => []], 'A run needs at least one site to translate into.'],
    'sites not a list' => [['sites' => ['a' => 'el']], 'The sites of a run request must be a list of handles or ids.'],
    'empty handle' => [['sites' => ['el'], 'collections' => ['']], 'The collections of a run request must be a list of handles or ids.'],
    'entry id not a string' => [['sites' => ['el'], 'entryIds' => [12]], 'The entryIds of a run request must be a list of handles or ids.'],
    'unknown option' => [['sites' => ['el'], 'options' => ['slug_mode' => 'keep']], 'Unknown run options [slug_mode].'],
    'invalid mode' => [['sites' => ['el'], 'options' => ['mode' => 'everything']], 'Invalid mode [everything]. Use missing, update, all or review.'],
    'invalid status' => [['sites' => ['el'], 'options' => ['new_status' => 'published']], 'Invalid new localization status [published]. Use draft or mirror.'],
    'translator not a string' => [['sites' => ['el'], 'options' => ['translator' => ['openai']]], 'The translator of a run request must be a model reference such as provider:model.'],
]);
